<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;

const GAME_SP_BUSINESS_TYPE = 13; // reuse the lucky-draw / game bonus category
const GAME_SP_MATCH_TABLE = 'hdvideo_sports_matches';
const GAME_SP_BET_TABLE = 'hdvideo_sports_bets';

function game_sp_run_schema_sql($sql)
{
    $res = @sql_query($sql);
    if (!$res) {
        do_log('[GAME_SPORTS_SCHEMA_ERROR] ' . $sql . ' :: ' . mysql_error(), 'error');
    }
    return $res;
}

function game_sp_ensure_tables()
{
    static $done = false;
    if ($done) {
        return;
    }
    game_sp_run_schema_sql("
        CREATE TABLE IF NOT EXISTS `" . GAME_SP_MATCH_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `league` varchar(80) NOT NULL DEFAULT '',
            `home_team` varchar(80) NOT NULL DEFAULT '',
            `away_team` varchar(80) NOT NULL DEFAULT '',
            `match_time` datetime NOT NULL,
            `bet_deadline` datetime NOT NULL,
            `odds_home` decimal(6,2) NOT NULL DEFAULT '0.00',
            `odds_draw` decimal(6,2) NOT NULL DEFAULT '0.00',
            `odds_away` decimal(6,2) NOT NULL DEFAULT '0.00',
            `status` enum('open','settled','cancelled') NOT NULL DEFAULT 'open',
            `result` enum('home','draw','away') DEFAULT NULL,
            `home_score` smallint unsigned DEFAULT NULL,
            `away_score` smallint unsigned DEFAULT NULL,
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_status_deadline` (`status`, `bet_deadline`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    game_sp_run_schema_sql("
        CREATE TABLE IF NOT EXISTS `" . GAME_SP_BET_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `match_id` int unsigned NOT NULL,
            `uid` int unsigned NOT NULL,
            `choice` enum('home','draw','away') NOT NULL,
            `odds` decimal(6,2) NOT NULL,
            `amount` decimal(20,1) NOT NULL,
            `status` enum('pending','won','lost','refunded') NOT NULL DEFAULT 'pending',
            `payout` decimal(20,1) NOT NULL DEFAULT '0.0',
            `created_at` datetime NOT NULL,
            `settled_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_match_status` (`match_id`, `status`),
            KEY `idx_uid_created` (`uid`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function game_sp_money($value)
{
    return number_format((float)$value, 1, '.', '');
}

function game_sp_is_admin()
{
    return get_user_class() >= UC_ADMINISTRATOR;
}

function game_sp_choice_label($choice)
{
    return ['home' => '主胜', 'draw' => '平局', 'away' => '客胜'][$choice] ?? $choice;
}

function game_sp_bonus_log($uid, $old, $delta, $new, $comment)
{
    $now = date('Y-m-d H:i:s');
    $fullComment = '[Sports] ' . $comment;
    sql_query(sprintf(
        "INSERT INTO bonus_logs (`business_type`, `uid`, `old_total_value`, `value`, `new_total_value`, `comment`, `created_at`, `updated_at`) VALUES (%d, %d, %s, %s, %s, %s, %s, %s)",
        GAME_SP_BUSINESS_TYPE,
        (int)$uid,
        sqlesc(game_sp_money($old)),
        sqlesc(game_sp_money($delta)),
        sqlesc(game_sp_money($new)),
        sqlesc($fullComment),
        sqlesc($now),
        sqlesc($now)
    )) or sqlerr(__FILE__, __LINE__);
}

function game_sp_parse_datetime($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    $ts -= $ts % 60; // ignore seconds, normalize to :00
    return date('Y-m-d H:i:s', $ts);
}

function game_sp_place_bet($matchId, $choice, $amount)
{
    global $CURUSER;
    $matchId = (int)$matchId;
    $now = date('Y-m-d H:i:s');
    $uid = (int)$CURUSER['id'];
    $amountSql = game_sp_money($amount);

    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $matchRes = sql_query("SELECT * FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `id` = $matchId FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $match = mysql_fetch_assoc($matchRes);
        if (!$match || $match['status'] !== 'open' || $match['bet_deadline'] <= $now) {
            sql_query("ROLLBACK");
            return "该比赛已截止或不可押注，请刷新。";
        }
        $oddsField = 'odds_' . $choice;
        $odds = (float)$match[$oddsField];
        if ($odds <= 1) {
            sql_query("ROLLBACK");
            return "该选项暂未开盘。";
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
            return "电影票不足，当前只有 " . game_sp_money($oldBonus) . " 张。";
        }
        $newBonus = $oldBonus - $amount;
        sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` - " . sqlesc($amountSql) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
        sql_query(sprintf(
            "INSERT INTO `" . GAME_SP_BET_TABLE . "` (`match_id`, `uid`, `choice`, `odds`, `amount`, `created_at`) VALUES (%d, %d, %s, %s, %s, %s)",
            $matchId,
            $uid,
            sqlesc($choice),
            sqlesc(number_format($odds, 2, '.', '')),
            sqlesc($amountSql),
            sqlesc($now)
        )) or sqlerr(__FILE__, __LINE__);
        game_sp_bonus_log($uid, $oldBonus, $amount, $newBonus,
            "{$match['home_team']} vs {$match['away_team']} 押" . game_sp_choice_label($choice) . "（赔率 " . number_format($odds, 2) . "），扣除 {$amount} 张电影票");
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        clear_user_cache($uid);
        $CURUSER['seedbonus'] = $newBonus;
        return "";
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        throw $e;
    }
}

function game_sp_settle_match($matchId, $result, $homeScore, $awayScore)
{
    $matchId = (int)$matchId;
    $now = date('Y-m-d H:i:s');
    $homeScoreSql = ($homeScore === '' || $homeScore === null) ? 'NULL' : (int)$homeScore;
    $awayScoreSql = ($awayScore === '' || $awayScore === null) ? 'NULL' : (int)$awayScore;

    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $matchRes = sql_query("SELECT * FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `id` = $matchId FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $match = mysql_fetch_assoc($matchRes);
        if (!$match) {
            sql_query("ROLLBACK");
            return "比赛不存在。";
        }
        if ($match['status'] !== 'open') {
            sql_query("ROLLBACK");
            return "该比赛已结算或已取消。";
        }
        sql_query("UPDATE `" . GAME_SP_MATCH_TABLE . "` SET `status` = 'settled', `result` = " . sqlesc($result) . ", `home_score` = $homeScoreSql, `away_score` = $awayScoreSql, `updated_at` = " . sqlesc($now) . " WHERE `id` = $matchId") or sqlerr(__FILE__, __LINE__);

        // Winners: choice == result, payout = amount * locked odds.
        $winRes = sql_query("
            SELECT b.`id`, b.`uid`, b.`amount`, b.`odds`, u.`seedbonus`
            FROM `" . GAME_SP_BET_TABLE . "` b
            INNER JOIN `users` u ON u.`id` = b.`uid`
            WHERE b.`match_id` = $matchId AND b.`status` = 'pending' AND b.`choice` = " . sqlesc($result) . "
            FOR UPDATE
        ") or sqlerr(__FILE__, __LINE__);
        while ($bet = mysql_fetch_assoc($winRes)) {
            $betId = (int)$bet['id'];
            $uid = (int)$bet['uid'];
            $amount = (float)$bet['amount'];
            $odds = (float)$bet['odds'];
            $payout = round($amount * $odds, 1);
            $oldBonus = (float)$bet['seedbonus'];
            $newBonus = $oldBonus + $payout;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(game_sp_money($payout)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            sql_query("UPDATE `" . GAME_SP_BET_TABLE . "` SET `status` = 'won', `payout` = " . sqlesc(game_sp_money($payout)) . ", `settled_at` = " . sqlesc($now) . " WHERE `id` = $betId") or sqlerr(__FILE__, __LINE__);
            game_sp_bonus_log($uid, $oldBonus, $payout, $newBonus,
                "{$match['home_team']} vs {$match['away_team']} 押" . game_sp_choice_label($result) . "命中（赔率 " . number_format($odds, 2) . "）派彩 {$payout}");
            clear_user_cache($uid);
        }
        sql_query("UPDATE `" . GAME_SP_BET_TABLE . "` SET `status` = 'lost', `settled_at` = " . sqlesc($now) . " WHERE `match_id` = $matchId AND `status` = 'pending'") or sqlerr(__FILE__, __LINE__);
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        return "";
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        throw $e;
    }
}

function game_sp_cancel_match($matchId)
{
    $matchId = (int)$matchId;
    $now = date('Y-m-d H:i:s');

    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $matchRes = sql_query("SELECT * FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `id` = $matchId FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $match = mysql_fetch_assoc($matchRes);
        if (!$match) {
            sql_query("ROLLBACK");
            return "比赛不存在。";
        }
        if ($match['status'] !== 'open') {
            sql_query("ROLLBACK");
            return "只有未结算的比赛才能取消。";
        }
        // Refund all pending bets.
        $betRes = sql_query("
            SELECT b.`id`, b.`uid`, b.`amount`, u.`seedbonus`
            FROM `" . GAME_SP_BET_TABLE . "` b
            INNER JOIN `users` u ON u.`id` = b.`uid`
            WHERE b.`match_id` = $matchId AND b.`status` = 'pending'
            FOR UPDATE
        ") or sqlerr(__FILE__, __LINE__);
        while ($bet = mysql_fetch_assoc($betRes)) {
            $betId = (int)$bet['id'];
            $uid = (int)$bet['uid'];
            $amount = (float)$bet['amount'];
            $oldBonus = (float)$bet['seedbonus'];
            $newBonus = $oldBonus + $amount;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(game_sp_money($amount)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            sql_query("UPDATE `" . GAME_SP_BET_TABLE . "` SET `status` = 'refunded', `payout` = " . sqlesc(game_sp_money($amount)) . ", `settled_at` = " . sqlesc($now) . " WHERE `id` = $betId") or sqlerr(__FILE__, __LINE__);
            game_sp_bonus_log($uid, $oldBonus, $amount, $newBonus,
                "{$match['home_team']} vs {$match['away_team']} 比赛取消，退还本金 {$amount}");
            clear_user_cache($uid);
        }
        sql_query("UPDATE `" . GAME_SP_MATCH_TABLE . "` SET `status` = 'cancelled', `updated_at` = " . sqlesc($now) . " WHERE `id` = $matchId") or sqlerr(__FILE__, __LINE__);
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        return "";
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        throw $e;
    }
}

function game_sp_create_match($data)
{
    $now = date('Y-m-d H:i:s');
    $league = trim((string)($data['league'] ?? ''));
    $home = trim((string)($data['home_team'] ?? ''));
    $away = trim((string)($data['away_team'] ?? ''));
    $matchTime = game_sp_parse_datetime($data['match_time'] ?? '');
    $deadline = game_sp_parse_datetime($data['bet_deadline'] ?? '');
    $oddsHome = (float)($data['odds_home'] ?? 0);
    $oddsDraw = (float)($data['odds_draw'] ?? 0);
    $oddsAway = (float)($data['odds_away'] ?? 0);

    if ($home === '' || $away === '') {
        return "主队和客队不能为空。";
    }
    if (mb_strlen($league) > 80 || mb_strlen($home) > 80 || mb_strlen($away) > 80) {
        return "队名或联赛名过长。";
    }
    if (!$matchTime || !$deadline) {
        return "比赛时间和截止时间必须填写且格式正确。";
    }
    if ($deadline > $matchTime) {
        return "押注截止时间不能晚于比赛开始时间。";
    }
    foreach (['主胜' => $oddsHome, '平局' => $oddsDraw, '客胜' => $oddsAway] as $name => $o) {
        if ($o <= 1 || $o > 1000) {
            return "{$name}赔率必须大于 1 且不超过 1000。";
        }
    }
    sql_query(sprintf(
        "INSERT INTO `" . GAME_SP_MATCH_TABLE . "` (`league`, `home_team`, `away_team`, `match_time`, `bet_deadline`, `odds_home`, `odds_draw`, `odds_away`, `status`, `created_at`, `updated_at`) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, 'open', %s, %s)",
        sqlesc($league),
        sqlesc($home),
        sqlesc($away),
        sqlesc($matchTime),
        sqlesc($deadline),
        sqlesc(number_format($oddsHome, 2, '.', '')),
        sqlesc(number_format($oddsDraw, 2, '.', '')),
        sqlesc(number_format($oddsAway, 2, '.', '')),
        sqlesc($now),
        sqlesc($now)
    )) or sqlerr(__FILE__, __LINE__);
    return "";
}

game_sp_ensure_tables();

$message = "";
$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'bet') {
        $matchId = (int)($_POST['match_id'] ?? 0);
        $choice = $_POST['choice'] ?? '';
        $amountRaw = trim((string)($_POST['amount'] ?? ''));
        if (!in_array($choice, ['home', 'draw', 'away'], true)) {
            $error = "请选择主胜、平局或客胜。";
        } elseif (!preg_match('/^[1-9][0-9]*$/', $amountRaw)) {
            $error = "押注电影票必须是正整数。";
        } elseif ((int)$amountRaw > 1000000) {
            $error = "单次押注不能超过 1000000 张电影票。";
        } else {
            $error = game_sp_place_bet($matchId, $choice, (int)$amountRaw);
            if ($error === "") {
                $message = "押注成功，已实时扣除 {$amountRaw} 张电影票。";
            }
        }
    } elseif ($action === 'create_match' && game_sp_is_admin()) {
        $error = game_sp_create_match($_POST);
        if ($error === "") {
            $message = "比赛已创建。";
        }
    } elseif ($action === 'settle_match' && game_sp_is_admin()) {
        $result = $_POST['result'] ?? '';
        if (!in_array($result, ['home', 'draw', 'away'], true)) {
            $error = "请选择比赛结果。";
        } else {
            $error = game_sp_settle_match((int)($_POST['match_id'] ?? 0), $result, $_POST['home_score'] ?? '', $_POST['away_score'] ?? '');
            if ($error === "") {
                $message = "比赛已结算并派彩。";
            }
        }
    } elseif ($action === 'cancel_match' && game_sp_is_admin()) {
        $error = game_sp_cancel_match((int)($_POST['match_id'] ?? 0));
        if ($error === "") {
            $message = "比赛已取消，押注已退回。";
        }
    } else {
        $error = "无效操作。";
    }
    $redirectParams = $error !== '' ? ['error' => $error] : ['message' => $message];
    header('Location: /games/sports/?' . http_build_query($redirectParams));
    exit;
}

$message = trim((string)($_GET['message'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));
$isAdmin = game_sp_is_admin();
$now = date('Y-m-d H:i:s');

$openRes = sql_query("SELECT * FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `status` = 'open' AND `bet_deadline` > " . sqlesc($now) . " ORDER BY `match_time` ASC, `id` ASC") or sqlerr(__FILE__, __LINE__);
$openMatches = [];
while ($m = mysql_fetch_assoc($openRes)) {
    $openMatches[] = $m;
}
$resultRes = sql_query("SELECT * FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `status` IN ('settled','cancelled') ORDER BY `updated_at` DESC, `id` DESC LIMIT 15") or sqlerr(__FILE__, __LINE__);
$myBetsRes = sql_query("
    SELECT b.*, m.`home_team`, m.`away_team`, m.`league`, m.`status` AS match_status, m.`result` AS match_result
    FROM `" . GAME_SP_BET_TABLE . "` b
    INNER JOIN `" . GAME_SP_MATCH_TABLE . "` m ON m.`id` = b.`match_id`
    WHERE b.`uid` = " . (int)$CURUSER['id'] . " ORDER BY b.`id` DESC LIMIT 12
") or sqlerr(__FILE__, __LINE__);

$adminMatches = [];
if ($isAdmin) {
    $adminRes = sql_query("SELECT * FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `status` = 'open' ORDER BY `match_time` ASC, `id` ASC") or sqlerr(__FILE__, __LINE__);
    while ($m = mysql_fetch_assoc($adminRes)) {
        $totRes = sql_query("SELECT `choice`, SUM(`amount`) AS total, COUNT(*) AS cnt FROM `" . GAME_SP_BET_TABLE . "` WHERE `match_id` = " . (int)$m['id'] . " AND `status` = 'pending' GROUP BY `choice`") or sqlerr(__FILE__, __LINE__);
        $tot = ['home' => 0, 'draw' => 0, 'away' => 0];
        while ($t = mysql_fetch_assoc($totRes)) {
            $tot[$t['choice']] = (float)$t['total'];
        }
        $m['_totals'] = $tot;
        $adminMatches[] = $m;
    }
}

$betStatusLabel = ['pending' => '待开奖', 'won' => '中奖', 'lost' => '未中', 'refunded' => '已退回'];

stdhead("菠菜系统");
?>
<style>
.sp-wrap { max-width: 1000px; margin: 0 auto; }
.sp-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.sp-title { font-size: 24px; font-weight: 800; }
.sp-balance { font-size: 14px; font-weight: 700; }
.sp-muted { color: #6f7f95; }
.sp-message { padding: 10px 12px; border-radius: 6px; margin-bottom: 14px; font-weight: 700; }
.sp-message.ok { background: rgba(34,150,90,.14); color: #16834d; }
.sp-message.err { background: rgba(220,60,70,.14); color: #c02432; }
.sp-section-title { font-size: 18px; font-weight: 800; margin: 18px 0 10px; }
.sp-match { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 14px; margin-bottom: 12px; background: rgba(30,60,100,.06); }
.sp-match-top { display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px; margin-bottom: 6px; }
.sp-league { font-size: 12px; color: #2f80b5; font-weight: 700; }
.sp-teams { font-size: 17px; font-weight: 800; }
.sp-time { color: #6f7f95; font-size: 13px; }
.sp-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 8px; }
.sp-odds { display: inline-flex; gap: 8px; flex-wrap: wrap; }
.sp-odds label { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border: 1px solid rgba(120,150,190,.45); border-radius: 6px; cursor: pointer; }
.sp-odds b { color: #c0392b; }
.sp-form input[type="number"] { width: 140px; padding: 8px; }
.sp-form button { padding: 8px 16px; font-weight: 700; cursor: pointer; }
.sp-table { width: 100%; border-collapse: collapse; }
.sp-table th, .sp-table td { padding: 8px; border: 1px solid rgba(120,150,190,.26); text-align: center; }
.sp-admin { border: 1px dashed rgba(200,120,40,.6); border-radius: 8px; padding: 14px; margin-top: 22px; background: rgba(200,120,40,.06); }
.sp-admin h3 { margin: 0 0 10px; }
.sp-admin-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; align-items: end; }
.sp-admin-form label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: #6f7f95; }
.sp-admin-form input { padding: 7px; }
.sp-inline { display: inline-flex; gap: 6px; align-items: center; flex-wrap: wrap; }
</style>
<div class="sp-wrap">
    <div class="sp-head">
        <div>
            <div class="sp-title">菠菜系统</div>
            <div class="sp-muted">体育赛事竞猜，固定赔率。用电影票押 主胜 / 平局 / 客胜，押中得「押注 × 赔率」。</div>
        </div>
        <div class="sp-balance">我的电影票：<?php echo game_sp_money($CURUSER['seedbonus']) ?> 张</div>
    </div>

    <?php if ($message) { ?><div class="sp-message ok"><?php echo htmlspecialchars($message) ?></div><?php } ?>
    <?php if ($error) { ?><div class="sp-message err"><?php echo htmlspecialchars($error) ?></div><?php } ?>

    <div class="sp-section-title">进行中的赛事</div>
    <?php if (!$openMatches) { ?>
        <div class="sp-match sp-muted">暂无可押注的赛事，敬请期待。</div>
    <?php } ?>
    <?php foreach ($openMatches as $m) { ?>
        <div class="sp-match">
            <div class="sp-match-top">
                <?php if ($m['league'] !== '') { ?><span class="sp-league"><?php echo htmlspecialchars($m['league']) ?></span><?php } ?>
                <span class="sp-teams"><?php echo htmlspecialchars($m['home_team']) ?> <span class="sp-muted">vs</span> <?php echo htmlspecialchars($m['away_team']) ?></span>
            </div>
            <div class="sp-time">开赛：<?php echo htmlspecialchars($m['match_time']) ?>　·　截止：<?php echo htmlspecialchars($m['bet_deadline']) ?></div>
            <form class="sp-form" method="post" action="/games/sports/">
                <input type="hidden" name="action" value="bet">
                <input type="hidden" name="match_id" value="<?php echo (int)$m['id'] ?>">
                <div class="sp-odds">
                    <label><input type="radio" name="choice" value="home" checked> 主胜 <b><?php echo number_format($m['odds_home'], 2) ?></b></label>
                    <label><input type="radio" name="choice" value="draw"> 平局 <b><?php echo number_format($m['odds_draw'], 2) ?></b></label>
                    <label><input type="radio" name="choice" value="away"> 客胜 <b><?php echo number_format($m['odds_away'], 2) ?></b></label>
                </div>
                <input type="number" name="amount" min="1" step="1" placeholder="电影票数量" required>
                <button type="submit">押注</button>
            </form>
        </div>
    <?php } ?>

    <div class="sp-section-title">我的最近押注</div>
    <table class="sp-table">
        <tr><th>赛事</th><th>选择</th><th>赔率</th><th>押注</th><th>状态</th><th>返还</th></tr>
        <?php while ($bet = mysql_fetch_assoc($myBetsRes)) { ?>
            <tr>
                <td><?php echo htmlspecialchars($bet['home_team'] . ' vs ' . $bet['away_team']) ?></td>
                <td><?php echo game_sp_choice_label($bet['choice']) ?></td>
                <td><?php echo number_format($bet['odds'], 2) ?></td>
                <td><?php echo game_sp_money($bet['amount']) ?></td>
                <td><?php echo $betStatusLabel[$bet['status']] ?? htmlspecialchars($bet['status']) ?></td>
                <td><?php echo game_sp_money($bet['payout']) ?></td>
            </tr>
        <?php } ?>
    </table>

    <div class="sp-section-title">最近开奖</div>
    <table class="sp-table">
        <tr><th>赛事</th><th>比分</th><th>结果</th><th>状态</th></tr>
        <?php while ($m = mysql_fetch_assoc($resultRes)) { ?>
            <tr>
                <td><?php echo htmlspecialchars(($m['league'] !== '' ? '[' . $m['league'] . '] ' : '') . $m['home_team'] . ' vs ' . $m['away_team']) ?></td>
                <td><?php echo ($m['home_score'] === null || $m['away_score'] === null) ? '-' : ((int)$m['home_score'] . ' : ' . (int)$m['away_score']) ?></td>
                <td><?php echo $m['status'] === 'cancelled' ? '已取消(退款)' : game_sp_choice_label($m['result']) ?></td>
                <td><?php echo htmlspecialchars($m['updated_at']) ?></td>
            </tr>
        <?php } ?>
    </table>

    <?php if ($isAdmin) { ?>
        <div class="sp-admin">
            <h3>🛠 管理员：创建比赛</h3>
            <form method="post" action="/games/sports/">
                <input type="hidden" name="action" value="create_match">
                <div class="sp-admin-form">
                    <label>联赛<input type="text" name="league" maxlength="80" placeholder="如 世界杯"></label>
                    <label>主队<input type="text" name="home_team" maxlength="80" required></label>
                    <label>客队<input type="text" name="away_team" maxlength="80" required></label>
                    <label>比赛时间<input type="datetime-local" name="match_time" step="60" required></label>
                    <label>押注截止<input type="datetime-local" name="bet_deadline" step="60" required></label>
                    <label>主胜赔率<input type="number" name="odds_home" min="1.01" max="1000" step="0.01" required></label>
                    <label>平局赔率<input type="number" name="odds_draw" min="1.01" max="1000" step="0.01" required></label>
                    <label>客胜赔率<input type="number" name="odds_away" min="1.01" max="1000" step="0.01" required></label>
                    <label>&nbsp;<button type="submit">创建比赛</button></label>
                </div>
            </form>

            <h3 style="margin-top:18px;">🛠 管理员：未结算比赛</h3>
            <table class="sp-table">
                <tr><th>赛事</th><th>截止</th><th>押注(主/平/客)</th><th>录结果</th><th>操作</th></tr>
                <?php foreach ($adminMatches as $m) { ?>
                    <tr>
                        <td><?php echo htmlspecialchars($m['home_team'] . ' vs ' . $m['away_team']) ?><br><span class="sp-muted"><?php echo htmlspecialchars($m['league']) ?></span></td>
                        <td><?php echo htmlspecialchars($m['bet_deadline']) ?></td>
                        <td><?php echo game_sp_money($m['_totals']['home']) ?> / <?php echo game_sp_money($m['_totals']['draw']) ?> / <?php echo game_sp_money($m['_totals']['away']) ?></td>
                        <td>
                            <form method="post" action="/games/sports/" class="sp-inline">
                                <input type="hidden" name="action" value="settle_match">
                                <input type="hidden" name="match_id" value="<?php echo (int)$m['id'] ?>">
                                <select name="result">
                                    <option value="home">主胜</option>
                                    <option value="draw">平局</option>
                                    <option value="away">客胜</option>
                                </select>
                                <input type="number" name="home_score" min="0" max="99" style="width:48px" placeholder="主">
                                <input type="number" name="away_score" min="0" max="99" style="width:48px" placeholder="客">
                                <button type="submit" onclick="return confirm('确认结算并派彩？此操作不可撤销。');">结算</button>
                            </form>
                        </td>
                        <td>
                            <form method="post" action="/games/sports/" onsubmit="return confirm('确认取消该比赛并退还所有押注？');">
                                <input type="hidden" name="action" value="cancel_match">
                                <input type="hidden" name="match_id" value="<?php echo (int)$m['id'] ?>">
                                <button type="submit">取消/退款</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                <?php if (!$adminMatches) { ?><tr><td colspan="5" class="sp-muted">暂无未结算比赛。</td></tr><?php } ?>
            </table>
        </div>
    <?php } ?>
</div>
<?php
stdfoot();
