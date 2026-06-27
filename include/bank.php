<?php
/**
 * 高清银行 — store (deposit) and borrow (loan) 电影票 (seedbonus).
 * Deposits earn simple daily interest; loans accrue simple daily interest that must be
 * repaid. Interest rates / limits are set in the backend (Filament: 高清银行设置).
 * Self-managed tables (no migration). Server-authoritative; all moves are transactional.
 */

const BANK_BUSINESS_TYPE = 51;
const BANK_ACCOUNT_TABLE = 'hdvideo_bank_accounts';
const BANK_CONFIG_TABLE = 'hdvideo_bank_config';
const BANK_DEF_DEPOSIT_RATE = 0.10;   // %/day
const BANK_DEF_LOAN_RATE = 0.30;      // %/day
const BANK_DEF_MAX_LOAN = 100000;     // 电影票
const BANK_DEF_MIN_AMOUNT = 100;      // min per deposit/borrow

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
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . BANK_CONFIG_TABLE . "` (
            `id` int unsigned NOT NULL,
            `deposit_rate` decimal(8,4) NOT NULL DEFAULT '0.1000',
            `loan_rate` decimal(8,4) NOT NULL DEFAULT '0.3000',
            `max_loan` bigint NOT NULL DEFAULT 100000,
            `min_amount` int NOT NULL DEFAULT 100,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $res = @sql_query("SELECT COUNT(*) AS c FROM `" . BANK_CONFIG_TABLE . "` WHERE `id` = 1");
    if ($res && (int)mysql_fetch_assoc($res)['c'] === 0) {
        @sql_query(sprintf(
            "INSERT INTO `" . BANK_CONFIG_TABLE . "` (`id`,`deposit_rate`,`loan_rate`,`max_loan`,`min_amount`,`updated_at`) VALUES (1,%s,%s,%d,%d,%s)",
            BANK_DEF_DEPOSIT_RATE, BANK_DEF_LOAN_RATE, BANK_DEF_MAX_LOAN, BANK_DEF_MIN_AMOUNT, sqlesc(date('Y-m-d H:i:s'))
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
    $row = $res ? mysql_fetch_assoc($res) : null;
    $cfg = [
        'deposit_rate' => $row ? (float)$row['deposit_rate'] : BANK_DEF_DEPOSIT_RATE,
        'loan_rate' => $row ? (float)$row['loan_rate'] : BANK_DEF_LOAN_RATE,
        'max_loan' => $row ? (int)$row['max_loan'] : BANK_DEF_MAX_LOAN,
        'min_amount' => $row ? (int)$row['min_amount'] : BANK_DEF_MIN_AMOUNT,
    ];
    return $cfg;
}

/** Accrued value of a principal after $sinceTs at $ratePerDay (%/day), simple interest. */
function bank_accrue($principal, $sinceTs, $ratePerDay)
{
    $principal = (float)$principal;
    if ($principal <= 0 || $sinceTs <= 0) return $principal;
    $days = max(0, (time() - (int)$sinceTs) / 86400);
    return $principal + $principal * ($ratePerDay / 100) * $days;
}

/** Load (creating if needed) the account row. Pass true to lock it inside a transaction. */
function bank_account($uid, $forUpdate = false)
{
    bank_ensure_tables();
    $uid = (int)$uid;
    $res = sql_query("SELECT * FROM `" . BANK_ACCOUNT_TABLE . "` WHERE `uid` = $uid LIMIT 1" . ($forUpdate ? " FOR UPDATE" : "")) or sqlerr(__FILE__, __LINE__);
    $a = mysql_fetch_assoc($res);
    if (!$a) {
        sql_query("INSERT INTO `" . BANK_ACCOUNT_TABLE . "` (`uid`,`updated_at`) VALUES ($uid, " . sqlesc(date('Y-m-d H:i:s')) . ")") or sqlerr(__FILE__, __LINE__);
        $a = ['uid' => $uid, 'deposit' => 0, 'deposit_ts' => 0, 'loan' => 0, 'loan_ts' => 0];
    }
    return $a;
}

/** Settle accrued interest into the stored principals and persist. Returns [deposit, loan]. */
function bank_settle($uid, $a)
{
    $cfg = bank_config();
    $now = time();
    $deposit = round(bank_accrue($a['deposit'], (int)$a['deposit_ts'], $cfg['deposit_rate']), 2);
    $loan = round(bank_accrue($a['loan'], (int)$a['loan_ts'], $cfg['loan_rate']), 2);
    sql_query(sprintf(
        "UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `deposit` = %s, `deposit_ts` = %d, `loan` = %s, `loan_ts` = %d, `updated_at` = %s WHERE `uid` = %d",
        sqlesc(bank_money($deposit)), $deposit > 0 ? $now : 0, sqlesc(bank_money($loan)), $loan > 0 ? $now : 0, sqlesc(date('Y-m-d H:i:s')), (int)$uid
    )) or sqlerr(__FILE__, __LINE__);
    return [$deposit, $loan];
}

function bank_log($uid, $old, $delta, $new, $comment)
{
    $now = date('Y-m-d H:i:s');
    sql_query(sprintf(
        "INSERT INTO bonus_logs (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
        BANK_BUSINESS_TYPE, (int)$uid, sqlesc(bank_money($old)), sqlesc(bank_money($delta)), sqlesc(bank_money($new)), sqlesc($comment), sqlesc($now), sqlesc($now)
    )) or sqlerr(__FILE__, __LINE__);
}

/** Read-only snapshot (interest computed on the fly, not persisted). */
function bank_status($uid)
{
    global $CURUSER;
    $cfg = bank_config();
    $a = bank_account($uid, false);
    $deposit = round(bank_accrue($a['deposit'], (int)$a['deposit_ts'], $cfg['deposit_rate']), 2);
    $loan = round(bank_accrue($a['loan'], (int)$a['loan_ts'], $cfg['loan_rate']), 2);
    return [
        'wallet' => (float)($CURUSER['seedbonus'] ?? 0),
        'deposit' => $deposit,
        'loan' => $loan,
        'deposit_rate' => $cfg['deposit_rate'],
        'loan_rate' => $cfg['loan_rate'],
        'max_loan' => $cfg['max_loan'],
        'min_amount' => $cfg['min_amount'],
        'borrowable' => max(0, $cfg['max_loan'] - $loan),
    ];
}

/** Perform a bank operation. Returns [statusArray|null, errorMessage]. */
function bank_do($uid, $action, $amount)
{
    global $CURUSER;
    bank_ensure_tables();
    $uid = (int)$uid;
    $amount = round((float)$amount, 2);
    $cfg = bank_config();
    if ($amount <= 0) return [null, '请输入有效的金额。'];

    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $ures = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = $uid FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $u = mysql_fetch_assoc($ures);
        if (!$u) { sql_query("ROLLBACK"); return [null, '用户不存在。']; }
        $wallet = (float)$u['seedbonus'];
        $a = bank_account($uid, true);
        [$deposit, $loan] = bank_settle($uid, $a);

        if ($action === 'deposit') {
            if ($amount < $cfg['min_amount']) { sql_query("ROLLBACK"); return [null, "单次存入不能少于 {$cfg['min_amount']} 电影票。"]; }
            if ($wallet < $amount) { sql_query("ROLLBACK"); return [null, '钱包电影票不足。']; }
            $newWallet = $wallet - $amount;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` - " . sqlesc(bank_money($amount)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `deposit` = " . sqlesc(bank_money($deposit + $amount)) . ", `deposit_ts` = " . time() . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
            bank_log($uid, $wallet, -$amount, $newWallet, "[高清银行] 存入 {$amount}");
        } elseif ($action === 'withdraw') {
            if ($deposit < $amount) { sql_query("ROLLBACK"); return [null, '存款余额不足。']; }
            $newWallet = $wallet + $amount;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(bank_money($amount)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `deposit` = " . sqlesc(bank_money($deposit - $amount)) . ", `deposit_ts` = " . time() . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
            bank_log($uid, $wallet, $amount, $newWallet, "[高清银行] 取出 {$amount}");
        } elseif ($action === 'borrow') {
            if ($amount < $cfg['min_amount']) { sql_query("ROLLBACK"); return [null, "单次借款不能少于 {$cfg['min_amount']} 电影票。"]; }
            if ($loan + $amount > $cfg['max_loan']) { sql_query("ROLLBACK"); return [null, "超过可借上限，当前最多还可借 " . bank_money(max(0, $cfg['max_loan'] - $loan)) . " 电影票。"]; }
            $newWallet = $wallet + $amount;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(bank_money($amount)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan` = " . sqlesc(bank_money($loan + $amount)) . ", `loan_ts` = " . time() . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
            bank_log($uid, $wallet, $amount, $newWallet, "[高清银行] 借款 {$amount}");
        } elseif ($action === 'repay') {
            $pay = min($amount, $loan, $wallet);
            if ($pay <= 0) { sql_query("ROLLBACK"); return [null, $loan <= 0 ? '当前没有欠款。' : '钱包电影票不足以还款。']; }
            $newWallet = $wallet - $pay;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` - " . sqlesc(bank_money($pay)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            sql_query("UPDATE `" . BANK_ACCOUNT_TABLE . "` SET `loan` = " . sqlesc(bank_money($loan - $pay)) . ", `loan_ts` = " . time() . " WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
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
