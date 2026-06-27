<?php
/**
 * 高清银行 — store (活期/定期 deposits) and borrow (term loans) of 电影票 (seedbonus).
 *  - 活期存款：随时存取，按活期日息计息。
 *  - 定期存款：选期限锁定，到期得全期高息；提前支取只退本金+活期息。
 *  - 借款：选期限，到期前按贷款日息，逾期后每天加收固定逾期费。
 * Rates/limits set in backend (Filament: 高清银行设置). Self-managed tables, transactional.
 */

const BANK_BUSINESS_TYPE = 51;
const BANK_ACCOUNT_TABLE = 'hdvideo_bank_accounts';
const BANK_CONFIG_TABLE = 'hdvideo_bank_config';
const BANK_TERMS = [7, 30, 90];        // 可选期限（天）
const BANK_DEF_DEPOSIT_RATE = 0.10;    // 活期 %/day
const BANK_DEF_FIXED_RATE = 0.25;      // 定期 %/day
const BANK_DEF_LOAN_RATE = 0.30;       // 贷款 %/day
const BANK_DEF_OVERDUE_FEE = 500;      // 逾期每天固定费（电影票）
const BANK_DEF_MAX_LOAN = 100000;
const BANK_DEF_MIN_AMOUNT = 100;

function bank_money($v)
{
    return number_format((float)$v, 2, '.', '');
}

function bank_ensure_tables()
{
    static $done = false;
    if ($done) return;
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . BANK_ACCOUNT_TABLE . "` (
            `uid` int unsigned NOT NULL,
            `deposit` decimal(20,2) NOT NULL DEFAULT '0.00',
            `deposit_ts` int unsigned NOT NULL DEFAULT 0,
            `loan` decimal(20,2) NOT NULL DEFAULT '0.00',
            `loan_ts` int unsigned NOT NULL DEFAULT 0,
            `loan_term` int unsigned NOT NULL DEFAULT 0,
            `loan_due_ts` int unsigned NOT NULL DEFAULT 0,
            `loan_rate` decimal(8,4) NOT NULL DEFAULT '0.0000',
            `fix_deposit` decimal(20,2) NOT NULL DEFAULT '0.00',
            `fix_ts` int unsigned NOT NULL DEFAULT 0,
            `fix_term` int unsigned NOT NULL DEFAULT 0,
            `fix_rate` decimal(8,4) NOT NULL DEFAULT '0.0000',
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // accounts created before terms lacked the loan-term / fixed-deposit columns.
    $col = sql_query("SHOW COLUMNS FROM `" . BANK_ACCOUNT_TABLE . "` LIKE 'fix_deposit'");
    if ($col && !mysql_fetch_assoc($col)) {
        foreach ([
            "ADD COLUMN `loan_term` int unsigned NOT NULL DEFAULT 0",
            "ADD COLUMN `loan_due_ts` int unsigned NOT NULL DEFAULT 0",
            "ADD COLUMN `loan_rate` decimal(8,4) NOT NULL DEFAULT '0.0000'",
            "ADD COLUMN `fix_deposit` decimal(20,2) NOT NULL DEFAULT '0.00'",
            "ADD COLUMN `fix_ts` int unsigned NOT NULL DEFAULT 0",
            "ADD COLUMN `fix_term` int unsigned NOT NULL DEFAULT 0",
            "ADD COLUMN `fix_rate` decimal(8,4) NOT NULL DEFAULT '0.0000'",
        ] as $clause) {
            sql_query("ALTER TABLE `" . BANK_ACCOUNT_TABLE . "` $clause") or sqlerr(__FILE__, __LINE__);
        }
    }
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . BANK_CONFIG_TABLE . "` (
            `id` int unsigned NOT NULL,
            `deposit_rate` decimal(8,4) NOT NULL DEFAULT '0.1000',
            `fixed_rate` decimal(8,4) NOT NULL DEFAULT '0.2500',
            `loan_rate` decimal(8,4) NOT NULL DEFAULT '0.3000',
            `overdue_fee` bigint NOT NULL DEFAULT 500,
            `max_loan` bigint NOT NULL DEFAULT 100000,
            `min_amount` int NOT NULL DEFAULT 100,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $cc = sql_query("SHOW COLUMNS FROM `" . BANK_CONFIG_TABLE . "` LIKE 'fixed_rate'");
    if ($cc && !mysql_fetch_assoc($cc)) {
        sql_query("ALTER TABLE `" . BANK_CONFIG_TABLE . "` ADD COLUMN `fixed_rate` decimal(8,4) NOT NULL DEFAULT '0.2500'") or sqlerr(__FILE__, __LINE__);
        sql_query("ALTER TABLE `" . BANK_CONFIG_TABLE . "` ADD COLUMN `overdue_fee` bigint NOT NULL DEFAULT 500") or sqlerr(__FILE__, __LINE__);
    }
    $res = @sql_query("SELECT COUNT(*) AS c FROM `" . BANK_CONFIG_TABLE . "` WHERE `id` = 1");
    if ($res && (int)mysql_fetch_assoc($res)['c'] === 0) {
        @sql_query(sprintf(
            "INSERT INTO `" . BANK_CONFIG_TABLE . "` (`id`,`deposit_rate`,`fixed_rate`,`loan_rate`,`overdue_fee`,`max_loan`,`min_amount`,`updated_at`) VALUES (1,%s,%s,%s,%d,%d,%d,%s)",
            BANK_DEF_DEPOSIT_RATE, BANK_DEF_FIXED_RATE, BANK_DEF_LOAN_RATE, BANK_DEF_OVERDUE_FEE, BANK_DEF_MAX_LOAN, BANK_DEF_MIN_AMOUNT, sqlesc(date('Y-m-d H:i:s'))
        ));
    }
    $done = true;
}

function bank_config()
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    bank_ensure_tables();
    $res = @sql_query("SELECT * FROM `" . BANK_CONFIG_TABLE . "` WHERE `id` = 1 LIMIT 1");
    $r = $res ? mysql_fetch_assoc($res) : null;
    $cfg = [
        'deposit_rate' => $r ? (float)$r['deposit_rate'] : BANK_DEF_DEPOSIT_RATE,
        'fixed_rate' => $r && isset($r['fixed_rate']) ? (float)$r['fixed_rate'] : BANK_DEF_FIXED_RATE,
        'loan_rate' => $r ? (float)$r['loan_rate'] : BANK_DEF_LOAN_RATE,
        'overdue_fee' => $r && isset($r['overdue_fee']) ? (int)$r['overdue_fee'] : BANK_DEF_OVERDUE_FEE,
        'max_loan' => $r ? (int)$r['max_loan'] : BANK_DEF_MAX_LOAN,
        'min_amount' => $r ? (int)$r['min_amount'] : BANK_DEF_MIN_AMOUNT,
    ];
    return $cfg;
}

function bank_account($uid, $forUpdate = false)
{
    bank_ensure_tables();
    $uid = (int)$uid;
    $res = sql_query("SELECT * FROM `" . BANK_ACCOUNT_TABLE . "` WHERE `uid` = $uid LIMIT 1" . ($forUpdate ? " FOR UPDATE" : "")) or sqlerr(__FILE__, __LINE__);
    $a = mysql_fetch_assoc($res);
    if (!$a) {
        sql_query("INSERT INTO `" . BANK_ACCOUNT_TABLE . "` (`uid`,`updated_at`) VALUES ($uid, " . sqlesc(date('Y-m-d H:i:s')) . ")") or sqlerr(__FILE__, __LINE__);
        $res = sql_query("SELECT * FROM `" . BANK_ACCOUNT_TABLE . "` WHERE `uid` = $uid LIMIT 1" . ($forUpdate ? " FOR UPDATE" : "")) or sqlerr(__FILE__, __LINE__);
        $a = mysql_fetch_assoc($res);
    }
    return $a;
}

/** Current 活期 value (interest accrued to now, simple). */
function bank_cur_value($a, $cfg, $now)
{
    $p = (float)$a['deposit'];
    if ($p <= 0 || (int)$a['deposit_ts'] <= 0) return $p;
    $days = max(0, ($now - (int)$a['deposit_ts']) / 86400);
    return round($p + $p * $cfg['deposit_rate'] / 100 * $days, 2);
}

/** Current owed for a loan (principal + interest since loan_ts + overdue fee since due). */
function bank_loan_owed($a, $cfg, $now)
{
    $owed = (float)$a['loan'];
    if ($owed <= 0) return 0.0;
    $ts = (int)$a['loan_ts'];
    $rate = (float)$a['loan_rate'] > 0 ? (float)$a['loan_rate'] : $cfg['loan_rate'];
    $days = max(0, ($now - $ts) / 86400);
    $owed += $owed * $rate / 100 * $days;
    $due = (int)$a['loan_due_ts'];
    if ($due > 0) {
        $ovStart = max($ts, $due);
        if ($now > $ovStart) {
            $owed += $cfg['overdue_fee'] * (($now - $ovStart) / 86400);
        }
    }
    return round($owed, 2);
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
    $cfg = bank_config();
    $a = bank_account($uid, false);
    $now = time();

    $fix = null;
    if ((float)$a['fix_deposit'] > 0) {
        $p = (float)$a['fix_deposit'];
        $term = (int)$a['fix_term'];
        $rate = (float)$a['fix_rate'];
        $due = (int)$a['fix_ts'] + $term * 86400;
        $matured = $now >= $due;
        $matureValue = round($p + $p * $rate / 100 * $term, 2);
        if ($matured) {
            $valueNow = $matureValue;
        } else {
            $elapsed = max(0, ($now - (int)$a['fix_ts']) / 86400);
            $valueNow = round($p + $p * $cfg['deposit_rate'] / 100 * $elapsed, 2); // early = 活期息
        }
        $fix = [
            'principal' => round($p, 2), 'rate' => $rate, 'term' => $term, 'due_ts' => $due,
            'matured' => $matured, 'mature_value' => $matureValue, 'value_now' => $valueNow,
        ];
    }

    $loan = null;
    if ((float)$a['loan'] > 0) {
        $owed = bank_loan_owed($a, $cfg, $now);
        $due = (int)$a['loan_due_ts'];
        $overdueDays = ($due > 0 && $now > $due) ? (int)floor(($now - $due) / 86400) : 0;
        $loan = [
            'owed' => $owed, 'rate' => (float)$a['loan_rate'] > 0 ? (float)$a['loan_rate'] : $cfg['loan_rate'],
            'term' => (int)$a['loan_term'], 'due_ts' => $due, 'overdue' => ($due > 0 && $now > $due), 'overdue_days' => $overdueDays,
        ];
    }

    return [
        'wallet' => round((float)($CURUSER['seedbonus'] ?? 0), 2),
        'cur_deposit' => bank_cur_value($a, $cfg, $now),
        'fix' => $fix,
        'loan' => $loan,
        'deposit_rate' => $cfg['deposit_rate'],
        'fixed_rate' => $cfg['fixed_rate'],
        'loan_rate' => $cfg['loan_rate'],
        'overdue_fee' => $cfg['overdue_fee'],
        'max_loan' => $cfg['max_loan'],
        'min_amount' => $cfg['min_amount'],
        'terms' => BANK_TERMS,
    ];
}

function bank_do($uid, $action, $amount, $term = 0)
{
    global $CURUSER;
    bank_ensure_tables();
    $uid = (int)$uid;
    $amount = round((float)$amount, 2);
    $term = (int)$term;
    $cfg = bank_config();

    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $ures = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = $uid FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $u = mysql_fetch_assoc($ures);
        if (!$u) { sql_query("ROLLBACK"); return [null, '用户不存在。']; }
        $wallet = (float)$u['seedbonus'];
        $a = bank_account($uid, true);
        $now = time();

        if ($action === 'deposit' || $action === 'withdraw') {
            // 活期：先把已计利息结算入本金
            $cur = bank_cur_value($a, $cfg, $now);
            if ($action === 'deposit') {
                if ($amount < $cfg['min_amount']) { sql_query("ROLLBACK"); return [null, "单次存入不少于 {$cfg['min_amount']}。"]; }
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
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `deposit` = " . sqlesc(bank_money($newCur)) . ", `deposit_ts` = $now, `updated_at` = " . sqlesc(date('Y-m-d H:i:s')) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);

        } elseif ($action === 'deposit_fix') {
            if ((float)$a['fix_deposit'] > 0) { sql_query("ROLLBACK"); return [null, '已有一笔定期，请先取出后再存。']; }
            if (!in_array($term, BANK_TERMS, true)) { sql_query("ROLLBACK"); return [null, '请选择有效的存期。']; }
            if ($amount < $cfg['min_amount']) { sql_query("ROLLBACK"); return [null, "单次存入不少于 {$cfg['min_amount']}。"]; }
            if ($wallet < $amount) { sql_query("ROLLBACK"); return [null, '钱包电影票不足。']; }
            $newWallet = $wallet - $amount;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` - " . sqlesc(bank_money($amount)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `fix_deposit` = " . sqlesc(bank_money($amount)) . ", `fix_ts` = $now, `fix_term` = $term, `fix_rate` = " . sqlesc(number_format($cfg['fixed_rate'], 4, '.', '')) . ", `updated_at` = " . sqlesc(date('Y-m-d H:i:s')) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
            bank_log($uid, $wallet, -$amount, $newWallet, "[高清银行] 存入{$term}天定期 {$amount}");

        } elseif ($action === 'withdraw_fix') {
            if ((float)$a['fix_deposit'] <= 0) { sql_query("ROLLBACK"); return [null, '没有定期存款。']; }
            $p = (float)$a['fix_deposit'];
            $due = (int)$a['fix_ts'] + (int)$a['fix_term'] * 86400;
            if ($now >= $due) {
                $payout = round($p + $p * (float)$a['fix_rate'] / 100 * (int)$a['fix_term'], 2);
                $note = "[高清银行] 定期到期支取 本金{$p}+息";
            } else {
                $elapsed = max(0, ($now - (int)$a['fix_ts']) / 86400);
                $payout = round($p + $p * $cfg['deposit_rate'] / 100 * $elapsed, 2); // 提前=活期息
                $note = "[高清银行] 定期提前支取(按活期息) 本金{$p}";
            }
            $newWallet = $wallet + $payout;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(bank_money($payout)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `fix_deposit` = 0, `fix_ts` = 0, `fix_term` = 0, `fix_rate` = 0, `updated_at` = " . sqlesc(date('Y-m-d H:i:s')) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
            bank_log($uid, $wallet, $payout, $newWallet, $note);

        } elseif ($action === 'borrow') {
            $owed = bank_loan_owed($a, $cfg, $now);
            if ($owed > 0) { sql_query("ROLLBACK"); return [null, '已有未结清借款，请先还清再借。']; }
            if (!in_array($term, BANK_TERMS, true)) { sql_query("ROLLBACK"); return [null, '请选择有效的借款期限。']; }
            if ($amount < $cfg['min_amount']) { sql_query("ROLLBACK"); return [null, "单次借款不少于 {$cfg['min_amount']}。"]; }
            if ($amount > $cfg['max_loan']) { sql_query("ROLLBACK"); return [null, "超过可借上限 {$cfg['max_loan']}。"]; }
            $due = $now + $term * 86400;
            $newWallet = $wallet + $amount;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(bank_money($amount)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan` = " . sqlesc(bank_money($amount)) . ", `loan_ts` = $now, `loan_term` = $term, `loan_due_ts` = $due, `loan_rate` = " . sqlesc(number_format($cfg['loan_rate'], 4, '.', '')) . ", `updated_at` = " . sqlesc(date('Y-m-d H:i:s')) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
            bank_log($uid, $wallet, $amount, $newWallet, "[高清银行] 借款{$term}天 {$amount}");

        } elseif ($action === 'repay') {
            $owed = bank_loan_owed($a, $cfg, $now);
            if ($owed <= 0) { sql_query("ROLLBACK"); return [null, '当前没有欠款。']; }
            $pay = min($amount, $owed, $wallet);
            if ($pay <= 0) { sql_query("ROLLBACK"); return [null, '钱包电影票不足以还款。']; }
            $remain = round($owed - $pay, 2);
            $newWallet = $wallet - $pay;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` - " . sqlesc(bank_money($pay)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            if ($remain <= 0) {
                sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan` = 0, `loan_ts` = 0, `loan_term` = 0, `loan_due_ts` = 0, `loan_rate` = 0, `updated_at` = " . sqlesc(date('Y-m-d H:i:s')) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
            } else {
                // 结算：剩余欠款落账，利息/逾期费从现在重新计
                sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan` = " . sqlesc(bank_money($remain)) . ", `loan_ts` = $now, `updated_at` = " . sqlesc(date('Y-m-d H:i:s')) . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
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
