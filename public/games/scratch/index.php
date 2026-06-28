<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;
require_once "../../../include/game_control.php";
game_guard('scratch');
require_once "../../../include/game_leaderboard.php";

/**
 * 刮刮乐 — fixed cost per card (后台可设), reveal a configurable prize (电影票 /
 * 上传量 / 下载减免 / 实物卡类 / 谢谢惠顾) by weighted draw. The result is decided
 * server-side at purchase; the canvas coating is just a cosmetic scratch-off.
 * Prizes & cost are fully managed from the backend (刮刮乐奖品设置).
 */
const SC_BUSINESS_TYPE = 104; // 刮刮乐（历史记录为 13）
const SC_TABLE = 'hdvideo_scratch_records';
const SC_ITEM_TABLE = 'hdvideo_scratch_items';
const SC_CONFIG_TABLE = 'hdvideo_scratch_config';
const SC_DEFAULT_COST = 500;
const SC_GB = 1073741824;
// [name, reward_type(none|bonus|upload|download|item), amount, weight]
//   bonus -> 电影票数；upload/download -> GB；item/none -> 0
const SC_ITEMS_DEFAULT = [
    ['谢谢惠顾', 'none', 0, 520],
    ['500电影票', 'bonus', 500, 250],
    ['1000电影票', 'bonus', 1000, 130],
    ['2000电影票', 'bonus', 2000, 55],
    ['50G上传量', 'upload', 50, 35],
    ['改名卡', 'item', 0, 8],
    ['彩色昵称', 'item', 0, 2],
];

function sc_money($v)
{
    return number_format((float)$v, 1, '.', '');
}

function sc_ensure_tables()
{
    static $done = false;
    if ($done) return;
    // records (per-scratch history; powers the leaderboards)
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . SC_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `uid` int unsigned NOT NULL,
            `cost` int unsigned NOT NULL,
            `multiplier` int unsigned NOT NULL DEFAULT 0,
            `payout` decimal(20,1) NOT NULL DEFAULT '0.0',
            `delta` decimal(20,1) NOT NULL DEFAULT '0.0',
            `note` varchar(80) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_uid` (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // legacy records table may predate the note column. NexusDB::query() throws on
    // SQL errors (and @ does NOT suppress exceptions), so add the column only when it
    // is actually missing — a blind ALTER would throw "Duplicate column" on later loads.
    $hasNote = false;
    $colRes = sql_query("SHOW COLUMNS FROM `" . SC_TABLE . "` LIKE 'note'");
    if ($colRes && mysql_fetch_assoc($colRes)) {
        $hasNote = true;
    }
    if (!$hasNote) {
        sql_query("ALTER TABLE `" . SC_TABLE . "` ADD COLUMN `note` varchar(80) NOT NULL DEFAULT ''");
    }
    // configurable prize roster
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . SC_ITEM_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(60) NOT NULL DEFAULT '',
            `reward_type` varchar(20) NOT NULL DEFAULT 'none',
            `amount` bigint NOT NULL DEFAULT 0,
            `weight` int unsigned NOT NULL DEFAULT 1,
            `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
            `sort` int NOT NULL DEFAULT 0,
            `created_at` datetime DEFAULT NULL,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // key/value config (per-scratch cost, etc.)
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . SC_CONFIG_TABLE . "` (
            `name` varchar(40) NOT NULL,
            `value` varchar(255) NOT NULL DEFAULT '',
            PRIMARY KEY (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // remove the obsolete multiplier-based prize table from the previous design
    @sql_query("DROP TABLE IF EXISTS `hdvideo_scratch_prizes`");
    // seed defaults once
    $res = @sql_query("SELECT COUNT(*) AS c FROM `" . SC_ITEM_TABLE . "`");
    if ($res && (int)mysql_fetch_assoc($res)['c'] === 0) {
        $now = date('Y-m-d H:i:s');
        $i = 0;
        foreach (SC_ITEMS_DEFAULT as $it) {
            $i++;
            @sql_query(sprintf(
                "INSERT INTO `" . SC_ITEM_TABLE . "` (`name`,`reward_type`,`amount`,`weight`,`is_enabled`,`sort`,`created_at`,`updated_at`) VALUES (%s,%s,%d,%d,1,%d,%s,%s)",
                sqlesc($it[0]), sqlesc($it[1]), (int)$it[2], (int)$it[3], $i, sqlesc($now), sqlesc($now)
            ));
        }
    }
    $cfg = @sql_query("SELECT COUNT(*) AS c FROM `" . SC_CONFIG_TABLE . "` WHERE `name` = 'cost'");
    if ($cfg && (int)mysql_fetch_assoc($cfg)['c'] === 0) {
        @sql_query("INSERT INTO `" . SC_CONFIG_TABLE . "` (`name`,`value`) VALUES ('cost', '" . SC_DEFAULT_COST . "')");
    }
    $dl = @sql_query("SELECT COUNT(*) AS c FROM `" . SC_CONFIG_TABLE . "` WHERE `name` = 'daily_limit'");
    if ($dl && (int)mysql_fetch_assoc($dl)['c'] === 0) {
        @sql_query("INSERT INTO `" . SC_CONFIG_TABLE . "` (`name`,`value`) VALUES ('daily_limit', '0')");
    }
    $cd = @sql_query("SELECT COUNT(*) AS c FROM `" . SC_CONFIG_TABLE . "` WHERE `name` = 'cooldown'");
    if ($cd && (int)mysql_fetch_assoc($cd)['c'] === 0) {
        @sql_query("INSERT INTO `" . SC_CONFIG_TABLE . "` (`name`,`value`) VALUES ('cooldown', '2')");
    }
    $done = true;
}

function sc_cost()
{
    static $cost = null;
    if ($cost !== null) return $cost;
    sc_ensure_tables();
    $res = @sql_query("SELECT `value` FROM `" . SC_CONFIG_TABLE . "` WHERE `name` = 'cost' LIMIT 1");
    $row = $res ? mysql_fetch_assoc($res) : null;
    $cost = $row ? max(0, (int)$row['value']) : SC_DEFAULT_COST;
    return $cost;
}

/** Per-user daily scratch cap; 0 = unlimited. */
function sc_daily_limit()
{
    static $lim = null;
    if ($lim !== null) return $lim;
    sc_ensure_tables();
    $res = @sql_query("SELECT `value` FROM `" . SC_CONFIG_TABLE . "` WHERE `name` = 'daily_limit' LIMIT 1");
    $row = $res ? mysql_fetch_assoc($res) : null;
    $lim = $row ? max(0, (int)$row['value']) : 0;
    return $lim;
}

/** How many cards a user has scratched since today 00:00. */
function sc_today_count($uid)
{
    $today = date('Y-m-d') . ' 00:00:00';
    $res = sql_query("SELECT COUNT(*) AS c FROM `" . SC_TABLE . "` WHERE `uid` = " . (int)$uid . " AND `created_at` >= " . sqlesc($today)) or sqlerr(__FILE__, __LINE__);
    return (int)mysql_fetch_assoc($res)['c'];
}

/** Minimum seconds between two scratches; 0 = no cooldown. */
function sc_cooldown()
{
    static $cd = null;
    if ($cd !== null) return $cd;
    sc_ensure_tables();
    $res = @sql_query("SELECT `value` FROM `" . SC_CONFIG_TABLE . "` WHERE `name` = 'cooldown' LIMIT 1");
    $row = $res ? mysql_fetch_assoc($res) : null;
    $cd = $row ? max(0, (int)$row['value']) : 2;
    return $cd;
}

/** Seconds remaining before the user may scratch again (0 = ready). */
function sc_cooldown_left($uid)
{
    $cooldown = sc_cooldown();
    if ($cooldown <= 0) return 0;
    $res = sql_query("SELECT `created_at` FROM `" . SC_TABLE . "` WHERE `uid` = " . (int)$uid . " ORDER BY `id` DESC LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $row = mysql_fetch_assoc($res);
    if (!$row) return 0;
    $diff = time() - strtotime($row['created_at']);
    return $diff < $cooldown ? ($cooldown - $diff) : 0;
}

/** Enabled prizes as list of [name, reward_type, amount, weight]; falls back to defaults. */
function sc_items()
{
    static $cache = null;
    if ($cache !== null) return $cache;
    sc_ensure_tables();
    $rows = [];
    $res = @sql_query("SELECT `name`,`reward_type`,`amount`,`weight` FROM `" . SC_ITEM_TABLE . "` WHERE `is_enabled` = 1 AND `weight` > 0 ORDER BY `sort` ASC, `id` ASC");
    if ($res) {
        while ($r = mysql_fetch_assoc($res)) {
            $rows[] = [$r['name'], $r['reward_type'], (int)$r['amount'], (int)$r['weight']];
        }
    }
    $cache = $rows ?: SC_ITEMS_DEFAULT;
    return $cache;
}

function sc_pick_item()
{
    $items = sc_items();
    $total = 0;
    foreach ($items as $it) $total += $it[3];
    if ($total <= 0) return ['谢谢惠顾', 'none', 0, 1];
    $r = mt_rand(1, $total);
    foreach ($items as $it) {
        if ($r <= $it[3]) return $it;
        $r -= $it[3];
    }
    return $items[count($items) - 1];
}

function sc_send_pm($uid, $subject, $body)
{
    $now = date('Y-m-d H:i:s');
    @sql_query(sprintf(
        "INSERT INTO messages (sender, receiver, added, subject, msg) VALUES (0, %d, %s, %s, %s)",
        (int)$uid, sqlesc($now), sqlesc($subject), sqlesc($body)
    ));
}

/** Buy + draw a card. Returns [result|null, error]. */
function sc_buy()
{
    global $CURUSER;
    sc_ensure_tables();
    $cost = sc_cost();
    $uid = (int)$CURUSER['id'];
    $now = date('Y-m-d H:i:s');
    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $res = sql_query("SELECT `seedbonus`,`uploaded`,`downloaded` FROM `users` WHERE `id` = $uid FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $u = mysql_fetch_assoc($res);
        if (!$u) {
            sql_query("ROLLBACK");
            return [null, "用户不存在。"];
        }
        $oldBonus = (float)$u['seedbonus'];
        if ($oldBonus < $cost) {
            sql_query("ROLLBACK");
            return [null, "电影票不足，当前只有 " . sc_money($oldBonus) . " 张（每张需 $cost）。"];
        }
        $cdLeft = sc_cooldown_left($uid);
        if ($cdLeft > 0) {
            sql_query("ROLLBACK");
            return [null, "手速太快，请 {$cdLeft} 秒后再刮。"];
        }
        $limit = sc_daily_limit();
        if ($limit > 0 && sc_today_count($uid) >= $limit) {
            sql_query("ROLLBACK");
            return [null, "今天的刮卡次数已用完（每日上限 $limit 次），明天再来。"];
        }

        $item = sc_pick_item();
        [$name, $type, $amount] = [$item[0], $item[1], (int)$item[2]];

        // electric tickets always pay the cost; a bonus prize adds back on top.
        $bonusGain = ($type === 'bonus') ? $amount : 0;
        $net = $bonusGain - $cost;
        $newBonus = $oldBonus + $net;
        sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(sc_money($net)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
        sql_query(sprintf(
            "INSERT INTO bonus_logs (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
            SC_BUSINESS_TYPE, $uid, sqlesc(sc_money($oldBonus)), sqlesc(sc_money($net)), sqlesc(sc_money($newBonus)),
            sqlesc("[刮刮乐] 花费{$cost} 刮中{$name}"), sqlesc($now), sqlesc($now)
        )) or sqlerr(__FILE__, __LINE__);

        if ($type === 'bonus') {
            $rewardLabel = "电影票 +" . $amount;
        } elseif ($type === 'upload') {
            $bytes = (int)($amount * SC_GB);
            sql_query("UPDATE `users` SET `uploaded` = `uploaded` + $bytes WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            $rewardLabel = "上传量 +" . mksize($bytes);
            do_log("[刮刮乐] uid=$uid 刮中 {$name} 上传量+$bytes");
        } elseif ($type === 'download') {
            $down = (float)$u['downloaded'];
            $bytes = (int)min($down, $amount * SC_GB);
            sql_query("UPDATE `users` SET `downloaded` = `downloaded` - $bytes WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            $rewardLabel = "下载量减免 -" . mksize($bytes);
            do_log("[刮刮乐] uid=$uid 刮中 {$name} 下载量减免-$bytes");
        } elseif ($type === 'item') {
            $rewardLabel = $name . "（已记录，请等待管理员发放）";
            sc_send_pm($uid, "刮刮乐中奖：{$name}", "恭喜！你在刮刮乐中刮中了【{$name}】。该奖品需要管理员手动发放，请耐心等待或联系管理员。");
            do_log("[刮刮乐] uid=$uid 刮中实物奖品【{$name}】，需人工发放");
        } else {
            $rewardLabel = "谢谢惠顾";
        }

        sql_query(sprintf(
            "INSERT INTO `" . SC_TABLE . "` (`uid`,`cost`,`multiplier`,`payout`,`delta`,`note`,`created_at`) VALUES (%d,%d,0,%s,%s,%s,%s)",
            $uid, $cost, sqlesc(sc_money($bonusGain)), sqlesc(sc_money($net)), sqlesc($name), sqlesc($now)
        )) or sqlerr(__FILE__, __LINE__);

        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        clear_user_cache($uid);
        $CURUSER['seedbonus'] = $newBonus;
        return [[
            'name' => $name,
            'type' => $type,
            'reward' => $rewardLabel,
            'cost' => $cost,
            'delta' => $net,
            'balance' => $newBonus,
        ], ""];
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        throw $e;
    }
}

/** HTML of the three leaderboard cards (shared by initial render and AJAX refresh). */
function sc_leaderboards_html()
{
    $scNet = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, SUM(`s`.`delta`) AS amt FROM `" . SC_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
    $scNetLow = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, SUM(`s`.`delta`) AS amt FROM `" . SC_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt ASC LIMIT 10");
    $scCnt = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, COUNT(*) AS amt FROM `" . SC_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
    $scLuck = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, MAX(`s`.`payout`) AS amt, COUNT(*) AS cnt FROM `" . SC_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC, cnt DESC LIMIT 10");
    $html = game_lb_table('💰 盈亏榜', $scNet, '净盈亏',
        function ($r) { return ((float)$r['amt'] >= 0 ? '+' : '') . game_lb_money($r['amt']); },
        function ($r) { return (float)$r['amt'] >= 0 ? 'glb-pos' : 'glb-neg'; }, $scNetLow);
    $html .= game_lb_table('🔥 活跃榜', $scCnt, '刮奖次数',
        function ($r) { return number_format((int)$r['amt']) . ' 次'; });
    $html .= game_lb_table('🍀 手气榜', $scLuck, '最高单刮电影票',
        function ($r) { return game_lb_money($r['amt']); },
        function ($r) { return (float)$r['amt'] > 0 ? 'glb-pos' : ''; });
    return $html;
}

// ---- AJAX leaderboard refresh ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'board') {
    sc_ensure_tables();
    header('Content-Type: text/html; charset=utf-8');
    echo sc_leaderboards_html();
    exit;
}

// ---- AJAX buy ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buy') {
    header('Content-Type: application/json');
    [$r, $err] = sc_buy();
    if ($err !== '') {
        echo json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
    exit;
}

sc_ensure_tables();
$cost = sc_cost();
$cooldown = sc_cooldown();
$cooldownLeft = sc_cooldown_left((int)$CURUSER['id']);
$dailyLimit = sc_daily_limit();
$todayLeft = $dailyLimit > 0 ? max(0, $dailyLimit - sc_today_count((int)$CURUSER['id'])) : -1;
$myRes = sql_query("SELECT * FROM `" . SC_TABLE . "` WHERE `uid` = " . (int)$CURUSER['id'] . " ORDER BY `id` DESC LIMIT 12") or sqlerr(__FILE__, __LINE__);
$sumRes = sql_query("SELECT COUNT(*) AS n, SUM(`delta`) AS net FROM `" . SC_TABLE . "` WHERE `uid` = " . (int)$CURUSER['id']) or sqlerr(__FILE__, __LINE__);
$sum = mysql_fetch_assoc($sumRes);

if (empty($_GET['pc']) && preg_match('/Mobile|Android|iPhone|iPod|Windows Phone|BlackBerry|webOS|HarmonyOS/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) { require __DIR__ . '/mobile.php'; exit; }

stdhead("刮刮乐");
echo game_back_link();
?>
<style>
.sc-wrap { max-width: 760px; margin: 0 auto; }
.sc-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.sc-title { font-size: 24px; font-weight: 800; }
.sc-badge { font-size: 12px; font-weight: 700; color: #e67e22; background: rgba(230,126,34,.12); padding: 2px 8px; border-radius: 999px; vertical-align: middle; }
.sc-balance { font-size: 14px; font-weight: 700; }
.sc-muted { color: #6f7f95; }
.sc-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 16px; margin-bottom: 14px; background: rgba(30,60,100,.06); }
.sc-stage { position: relative; width: 320px; max-width: 100%; height: 150px; margin: 4px auto 0; border-radius: 12px; overflow: hidden; box-shadow: inset 0 2px 8px rgba(0,0,0,.18); }
.sc-prize { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 8px; background: linear-gradient(135deg,#fff7e6,#ffe6c0); color: #1b2b3a; }
.sc-prize .pz-name { font-size: 24px; font-weight: 900; }
.sc-prize .pz-reward { margin-top: 6px; font-size: 14px; font-weight: 700; }
.sc-prize.win .pz-name { color: #0f7a42; }
.sc-prize.lose .pz-name { color: #7a8794; }
.sc-canvas { position: absolute; inset: 0; touch-action: none; cursor: grab; }
.sc-canvas:active { cursor: grabbing; }
.sc-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: center; margin-top: 14px; }
.sc-btn { padding: 10px 22px; font-weight: 800; cursor: pointer; border-radius: 6px; border: 1px solid #e67e22; background: #e67e22; color: #fff; }
.sc-btn:disabled { opacity: .5; cursor: not-allowed; }
.sc-cost { font-weight: 700; }
.sc-hint { text-align: center; margin-top: 8px; color: #6f7f95; font-size: 13px; }
.sc-msg { margin-top: 10px; font-weight: 800; text-align: center; }
.sc-table { width: 100%; border-collapse: collapse; }
.sc-table th, .sc-table td { padding: 8px; border: 1px solid rgba(120,150,190,.26); text-align: center; }
.sc-pos { color: #16a34a; font-weight: 700; }
.sc-neg { color: #dc2626; font-weight: 700; }
.sc-odds { display: flex; flex-wrap: wrap; gap: 6px; }
.sc-odds span { padding: 4px 8px; border-radius: 6px; background: rgba(0,0,0,.05); font-size: 13px; }
</style>
<div class="sc-wrap">
    <div class="sc-head">
        <div>
            <div class="sc-title">刮刮乐 <span class="sc-badge">内测中 v0.7</span></div>
            <div class="sc-muted">每张 <b class="sc-cost"><?php echo (int)$cost ?></b> 电影票，买一张用鼠标刮开涂层，刮中即得。<?php if ($dailyLimit > 0) { ?> <span style="color:#e67e22;font-weight:700">今日剩余 <span id="scLeft"><?php echo (int)$todayLeft ?></span> 次</span>（每日上限 <?php echo (int)$dailyLimit ?>）<?php } ?></div>
        </div>
        <div class="sc-balance">我的电影票：<b id="scBal"><?php echo sc_money($CURUSER['seedbonus']) ?></b> 张</div>
    </div>

    <div class="sc-panel">
        <div class="sc-stage" id="scStage">
            <div class="sc-prize" id="scPrize">
                <div class="pz-name">刮刮乐</div>
                <div class="pz-reward">点击下方「买一张」开始</div>
            </div>
            <canvas class="sc-canvas" id="scCanvas" width="320" height="150" style="display:none"></canvas>
        </div>
        <div class="sc-hint" id="scHint">买一张后，按住鼠标在卡片上来回涂抹即可刮开</div>
        <div class="sc-actions">
            <?php $exhausted = ($dailyLimit > 0 && $todayLeft <= 0); ?>
            <button type="button" class="sc-btn" id="scBuy"<?php echo $exhausted ? ' disabled' : '' ?>><?php echo $exhausted ? '今日次数已用完' : '买一张（' . (int)$cost . ' 电影票）' ?></button>
        </div>
        <div class="sc-msg" id="scMsg"></div>
        <div class="sc-odds" style="margin-top:12px">
            <?php
            $scItems = sc_items();
            $scTotal = 0;
            foreach ($scItems as $it) $scTotal += $it[3];
            foreach ($scItems as $it) {
                $pct = $scTotal > 0 ? round($it[3] / $scTotal * 100, 2) : 0;
            ?><span><?php echo htmlspecialchars($it[0]) ?>：<?php echo $pct ?>%</span><?php } ?>
        </div>
    </div>

    <div class="sc-panel" id="scMine">
        <h3 style="margin:0 0 10px">我的最近刮奖（共 <?php echo (int)($sum['n'] ?? 0) ?> 次，电影票净 <span class="<?php echo (float)($sum['net'] ?? 0) >= 0 ? 'sc-pos' : 'sc-neg' ?>"><?php echo ((float)($sum['net'] ?? 0) >= 0 ? '+' : '') . number_format((float)($sum['net'] ?? 0), 0) ?></span>）</h3>
        <table class="sc-table">
            <tr><th>时间</th><th>花费</th><th>刮中</th><th>电影票盈亏</th></tr>
            <tbody id="scRows">
            <?php while ($r = mysql_fetch_assoc($myRes)) { ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['created_at']) ?></td>
                    <td><?php echo (int)$r['cost'] ?></td>
                    <td><?php echo htmlspecialchars($r['note'] !== '' ? $r['note'] : '—') ?></td>
                    <td class="<?php echo (float)$r['delta'] >= 0 ? 'sc-pos' : 'sc-neg' ?>"><?php echo ((float)$r['delta'] >= 0 ? '+' : '') . sc_money($r['delta']) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>

    <?php echo game_lb_css(); ?>
    <div class="sc-panel">
        <h3 style="margin:0 0 12px">🏆 刮刮乐榜单</h3>
        <div class="glb-grid" id="scBoardGrid"><?php echo sc_leaderboards_html() ?></div>
    </div>
</div>
<script>
(function () {
    var busy = false, pending = null, revealed = true;
    var stage = document.getElementById('scStage');
    var prize = document.getElementById('scPrize');
    var canvas = document.getElementById('scCanvas');
    var hint = document.getElementById('scHint');
    var msg = document.getElementById('scMsg');
    var buyBtn = document.getElementById('scBuy');
    var ctx = canvas.getContext('2d');
    var scratching = false, lastX = 0, lastY = 0;
    var COOLDOWN = <?php echo (int)$cooldown ?>;
    var BUY_LABEL = '买一张（<?php echo (int)$cost ?> 电影票）';
    var exhausted = <?php echo ($dailyLimit > 0 && $todayLeft <= 0) ? 'true' : 'false' ?>;
    var lastBuyTs = 0, cdTimer = null;

    function armBuy() {
        if (cdTimer) { clearTimeout(cdTimer); cdTimer = null; }
        if (exhausted) { buyBtn.disabled = true; buyBtn.textContent = '今日次数已用完'; return; }
        var remain = COOLDOWN * 1000 - (Date.now() - lastBuyTs);
        if (remain <= 0) { buyBtn.disabled = false; buyBtn.textContent = BUY_LABEL; return; }
        buyBtn.disabled = true;
        (function tick() {
            var r = Math.ceil((COOLDOWN * 1000 - (Date.now() - lastBuyTs)) / 1000);
            if (r <= 0) { buyBtn.disabled = false; buyBtn.textContent = BUY_LABEL; cdTimer = null; }
            else { buyBtn.textContent = '等待 ' + r + ' 秒'; cdTimer = setTimeout(tick, 250); }
        })();
    }

    function paintCoating() {
        ctx.globalCompositeOperation = 'source-over';
        var g = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
        g.addColorStop(0, '#c7ccd4'); g.addColorStop(0.5, '#aeb4bd'); g.addColorStop(1, '#c7ccd4');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = 'rgba(80,90,105,.9)';
        ctx.font = 'bold 20px sans-serif';
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillText('刮开涂层', canvas.width / 2, canvas.height / 2);
    }

    function pos(e) {
        var rect = canvas.getBoundingClientRect();
        var t = e.touches ? e.touches[0] : e;
        return {
            x: (t.clientX - rect.left) * (canvas.width / rect.width),
            y: (t.clientY - rect.top) * (canvas.height / rect.height)
        };
    }

    function scratchTo(p) {
        ctx.globalCompositeOperation = 'destination-out';
        ctx.lineWidth = 38; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
        ctx.beginPath();
        ctx.moveTo(lastX, lastY);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        ctx.beginPath();
        ctx.arc(p.x, p.y, 19, 0, Math.PI * 2);
        ctx.fill();
        lastX = p.x; lastY = p.y;
    }

    function clearedPercent() {
        var img = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
        var cleared = 0, step = 16; // sample every 4th pixel (RGBA*4)
        for (var i = 3; i < img.length; i += step) { if (img[i] === 0) cleared++; }
        return cleared / (img.length / step);
    }

    function finishReveal() {
        if (revealed) return;
        revealed = true;
        ctx.globalCompositeOperation = 'destination-out';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        canvas.style.display = 'none';
        if (pending) {
            document.getElementById('scBal').textContent = (Math.round(pending.balance * 10) / 10).toFixed(1);
            var tb = document.getElementById('scRows');
            var tr = document.createElement('tr');
            var cls = pending.delta >= 0 ? 'sc-pos' : 'sc-neg';
            tr.innerHTML = '<td>刚刚</td><td>' + pending.cost + '</td><td>' + pending.name + '</td><td class="' + cls + '">' + (pending.delta >= 0 ? '+' : '') + Math.round(pending.delta) + '</td>';
            tb.insertBefore(tr, tb.firstChild);
            msg.innerHTML = '<span class="' + (pending.delta >= 0 ? 'sc-pos' : 'sc-neg') + '">🎉 ' + pending.reward + '</span>';
        }
        hint.textContent = '再来一张试试手气';
        pending = null;
        armBuy();
        refreshBoard();
    }

    function refreshBoard() {
        fetch(location.href, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                ['scMine', 'scBoardGrid'].forEach(function (id) {
                    var f = doc.getElementById(id), c = document.getElementById(id);
                    if (f && c) c.innerHTML = f.innerHTML;
                });
            }).catch(function () {});
    }

    function onMove(e) {
        if (!scratching || revealed) return;
        e.preventDefault();
        scratchTo(pos(e));
        if (clearedPercent() > 0.5) finishReveal();
    }
    function onDown(e) { if (revealed) return; scratching = true; var p = pos(e); lastX = p.x; lastY = p.y; scratchTo(p); }
    function onUp() { scratching = false; if (!revealed && clearedPercent() > 0.5) finishReveal(); }

    canvas.addEventListener('mousedown', onDown);
    canvas.addEventListener('mousemove', onMove);
    window.addEventListener('mouseup', onUp);
    canvas.addEventListener('touchstart', onDown, { passive: false });
    canvas.addEventListener('touchmove', onMove, { passive: false });
    canvas.addEventListener('touchend', onUp);

    buyBtn.addEventListener('click', function () {
        if (busy || !revealed) { return; }
        busy = true; buyBtn.disabled = true; msg.textContent = '';
        fetch('/games/scratch/', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=buy' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { msg.innerHTML = '<span class="sc-neg">' + (d.error || '出错了') + '</span>'; busy = false; armBuy(); return; }
                pending = d;
                lastBuyTs = Date.now();
                var win = d.type !== 'none';
                prize.className = 'sc-prize ' + (win ? 'win' : 'lose');
                prize.innerHTML = '<div class="pz-name">' + d.name + '</div><div class="pz-reward">' + d.reward + '</div>';
                revealed = false;
                canvas.style.display = 'block';
                paintCoating();
                hint.textContent = '按住鼠标在卡片上来回涂抹刮开';
                var leftEl = document.getElementById('scLeft');
                if (leftEl) {
                    var left = Math.max(0, (parseInt(leftEl.textContent, 10) || 0) - 1);
                    leftEl.textContent = left;
                    if (left <= 0) { exhausted = true; }
                }
                busy = false;
            })
            .catch(function () { msg.innerHTML = '<span class="sc-neg">网络错误</span>'; busy = false; armBuy(); });
    });

    // initial cooldown / exhausted state on load
    if (exhausted) { armBuy(); }
    else if (<?php echo (int)$cooldownLeft ?> > 0) { lastBuyTs = Date.now() - (COOLDOWN - <?php echo (int)$cooldownLeft ?>) * 1000; armBuy(); }
})();
</script>
<?php
stdfoot();
