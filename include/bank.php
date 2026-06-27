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

const BANK_BUSINESS_TYPE = 51;
const BANK_ACCOUNT_TABLE = 'hdvideo_bank_accounts';
const BANK_CONFIG_TABLE = 'hdvideo_bank_config';

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
    ] as $colName => $def) {
        $c = sql_query("SHOW COLUMNS FROM `" . BANK_ACCOUNT_TABLE . "` LIKE '$colName'");
        if ($c && !mysql_fetch_assoc($c)) {
            sql_query("ALTER TABLE `" . BANK_ACCOUNT_TABLE . "` ADD COLUMN `$colName` $def") or sqlerr(__FILE__, __LINE__);
        }
    }
    $done = true;
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
    $rateM = (float)$a['loan_rate_m'];
    $days = max(0, ($now - (int)$a['loan_ts']) / 86400);
    $interest += $p * ($rateM / 100) * ($days / 30); // 月利率按30天折算到日
    return round($p + $interest, 2);
}

function bank_log($uid, $old, $delta, $new, $comment)
{
    $now = date('Y-m-d H:i:s');
    sql_query(sprintf(
        "INSERT INTO bonus_logs (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
        BANK_BUSINESS_TYPE, (int)$uid, sqlesc(bank_money($old)), sqlesc(bank_money($delta)), sqlesc(bank_money($new)), sqlesc($comment), sqlesc($now), sqlesc($now)
    )) or sqlerr(__FILE__, __LINE__);
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
            'overdue' => ($due > 0 && $now > $due),
            'overdue_days' => ($due > 0 && $now > $due) ? (int)floor(($now - $due) / 86400) : 0,
        ];
    }

    $fixTiers = [];
    foreach (BANK_FIX_TIERS as $d => $r) $fixTiers[] = ['days' => $d, 'annual' => $r];
    $loanTiers = [];
    foreach (BANK_LOAN_TIERS as $pn => $r) $loanTiers[] = ['periods' => $pn, 'rate_m' => bank_loan_rate($pn, $credit['grade'])];

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
        'can_borrow' => ($credit['grade'] !== 'E' && $credit['reg_days'] >= 30),
        'borrow_block' => $credit['grade'] === 'E' ? '信用等级为 E，暂不可借款' : ($credit['reg_days'] < 30 ? '注册未满 30 天，暂不可借款' : ''),
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
            if ($newPrincipal <= 0 && $newInterest <= 0) {
                sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan` = 0, `loan_interest` = 0, `loan_ts` = 0, `loan_periods` = 0, `loan_rate_m` = 0, `loan_start_ts` = 0, `loan_due_ts` = 0, `updated_at` = " . sqlesc($ts) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
            } else {
                sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan` = " . sqlesc(bank_money($newPrincipal)) . ", `loan_interest` = " . sqlesc(bank_money($newInterest)) . ", `loan_ts` = $now, `updated_at` = " . sqlesc($ts) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
            }
            bank_log($uid, $wallet, -$pay, $newWallet, "[高清银行] 还款 {$pay}");

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
