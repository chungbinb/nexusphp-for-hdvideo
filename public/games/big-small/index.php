<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;
require_once "../../../include/game_control.php";
game_guard('big-small');

const GAME_BS_INTERVAL = 60;
const GAME_BS_BUSINESS_TYPE = 101; // 压大小（历史记录为 13）
const GAME_BS_ROUND_TABLE = 'hdvideo_game_big_small_rounds';
const GAME_BS_BET_TABLE = 'hdvideo_game_big_small_bets';
const GAME_BS_TRIPLE_MULT = 5;   // 押豹子: any triple
const GAME_BS_STRAIGHT_MULT = 4; // 押顺子: any straight (consecutive digits, any order)

function game_bs_run_schema_sql($sql)
{
    $res = @sql_query($sql);
    if (!$res) {
        do_log('[GAME_BIG_SMALL_SCHEMA_ERROR] ' . $sql . ' :: ' . mysql_error(), 'error');
    }
    return $res;
}

function game_bs_column_exists($table, $column)
{
    $res = @sql_query("SHOW COLUMNS FROM `$table` LIKE " . sqlesc($column));
    return $res && mysql_num_rows($res) > 0;
}

function game_bs_ensure_tables()
{
    static $done = false;
    if ($done) {
        return;
    }
    game_bs_run_schema_sql("
        CREATE TABLE IF NOT EXISTS `" . GAME_BS_ROUND_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `round_start` datetime NOT NULL,
            `round_end` datetime NOT NULL,
            `status` enum('open','settling','closed','cancelled') NOT NULL DEFAULT 'open',
            `result` enum('big','small','triple','push') DEFAULT NULL,
            `result_number` smallint unsigned DEFAULT NULL,
            `total_big` decimal(20,1) NOT NULL DEFAULT '0.0',
            `total_small` decimal(20,1) NOT NULL DEFAULT '0.0',
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_round_start` (`round_start`),
            KEY `idx_status_end` (`status`, `round_end`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    game_bs_run_schema_sql("
        CREATE TABLE IF NOT EXISTS `" . GAME_BS_BET_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `round_id` int unsigned NOT NULL,
            `uid` int unsigned NOT NULL,
            `choice` enum('big','small','number','triple','straight') NOT NULL,
            `bet_number` smallint unsigned DEFAULT NULL,
            `amount` decimal(20,1) NOT NULL,
            `status` enum('pending','won','lost','refunded') NOT NULL DEFAULT 'pending',
            `payout` decimal(20,1) NOT NULL DEFAULT '0.0',
            `created_at` datetime NOT NULL,
            `settled_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_round_status` (`round_id`, `status`),
            KEY `idx_uid_created` (`uid`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // In-place upgrade for tables created by the previous (1-100, big/small only) version.
    if (!game_bs_column_exists(GAME_BS_BET_TABLE, 'bet_number')) {
        game_bs_run_schema_sql("ALTER TABLE `" . GAME_BS_ROUND_TABLE . "` MODIFY `result` enum('big','small','triple','push') DEFAULT NULL");
        game_bs_run_schema_sql("ALTER TABLE `" . GAME_BS_ROUND_TABLE . "` MODIFY `result_number` smallint unsigned DEFAULT NULL");
        game_bs_run_schema_sql("ALTER TABLE `" . GAME_BS_BET_TABLE . "` MODIFY `choice` enum('big','small','number') NOT NULL");
        game_bs_run_schema_sql("ALTER TABLE `" . GAME_BS_BET_TABLE . "` ADD COLUMN `bet_number` smallint unsigned DEFAULT NULL AFTER `choice`");
    }
    // Add 押豹子 / 押顺子 bet choices (v3).
    if (!game_bs_choice_has_value('straight')) {
        game_bs_run_schema_sql("ALTER TABLE `" . GAME_BS_BET_TABLE . "` MODIFY `choice` enum('big','small','number','triple','straight') NOT NULL");
    }
    $done = true;
}

function game_bs_choice_has_value($val)
{
    $res = @sql_query("SHOW COLUMNS FROM `" . GAME_BS_BET_TABLE . "` LIKE 'choice'");
    $row = $res ? mysql_fetch_assoc($res) : null;
    return $row && strpos($row['Type'], "'$val'") !== false;
}

function game_bs_money($value)
{
    return number_format((float)$value, 1, '.', '');
}

function game_bs_rand($min, $max)
{
    return function_exists('random_int') ? random_int($min, $max) : mt_rand($min, $max);
}

/**
 * Classify a drawn 3-digit number (each digit 1-9):
 *   triple   -> all three digits equal (豹子)
 *   straight -> three consecutive digits in ANY order (顺子, e.g. 123/321/231/132)
 *   normal   -> anything else
 */
function game_bs_number_type($number)
{
    $d1 = intdiv($number, 100) % 10;
    $d2 = intdiv($number, 10) % 10;
    $d3 = $number % 10;
    if ($d1 === $d2 && $d2 === $d3) {
        return 'triple';
    }
    $s = [$d1, $d2, $d3];
    sort($s);
    if ($s[1] === $s[0] + 1 && $s[2] === $s[1] + 1) {
        return 'straight';
    }
    return 'normal';
}

function game_bs_number_multiplier($number)
{
    switch (game_bs_number_type($number)) {
        case 'triple': return 10;
        case 'straight': return 7;
        default: return 6;
    }
}

function game_bs_type_label($number)
{
    switch (game_bs_number_type($number)) {
        case 'triple': return '豹子';
        case 'straight': return '顺子';
        default: return '普通';
    }
}

function game_bs_issue_no($roundId)
{
    static $cache = [];
    $roundId = (int)$roundId;
    if ($roundId <= 0) {
        return 0;
    }
    if (!isset($cache[$roundId])) {
        // Cancelled rounds (no bets, no draw) do not consume an issue number.
        $res = sql_query("SELECT COUNT(*) AS c FROM `" . GAME_BS_ROUND_TABLE . "` WHERE `id` <= $roundId AND `status` != 'cancelled'") or sqlerr(__FILE__, __LINE__);
        $cache[$roundId] = (int)mysql_fetch_assoc($res)['c'];
    }
    return $cache[$roundId];
}

function game_bs_bonus_log($uid, $old, $delta, $new, $comment)
{
    $now = date('Y-m-d H:i:s');
    $fullComment = '[Lucky draw] ' . $comment;
    sql_query(sprintf(
        "INSERT INTO bonus_logs (`business_type`, `uid`, `old_total_value`, `value`, `new_total_value`, `comment`, `created_at`, `updated_at`) VALUES (%d, %d, %s, %s, %s, %s, %s, %s)",
        GAME_BS_BUSINESS_TYPE,
        (int)$uid,
        sqlesc(game_bs_money($old)),
        sqlesc(game_bs_money($delta)),
        sqlesc(game_bs_money($new)),
        sqlesc($fullComment),
        sqlesc($now),
        sqlesc($now)
    )) or sqlerr(__FILE__, __LINE__);
}

function game_bs_get_or_create_current_round()
{
    $startTs = floor(TIMENOW / GAME_BS_INTERVAL) * GAME_BS_INTERVAL;
    $endTs = $startTs + GAME_BS_INTERVAL;
    $start = date('Y-m-d H:i:s', $startTs);
    $end = date('Y-m-d H:i:s', $endTs);
    $now = date('Y-m-d H:i:s');
    $res = sql_query("SELECT * FROM `" . GAME_BS_ROUND_TABLE . "` WHERE `round_start` = " . sqlesc($start) . " LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $round = mysql_fetch_assoc($res);
    if ($round) {
        return $round;
    }
    sql_query(sprintf(
        "INSERT IGNORE INTO `" . GAME_BS_ROUND_TABLE . "` (`round_start`, `round_end`, `status`, `created_at`, `updated_at`) VALUES (%s, %s, 'open', %s, %s)",
        sqlesc($start),
        sqlesc($end),
        sqlesc($now),
        sqlesc($now)
    )) or sqlerr(__FILE__, __LINE__);
    $res = sql_query("SELECT * FROM `" . GAME_BS_ROUND_TABLE . "` WHERE `round_start` = " . sqlesc($start) . " LIMIT 1") or sqlerr(__FILE__, __LINE__);
    return mysql_fetch_assoc($res);
}

/**
 * Pay out / refund a set of pending bets and mark them settled. $multiplier is
 * the total return as a multiple of the stake (e.g. 2 for big/small, 1 to refund).
 */
function game_bs_pay_winners($roundId, $extraWhere, $multiplier, $now, $newStatus, $commentFn)
{
    $winRes = sql_query("
        SELECT b.`id`, b.`uid`, b.`amount`, b.`choice`, b.`bet_number`, u.`seedbonus`
        FROM `" . GAME_BS_BET_TABLE . "` b
        INNER JOIN `users` u ON u.`id` = b.`uid`
        WHERE b.`round_id` = $roundId AND b.`status` = 'pending' AND ($extraWhere)
        FOR UPDATE
    ") or sqlerr(__FILE__, __LINE__);
    while ($bet = mysql_fetch_assoc($winRes)) {
        $betId = (int)$bet['id'];
        $uid = (int)$bet['uid'];
        $amount = (float)$bet['amount'];
        $payout = $amount * $multiplier;
        $oldBonus = (float)$bet['seedbonus'];
        $newBonus = $oldBonus + $payout;
        sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(game_bs_money($payout)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
        sql_query("UPDATE `" . GAME_BS_BET_TABLE . "` SET `status` = " . sqlesc($newStatus) . ", `payout` = " . sqlesc(game_bs_money($payout)) . ", `settled_at` = " . sqlesc($now) . " WHERE `id` = $betId") or sqlerr(__FILE__, __LINE__);
        game_bs_bonus_log($uid, $oldBonus, $payout, $newBonus, $commentFn($bet, $payout));
        clear_user_cache($uid);
    }
}

function game_bs_settle_due_rounds()
{
    $now = date('Y-m-d H:i:s');
    $res = sql_query("SELECT `id` FROM `" . GAME_BS_ROUND_TABLE . "` WHERE `status` = 'open' AND `round_end` <= " . sqlesc($now) . " ORDER BY `id` ASC LIMIT 20") or sqlerr(__FILE__, __LINE__);
    while ($row = mysql_fetch_assoc($res)) {
        $roundId = (int)$row['id'];
        sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
        try {
            $roundRes = sql_query("SELECT * FROM `" . GAME_BS_ROUND_TABLE . "` WHERE `id` = $roundId FOR UPDATE") or sqlerr(__FILE__, __LINE__);
            $round = mysql_fetch_assoc($roundRes);
            if (!$round || $round['status'] !== 'open' || $round['round_end'] > $now) {
                sql_query("ROLLBACK");
                continue;
            }

            // No bets -> no draw (cancel the round).
            $countRes = sql_query("SELECT COUNT(*) AS c FROM `" . GAME_BS_BET_TABLE . "` WHERE `round_id` = $roundId") or sqlerr(__FILE__, __LINE__);
            $betCount = (int)mysql_fetch_assoc($countRes)['c'];
            if ($betCount < 1) {
                sql_query("UPDATE `" . GAME_BS_ROUND_TABLE . "` SET `status` = 'cancelled', `updated_at` = " . sqlesc($now) . " WHERE `id` = $roundId") or sqlerr(__FILE__, __LINE__);
                sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
                continue;
            }

            // Roll three dice, each 1-6.
            $d1 = game_bs_rand(1, 6);
            $d2 = game_bs_rand(1, 6);
            $d3 = game_bs_rand(1, 6);
            $number = $d1 * 100 + $d2 * 10 + $d3;
            $sum = $d1 + $d2 + $d3;
            $type = game_bs_number_type($number);

            // Sic-bo style big/small from the dice sum (3-18), triples lose.
            if ($type === 'triple') {
                $size = 'triple';          // 豹子: big & small both lose
            } elseif ($sum <= 10) {
                $size = 'small';
            } else {
                $size = 'big';             // sum 11-17
            }

            sql_query("UPDATE `" . GAME_BS_ROUND_TABLE . "` SET `status` = 'closed', `result` = " . sqlesc($size) . ", `result_number` = $number, `updated_at` = " . sqlesc($now) . " WHERE `id` = $roundId") or sqlerr(__FILE__, __LINE__);

            $issueNo = game_bs_issue_no($roundId);

            // 1) Big/small bets. (triple or sum==15 -> both lose, fall through to "lost".)
            if ($size === 'big' || $size === 'small') {
                game_bs_pay_winners($roundId, "b.`choice` = " . sqlesc($size), 2, $now, 'won',
                    function ($bet, $payout) use ($issueNo, $number, $size) {
                        return "压大小第 {$issueNo} 期开 {$number}（" . ($size === 'big' ? '大' : '小') . "），押中派彩 {$payout}";
                    });
            }

            // 2) Exact number bets: win iff bet_number == drawn number; tier by number type.
            $multiplier = game_bs_number_multiplier($number);
            $typeLabel = game_bs_type_label($number);
            game_bs_pay_winners($roundId, "b.`choice` = 'number' AND b.`bet_number` = $number", $multiplier, $now, 'won',
                function ($bet, $payout) use ($issueNo, $number, $typeLabel, $multiplier) {
                    return "压大小第 {$issueNo} 期开 {$number}，押数字命中（{$typeLabel} {$multiplier}倍）派彩 {$payout}";
                });

            // 3) 押豹子 / 押顺子: win if the drawn number is that category.
            if ($type === 'triple') {
                game_bs_pay_winners($roundId, "b.`choice` = 'triple'", GAME_BS_TRIPLE_MULT, $now, 'won',
                    function ($bet, $payout) use ($issueNo, $number) {
                        return "压大小第 {$issueNo} 期开 {$number}（豹子），押豹子命中派彩 {$payout}";
                    });
            } elseif ($type === 'straight') {
                game_bs_pay_winners($roundId, "b.`choice` = 'straight'", GAME_BS_STRAIGHT_MULT, $now, 'won',
                    function ($bet, $payout) use ($issueNo, $number) {
                        return "压大小第 {$issueNo} 期开 {$number}（顺子），押顺子命中派彩 {$payout}";
                    });
            }

            // Everything still pending lost.
            sql_query("UPDATE `" . GAME_BS_BET_TABLE . "` SET `status` = 'lost', `settled_at` = " . sqlesc($now) . " WHERE `round_id` = $roundId AND `status` = 'pending'") or sqlerr(__FILE__, __LINE__);
            sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        } catch (Throwable $e) {
            sql_query("ROLLBACK");
            throw $e;
        }
    }
}

function game_bs_place_bet($choice, $amount, $betNumber = null)
{
    global $CURUSER;
    game_bs_settle_due_rounds();
    $round = game_bs_get_or_create_current_round();
    $roundId = (int)$round['id'];
    $now = date('Y-m-d H:i:s');
    $uid = (int)$CURUSER['id'];
    $amountSql = game_bs_money($amount);

    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $roundRes = sql_query("SELECT * FROM `" . GAME_BS_ROUND_TABLE . "` WHERE `id` = $roundId FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $lockedRound = mysql_fetch_assoc($roundRes);
        if (!$lockedRound || $lockedRound['status'] !== 'open' || $lockedRound['round_end'] <= $now) {
            sql_query("ROLLBACK");
            return "本期已截止，请刷新后参与下一期。";
        }

        $userRes = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = $uid FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $user = mysql_fetch_assoc($userRes);
        if (!$user) {
            sql_query("ROLLBACK");
            return "用户不存在。";
        }
        $oldBonus = (float)$user['seedbonus'];
        if ($oldBonus < $amount) {
            sql_query("ROLLBACK");
            return "电影票不足，当前只有 " . game_bs_money($oldBonus) . " 张。";
        }
        $newBonus = $oldBonus - $amount;
        sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` - " . sqlesc($amountSql) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
        sql_query(sprintf(
            "INSERT INTO `" . GAME_BS_BET_TABLE . "` (`round_id`, `uid`, `choice`, `bet_number`, `amount`, `created_at`) VALUES (%d, %d, %s, %s, %s, %s)",
            $roundId,
            $uid,
            sqlesc($choice),
            $choice === 'number' ? (int)$betNumber : 'NULL',
            sqlesc($amountSql),
            sqlesc($now)
        )) or sqlerr(__FILE__, __LINE__);
        if ($choice === 'big' || $choice === 'small') {
            $choiceField = $choice === 'big' ? 'total_big' : 'total_small';
            sql_query("UPDATE `" . GAME_BS_ROUND_TABLE . "` SET `$choiceField` = `$choiceField` + " . sqlesc($amountSql) . ", `updated_at` = " . sqlesc($now) . " WHERE `id` = $roundId") or sqlerr(__FILE__, __LINE__);
            $what = '押' . ($choice === 'big' ? '大' : '小');
        } elseif ($choice === 'number') {
            $what = '押数字 ' . (int)$betNumber;
        } elseif ($choice === 'triple') {
            $what = '押豹子';
        } else {
            $what = '押顺子';
        }
        $issueNo = game_bs_issue_no($roundId);
        game_bs_bonus_log($uid, $oldBonus, $amount, $newBonus, "压大小第 {$issueNo} 期{$what}，扣除 {$amount} 张电影票");
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        clear_user_cache($uid);
        $CURUSER['seedbonus'] = $newBonus;
        return "";
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        throw $e;
    }
}

function game_bs_format_my_bet($bet)
{
    if ($bet['choice'] === 'big') {
        $choice = '押大';
    } elseif ($bet['choice'] === 'small') {
        $choice = '押小';
    } elseif ($bet['choice'] === 'triple') {
        $choice = '押豹子';
    } elseif ($bet['choice'] === 'straight') {
        $choice = '押顺子';
    } else {
        $choice = '押数字 ' . (int)$bet['bet_number'];
    }
    $statusMap = ['pending' => '待开奖', 'won' => '中', 'lost' => '未中', 'refunded' => '退回'];
    $st = $statusMap[$bet['status']] ?? $bet['status'];
    return htmlspecialchars($choice . ' ' . game_bs_money($bet['amount']) . '（' . $st . '）');
}

function game_bs_render_history()
{
    global $CURUSER;
    $uid = (int)$CURUSER['id'];
    $perPage = 50;
    $countRes = sql_query("SELECT COUNT(*) AS c FROM `" . GAME_BS_ROUND_TABLE . "` WHERE `status` = 'closed'") or sqlerr(__FILE__, __LINE__);
    $total = (int)mysql_fetch_assoc($countRes)['c'];
    $pages = max(1, (int)ceil($total / $perPage));
    $page = (int)($_GET['page'] ?? 1);
    if ($page < 1) { $page = 1; }
    if ($page > $pages) { $page = $pages; }
    $offset = ($page - 1) * $perPage;
    $res = sql_query("SELECT * FROM `" . GAME_BS_ROUND_TABLE . "` WHERE `status` = 'closed' ORDER BY `id` DESC LIMIT $perPage OFFSET $offset") or sqlerr(__FILE__, __LINE__);
    $resultSizeLabel = ['big' => '大', 'small' => '小', 'triple' => '豹子(通杀)', 'push' => '和15(通杀)'];
    stdhead("历史开奖");
echo game_back_link();
    ?>
    <style>
    .bsh-wrap { max-width: 760px; margin: 0 auto; }
    .bsh-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
    .bsh-title { font-size: 22px; font-weight: 800; }
    .bsh-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 16px; background: rgba(30,60,100,.06); }
    .bsh-table { width: 100%; border-collapse: collapse; }
    .bsh-table th, .bsh-table td { padding: 8px; border: 1px solid rgba(120,150,190,.26); text-align: center; }
    .bsh-pager { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 14px; }
    .bsh-pager .muted { color: #9aa7b5; }
    </style>
    <div class="bsh-wrap">
        <?php echo game_bs_subnav('history'); ?>
        <h3 style="margin:0 0 12px">历史开奖</h3>
        <div class="bsh-panel">
            <table class="bsh-table">
                <tr><th>期号</th><th>截止时间</th><th>数字</th><th>结果</th><th>我的押注</th></tr>
                <?php while ($item = mysql_fetch_assoc($res)) { ?>
                    <tr>
                        <td><?php echo game_bs_issue_no($item['id']) ?></td>
                        <td><?php echo htmlspecialchars($item['round_end']) ?></td>
                        <td><?php echo (int)$item['result_number'] ?></td>
                        <td><?php
                            echo $resultSizeLabel[$item['result']] ?? htmlspecialchars((string)$item['result']);
                            if ($item['result_number'] !== null && game_bs_number_type((int)$item['result_number']) !== 'normal') {
                                echo '（' . game_bs_type_label((int)$item['result_number']) . '）';
                            }
                        ?></td>
                        <td><?php
                            $myRes = sql_query("SELECT * FROM `" . GAME_BS_BET_TABLE . "` WHERE `uid` = $uid AND `round_id` = " . (int)$item['id'] . " ORDER BY `id` ASC") or sqlerr(__FILE__, __LINE__);
                            $myParts = [];
                            while ($mb = mysql_fetch_assoc($myRes)) { $myParts[] = game_bs_format_my_bet($mb); }
                            echo $myParts ? implode('<br>', $myParts) : '-';
                        ?></td>
                    </tr>
                <?php } ?>
            </table>
            <div class="bsh-pager">
                <?php if ($page > 1) { ?><a href="/games/big-small/?view=history&page=<?php echo $page - 1 ?>">&laquo; 上一页</a><?php } else { ?><span class="muted">&laquo; 上一页</span><?php } ?>
                <span>第 <?php echo $page ?> / <?php echo $pages ?> 页（共 <?php echo $total ?> 期）</span>
                <?php if ($page < $pages) { ?><a href="/games/big-small/?view=history&page=<?php echo $page + 1 ?>">下一页 &raquo;</a><?php } else { ?><span class="muted">下一页 &raquo;</span><?php } ?>
            </div>
        </div>
    </div>
    <?php
    stdfoot();
}

function game_bs_render_my_bets()
{
    global $CURUSER;
    $uid = (int)$CURUSER['id'];
    $perPage = 50;
    $countRes = sql_query("SELECT COUNT(*) AS c FROM `" . GAME_BS_BET_TABLE . "` WHERE `uid` = $uid") or sqlerr(__FILE__, __LINE__);
    $total = (int)mysql_fetch_assoc($countRes)['c'];
    $pages = max(1, (int)ceil($total / $perPage));
    $page = (int)($_GET['page'] ?? 1);
    if ($page < 1) { $page = 1; }
    if ($page > $pages) { $page = $pages; }
    $offset = ($page - 1) * $perPage;
    $res = sql_query("SELECT * FROM `" . GAME_BS_BET_TABLE . "` WHERE `uid` = $uid ORDER BY `id` DESC LIMIT $perPage OFFSET $offset") or sqlerr(__FILE__, __LINE__);
    $statusMap = ['pending' => '待开奖', 'won' => '中奖', 'lost' => '未中', 'refunded' => '已退回'];
    stdhead("我的历史押注");
echo game_back_link();
    ?>
    <style>
    .bsh-wrap { max-width: 760px; margin: 0 auto; }
    .bsh-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
    .bsh-title { font-size: 22px; font-weight: 800; }
    .bsh-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 16px; background: rgba(30,60,100,.06); }
    .bsh-table { width: 100%; border-collapse: collapse; }
    .bsh-table th, .bsh-table td { padding: 8px; border: 1px solid rgba(120,150,190,.26); text-align: center; }
    .bsh-pager { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 14px; }
    .bsh-pager .muted { color: #9aa7b5; }
    </style>
    <div class="bsh-wrap">
        <?php echo game_bs_subnav('mybets'); ?>
        <h3 style="margin:0 0 12px">我的历史押注</h3>
        <div class="bsh-panel">
            <table class="bsh-table">
                <tr><th>期号</th><th>选择</th><th>押注</th><th>状态</th><th>返还</th></tr>
                <?php while ($bet = mysql_fetch_assoc($res)) { ?>
                    <tr>
                        <td><?php echo game_bs_issue_no($bet['round_id']) ?></td>
                        <td><?php $c = $bet['choice']; echo $c === 'big' ? '大' : ($c === 'small' ? '小' : ($c === 'triple' ? '豹子' : ($c === 'straight' ? '顺子' : '数字 ' . (int)$bet['bet_number']))) ?></td>
                        <td><?php echo game_bs_money($bet['amount']) ?></td>
                        <td><?php echo $statusMap[$bet['status']] ?? htmlspecialchars($bet['status']) ?></td>
                        <td><?php echo game_bs_money($bet['payout']) ?></td>
                    </tr>
                <?php } ?>
            </table>
            <div class="bsh-pager">
                <?php if ($page > 1) { ?><a href="/games/big-small/?view=mybets&page=<?php echo $page - 1 ?>">&laquo; 上一页</a><?php } else { ?><span class="muted">&laquo; 上一页</span><?php } ?>
                <span>第 <?php echo $page ?> / <?php echo $pages ?> 页（共 <?php echo $total ?> 注）</span>
                <?php if ($page < $pages) { ?><a href="/games/big-small/?view=mybets&page=<?php echo $page + 1 ?>">下一页 &raquo;</a><?php } else { ?><span class="muted">下一页 &raquo;</span><?php } ?>
            </div>
        </div>
    </div>
    <?php
    stdfoot();
}

function game_bs_subnav($active = '')
{
    $items = ['' => '压大小', 'history' => '历史开奖', 'mybets' => '我的押注', 'ranking' => '用户排名', 'pnl' => '盈亏榜'];
    $out = '<div style="display:flex;flex-wrap:wrap;gap:2px;margin-bottom:16px;border-bottom:1px solid rgba(120,150,190,.3)">';
    foreach ($items as $k => $label) {
        $url = $k === '' ? '/games/big-small/' : '/games/big-small/?view=' . $k;
        $color = $k === $active ? '#2ecc71' : '#6f7f95';
        $border = $k === $active ? '3px solid #2ecc71' : '3px solid transparent';
        $out .= '<a href="' . $url . '" style="padding:9px 14px;font-weight:700;text-decoration:none;color:' . $color . ';border-bottom:' . $border . '">' . $label . '</a>';
    }
    return $out . '</div>';
}

function game_bs_leaderboard($orderBy, $limit, $minInvested, $havingExtra = '')
{
    $extra = $havingExtra !== '' ? " AND ($havingExtra)" : '';
    $sql = "SELECT b.`uid`, u.`username`,
            SUM(CASE WHEN b.`status` IN ('won','lost') THEN b.`amount` ELSE 0 END) AS invested,
            SUM(CASE WHEN b.`status` = 'won' THEN 1 ELSE 0 END) AS won_cnt,
            SUM(CASE WHEN b.`status` = 'lost' THEN 1 ELSE 0 END) AS lost_cnt,
            SUM(CASE WHEN b.`status` = 'won' THEN b.`payout` - b.`amount` ELSE 0 END) AS win_points,
            SUM(CASE WHEN b.`status` = 'lost' THEN b.`amount` ELSE 0 END) AS lose_points
        FROM `" . GAME_BS_BET_TABLE . "` b
        INNER JOIN `users` u ON u.`id` = b.`uid`
        GROUP BY b.`uid`, u.`username`
        HAVING invested > " . (int)$minInvested . $extra . "
        ORDER BY $orderBy
        LIMIT " . (int)$limit;
    $res = sql_query($sql) or sqlerr(__FILE__, __LINE__);
    $rows = [];
    while ($r = mysql_fetch_assoc($res)) {
        $rows[] = $r;
    }
    return $rows;
}

function game_bs_my_stats($uid)
{
    $res = sql_query("SELECT
            SUM(CASE WHEN `status` IN ('won','lost') THEN 1 ELSE 0 END) AS total,
            SUM(CASE WHEN `status` = 'won' THEN 1 ELSE 0 END) AS won_cnt,
            SUM(CASE WHEN `status` = 'lost' THEN 1 ELSE 0 END) AS lost_cnt,
            SUM(CASE WHEN `status` = 'won' THEN `payout` - `amount` ELSE 0 END) AS win_points,
            SUM(CASE WHEN `status` = 'lost' THEN `amount` ELSE 0 END) AS lose_points
        FROM `" . GAME_BS_BET_TABLE . "` WHERE `uid` = " . (int)$uid) or sqlerr(__FILE__, __LINE__);
    $r = mysql_fetch_assoc($res);
    return [
        'total' => (int)($r['total'] ?? 0), 'won' => (int)($r['won_cnt'] ?? 0), 'lost' => (int)($r['lost_cnt'] ?? 0),
        'win_points' => (float)($r['win_points'] ?? 0), 'lose_points' => (float)($r['lose_points'] ?? 0),
    ];
}

function game_bs_points($v, $signed = false)
{
    $v = (float)$v;
    $s = ($signed && $v > 0) ? '+' : '';
    return $s . number_format(round($v), 0);
}

function game_bs_lb_styles()
{
    echo '<style>
    .bsh-wrap { max-width: 980px; margin: 0 auto; }
    .bsh-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 16px; background: rgba(30,60,100,.06); }
    .bsh-table { width: 100%; border-collapse: collapse; }
    .bsh-table th, .bsh-table td { padding: 8px; border: 1px solid rgba(120,150,190,.26); text-align: center; }
    .bs-pos { color: #16a34a; font-weight: 700; }
    .bs-neg { color: #dc2626; font-weight: 700; }
    .bs-ranknum { font-weight: 800; color: #2f80b5; }
    .bs-mystat { display: flex; gap: 16px; flex-wrap: wrap; padding: 10px 14px; background: rgba(0,0,0,.04); border-radius: 6px; margin-bottom: 14px; font-weight: 700; }
    .bs-tab2 { display: inline-block; padding: 6px 14px; border: 1px solid rgba(120,150,190,.45); border-radius: 999px; cursor: pointer; font-weight: 700; background: rgba(0,0,0,.03); margin-right: 8px; }
    .bs-tab2.is-active { background: #2ecc71; color: #fff; border-color: #2ecc71; }
    </style>';
}

function game_bs_render_ranking()
{
    $rows = game_bs_leaderboard('invested DESC', 100, 1000);
    stdhead("用户排名");
echo game_back_link();
    game_bs_lb_styles();
    ?>
    <div class="bsh-wrap">
        <?php echo game_bs_subnav('ranking'); ?>
        <h3 style="margin:0 0 12px">用户排名（总投入大于 1000 才计入）</h3>
        <div class="bsh-panel">
            <table class="bsh-table">
                <tr><th>排名</th><th>用户名</th><th>积分变化</th><th>赢盘积分</th><th>输盘积分</th><th>赢盘次数</th><th>输盘次数</th><th>获胜比例</th><th>总投入</th></tr>
                <?php foreach ($rows as $i => $r) {
                    $net = (float)$r['win_points'] - (float)$r['lose_points'];
                    $ratio = (int)$r['lost_cnt'] > 0 ? number_format((int)$r['won_cnt'] / (int)$r['lost_cnt'], 2) : ((int)$r['won_cnt'] > 0 ? '∞' : '-');
                ?>
                    <tr>
                        <td class="bs-ranknum">#<?php echo $i + 1 ?></td>
                        <td><a href="userdetails.php?id=<?php echo (int)$r['uid'] ?>"><?php echo htmlspecialchars($r['username']) ?></a></td>
                        <td class="<?php echo $net >= 0 ? 'bs-pos' : 'bs-neg' ?>"><?php echo game_bs_points($net, true) ?></td>
                        <td><?php echo game_bs_points($r['win_points']) ?></td>
                        <td><?php echo game_bs_points($r['lose_points']) ?></td>
                        <td><?php echo (int)$r['won_cnt'] ?></td>
                        <td><?php echo (int)$r['lost_cnt'] ?></td>
                        <td><?php echo $ratio ?></td>
                        <td><?php echo game_bs_points($r['invested']) ?></td>
                    </tr>
                <?php } ?>
                <?php if (!$rows) { ?><tr><td colspan="9" style="color:#6f7f95">暂无上榜用户。</td></tr><?php } ?>
            </table>
        </div>
    </div>
    <?php
    stdfoot();
}

function game_bs_render_pnl()
{
    global $CURUSER;
    $winRows = game_bs_leaderboard('(win_points - lose_points) DESC', 50, 1000, '(win_points - lose_points) > 0');
    $loseRows = game_bs_leaderboard('(win_points - lose_points) ASC', 50, 1000, '(win_points - lose_points) < 0');
    $my = game_bs_my_stats((int)$CURUSER['id']);
    $myNet = $my['win_points'] - $my['lose_points'];
    stdhead("盈亏榜");
echo game_back_link();
    game_bs_lb_styles();
    ?>
    <div class="bsh-wrap" style="max-width:760px">
        <?php echo game_bs_subnav('pnl'); ?>
        <div class="bs-mystat">
            <span>我的胜负</span>
            <span>总：<?php echo $my['total'] ?></span>
            <span class="bs-pos">盈：<?php echo game_bs_points($my['win_points']) ?></span>
            <span class="bs-neg">亏：<?php echo game_bs_points($my['lose_points']) ?></span>
            <span>胜：<?php echo $my['won'] ?></span>
            <span>负：<?php echo $my['lost'] ?></span>
            <span>净：<span class="<?php echo $myNet >= 0 ? 'bs-pos' : 'bs-neg' ?>"><?php echo game_bs_points($myNet, true) ?></span></span>
        </div>
        <div id="bsPnlTabs" style="margin-bottom:12px">
            <span class="bs-tab2 is-active" data-pnl="win">🏆 胜榜·总盈利</span>
            <span class="bs-tab2" data-pnl="lose">💸 负榜·总亏损</span>
        </div>
        <table class="bsh-table" id="bsPnlWin">
            <tr><th>排名</th><th>用户名</th><th>胜场</th><th>总盈利</th></tr>
            <?php foreach ($winRows as $i => $r) { ?>
                <tr>
                    <td class="bs-ranknum"><?php echo $i + 1 ?></td>
                    <td><a href="userdetails.php?id=<?php echo (int)$r['uid'] ?>"><?php echo htmlspecialchars($r['username']) ?></a></td>
                    <td><?php echo (int)$r['won_cnt'] ?>胜</td>
                    <td class="bs-pos"><?php echo game_bs_points((float)$r['win_points'] - (float)$r['lose_points'], true) ?></td>
                </tr>
            <?php } ?>
            <?php if (!$winRows) { ?><tr><td colspan="4" style="color:#6f7f95">暂无盈利用户。</td></tr><?php } ?>
        </table>
        <table class="bsh-table" id="bsPnlLose" style="display:none">
            <tr><th>排名</th><th>用户名</th><th>负场</th><th>总亏损</th></tr>
            <?php foreach ($loseRows as $i => $r) { ?>
                <tr>
                    <td class="bs-ranknum"><?php echo $i + 1 ?></td>
                    <td><a href="userdetails.php?id=<?php echo (int)$r['uid'] ?>"><?php echo htmlspecialchars($r['username']) ?></a></td>
                    <td><?php echo (int)$r['lost_cnt'] ?>负</td>
                    <td class="bs-neg"><?php echo game_bs_points((float)$r['win_points'] - (float)$r['lose_points'], true) ?></td>
                </tr>
            <?php } ?>
            <?php if (!$loseRows) { ?><tr><td colspan="4" style="color:#6f7f95">暂无亏损用户。</td></tr><?php } ?>
        </table>
    </div>
    <script>
    (function () {
        var tabs = document.getElementById('bsPnlTabs');
        tabs.addEventListener('click', function (e) {
            var tab = e.target.closest('.bs-tab2');
            if (!tab) { return; }
            tabs.querySelectorAll('.bs-tab2').forEach(function (t) { t.classList.remove('is-active'); });
            tab.classList.add('is-active');
            var w = tab.getAttribute('data-pnl');
            document.getElementById('bsPnlWin').style.display = w === 'win' ? '' : 'none';
            document.getElementById('bsPnlLose').style.display = w === 'lose' ? '' : 'none';
        });
    })();
    </script>
    <?php
    stdfoot();
}

game_bs_ensure_tables();
game_bs_settle_due_rounds();

if (($_GET['view'] ?? '') === 'history') {
    game_bs_render_history();
    exit;
}
if (($_GET['view'] ?? '') === 'mybets') {
    game_bs_render_my_bets();
    exit;
}
if (($_GET['view'] ?? '') === 'ranking') {
    game_bs_render_ranking();
    exit;
}
if (($_GET['view'] ?? '') === 'pnl') {
    game_bs_render_pnl();
    exit;
}

$message = "";
$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $choice = $_POST['choice'] ?? '';
    $amountRaw = trim((string)($_POST['amount'] ?? ''));
    $betNumber = null;
    if (!in_array($choice, ['big', 'small', 'number', 'triple', 'straight'], true)) {
        $error = "请选择有效的押注选项。";
    } elseif ($choice === 'number' && !preg_match('/^[1-6][1-6][1-6]$/', trim((string)($_POST['bet_number'] ?? '')))) {
        $error = "押注数字必须是 111 到 666，且每一位都是 1-6。";
    } elseif (!preg_match('/^[1-9][0-9]*$/', $amountRaw)) {
        $error = "押注电影票必须是正整数。";
    } else {
        if ($choice === 'number') {
            $betNumber = (int)trim((string)$_POST['bet_number']);
        }
        $amount = (int)$amountRaw;
        if ($amount > 10000000000) {
            $error = "单次押注金额过大。";
        } else {
            $error = game_bs_place_bet($choice, $amount, $betNumber);
            if ($error === "") {
                $message = "押注成功，已实时扣除 {$amount} 张电影票。";
            }
        }
    }
    $redirectParams = $error !== '' ? ['error' => $error] : ['message' => $message];
    header('Location: /games/big-small/?' . http_build_query($redirectParams));
    exit;
}

$message = trim((string)($_GET['message'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

$round = game_bs_get_or_create_current_round();
$roundId = (int)$round['id'];
$issueNo = game_bs_issue_no($roundId);
$nowTs = TIMENOW;
$endTs = strtotime($round['round_end']);
$remain = max(0, $endTs - $nowTs);
$betsRes = sql_query("SELECT `choice`, SUM(`amount`) AS total, COUNT(*) AS count_bets FROM `" . GAME_BS_BET_TABLE . "` WHERE `round_id` = $roundId GROUP BY `choice`") or sqlerr(__FILE__, __LINE__);
$betStats = ['big' => ['total' => 0, 'count' => 0], 'small' => ['total' => 0, 'count' => 0], 'number' => ['total' => 0, 'count' => 0]];
while ($stat = mysql_fetch_assoc($betsRes)) {
    $betStats[$stat['choice']] = ['total' => (float)$stat['total'], 'count' => (int)$stat['count_bets']];
}
$myBetsRes = sql_query("SELECT * FROM `" . GAME_BS_BET_TABLE . "` WHERE `uid` = " . (int)$CURUSER['id'] . " ORDER BY `id` DESC LIMIT 10") or sqlerr(__FILE__, __LINE__);
$historyRes = sql_query("SELECT * FROM `" . GAME_BS_ROUND_TABLE . "` WHERE `status` IN ('closed','cancelled') ORDER BY `id` DESC LIMIT 10") or sqlerr(__FILE__, __LINE__);

$resultSizeLabel = ['big' => '大', 'small' => '小', 'triple' => '豹子(通杀)', 'push' => '和15(通杀)'];

stdhead("压大小");
echo game_back_link();
?>
<style>
.bs-wrap { max-width: 980px; margin: 0 auto; }
.bs-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.bs-title { font-size: 24px; font-weight: 800; }
.bs-badge { font-size: 12px; font-weight: 700; color: #e67e22; background: rgba(230,126,34,.12); padding: 2px 8px; border-radius: 999px; vertical-align: middle; }
.bs-balance { font-size: 14px; font-weight: 700; }
.bs-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 16px; margin-bottom: 14px; background: rgba(30,60,100,.06); }
.bs-round { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 14px; }
.bs-stat { padding: 12px; border-radius: 6px; background: rgba(0,0,0,.04); }
.bs-stat span { display: block; color: #6f7f95; margin-bottom: 6px; }
.bs-stat b { font-size: 18px; }
.bs-rules { margin-bottom: 14px; color: #6f7f95; line-height: 1.7; font-size: 13px; }
.bs-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.bs-choice { display: inline-flex; gap: 8px; flex-wrap: wrap; }
.bs-choice label { display: inline-flex; align-items: center; gap: 5px; padding: 8px 12px; border: 1px solid rgba(120,150,190,.45); border-radius: 6px; cursor: pointer; }
.bs-form input[type="number"], .bs-form input[type="text"] { width: 150px; padding: 8px; }
.bs-form button { padding: 8px 18px; font-weight: 700; cursor: pointer; }
.bs-quick { display: inline-flex; gap: 6px; flex-wrap: wrap; }
.bs-chip { padding: 7px 12px; border: 1px solid rgba(120,150,190,.45); border-radius: 6px; cursor: pointer; font-weight: 700; background: rgba(255,255,255,.55); user-select: none; }
.bs-chip:hover { border-color: #2ecc71; color: #2ecc71; }
.bs-chip.allin { background: #e67e22; color: #fff; border-color: #e67e22; }
.bs-chip.allin:hover { background: #d35400; color: #fff; }
.bs-message { padding: 10px 12px; border-radius: 6px; margin-bottom: 14px; font-weight: 700; }
.bs-message.ok { background: rgba(34, 150, 90, .14); color: #16834d; }
.bs-message.err { background: rgba(220, 60, 70, .14); color: #c02432; }
.bs-tables { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 14px; }
.bs-table { width: 100%; border-collapse: collapse; }
.bs-table th, .bs-table td { padding: 8px; border: 1px solid rgba(120,150,190,.26); text-align: center; }
.bs-muted { color: #6f7f95; }
</style>
<div class="bs-wrap">
    <div class="bs-head">
        <div>
            <div class="bs-title">压大小 <span class="bs-badge">公测中 v1.2</span></div>
            <div class="bs-muted">每 1 分钟一期。开奖三个骰子，每个 1-6（111-666）。</div>
        </div>
        <div class="bs-balance">我的电影票：<?php echo game_bs_money($CURUSER['seedbonus']) ?> 张</div>
    </div>

    <?php if ($message) { ?><div class="bs-message ok"><?php echo htmlspecialchars($message) ?></div><?php } ?>
    <?php if ($error) { ?><div class="bs-message err"><?php echo htmlspecialchars($error) ?></div><?php } ?>

    <?php echo game_bs_subnav(''); ?>

    <div class="bs-panel">
        <div class="bs-round">
            <div class="bs-stat"><span>当前期号</span><b><?php echo $issueNo ?></b></div>
            <div class="bs-stat"><span>本期截止</span><b><?php echo htmlspecialchars($round['round_end']) ?></b></div>
            <div class="bs-stat"><span>剩余时间</span><b id="bsRemain"><?php echo floor($remain / 60) ?>:<?php echo str_pad((string)($remain % 60), 2, '0', STR_PAD_LEFT) ?></b></div>
            <div class="bs-stat"><span>押大 / 押小 / 押数字</span><b><?php echo game_bs_money($betStats['big']['total']) ?> / <?php echo game_bs_money($betStats['small']['total']) ?> / <?php echo game_bs_money($betStats['number']['total']) ?></b></div>
        </div>
        <div class="bs-rules">
            玩法：开奖三个骰子各 1-6（如 5 3 1）。<br>
            · <b>押大 / 押小</b>：按三个骰子之和判定，和 4-10 为小、11-17 为大，押中得 <b>2 倍</b>；三个相同（豹子，如 555）押大小都输（庄家通杀）。<br>
            · <b>押数字</b>：押中开奖的精确数字，豹子<b>10 倍</b>、顺子<b>7 倍</b>、其它<b>6 倍</b>。<br>
            · <b>押豹子</b>：开出任意豹子（三位相同）即中，<b><?php echo GAME_BS_TRIPLE_MULT ?> 倍</b>。<b>押顺子</b>：开出任意顺子即中，<b><?php echo GAME_BS_STRAIGHT_MULT ?> 倍</b>。<br>
            · 顺子＝三位是连续数字（不分顺序），如 123 / 321 / 231 / 132 都算。<br>
            · 本期无人押注则不开奖（自动作废）。
        </div>
        <form class="bs-form" method="post" action="/games/big-small/">
            <div class="bs-choice">
                <label><input type="radio" name="choice" value="big" checked> 押大</label>
                <label><input type="radio" name="choice" value="small"> 押小</label>
                <label><input type="radio" name="choice" value="triple"> 押豹子</label>
                <label><input type="radio" name="choice" value="straight"> 押顺子</label>
                <label><input type="radio" name="choice" value="number"> 押数字</label>
            </div>
            <input type="text" name="bet_number" id="bsBetNumber" inputmode="numeric" maxlength="3" pattern="[1-6]{3}" placeholder="数字 111-666" disabled>
            <input type="number" name="amount" min="1" step="1" placeholder="电影票数量" required>
            <button type="submit">立即押注</button>
            <span class="bs-quick">
                <span class="bs-chip" data-amt="100">100</span>
                <span class="bs-chip" data-amt="500">500</span>
                <span class="bs-chip" data-amt="1000">1000</span>
                <span class="bs-chip" data-amt="5000">5000</span>
                <span class="bs-chip" data-amt="10000">10000</span>
                <span class="bs-chip allin" data-amt="all">梭哈</span>
            </span>
        </form>
    </div>

    <div class="bs-tables">
        <div class="bs-panel">
            <h3 style="display:flex;align-items:center;justify-content:space-between;">我的最近押注 <a href="/games/big-small/?view=mybets" style="font-size:13px;font-weight:600;">我的历史押注 &raquo;</a></h3>
            <table class="bs-table">
                <tr><th>期号</th><th>选择</th><th>押注</th><th>状态</th><th>返还</th></tr>
                <?php while ($bet = mysql_fetch_assoc($myBetsRes)) { ?>
                    <tr>
                        <td><?php echo game_bs_issue_no($bet['round_id']) ?></td>
                        <td><?php $c = $bet['choice']; echo $c === 'big' ? '大' : ($c === 'small' ? '小' : ($c === 'triple' ? '豹子' : ($c === 'straight' ? '顺子' : '数字 ' . (int)$bet['bet_number']))) ?></td>
                        <td><?php echo game_bs_money($bet['amount']) ?></td>
                        <td><?php echo ['pending' => '待开奖', 'won' => '中奖', 'lost' => '未中', 'refunded' => '已退回'][$bet['status']] ?? htmlspecialchars($bet['status']) ?></td>
                        <td><?php echo game_bs_money($bet['payout']) ?></td>
                    </tr>
                <?php } ?>
            </table>
        </div>
        <div class="bs-panel">
            <h3 style="display:flex;align-items:center;justify-content:space-between;">最近开奖 <a href="/games/big-small/?view=history" style="font-size:13px;font-weight:600;">历史开奖 &raquo;</a></h3>
            <table class="bs-table">
                <tr><th>期号</th><th>截止时间</th><th>数字</th><th>结果</th></tr>
                <?php while ($item = mysql_fetch_assoc($historyRes)) { ?>
                    <tr>
                        <td><?php echo $item['status'] === 'cancelled' ? '-' : game_bs_issue_no($item['id']) ?></td>
                        <td><?php echo htmlspecialchars($item['round_end']) ?></td>
                        <td><?php echo $item['status'] === 'cancelled' ? '-' : (int)$item['result_number'] ?></td>
                        <td><?php
                            if ($item['status'] === 'cancelled') {
                                echo '无人押注';
                            } else {
                                echo $resultSizeLabel[$item['result']] ?? htmlspecialchars((string)$item['result']);
                                if ($item['result_number'] !== null && game_bs_number_type((int)$item['result_number']) !== 'normal') {
                                    echo '（' . game_bs_type_label((int)$item['result_number']) . '）';
                                }
                            }
                        ?></td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    </div>
</div>
<script>
(function () {
    var remain = <?php echo (int)$remain ?>;
    var node = document.getElementById('bsRemain');
    function tick() {
        var minutes = Math.floor(remain / 60);
        var seconds = remain % 60;
        node.textContent = minutes + ':' + String(seconds).padStart(2, '0');
        if (remain > 0) {
            remain -= 1;
            window.setTimeout(tick, 1000);
        } else {
            window.setTimeout(function () { window.location.reload(); }, 1200);
        }
    }
    tick();

    // Quick-bet chips (100/500/.../梭哈).
    var bsBalance = <?php echo (int)floor((float)$CURUSER['seedbonus']) ?>;
    document.querySelectorAll('.bs-chip[data-amt]').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var form = chip.closest('form');
            var input = form ? form.querySelector('input[name="amount"]') : null;
            if (!input) { return; }
            var amt = chip.getAttribute('data-amt');
            input.value = amt === 'all' ? bsBalance : amt;
            input.focus();
        });
    });

    // Enable the number input only when "押数字" is selected.
    var numberInput = document.getElementById('bsBetNumber');
    var radios = document.querySelectorAll('input[name="choice"]');
    function syncNumberInput() {
        var checked = document.querySelector('input[name="choice"]:checked');
        var isNumber = checked && checked.value === 'number';
        numberInput.disabled = !isNumber;
        numberInput.required = isNumber;
        if (!isNumber) { numberInput.value = ''; }
    }
    radios.forEach(function (r) { r.addEventListener('change', syncNumberInput); });
    syncNumberInput();
})();
</script>
<?php
stdfoot();
