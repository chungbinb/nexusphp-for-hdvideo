<?php
/**
 * HDV 魔力银行 (P1: 信用评级 + 存款 + 分期借款 + 逾期状态)
 *  - 魔力 = 电影票 = users.seedbonus。
 *  - 活期：随存随取，年化计息（满24h起息），按日折算。
 *  - 定期：30/90/180/365 天年化档，到期得全额利息；提前支取只退本金（利息全失效）。
 *  - 借款：按信用等级定额度/利率；分 3/6/12/18/24 期(每期30天)，月利率，利息按实际占用本金与时间计；
 *    随时可提前部分/全部还款；到期未清记为逾期(实际处置在 P2)。
 * 费率/额度表见下方常量；信用评分自动按 分享率/做种时长/注册时长/处罚/还款历史 计算。
 * 自管理表，服务端事务。
 */

// 在后台(Filament/Laravel)上下文里，旧版 mysql_* 兼容层可能未加载，按需补上。
if (!function_exists('mysql_query') && is_file(__DIR__ . '/../nexus/Database/helpers.php')) {
    require_once __DIR__ . '/../nexus/Database/helpers.php';
}

const BANK_BUSINESS_TYPE = 51;
const BANK_ACCOUNT_TABLE = 'hdvideo_bank_accounts';
const BANK_CONFIG_TABLE = 'hdvideo_bank_config';
const BANK_POOL_TABLE = 'hdvideo_bank_pool';   // 资金池/风险准备金/待分红统计（单行 id=1）
const BANK_RESERVE_PCT = 25;                    // 贷款利息计入风险准备金的比例 %（其余进分红池）
const BANK_APP_TABLE = 'hdvideo_bank_loan_apps';     // 需担保的贷款申请
const BANK_GUARANTEE_TABLE = 'hdvideo_bank_guarantees';
const BANK_REQUEST_TABLE = 'hdvideo_bank_requests';  // 保险理赔/破产保护/债务重组 申请
const BANK_SETTINGS_TABLE = 'hdvideo_bank_settings'; // 单行开关表(id=1)
const BANK_MAX_GUARANTEES = 3;                  // 每人最多同时担保笔数
const BANK_INSURANCE_PCT = 1;                   // 贷款保险费 = 贷款额的 %

const BANK_MIN_DEPOSIT = 10000;     // 最低存款（活期/定期单笔）
const BANK_MIN_LOAN = 10000;        // 最低借款
const BANK_CUR_ANNUAL = 1.20;       // 活期年化 %
const BANK_INTEREST_MIN_HOURS = 24; // 活期满24h起息

// 定期：天数 => 年化 %
const BANK_FIX_TIERS = [30 => 2.50, 90 => 4.00, 180 => 6.00, 365 => 8.00];
// 借款分期：期数 => 月利率 %（每期30天）
const BANK_LOAN_TIERS = [3 => 0.50, 6 => 0.80, 12 => 1.00, 18 => 1.20, 24 => 1.50];
// 信用等级 => 最高可借（电影票）
const BANK_CREDIT_LIMIT = [
    'SSS' => 5000000, 'SS' => 3000000, 'S' => 2000000, 'A' => 1000000,
    'B' => 500000, 'C' => 100000, 'D' => 50000, 'E' => 0,
];
// 高信用月利率优惠（绝对值，%）
const BANK_RATE_DISCOUNT = ['SSS' => 0.15, 'SS' => 0.10, 'S' => 0.05];

function bank_money($v) { return number_format((float)$v, 2, '.', ''); }

function bank_ensure_tables()
{
    static $done = false;
    if ($done) return;
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . BANK_ACCOUNT_TABLE . "` (
            `uid` int unsigned NOT NULL,
            `deposit` decimal(20,2) NOT NULL DEFAULT '0.00',
            `deposit_ts` int unsigned NOT NULL DEFAULT 0,
            `fix_deposit` decimal(20,2) NOT NULL DEFAULT '0.00',
            `fix_ts` int unsigned NOT NULL DEFAULT 0,
            `fix_term` int unsigned NOT NULL DEFAULT 0,
            `fix_annual` decimal(8,4) NOT NULL DEFAULT '0.0000',
            `loan` decimal(20,2) NOT NULL DEFAULT '0.00',
            `loan_interest` decimal(20,2) NOT NULL DEFAULT '0.00',
            `loan_ts` int unsigned NOT NULL DEFAULT 0,
            `loan_periods` int unsigned NOT NULL DEFAULT 0,
            `loan_rate_m` decimal(8,4) NOT NULL DEFAULT '0.0000',
            `loan_start_ts` int unsigned NOT NULL DEFAULT 0,
            `loan_due_ts` int unsigned NOT NULL DEFAULT 0,
            `blacklisted` tinyint(1) NOT NULL DEFAULT 0,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // upgrade older account tables (dev46/47 had different loan/fix columns)
    foreach ([
        'fix_deposit' => "decimal(20,2) NOT NULL DEFAULT '0.00'",
        'fix_ts' => "int unsigned NOT NULL DEFAULT 0",
        'fix_term' => "int unsigned NOT NULL DEFAULT 0",
        'fix_annual' => "decimal(8,4) NOT NULL DEFAULT '0.0000'",
        'loan_interest' => "decimal(20,2) NOT NULL DEFAULT '0.00'",
        'loan_periods' => "int unsigned NOT NULL DEFAULT 0",
        'loan_rate_m' => "decimal(8,4) NOT NULL DEFAULT '0.0000'",
        'loan_start_ts' => "int unsigned NOT NULL DEFAULT 0",
        'loan_due_ts' => "int unsigned NOT NULL DEFAULT 0",
        'blacklisted' => "tinyint(1) NOT NULL DEFAULT 0",
        'loan_margin' => "decimal(20,2) NOT NULL DEFAULT '0.00'",
        'loan_app_id' => "int unsigned NOT NULL DEFAULT 0",
        'loan_insured' => "tinyint(1) NOT NULL DEFAULT 0",
        'no_accrual' => "tinyint(1) NOT NULL DEFAULT 0",
        'bankruptcy_used' => "tinyint(1) NOT NULL DEFAULT 0",
    ] as $colName => $def) {
        $c = sql_query("SHOW COLUMNS FROM `" . BANK_ACCOUNT_TABLE . "` LIKE '$colName'");
        if ($c && !mysql_fetch_assoc($c)) {
            sql_query("ALTER TABLE `" . BANK_ACCOUNT_TABLE . "` ADD COLUMN `$colName` $def") or sqlerr(__FILE__, __LINE__);
        }
    }
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . BANK_REQUEST_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `uid` int unsigned NOT NULL,
            `type` varchar(20) NOT NULL,
            `reason` varchar(500) NOT NULL DEFAULT '',
            `status` varchar(12) NOT NULL DEFAULT 'pending',
            `admin_note` varchar(255) NOT NULL DEFAULT '',
            `params` varchar(255) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL,
            `handled_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_status` (`status`),
            KEY `idx_uid` (`uid`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . BANK_APP_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `uid` int unsigned NOT NULL,
            `amount` bigint NOT NULL DEFAULT 0,
            `periods` int unsigned NOT NULL DEFAULT 0,
            `need` int unsigned NOT NULL DEFAULT 0,
            `margin_pct` int unsigned NOT NULL DEFAULT 0,
            `status` varchar(12) NOT NULL DEFAULT 'pending',
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_uid` (`uid`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . BANK_GUARANTEE_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `app_id` int unsigned NOT NULL,
            `borrower` int unsigned NOT NULL,
            `guarantor` int unsigned NOT NULL,
            `status` varchar(12) NOT NULL DEFAULT 'pending',
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_g` (`guarantor`,`status`),
            KEY `idx_app` (`app_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . BANK_POOL_TABLE . "` (
            `id` int unsigned NOT NULL,
            `total_interest` decimal(20,2) NOT NULL DEFAULT '0.00',
            `risk_reserve` decimal(20,2) NOT NULL DEFAULT '0.00',
            `dividend_pool` decimal(20,2) NOT NULL DEFAULT '0.00',
            `last_dividend_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pc = @sql_query("SELECT COUNT(*) AS c FROM `" . BANK_POOL_TABLE . "` WHERE `id` = 1");
    if ($pc && (int)mysql_fetch_assoc($pc)['c'] === 0) {
        @sql_query("INSERT INTO `" . BANK_POOL_TABLE . "` (`id`) VALUES (1)");
    }
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . BANK_SETTINGS_TABLE . "` (
            `id` int unsigned NOT NULL,
            `transfer_enabled` tinyint(1) NOT NULL DEFAULT 1,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $sc = @sql_query("SELECT COUNT(*) AS c FROM `" . BANK_SETTINGS_TABLE . "` WHERE `id` = 1");
    if ($sc && (int)mysql_fetch_assoc($sc)['c'] === 0) {
        @sql_query("INSERT INTO `" . BANK_SETTINGS_TABLE . "` (`id`,`transfer_enabled`) VALUES (1,1)");
    }
    $done = true;
}

/** 用户互转开关（默认开）。 */
function bank_transfer_enabled()
{
    static $v = null;
    if ($v !== null) return $v;
    bank_ensure_tables();
    $r = mysql_fetch_assoc(sql_query("SELECT `transfer_enabled` FROM `" . BANK_SETTINGS_TABLE . "` WHERE `id` = 1 LIMIT 1") ?: false);
    $v = $r ? ((int)$r['transfer_enabled'] === 1) : true;
    return $v;
}

/** 贷款利息入账：按比例分配到风险准备金与分红池。 */
function bank_pool_add_interest($interest)
{
    $interest = round((float)$interest, 2);
    if ($interest <= 0) return;
    $reserve = round($interest * BANK_RESERVE_PCT / 100, 2);
    $div = round($interest - $reserve, 2);
    sql_query("UPDATE `" . BANK_POOL_TABLE . "` SET `total_interest` = `total_interest` + " . sqlesc(bank_money($interest)) . ", `risk_reserve` = `risk_reserve` + " . sqlesc(bank_money($reserve)) . ", `dividend_pool` = `dividend_pool` + " . sqlesc(bank_money($div)) . " WHERE `id` = 1") or sqlerr(__FILE__, __LINE__);
}

/** 资金池/风险准备金/分红 概况。 */
function bank_pool_stats()
{
    bank_ensure_tables();
    $r = mysql_fetch_assoc(sql_query("SELECT COALESCE(SUM(`deposit`+`fix_deposit`),0) AS dep, COALESCE(SUM(`loan`),0) AS loans, SUM((`deposit`+`fix_deposit`)>0) AS depositors FROM `" . BANK_ACCOUNT_TABLE . "`") ?: false) ?: ['dep' => 0, 'loans' => 0, 'depositors' => 0];
    $p = mysql_fetch_assoc(sql_query("SELECT * FROM `" . BANK_POOL_TABLE . "` WHERE `id` = 1 LIMIT 1") ?: false) ?: [];
    return [
        'deposits' => round((float)$r['dep'], 2),
        'loans' => round((float)$r['loans'], 2),
        'depositors' => (int)$r['depositors'],
        'risk_reserve' => round((float)($p['risk_reserve'] ?? 0), 2),
        'dividend_pool' => round((float)($p['dividend_pool'] ?? 0), 2),
        'total_interest' => round((float)($p['total_interest'] ?? 0), 2),
        'last_dividend_at' => $p['last_dividend_at'] ?? null,
        'reserve_pct' => BANK_RESERVE_PCT,
    ];
}

/** 季度分红：把分红池按各用户存款占比派发到钱包。返回 [总派发, 人数]。 */
function bank_distribute_dividends()
{
    bank_ensure_tables();
    $p = mysql_fetch_assoc(sql_query("SELECT `dividend_pool` FROM `" . BANK_POOL_TABLE . "` WHERE `id` = 1 LIMIT 1") ?: false);
    $pool = round((float)($p['dividend_pool'] ?? 0), 2);
    if ($pool <= 0) return [0, 0];
    $rows = [];
    $total = 0.0;
    $res = sql_query("SELECT `uid`, (`deposit`+`fix_deposit`) AS dep FROM `" . BANK_ACCOUNT_TABLE . "` WHERE (`deposit`+`fix_deposit`) > 0") or sqlerr(__FILE__, __LINE__);
    while ($row = mysql_fetch_assoc($res)) {
        $rows[] = ['uid' => (int)$row['uid'], 'dep' => (float)$row['dep']];
        $total += (float)$row['dep'];
    }
    if ($total <= 0 || !$rows) return [0, 0];

    $distributed = 0.0;
    $count = 0;
    $now = date('Y-m-d H:i:s');
    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        foreach ($rows as $r) {
            $amt = floor($pool * ($r['dep'] / $total) * 100) / 100;
            if ($amt <= 0) continue;
            $ures = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = {$r['uid']} FOR UPDATE") or sqlerr(__FILE__, __LINE__);
            $u = mysql_fetch_assoc($ures);
            if (!$u) continue;
            $old = (float)$u['seedbonus'];
            $new = $old + $amt;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(bank_money($amt)) . " WHERE `id` = {$r['uid']}") or sqlerr(__FILE__, __LINE__);
            bank_log($r['uid'], $old, $amt, $new, "[高清银行] 季度存款分红 {$amt}");
            if (function_exists('clear_user_cache')) clear_user_cache($r['uid']);
            $distributed += $amt;
            $count++;
        }
        sql_query("UPDATE `" . BANK_POOL_TABLE . "` SET `dividend_pool` = 0, `last_dividend_at` = " . sqlesc($now) . " WHERE `id` = 1") or sqlerr(__FILE__, __LINE__);
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        return [0, 0];
    }
    return [round($distributed, 2), $count];
}

function bank_account($uid, $forUpdate = false)
{
    bank_ensure_tables();
    $uid = (int)$uid;
    $lock = $forUpdate ? " FOR UPDATE" : "";
    $res = sql_query("SELECT * FROM `" . BANK_ACCOUNT_TABLE . "` WHERE `uid` = $uid LIMIT 1$lock") or sqlerr(__FILE__, __LINE__);
    $a = mysql_fetch_assoc($res);
    if (!$a) {
        sql_query("INSERT INTO `" . BANK_ACCOUNT_TABLE . "` (`uid`,`updated_at`) VALUES ($uid, " . sqlesc(date('Y-m-d H:i:s')) . ")") or sqlerr(__FILE__, __LINE__);
        $res = sql_query("SELECT * FROM `" . BANK_ACCOUNT_TABLE . "` WHERE `uid` = $uid LIMIT 1$lock") or sqlerr(__FILE__, __LINE__);
        $a = mysql_fetch_assoc($res);
    }
    return $a;
}

/** 信用评分与等级（自动）。返回 [grade, score, max_loan, reg_days, ratio]. */
function bank_credit($uid)
{
    $uid = (int)$uid;
    $res = sql_query("SELECT `uploaded`,`downloaded`,`seedtime`,`added`,`enabled`,`leechwarn` FROM `users` WHERE `id` = $uid LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $u = mysql_fetch_assoc($res) ?: [];
    $up = (float)($u['uploaded'] ?? 0);
    $down = (float)($u['downloaded'] ?? 0);
    $ratio = $down > 0 ? $up / $down : ($up > 0 ? 99 : 1);
    $regDays = !empty($u['added']) ? max(0, (time() - strtotime($u['added'])) / 86400) : 0;
    $seedDays = (float)($u['seedtime'] ?? 0) / 86400;
    $banned = (($u['enabled'] ?? 'yes') !== 'yes');
    $warned = (($u['leechwarn'] ?? 'no') === 'yes');

    $score = 100;
    $score += $ratio >= 4 ? 200 : ($ratio >= 2.5 ? 160 : ($ratio >= 1.5 ? 120 : ($ratio >= 1 ? 80 : ($ratio >= 0.5 ? 40 : 0))));
    $score += $regDays >= 365 ? 150 : ($regDays >= 180 ? 110 : ($regDays >= 90 ? 70 : ($regDays >= 30 ? 40 : 10)));
    $score += $seedDays >= 180 ? 200 : ($seedDays >= 90 ? 150 : ($seedDays >= 30 ? 100 : ($seedDays >= 7 ? 50 : 10)));
    // 还款历史：历史还款笔数加分（封顶 150）
    $rp = sql_query("SELECT COUNT(*) AS c FROM `bonus_logs` WHERE `business_type` = " . BANK_BUSINESS_TYPE . " AND `uid` = $uid AND `comment` LIKE '%还款%'") or sqlerr(__FILE__, __LINE__);
    $repays = (int)mysql_fetch_assoc($rp)['c'];
    $score += min(150, $repays * 20);
    if ($banned) $score -= 400;
    if ($warned) $score -= 150;
    $score = max(0, (int)$score);

    $grade = $score >= 750 ? 'SSS' : ($score >= 650 ? 'SS' : ($score >= 550 ? 'S' : ($score >= 430 ? 'A' : ($score >= 320 ? 'B' : ($score >= 200 ? 'C' : ($score >= 100 ? 'D' : 'E'))))));
    return [
        'grade' => $grade,
        'score' => $score,
        'max_loan' => BANK_CREDIT_LIMIT[$grade],
        'reg_days' => (int)$regDays,
        'ratio' => round($ratio, 2),
    ];
}

function bank_loan_rate($periods, $grade)
{
    $base = BANK_LOAN_TIERS[$periods] ?? 1.0;
    $disc = BANK_RATE_DISCOUNT[$grade] ?? 0;
    return max(0.1, round($base - $disc, 4));
}

/** 活期当前价值（满24h起息，年化按日折算）。 */
function bank_cur_value($a, $now)
{
    $p = (float)$a['deposit'];
    if ($p <= 0 || (int)$a['deposit_ts'] <= 0) return round($p, 2);
    $secs = $now - (int)$a['deposit_ts'];
    if ($secs < BANK_INTEREST_MIN_HOURS * 3600) return round($p, 2);
    $days = $secs / 86400;
    return round($p + $p * (BANK_CUR_ANNUAL / 100 / 365) * $days, 2);
}

/** 借款当前欠款 = 本金 + 已计未还利息 + 自上次结算以来新增利息。 */
function bank_loan_owed($a, $now)
{
    $p = (float)$a['loan'];
    if ($p <= 0) return 0.0;
    $interest = (float)$a['loan_interest'];
    if (empty($a['no_accrual'])) { // 破产保护/重组后冻结计息
        $rateM = (float)$a['loan_rate_m'];
        $days = max(0, ($now - (int)$a['loan_ts']) / 86400);
        $interest += $p * ($rateM / 100) * ($days / 30); // 月利率按30天折算到日
    }
    return round($p + $interest, 2);
}

/** 逾期分级：0无 1(1-7) 2(8-15) 3(16-30) 4(31-60) 5(61-90) 6(>90)。 */
function bank_overdue_tier($days)
{
    if ($days <= 0) return 0;
    if ($days <= 7) return 1;
    if ($days <= 15) return 2;
    if ($days <= 30) return 3;
    if ($days <= 60) return 4;
    if ($days <= 90) return 5;
    return 6;
}

/**
 * 逾期/黑名单状态（供游戏等娱乐功能拦截使用）。tier>=3(逾期16天+)即限制娱乐功能。
 * 逾期>60天自动拉黑(持久)。返回 ['restricted','tier','days','blacklisted'].
 */
function bank_restricted($uid)
{
    bank_ensure_tables();
    $uid = (int)$uid;
    $res = sql_query("SELECT `loan`,`loan_due_ts`,`blacklisted` FROM `" . BANK_ACCOUNT_TABLE . "` WHERE `uid` = $uid LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $a = mysql_fetch_assoc($res);
    if (!$a) return ['restricted' => false, 'tier' => 0, 'days' => 0, 'blacklisted' => false];
    $days = 0;
    $due = (int)$a['loan_due_ts'];
    if ((float)$a['loan'] > 0 && $due > 0 && time() > $due) {
        $days = (int)floor((time() - $due) / 86400);
    }
    $tier = bank_overdue_tier($days);
    $blacklisted = ((int)$a['blacklisted'] === 1);
    if ($days > 60 && !$blacklisted) {
        sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `blacklisted` = 1 WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
        $blacklisted = true;
    }
    return ['restricted' => ($tier >= 3), 'tier' => $tier, 'days' => $days, 'blacklisted' => $blacklisted];
}

/** P2 自动还款：把钱包电影票优先用于偿还借款（每日计划任务调用，不依赖 $CURUSER）。返回已扣金额。 */
function bank_auto_repay($uid)
{
    bank_ensure_tables();
    $uid = (int)$uid;
    $now = time();
    $ts = date('Y-m-d H:i:s');
    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $ures = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = $uid FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $u = mysql_fetch_assoc($ures);
        if (!$u) { sql_query("ROLLBACK"); return 0; }
        $wallet = (float)$u['seedbonus'];
        $a = bank_account($uid, true);
        $p = (float)$a['loan'];
        if ($p <= 0 || $wallet <= 0) { sql_query("COMMIT"); return 0; }
        $interest = (float)$a['loan_interest'] + $p * ((float)$a['loan_rate_m'] / 100) * ((($now - (int)$a['loan_ts']) / 86400) / 30);
        $interest = round(max(0, $interest), 2);
        $owed = round($p + $interest, 2);
        $pay = round(min($wallet, $owed), 2);
        if ($pay <= 0) { sql_query("COMMIT"); return 0; }
        $payInterest = min($pay, $interest);
        $payPrincipal = round($pay - $payInterest, 2);
        $newInterest = round($interest - $payInterest, 2);
        $newPrincipal = round($p - $payPrincipal, 2);
        $newWallet = round($wallet - $pay, 2);
        sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` - " . sqlesc(bank_money($pay)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
        if ($payInterest > 0) bank_pool_add_interest($payInterest);
        bank_log($uid, $wallet, -$pay, $newWallet, "[高清银行] 自动还款 {$pay}");
        if ($newPrincipal <= 0 && $newInterest <= 0) {
            $marginRet = round((float)$a['loan_margin'], 2);
            $appId = (int)$a['loan_app_id'];
            if ($marginRet > 0) {
                sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(bank_money($marginRet)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
                bank_log($uid, $newWallet, $marginRet, round($newWallet + $marginRet, 2), "[高清银行] 退还保证金 {$marginRet}");
                $newWallet = round($newWallet + $marginRet, 2);
            }
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan` = 0, `loan_interest` = 0, `loan_ts` = 0, `loan_periods` = 0, `loan_rate_m` = 0, `loan_start_ts` = 0, `loan_due_ts` = 0, `loan_margin` = 0, `loan_app_id` = 0, `updated_at` = " . sqlesc($ts) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
            if ($appId > 0) {
                sql_query("UPDATE `" . BANK_GUARANTEE_TABLE . "` SET `status` = 'released' WHERE `app_id` = $appId AND `status` = 'agreed'") or sqlerr(__FILE__, __LINE__);
                sql_query("UPDATE `" . BANK_APP_TABLE . "` SET `status` = 'closed', `updated_at` = " . sqlesc($ts) . " WHERE `id` = $appId") or sqlerr(__FILE__, __LINE__);
            }
        } else {
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan` = " . sqlesc(bank_money($newPrincipal)) . ", `loan_interest` = " . sqlesc(bank_money($newInterest)) . ", `loan_ts` = $now, `updated_at` = " . sqlesc($ts) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
        }
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        if (function_exists('clear_user_cache')) clear_user_cache($uid);
        return $pay;
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        return 0;
    }
}

function bank_log($uid, $old, $delta, $new, $comment)
{
    $now = date('Y-m-d H:i:s');
    sql_query(sprintf(
        "INSERT INTO bonus_logs (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
        BANK_BUSINESS_TYPE, (int)$uid, sqlesc(bank_money($old)), sqlesc(bank_money($delta)), sqlesc(bank_money($new)), sqlesc($comment), sqlesc($now), sqlesc($now)
    )) or sqlerr(__FILE__, __LINE__);
}

const BANK_REQ_TYPES = ['insurance_claim' => '保险理赔', 'bankruptcy' => '破产保护', 'restructure' => '债务重组'];

/** 购买贷款保险（贷款额的1%，即时生效，无需审核）。 */
function bank_buy_insurance($uid)
{
    bank_ensure_tables();
    $uid = (int)$uid;
    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $ures = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = $uid FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $u = mysql_fetch_assoc($ures);
        if (!$u) { sql_query("ROLLBACK"); return [null, '用户不存在。']; }
        $wallet = (float)$u['seedbonus'];
        $a = bank_account($uid, true);
        if ((float)$a['loan'] <= 0) { sql_query("ROLLBACK"); return [null, '没有可投保的贷款。']; }
        if ((int)$a['loan_insured'] === 1) { sql_query("ROLLBACK"); return [null, '该贷款已购买保险。']; }
        $fee = round((float)$a['loan'] * BANK_INSURANCE_PCT / 100, 2);
        if ($wallet < $fee) { sql_query("ROLLBACK"); return [null, '电影票不足以支付保费（' . bank_money($fee) . '）。']; }
        $new = round($wallet - $fee, 2);
        sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` - " . sqlesc(bank_money($fee)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
        sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan_insured` = 1, `updated_at` = " . sqlesc(date('Y-m-d H:i:s')) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
        bank_log($uid, $wallet, -$fee, $new, "[高清银行] 购买贷款保险 {$fee}");
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        if (function_exists('clear_user_cache')) clear_user_cache($uid);
        $GLOBALS['CURUSER']['seedbonus'] = $new;
        return [bank_status($uid), ''];
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        return [null, '系统错误，请重试。'];
    }
}

/** 提交特殊申请（保险理赔/破产保护/债务重组），等待后台审核。 */
function bank_request($uid, $type, $reason)
{
    bank_ensure_tables();
    $uid = (int)$uid;
    if (!isset(BANK_REQ_TYPES[$type])) return [null, '无效的申请类型。'];
    $a = bank_account($uid, false);
    if ((float)$a['loan'] <= 0) return [null, '当前没有借款，无需申请。'];
    $dup = sql_query("SELECT `id` FROM `" . BANK_REQUEST_TABLE . "` WHERE `uid` = $uid AND `type` = " . sqlesc($type) . " AND `status` = 'pending' LIMIT 1") or sqlerr(__FILE__, __LINE__);
    if (mysql_fetch_assoc($dup)) return [null, '已有同类申请在审核中。'];

    if ($type === 'insurance_claim' && (int)$a['loan_insured'] !== 1) return [null, '该贷款未购买保险，无法理赔。'];
    if ($type === 'bankruptcy') {
        if ((int)$a['bankruptcy_used'] === 1) return [null, '破产保护每账号仅可申请一次。'];
        $credit = bank_credit($uid);
        $seedDays = 0;
        $su = mysql_fetch_assoc(sql_query("SELECT `seedtime` FROM `users` WHERE `id` = $uid LIMIT 1") ?: false);
        if ($su) $seedDays = (float)$su['seedtime'] / 86400;
        if ($seedDays < 180) return [null, '破产保护需累计做种满180天。'];
        if (!in_array($credit['grade'], ['SSS', 'SS', 'S', 'A'], true)) return [null, '破产保护需信用A级及以上。'];
    }

    $reason = mb_substr(trim((string)$reason), 0, 500);
    sql_query(sprintf("INSERT INTO `" . BANK_REQUEST_TABLE . "` (`uid`,`type`,`reason`,`status`,`created_at`) VALUES (%d,%s,%s,'pending',%s)",
        $uid, sqlesc($type), sqlesc($reason), sqlesc(date('Y-m-d H:i:s')))) or sqlerr(__FILE__, __LINE__);
    return [bank_status($uid), ''];
}

function bank_my_requests($uid)
{
    bank_ensure_tables();
    $out = [];
    $res = sql_query("SELECT `type`,`status` FROM `" . BANK_REQUEST_TABLE . "` WHERE `uid` = " . (int)$uid . " AND `status` = 'pending'") or sqlerr(__FILE__, __LINE__);
    while ($r = mysql_fetch_assoc($res)) {
        $out[] = ['type' => $r['type'], 'label' => BANK_REQ_TYPES[$r['type']] ?? $r['type']];
    }
    return $out;
}

/** 后台审核处理。$approve=true 批准并执行效果。$params: restructure 用的新期数。返回 [ok, msg]. */
function bank_handle_request($id, $approve, $adminNote = '', $params = '')
{
    bank_ensure_tables();
    $id = (int)$id;
    $res = sql_query("SELECT * FROM `" . BANK_REQUEST_TABLE . "` WHERE `id` = $id AND `status` = 'pending' LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $req = mysql_fetch_assoc($res);
    if (!$req) return [false, '申请不存在或已处理。'];
    $uid = (int)$req['uid'];
    $type = $req['type'];
    $now = time();
    $ts = date('Y-m-d H:i:s');

    if (!$approve) {
        sql_query("UPDATE `" . BANK_REQUEST_TABLE . "` SET `status` = 'rejected', `admin_note` = " . sqlesc(mb_substr((string)$adminNote, 0, 255)) . ", `handled_at` = " . sqlesc($ts) . " WHERE `id` = $id") or sqlerr(__FILE__, __LINE__);
        bank_pm($uid, "银行申请未通过", "你的「" . (BANK_REQ_TYPES[$type] ?? $type) . "」申请未通过。" . ($adminNote !== '' ? "原因：{$adminNote}" : ''));
        return [true, '已拒绝。'];
    }

    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $a = bank_account($uid, true);
        if ((float)$a['loan'] <= 0) { sql_query("ROLLBACK"); return [false, '该用户已无借款，无需处理。']; }
        // 先结算已计利息入 loan_interest
        $p = (float)$a['loan'];
        $accrued = empty($a['no_accrual']) ? $p * ((float)$a['loan_rate_m'] / 100) * ((($now - (int)$a['loan_ts']) / 86400) / 30) : 0;
        $interest = round((float)$a['loan_interest'] + max(0, $accrued), 2);

        if ($type === 'insurance_claim') {
            // 减免逾期利息：清零应计利息，延长到期30天，消耗保险
            $due = max((int)$a['loan_due_ts'], $now) + 30 * 86400;
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan_interest` = 0, `loan_ts` = $now, `loan_due_ts` = $due, `loan_insured` = 0, `updated_at` = " . sqlesc($ts) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
            bank_pm($uid, "保险理赔已通过", "已减免本笔贷款的应计利息 " . bank_money($interest) . "，并延长还款期30天。请尽快还清本金。");
        } elseif ($type === 'bankruptcy') {
            // 暂停计息，延长90天，保留信用，标记已用
            $due = max((int)$a['loan_due_ts'], $now) + 90 * 86400;
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan_interest` = " . sqlesc(bank_money($interest)) . ", `loan_ts` = $now, `no_accrual` = 1, `loan_due_ts` = $due, `bankruptcy_used` = 1, `updated_at` = " . sqlesc($ts) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
            bank_pm($uid, "破产保护已通过", "已暂停计息、延长还款期90天并保留你的信用等级。请在宽限期内积极还款。");
        } elseif ($type === 'restructure') {
            $periods = (int)$params;
            if (!isset(BANK_LOAN_TIERS[$periods])) { sql_query("ROLLBACK"); return [false, '请提供有效的新期数(3/6/12/18/24)。']; }
            $credit = bank_credit($uid);
            $rateM = bank_loan_rate($periods, $credit['grade']);
            $due = $now + $periods * 30 * 86400;
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan_interest` = " . sqlesc(bank_money($interest)) . ", `loan_ts` = $now, `loan_periods` = $periods, `loan_rate_m` = " . sqlesc(number_format($rateM, 4, '.', '')) . ", `loan_due_ts` = $due, `no_accrual` = 0, `updated_at` = " . sqlesc($ts) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
            bank_pm($uid, "债务重组已通过", "已将你的贷款重组为 {$periods} 期（月息 {$rateM}%），到期日顺延，降低每期压力。");
        } else {
            sql_query("ROLLBACK");
            return [false, '未知类型。'];
        }
        sql_query("UPDATE `" . BANK_REQUEST_TABLE . "` SET `status` = 'approved', `admin_note` = " . sqlesc(mb_substr((string)$adminNote, 0, 255)) . ", `params` = " . sqlesc(mb_substr((string)$params, 0, 255)) . ", `handled_at` = " . sqlesc($ts) . " WHERE `id` = $id") or sqlerr(__FILE__, __LINE__);
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        if (function_exists('clear_user_cache')) clear_user_cache($uid);
        return [true, '已批准并执行。'];
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        return [false, '处理失败：' . $e->getMessage()];
    }
}

function bank_pm($uid, $subject, $body)
{
    $now = date('Y-m-d H:i:s');
    @sql_query(sprintf("INSERT INTO messages (sender, receiver, added, subject, msg) VALUES (0, %d, %s, %s, %s)", (int)$uid, sqlesc($now), sqlesc($subject), sqlesc($body)));
}

/** 用户给用户转账电影票（受后台开关控制）。返回 [statusArray|null, error]. */
function bank_transfer($fromUid, $toName, $amount)
{
    global $CURUSER;
    bank_ensure_tables();
    if (!bank_transfer_enabled()) return [null, '转账功能已关闭。'];
    $fromUid = (int)$fromUid;
    $amount = round((float)$amount, 2);
    if ($amount <= 0) return [null, '请输入有效的转账金额。'];
    $toName = trim((string)$toName);
    if ($toName === '') return [null, '请输入收款人用户名。'];
    $toUid = bank_uid_by_name($toName);
    if ($toUid <= 0) return [null, '找不到该用户。'];
    if ($toUid === $fromUid) return [null, '不能转账给自己。'];
    $rs = bank_restricted($fromUid);
    if (!empty($rs['restricted'])) return [null, '贷款逾期期间暂停转账，请先还清欠款。'];

    $lo = min($fromUid, $toUid);
    $hi = max($fromUid, $toUid);
    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        // 按 uid 顺序加锁，避免死锁
        sql_query("SELECT `id` FROM `users` WHERE `id` = $lo FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        sql_query("SELECT `id` FROM `users` WHERE `id` = $hi FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $fu = mysql_fetch_assoc(sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = $fromUid LIMIT 1"));
        $tu = mysql_fetch_assoc(sql_query("SELECT `seedbonus`,`username` FROM `users` WHERE `id` = $toUid LIMIT 1"));
        if (!$fu || !$tu) { sql_query("ROLLBACK"); return [null, '用户不存在。']; }
        $fromOld = (float)$fu['seedbonus'];
        if ($fromOld < $amount) { sql_query("ROLLBACK"); return [null, '电影票不足。']; }
        $toOld = (float)$tu['seedbonus'];
        $fromName = bank_name_by_uid($fromUid);
        sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` - " . sqlesc(bank_money($amount)) . " WHERE `id` = $fromUid") or sqlerr(__FILE__, __LINE__);
        sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(bank_money($amount)) . " WHERE `id` = $toUid") or sqlerr(__FILE__, __LINE__);
        bank_log($fromUid, $fromOld, -$amount, round($fromOld - $amount, 2), "[高清银行] 转账给 {$tu['username']} {$amount}");
        bank_log($toUid, $toOld, $amount, round($toOld + $amount, 2), "[高清银行] 收到 {$fromName} 转账 {$amount}");
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        if (function_exists('clear_user_cache')) { clear_user_cache($fromUid); clear_user_cache($toUid); }
        bank_pm($toUid, "收到转账", "你收到来自 {$fromName} 的转账 " . number_format($amount) . " 电影票。");
        $CURUSER['seedbonus'] = round($fromOld - $amount, 2);
        return [bank_status($fromUid), ''];
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        return [null, '系统错误，请重试。'];
    }
}

/** 担保/保证金要求：[需担保人数, 保证金比例%]。 */
function bank_guarantor_req($amount)
{
    $amount = (float)$amount;
    if ($amount < 1000000) return [0, 0];
    if ($amount <= 3000000) return [1, 10];
    if ($amount <= 5000000) return [2, 15];
    return [3, 20];
}

function bank_uid_by_name($name)
{
    $name = trim((string)$name);
    if ($name === '') return 0;
    $res = sql_query("SELECT `id` FROM `users` WHERE `username` = " . sqlesc($name) . " LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $r = mysql_fetch_assoc($res);
    return $r ? (int)$r['id'] : 0;
}

function bank_name_by_uid($uid)
{
    $res = sql_query("SELECT `username` FROM `users` WHERE `id` = " . (int)$uid . " LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $r = mysql_fetch_assoc($res);
    return $r ? (string)$r['username'] : ('用户#' . (int)$uid);
}

function bank_active_guarantee_count($uid)
{
    $res = sql_query("SELECT COUNT(*) AS c FROM `" . BANK_GUARANTEE_TABLE . "` WHERE `guarantor` = " . (int)$uid . " AND `status` = 'agreed'") or sqlerr(__FILE__, __LINE__);
    return (int)mysql_fetch_assoc($res)['c'];
}

/** 担保人资格（第十一条简化版）。返回 [bool, reason]. */
function bank_guarantor_eligible($uid)
{
    bank_ensure_tables();
    $credit = bank_credit($uid);
    if ($credit['reg_days'] < 180) return [false, '注册未满180天'];
    if ($credit['ratio'] < 2.5) return [false, '分享率需≥2.5'];
    if (!in_array($credit['grade'], ['SSS', 'SS', 'S', 'A'], true)) return [false, '信用等级需A级及以上'];
    $rs = bank_restricted($uid);
    if (!empty($rs['blacklisted']) || $rs['tier'] > 0) return [false, '本人有逾期/黑名单记录'];
    if (bank_active_guarantee_count($uid) >= BANK_MAX_GUARANTEES) return [false, '已达最多同时担保 ' . BANK_MAX_GUARANTEES . ' 笔'];
    return [true, ''];
}

/** 申请贷款（无需担保则直接放款；需担保则建申请并邀请担保人）。返回 [statusArray|null, error]. */
function bank_apply_loan($uid, $amount, $periods, $guarantorsStr)
{
    bank_ensure_tables();
    $uid = (int)$uid;
    $amount = round((float)$amount, 2);
    $periods = (int)$periods;
    [$need, $marginPct] = bank_guarantor_req($amount);

    // 基础校验
    $a = bank_account($uid, false);
    if (bank_loan_owed($a, time()) > 0) return [null, '已有未结清借款，请先还清再借。'];
    $exist = sql_query("SELECT `id` FROM `" . BANK_APP_TABLE . "` WHERE `uid` = $uid AND `status` = 'pending' LIMIT 1") or sqlerr(__FILE__, __LINE__);
    if (mysql_fetch_assoc($exist)) return [null, '你有一笔贷款申请待担保人确认，请先处理。'];
    if ((int)$a['blacklisted'] === 1) return [null, '账户已被列入贷款黑名单。'];
    if (!isset(BANK_LOAN_TIERS[$periods])) return [null, '请选择有效的分期期数。'];
    $credit = bank_credit($uid);
    if ($credit['grade'] === 'E') return [null, '信用等级为 E，暂不可借款。'];
    if ($credit['reg_days'] < 30) return [null, '注册未满 30 天，暂不可借款。'];
    if ($amount < BANK_MIN_LOAN) return [null, "最低借款 " . BANK_MIN_LOAN . "。"];
    if ($amount > $credit['max_loan']) return [null, "超过你的信用额度（{$credit['grade']} 级最高可借 " . number_format($credit['max_loan']) . "）。"];

    if ($need === 0) {
        return bank_do($uid, 'borrow', $amount, $periods); // 无需担保，直接放款
    }

    // 需要担保人
    $names = array_values(array_filter(array_map('trim', preg_split('/[,，\s]+/u', (string)$guarantorsStr))));
    $names = array_unique($names);
    if (count($names) < $need) return [null, "该金额需 {$need} 名担保人，请填写 {$need} 个担保人用户名。"];
    $gUids = [];
    foreach ($names as $n) {
        $gid = bank_uid_by_name($n);
        if ($gid <= 0) return [null, "找不到用户：{$n}"];
        if ($gid === $uid) return [null, '不能担保自己。'];
        if (in_array($gid, $gUids, true)) continue;
        [$ok, $reason] = bank_guarantor_eligible($gid);
        if (!$ok) return [null, "{$n} 不符合担保资格（{$reason}）。"];
        $gUids[] = $gid;
    }
    if (count($gUids) < $need) return [null, "需 {$need} 名不同的合格担保人。"];
    $gUids = array_slice($gUids, 0, $need);

    $now = date('Y-m-d H:i:s');
    sql_query(sprintf("INSERT INTO `" . BANK_APP_TABLE . "` (`uid`,`amount`,`periods`,`need`,`margin_pct`,`status`,`created_at`,`updated_at`) VALUES (%d,%d,%d,%d,%d,'pending',%s,%s)",
        $uid, (int)$amount, $periods, $need, $marginPct, sqlesc($now), sqlesc($now))) or sqlerr(__FILE__, __LINE__);
    $appId = (int)mysql_insert_id();
    $bname = bank_name_by_uid($uid);
    foreach ($gUids as $gid) {
        sql_query(sprintf("INSERT INTO `" . BANK_GUARANTEE_TABLE . "` (`app_id`,`borrower`,`guarantor`,`status`,`created_at`) VALUES (%d,%d,%d,'pending',%s)", $appId, $uid, $gid, sqlesc($now))) or sqlerr(__FILE__, __LINE__);
        bank_pm($gid, "魔力银行担保请求", "用户 {$bname} 申请借款 " . number_format($amount) . " 电影票，邀请你作为担保人。请到右侧「高清银行」弹窗确认是否同意担保。若借款人逾期超30天未还，担保人需按比例代偿。");
    }
    return [bank_status($uid), ''];
}

/** 全部担保人同意后正式放款（冻结保证金）。 */
function bank_grant_from_app($appId)
{
    $appId = (int)$appId;
    $ares = sql_query("SELECT * FROM `" . BANK_APP_TABLE . "` WHERE `id` = $appId AND `status` = 'pending' LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $app = mysql_fetch_assoc($ares);
    if (!$app) return false;
    $uid = (int)$app['uid'];
    $amount = (float)$app['amount'];
    $periods = (int)$app['periods'];
    $marginPct = (int)$app['margin_pct'];
    $now = time();
    $ts = date('Y-m-d H:i:s');
    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $ures = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = $uid FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $u = mysql_fetch_assoc($ures);
        if (!$u) { sql_query("ROLLBACK"); return false; }
        $wallet = (float)$u['seedbonus'];
        $a = bank_account($uid, true);
        if (bank_loan_owed($a, $now) > 0) { sql_query("ROLLBACK"); return false; }
        $margin = round($amount * $marginPct / 100, 2);
        if ($wallet < $margin) {
            sql_query("ROLLBACK");
            sql_query("UPDATE `" . BANK_APP_TABLE . "` SET `status` = 'cancelled', `updated_at` = " . sqlesc($ts) . " WHERE `id` = $appId");
            sql_query("UPDATE `" . BANK_GUARANTEE_TABLE . "` SET `status` = 'cancelled' WHERE `app_id` = $appId");
            bank_pm($uid, "贷款放款失败", "保证金不足（需 " . number_format($margin) . " 电影票），贷款申请已取消。");
            return false;
        }
        $credit = bank_credit($uid);
        $rateM = bank_loan_rate($periods, $credit['grade']);
        $due = $now + $periods * 30 * 86400;
        $net = $amount - $margin; // 到账 = 借款 - 冻结保证金
        $newWallet = $wallet + $net;
        sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(bank_money($net)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
        sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan` = " . sqlesc(bank_money($amount)) . ", `loan_interest` = 0, `loan_ts` = $now, `loan_periods` = $periods, `loan_rate_m` = " . sqlesc(number_format($rateM, 4, '.', '')) . ", `loan_start_ts` = $now, `loan_due_ts` = $due, `loan_margin` = " . sqlesc(bank_money($margin)) . ", `loan_app_id` = $appId, `updated_at` = " . sqlesc($ts) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
        sql_query("UPDATE `" . BANK_APP_TABLE . "` SET `status` = 'approved', `updated_at` = " . sqlesc($ts) . " WHERE `id` = $appId") or sqlerr(__FILE__, __LINE__);
        bank_log($uid, $wallet, $net, $newWallet, "[高清银行] 担保借款{$periods}期 {$amount}（冻结保证金{$margin}）");
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        if (function_exists('clear_user_cache')) clear_user_cache($uid);
        bank_pm($uid, "贷款已放款", "你的借款 " . number_format($amount) . " 已放款（冻结保证金 " . number_format($margin) . "，实际到账 " . number_format($net) . "）。请按时还款，逾期将启用担保代偿。");
        return true;
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        return false;
    }
}

/** 担保人响应（同意/拒绝）。返回 [statusArray|null, error]. */
function bank_respond_guarantee($guarantorUid, $appId, $agree)
{
    bank_ensure_tables();
    $guarantorUid = (int)$guarantorUid;
    $appId = (int)$appId;
    $res = sql_query("SELECT * FROM `" . BANK_GUARANTEE_TABLE . "` WHERE `app_id` = $appId AND `guarantor` = $guarantorUid AND `status` = 'pending' LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $g = mysql_fetch_assoc($res);
    if (!$g) return [null, '该担保请求不存在或已处理。'];
    $borrower = (int)$g['borrower'];
    $now = date('Y-m-d H:i:s');

    if (!$agree) {
        sql_query("UPDATE `" . BANK_GUARANTEE_TABLE . "` SET `status` = 'rejected' WHERE `id` = " . (int)$g['id']) or sqlerr(__FILE__, __LINE__);
        sql_query("UPDATE `" . BANK_APP_TABLE . "` SET `status` = 'rejected', `updated_at` = " . sqlesc($now) . " WHERE `id` = $appId AND `status` = 'pending'") or sqlerr(__FILE__, __LINE__);
        sql_query("UPDATE `" . BANK_GUARANTEE_TABLE . "` SET `status` = 'cancelled' WHERE `app_id` = $appId AND `status` = 'pending'") or sqlerr(__FILE__, __LINE__);
        bank_pm($borrower, "担保被拒绝", "你的贷款申请被担保人拒绝，申请已取消，可重新申请。");
        return [bank_status($guarantorUid), ''];
    }

    [$ok, $reason] = bank_guarantor_eligible($guarantorUid);
    if (!$ok) return [null, "你当前不符合担保资格（{$reason}）。"];
    sql_query("UPDATE `" . BANK_GUARANTEE_TABLE . "` SET `status` = 'agreed' WHERE `id` = " . (int)$g['id']) or sqlerr(__FILE__, __LINE__);
    // 全部同意则放款
    $pend = sql_query("SELECT COUNT(*) AS c FROM `" . BANK_GUARANTEE_TABLE . "` WHERE `app_id` = $appId AND `status` = 'pending'") or sqlerr(__FILE__, __LINE__);
    if ((int)mysql_fetch_assoc($pend)['c'] === 0) {
        bank_grant_from_app($appId);
    }
    return [bank_status($guarantorUid), ''];
}

function bank_cancel_app($uid)
{
    bank_ensure_tables();
    $uid = (int)$uid;
    $res = sql_query("SELECT `id` FROM `" . BANK_APP_TABLE . "` WHERE `uid` = $uid AND `status` = 'pending' LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $app = mysql_fetch_assoc($res);
    if (!$app) return [null, '没有待处理的申请。'];
    $appId = (int)$app['id'];
    sql_query("UPDATE `" . BANK_APP_TABLE . "` SET `status` = 'cancelled', `updated_at` = " . sqlesc(date('Y-m-d H:i:s')) . " WHERE `id` = $appId") or sqlerr(__FILE__, __LINE__);
    sql_query("UPDATE `" . BANK_GUARANTEE_TABLE . "` SET `status` = 'cancelled' WHERE `app_id` = $appId AND `status` = 'pending'") or sqlerr(__FILE__, __LINE__);
    return [bank_status($uid), ''];
}

/** 借款人欠款列表中、我作为担保人的待确认请求。 */
function bank_guarantee_requests($uid)
{
    bank_ensure_tables();
    $uid = (int)$uid;
    $out = [];
    $res = sql_query("SELECT g.`app_id`, g.`borrower`, ap.`amount`, ap.`periods`, ap.`need` FROM `" . BANK_GUARANTEE_TABLE . "` g INNER JOIN `" . BANK_APP_TABLE . "` ap ON ap.`id` = g.`app_id` WHERE g.`guarantor` = $uid AND g.`status` = 'pending' AND ap.`status` = 'pending'") or sqlerr(__FILE__, __LINE__);
    while ($r = mysql_fetch_assoc($res)) {
        $out[] = [
            'app_id' => (int)$r['app_id'],
            'borrower' => bank_name_by_uid((int)$r['borrower']),
            'amount' => (float)$r['amount'],
            'periods' => (int)$r['periods'],
        ];
    }
    return $out;
}

/** 我自己的待确认贷款申请（含各担保人状态）。 */
function bank_my_app($uid)
{
    bank_ensure_tables();
    $uid = (int)$uid;
    $res = sql_query("SELECT * FROM `" . BANK_APP_TABLE . "` WHERE `uid` = $uid AND `status` = 'pending' ORDER BY `id` DESC LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $app = mysql_fetch_assoc($res);
    if (!$app) return null;
    $gs = [];
    $gr = sql_query("SELECT `guarantor`,`status` FROM `" . BANK_GUARANTEE_TABLE . "` WHERE `app_id` = " . (int)$app['id']) or sqlerr(__FILE__, __LINE__);
    while ($g = mysql_fetch_assoc($gr)) {
        $gs[] = ['name' => bank_name_by_uid((int)$g['guarantor']), 'status' => $g['status']];
    }
    return ['amount' => (float)$app['amount'], 'periods' => (int)$app['periods'], 'margin_pct' => (int)$app['margin_pct'], 'guarantors' => $gs];
}

/** 担保代偿（逾期>30天调用）：先用保证金，再由担保人按人数均摊。返回已代偿金额。 */
function bank_compensate($borrowerUid)
{
    bank_ensure_tables();
    $borrowerUid = (int)$borrowerUid;
    $now = time();
    $ts = date('Y-m-d H:i:s');
    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $a = bank_account($borrowerUid, true);
        $owed = bank_loan_owed($a, $now);
        if ($owed <= 0) { sql_query("COMMIT"); return 0; }
        $appId = (int)$a['loan_app_id'];
        $margin = (float)$a['loan_margin'];
        $covered = 0;
        // 1) 保证金抵债
        if ($margin > 0) {
            $useM = min($margin, $owed);
            $owed = round($owed - $useM, 2);
            $margin = round($margin - $useM, 2);
            $covered += $useM;
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan_margin` = " . sqlesc(bank_money($margin)) . " WHERE `uid` = $borrowerUid") or sqlerr(__FILE__, __LINE__);
            bank_log($borrowerUid, 0, 0, 0, "[高清银行] 保证金抵债 {$useM}");
        }
        // 2) 担保人代偿
        if ($owed > 0 && $appId > 0) {
            $gr = sql_query("SELECT `id`,`guarantor` FROM `" . BANK_GUARANTEE_TABLE . "` WHERE `app_id` = $appId AND `status` = 'agreed'") or sqlerr(__FILE__, __LINE__);
            $gs = [];
            while ($g = mysql_fetch_assoc($gr)) $gs[] = $g;
            $n = count($gs);
            if ($n > 0) {
                $share = round($owed / $n, 2);
                foreach ($gs as $g) {
                    if ($owed <= 0) break;
                    $gid = (int)$g['guarantor'];
                    $want = min($share, $owed);
                    $gu = mysql_fetch_assoc(sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = $gid FOR UPDATE"));
                    if (!$gu) continue;
                    $gw = (float)$gu['seedbonus'];
                    $take = round(min($want, max(0, $gw)), 2);
                    if ($take > 0) {
                        sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` - " . sqlesc(bank_money($take)) . " WHERE `id` = $gid") or sqlerr(__FILE__, __LINE__);
                        bank_log($gid, $gw, -$take, round($gw - $take, 2), "[高清银行] 担保代偿（借款人逾期）{$take}");
                        if (function_exists('clear_user_cache')) clear_user_cache($gid);
                        $owed = round($owed - $take, 2);
                        $covered += $take;
                    }
                    sql_query("UPDATE `" . BANK_GUARANTEE_TABLE . "` SET `status` = 'fulfilled' WHERE `id` = " . (int)$g['id']) or sqlerr(__FILE__, __LINE__);
                }
            }
        }
        // 3) 更新借款人欠款
        if ($owed <= 0) {
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan` = 0, `loan_interest` = 0, `loan_ts` = 0, `loan_periods` = 0, `loan_rate_m` = 0, `loan_start_ts` = 0, `loan_due_ts` = 0, `loan_margin` = 0, `loan_app_id` = 0, `updated_at` = " . sqlesc($ts) . " WHERE `uid` = $borrowerUid") or sqlerr(__FILE__, __LINE__);
            if ($appId > 0) sql_query("UPDATE `" . BANK_APP_TABLE . "` SET `status` = 'compensated', `updated_at` = " . sqlesc($ts) . " WHERE `id` = $appId");
        } else {
            // 仍有剩余，记为本金，重置计息时间
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan` = " . sqlesc(bank_money($owed)) . ", `loan_interest` = 0, `loan_ts` = $now, `updated_at` = " . sqlesc($ts) . " WHERE `uid` = $borrowerUid") or sqlerr(__FILE__, __LINE__);
        }
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        if (function_exists('clear_user_cache')) clear_user_cache($borrowerUid);
        return round($covered, 2);
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        return 0;
    }
}

function bank_status($uid)
{
    global $CURUSER;
    $a = bank_account($uid, false);
    $now = time();
    $credit = bank_credit($uid);

    $fix = null;
    if ((float)$a['fix_deposit'] > 0) {
        $p = (float)$a['fix_deposit'];
        $term = (int)$a['fix_term'];
        $annual = (float)$a['fix_annual'];
        $due = (int)$a['fix_ts'] + $term * 86400;
        $matured = $now >= $due;
        $matureValue = round($p + $p * ($annual / 100 / 365) * $term, 2);
        $fix = [
            'principal' => round($p, 2), 'annual' => $annual, 'term' => $term, 'due_ts' => $due,
            'matured' => $matured, 'mature_value' => $matureValue,
            'value_now' => $matured ? $matureValue : round($p, 2), // 提前取只退本金
        ];
    }

    $loan = null;
    if ((float)$a['loan'] > 0) {
        $due = (int)$a['loan_due_ts'];
        $loan = [
            'owed' => bank_loan_owed($a, $now),
            'principal' => round((float)$a['loan'], 2),
            'periods' => (int)$a['loan_periods'],
            'rate_m' => (float)$a['loan_rate_m'],
            'due_ts' => $due,
            'margin' => round((float)$a['loan_margin'], 2),
            'insured' => ((int)$a['loan_insured'] === 1),
            'frozen' => ((int)$a['no_accrual'] === 1),
            'overdue' => ($due > 0 && $now > $due),
            'overdue_days' => ($due > 0 && $now > $due) ? (int)floor(($now - $due) / 86400) : 0,
        ];
    }

    $fixTiers = [];
    foreach (BANK_FIX_TIERS as $d => $r) $fixTiers[] = ['days' => $d, 'annual' => $r];
    $loanTiers = [];
    foreach (BANK_LOAN_TIERS as $pn => $r) $loanTiers[] = ['periods' => $pn, 'rate_m' => bank_loan_rate($pn, $credit['grade'])];

    $rs = bank_restricted($uid);
    if ($loan !== null) {
        $loan['tier'] = $rs['tier'];
    }
    $canBorrow = ($credit['grade'] !== 'E' && $credit['reg_days'] >= 30 && !$rs['blacklisted']);
    $block = $rs['blacklisted'] ? '账户已被列入贷款黑名单（曾逾期超过60天）' : ($credit['grade'] === 'E' ? '信用等级为 E，暂不可借款' : ($credit['reg_days'] < 30 ? '注册未满 30 天，暂不可借款' : ''));

    return [
        'wallet' => round((float)($CURUSER['seedbonus'] ?? 0), 2),
        'cur_deposit' => bank_cur_value($a, $now),
        'cur_annual' => BANK_CUR_ANNUAL,
        'fix' => $fix,
        'loan' => $loan,
        'credit' => $credit,
        'fix_tiers' => $fixTiers,
        'loan_tiers' => $loanTiers,
        'min_deposit' => BANK_MIN_DEPOSIT,
        'min_loan' => BANK_MIN_LOAN,
        'restricted' => $rs['restricted'],
        'overdue_tier' => $rs['tier'],
        'blacklisted' => $rs['blacklisted'],
        'can_borrow' => $canBorrow,
        'borrow_block' => $block,
        'pool' => bank_pool_stats(),
        'my_app' => bank_my_app($uid),
        'guarantee_requests' => bank_guarantee_requests($uid),
        'my_requests' => bank_my_requests($uid),
        'insurance_pct' => BANK_INSURANCE_PCT,
        'transfer_enabled' => bank_transfer_enabled(),
    ];
}

function bank_do($uid, $action, $amount, $param = 0)
{
    global $CURUSER;
    bank_ensure_tables();
    $uid = (int)$uid;
    $amount = round((float)$amount, 2);
    $param = (int)$param;

    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $ures = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = $uid FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $u = mysql_fetch_assoc($ures);
        if (!$u) { sql_query("ROLLBACK"); return [null, '用户不存在。']; }
        $wallet = (float)$u['seedbonus'];
        $a = bank_account($uid, true);
        $now = time();
        $ts = date('Y-m-d H:i:s');

        if ($action === 'deposit' || $action === 'withdraw') {
            $cur = bank_cur_value($a, $now);
            if ($action === 'deposit') {
                if ($amount < BANK_MIN_DEPOSIT) { sql_query("ROLLBACK"); return [null, "活期最低存入 " . BANK_MIN_DEPOSIT . "。"]; }
                if ($wallet < $amount) { sql_query("ROLLBACK"); return [null, '钱包电影票不足。']; }
                $newCur = $cur + $amount; $newWallet = $wallet - $amount;
                sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` - " . sqlesc(bank_money($amount)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
                bank_log($uid, $wallet, -$amount, $newWallet, "[高清银行] 活期存入 {$amount}");
            } else {
                if ($cur < $amount) { sql_query("ROLLBACK"); return [null, '活期余额不足。']; }
                $newCur = $cur - $amount; $newWallet = $wallet + $amount;
                sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(bank_money($amount)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
                bank_log($uid, $wallet, $amount, $newWallet, "[高清银行] 活期取出 {$amount}");
            }
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `deposit` = " . sqlesc(bank_money($newCur)) . ", `deposit_ts` = $now, `updated_at` = " . sqlesc($ts) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);

        } elseif ($action === 'deposit_fix') {
            if ((float)$a['fix_deposit'] > 0) { sql_query("ROLLBACK"); return [null, '已有一笔定期，请先取出后再存。']; }
            if (!isset(BANK_FIX_TIERS[$param])) { sql_query("ROLLBACK"); return [null, '请选择有效的存期。']; }
            if ($amount < BANK_MIN_DEPOSIT) { sql_query("ROLLBACK"); return [null, "定期最低存入 " . BANK_MIN_DEPOSIT . "。"]; }
            if ($wallet < $amount) { sql_query("ROLLBACK"); return [null, '钱包电影票不足。']; }
            $annual = BANK_FIX_TIERS[$param];
            $newWallet = $wallet - $amount;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` - " . sqlesc(bank_money($amount)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `fix_deposit` = " . sqlesc(bank_money($amount)) . ", `fix_ts` = $now, `fix_term` = $param, `fix_annual` = " . sqlesc(number_format($annual, 4, '.', '')) . ", `updated_at` = " . sqlesc($ts) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
            bank_log($uid, $wallet, -$amount, $newWallet, "[高清银行] 存入{$param}天定期 {$amount}");

        } elseif ($action === 'withdraw_fix') {
            if ((float)$a['fix_deposit'] <= 0) { sql_query("ROLLBACK"); return [null, '没有定期存款。']; }
            $p = (float)$a['fix_deposit'];
            $due = (int)$a['fix_ts'] + (int)$a['fix_term'] * 86400;
            if ($now >= $due) {
                $payout = round($p + $p * ((float)$a['fix_annual'] / 100 / 365) * (int)$a['fix_term'], 2);
                $note = "[高清银行] 定期到期支取 {$payout}";
            } else {
                $payout = round($p, 2); // 提前支取：利息全失效，只退本金
                $note = "[高清银行] 定期提前支取(利息失效) 退本金{$payout}";
            }
            $newWallet = $wallet + $payout;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(bank_money($payout)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `fix_deposit` = 0, `fix_ts` = 0, `fix_term` = 0, `fix_annual` = 0, `updated_at` = " . sqlesc($ts) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
            bank_log($uid, $wallet, $payout, $newWallet, $note);

        } elseif ($action === 'borrow') {
            if (bank_loan_owed($a, $now) > 0) { sql_query("ROLLBACK"); return [null, '已有未结清借款，请先还清再借。']; }
            if (!isset(BANK_LOAN_TIERS[$param])) { sql_query("ROLLBACK"); return [null, '请选择有效的分期期数。']; }
            if ((int)$a['blacklisted'] === 1) { sql_query("ROLLBACK"); return [null, '账户已被列入贷款黑名单，暂不可借款。']; }
            $credit = bank_credit($uid);
            if ($credit['grade'] === 'E') { sql_query("ROLLBACK"); return [null, '信用等级为 E，暂不可借款。']; }
            if ($credit['reg_days'] < 30) { sql_query("ROLLBACK"); return [null, '注册未满 30 天，暂不可借款。']; }
            if ($amount < BANK_MIN_LOAN) { sql_query("ROLLBACK"); return [null, "最低借款 " . BANK_MIN_LOAN . "。"]; }
            if ($amount > $credit['max_loan']) { sql_query("ROLLBACK"); return [null, "超过你的信用额度（{$credit['grade']} 级最高可借 " . number_format($credit['max_loan']) . "）。"]; }
            $rateM = bank_loan_rate($param, $credit['grade']);
            $due = $now + $param * 30 * 86400;
            $newWallet = $wallet + $amount;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(bank_money($amount)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan` = " . sqlesc(bank_money($amount)) . ", `loan_interest` = 0, `loan_ts` = $now, `loan_periods` = $param, `loan_rate_m` = " . sqlesc(number_format($rateM, 4, '.', '')) . ", `loan_start_ts` = $now, `loan_due_ts` = $due, `updated_at` = " . sqlesc($ts) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
            bank_log($uid, $wallet, $amount, $newWallet, "[高清银行] 借款{$param}期(月息{$rateM}%) {$amount}");

        } elseif ($action === 'repay') {
            $p = (float)$a['loan'];
            if ($p <= 0) { sql_query("ROLLBACK"); return [null, '当前没有欠款。']; }
            // 结算利息到 loan_interest
            $interest = (float)$a['loan_interest'] + $p * ((float)$a['loan_rate_m'] / 100) * ((($now - (int)$a['loan_ts']) / 86400) / 30);
            $interest = round(max(0, $interest), 2);
            $owed = round($p + $interest, 2);
            $pay = min($amount, $owed, $wallet);
            if ($pay <= 0) { sql_query("ROLLBACK"); return [null, '钱包电影票不足以还款。']; }
            // 先还利息，再还本金
            $payInterest = min($pay, $interest);
            $payPrincipal = round($pay - $payInterest, 2);
            $newInterest = round($interest - $payInterest, 2);
            $newPrincipal = round($p - $payPrincipal, 2);
            $newWallet = $wallet - $pay;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` - " . sqlesc(bank_money($pay)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            if ($payInterest > 0) bank_pool_add_interest($payInterest);
            bank_log($uid, $wallet, -$pay, $newWallet, "[高清银行] 还款 {$pay}");
            if ($newPrincipal <= 0 && $newInterest <= 0) {
                $marginRet = round((float)$a['loan_margin'], 2);
                $appId = (int)$a['loan_app_id'];
                if ($marginRet > 0) {
                    sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(bank_money($marginRet)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
                    bank_log($uid, $newWallet, $marginRet, round($newWallet + $marginRet, 2), "[高清银行] 退还保证金 {$marginRet}");
                    $newWallet = round($newWallet + $marginRet, 2);
                }
                sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan` = 0, `loan_interest` = 0, `loan_ts` = 0, `loan_periods` = 0, `loan_rate_m` = 0, `loan_start_ts` = 0, `loan_due_ts` = 0, `loan_margin` = 0, `loan_app_id` = 0, `updated_at` = " . sqlesc($ts) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
                if ($appId > 0) {
                    sql_query("UPDATE `" . BANK_GUARANTEE_TABLE . "` SET `status` = 'released' WHERE `app_id` = $appId AND `status` = 'agreed'") or sqlerr(__FILE__, __LINE__);
                    sql_query("UPDATE `" . BANK_APP_TABLE . "` SET `status` = 'closed', `updated_at` = " . sqlesc($ts) . " WHERE `id` = $appId") or sqlerr(__FILE__, __LINE__);
                }
            } else {
                sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan` = " . sqlesc(bank_money($newPrincipal)) . ", `loan_interest` = " . sqlesc(bank_money($newInterest)) . ", `loan_ts` = $now, `updated_at` = " . sqlesc($ts) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
            }

        } else {
            sql_query("ROLLBACK");
            return [null, '未知操作。'];
        }

        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        clear_user_cache($uid);
        $CURUSER['seedbonus'] = $newWallet;
        return [bank_status($uid), ''];
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        return [null, '系统错误，请重试。'];
    }
}
