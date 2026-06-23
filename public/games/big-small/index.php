<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;

const GAME_BS_INTERVAL = 600;
const GAME_BS_BUSINESS_TYPE = 13;
const GAME_BS_ROUND_TABLE = 'hdvideo_game_big_small_rounds';
const GAME_BS_BET_TABLE = 'hdvideo_game_big_small_bets';

function game_bs_run_schema_sql($sql)
{
    $res = @sql_query($sql);
    if (!$res) {
        do_log('[GAME_BIG_SMALL_SCHEMA_ERROR] ' . $sql . ' :: ' . mysql_error(), 'error');
    }
    return $res;
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
            `result` enum('big','small') DEFAULT NULL,
            `result_number` tinyint unsigned DEFAULT NULL,
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
            `choice` enum('big','small') NOT NULL,
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
    $done = true;
}

function game_bs_money($value)
{
    return number_format((float)$value, 1, '.', '');
}

function game_bs_issue_no($roundId)
{
    static $cache = [];
    $roundId = (int)$roundId;
    if ($roundId <= 0) {
        return 0;
    }
    if (!isset($cache[$roundId])) {
        $res = sql_query("SELECT COUNT(*) AS c FROM `" . GAME_BS_ROUND_TABLE . "` WHERE `id` <= $roundId") or sqlerr(__FILE__, __LINE__);
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

            $countRes = sql_query("SELECT COUNT(*) AS c FROM `" . GAME_BS_BET_TABLE . "` WHERE `round_id` = $roundId") or sqlerr(__FILE__, __LINE__);
            $betCount = (int)mysql_fetch_assoc($countRes)['c'];
            if ($betCount < 1) {
                sql_query("UPDATE `" . GAME_BS_ROUND_TABLE . "` SET `status` = 'cancelled', `updated_at` = " . sqlesc($now) . " WHERE `id` = $roundId") or sqlerr(__FILE__, __LINE__);
                sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
                continue;
            }

            $number = function_exists('random_int') ? random_int(1, 100) : mt_rand(1, 100);
            $result = $number > 50 ? 'big' : 'small';
            sql_query("UPDATE `" . GAME_BS_ROUND_TABLE . "` SET `status` = 'closed', `result` = " . sqlesc($result) . ", `result_number` = $number, `updated_at` = " . sqlesc($now) . " WHERE `id` = $roundId") or sqlerr(__FILE__, __LINE__);

            $winRes = sql_query("
                SELECT b.`id`, b.`uid`, b.`amount`, u.`seedbonus`
                FROM `" . GAME_BS_BET_TABLE . "` b
                INNER JOIN `users` u ON u.`id` = b.`uid`
                WHERE b.`round_id` = $roundId AND b.`status` = 'pending' AND b.`choice` = " . sqlesc($result) . "
                FOR UPDATE
            ") or sqlerr(__FILE__, __LINE__);
            while ($bet = mysql_fetch_assoc($winRes)) {
                $betId = (int)$bet['id'];
                $uid = (int)$bet['uid'];
                $amount = (float)$bet['amount'];
                $payout = $amount * 2;
                $oldBonus = (float)$bet['seedbonus'];
                $newBonus = $oldBonus + $payout;
                sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(game_bs_money($payout)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
                sql_query("UPDATE `" . GAME_BS_BET_TABLE . "` SET `status` = 'won', `payout` = " . sqlesc(game_bs_money($payout)) . ", `settled_at` = " . sqlesc($now) . " WHERE `id` = $betId") or sqlerr(__FILE__, __LINE__);
                $issueNo = game_bs_issue_no($roundId);
                game_bs_bonus_log($uid, $oldBonus, $payout, $newBonus, "压大小第 {$issueNo} 期中奖派彩，押注 {$amount}，返还 {$payout}");
                clear_user_cache($uid);
            }
            sql_query("UPDATE `" . GAME_BS_BET_TABLE . "` SET `status` = 'lost', `settled_at` = " . sqlesc($now) . " WHERE `round_id` = $roundId AND `status` = 'pending'") or sqlerr(__FILE__, __LINE__);
            sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        } catch (Throwable $e) {
            sql_query("ROLLBACK");
            throw $e;
        }
    }
}

function game_bs_place_bet($choice, $amount)
{
    global $CURUSER;
    game_bs_settle_due_rounds();
    $round = game_bs_get_or_create_current_round();
    $roundId = (int)$round['id'];
    $now = date('Y-m-d H:i:s');
    $uid = (int)$CURUSER['id'];
    $amountSql = game_bs_money($amount);
    $choiceField = $choice === 'big' ? 'total_big' : 'total_small';

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
            "INSERT INTO `" . GAME_BS_BET_TABLE . "` (`round_id`, `uid`, `choice`, `amount`, `created_at`) VALUES (%d, %d, %s, %s, %s)",
            $roundId,
            $uid,
            sqlesc($choice),
            sqlesc($amountSql),
            sqlesc($now)
        )) or sqlerr(__FILE__, __LINE__);
        sql_query("UPDATE `" . GAME_BS_ROUND_TABLE . "` SET `$choiceField` = `$choiceField` + " . sqlesc($amountSql) . ", `updated_at` = " . sqlesc($now) . " WHERE `id` = $roundId") or sqlerr(__FILE__, __LINE__);
        $issueNo = game_bs_issue_no($roundId);
        game_bs_bonus_log($uid, $oldBonus, $amount, $newBonus, "压大小第 {$issueNo} 期押" . ($choice === 'big' ? '大' : '小') . "，扣除 {$amount} 张电影票");
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        clear_user_cache($uid);
        $CURUSER['seedbonus'] = $newBonus;
        return "";
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        throw $e;
    }
}

game_bs_ensure_tables();
game_bs_settle_due_rounds();

$message = "";
$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $choice = $_POST['choice'] ?? '';
    $amountRaw = trim((string)($_POST['amount'] ?? ''));
    if (!in_array($choice, ['big', 'small'], true)) {
        $error = "请选择押大或押小。";
    } elseif (!preg_match('/^[1-9][0-9]*$/', $amountRaw)) {
        $error = "押注电影票必须是正整数。";
    } else {
        $amount = (int)$amountRaw;
        if ($amount > 1000000) {
            $error = "单次押注不能超过 1000000 张电影票。";
        } else {
            $error = game_bs_place_bet($choice, $amount);
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
$betStats = ['big' => ['total' => 0, 'count' => 0], 'small' => ['total' => 0, 'count' => 0]];
while ($stat = mysql_fetch_assoc($betsRes)) {
    $betStats[$stat['choice']] = ['total' => (float)$stat['total'], 'count' => (int)$stat['count_bets']];
}
$myBetsRes = sql_query("SELECT * FROM `" . GAME_BS_BET_TABLE . "` WHERE `uid` = " . (int)$CURUSER['id'] . " ORDER BY `id` DESC LIMIT 10") or sqlerr(__FILE__, __LINE__);
$historyRes = sql_query("SELECT * FROM `" . GAME_BS_ROUND_TABLE . "` WHERE `status` IN ('closed','cancelled') ORDER BY `id` DESC LIMIT 10") or sqlerr(__FILE__, __LINE__);

stdhead("压大小");
?>
<style>
.bs-wrap { max-width: 980px; margin: 0 auto; }
.bs-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.bs-title { font-size: 24px; font-weight: 800; }
.bs-balance { font-size: 14px; font-weight: 700; }
.bs-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 16px; margin-bottom: 14px; background: rgba(30,60,100,.06); }
.bs-round { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin-bottom: 14px; }
.bs-stat { padding: 12px; border-radius: 6px; background: rgba(0,0,0,.04); }
.bs-stat span { display: block; color: #6f7f95; margin-bottom: 6px; }
.bs-stat b { font-size: 18px; }
.bs-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.bs-choice { display: inline-flex; gap: 8px; }
.bs-choice label { display: inline-flex; align-items: center; gap: 5px; padding: 8px 12px; border: 1px solid rgba(120,150,190,.45); border-radius: 6px; cursor: pointer; }
.bs-form input[type="number"] { width: 150px; padding: 8px; }
.bs-form button { padding: 8px 18px; font-weight: 700; cursor: pointer; }
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
            <div class="bs-title">压大小</div>
            <div class="bs-muted">每 10 分钟一期。开奖数字 1-50 为小，51-100 为大。</div>
        </div>
        <div class="bs-balance">我的电影票：<?php echo game_bs_money($CURUSER['seedbonus']) ?> 张</div>
    </div>

    <?php if ($message) { ?><div class="bs-message ok"><?php echo htmlspecialchars($message) ?></div><?php } ?>
    <?php if ($error) { ?><div class="bs-message err"><?php echo htmlspecialchars($error) ?></div><?php } ?>

    <div class="bs-panel">
        <div class="bs-round">
            <div class="bs-stat"><span>当前期号</span><b><?php echo $issueNo ?></b></div>
            <div class="bs-stat"><span>本期截止</span><b><?php echo htmlspecialchars($round['round_end']) ?></b></div>
            <div class="bs-stat"><span>剩余时间</span><b id="bsRemain"><?php echo floor($remain / 60) ?>:<?php echo str_pad((string)($remain % 60), 2, '0', STR_PAD_LEFT) ?></b></div>
            <div class="bs-stat"><span>押大 / 押小</span><b><?php echo game_bs_money($betStats['big']['total']) ?> / <?php echo game_bs_money($betStats['small']['total']) ?></b></div>
        </div>
        <form class="bs-form" method="post" action="/games/big-small/">
            <div class="bs-choice">
                <label><input type="radio" name="choice" value="big" checked> 押大</label>
                <label><input type="radio" name="choice" value="small"> 押小</label>
            </div>
            <input type="number" name="amount" min="1" step="1" placeholder="电影票数量" required>
            <button type="submit">立即押注</button>
        </form>
    </div>

    <div class="bs-tables">
        <div class="bs-panel">
            <h3>我的最近押注</h3>
            <table class="bs-table">
                <tr><th>期号</th><th>选择</th><th>押注</th><th>状态</th><th>返还</th></tr>
                <?php while ($bet = mysql_fetch_assoc($myBetsRes)) { ?>
                    <tr>
                        <td><?php echo game_bs_issue_no($bet['round_id']) ?></td>
                        <td><?php echo $bet['choice'] === 'big' ? '大' : '小' ?></td>
                        <td><?php echo game_bs_money($bet['amount']) ?></td>
                        <td><?php echo ['pending' => '待开奖', 'won' => '中奖', 'lost' => '未中', 'refunded' => '已退回'][$bet['status']] ?? htmlspecialchars($bet['status']) ?></td>
                        <td><?php echo game_bs_money($bet['payout']) ?></td>
                    </tr>
                <?php } ?>
            </table>
        </div>
        <div class="bs-panel">
            <h3>最近开奖</h3>
            <table class="bs-table">
                <tr><th>期号</th><th>截止时间</th><th>数字</th><th>结果</th></tr>
                <?php while ($item = mysql_fetch_assoc($historyRes)) { ?>
                    <tr>
                        <td><?php echo game_bs_issue_no($item['id']) ?></td>
                        <td><?php echo htmlspecialchars($item['round_end']) ?></td>
                        <td><?php echo $item['status'] === 'cancelled' ? '-' : (int)$item['result_number'] ?></td>
                        <td><?php echo $item['status'] === 'cancelled' ? '无人押注' : ($item['result'] === 'big' ? '大' : '小') ?></td>
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
})();
</script>
<?php
stdfoot();
