<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;
require_once "../../../include/game_control.php";
game_guard('scratch');

/**
 * 刮刮乐 — instant scratch card. Pay a cost in 电影票, reveal a payout = cost ×
 * a weighted-random multiplier. House-favored (EV < 1). Self-managed records table.
 */
const SC_BUSINESS_TYPE = 13;
const SC_TABLE = 'hdvideo_scratch_records';
const SC_COST_OPTIONS = [100, 500, 1000, 5000];
const SC_MAX_COST = 10000000000;
// [multiplier(total return), weight]; weights sum = 1000
const SC_PRIZES = [
    [0, 600],   // 谢谢惠顾
    [1, 220],   // 回本
    [2, 100],
    [3, 50],
    [5, 20],
    [10, 8],
    [88, 2],    // 大奖
];

function sc_ensure_table()
{
    static $done = false;
    if ($done) return;
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . SC_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `uid` int unsigned NOT NULL,
            `cost` int unsigned NOT NULL,
            `multiplier` int unsigned NOT NULL,
            `payout` decimal(20,1) NOT NULL DEFAULT '0.0',
            `delta` decimal(20,1) NOT NULL DEFAULT '0.0',
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_uid` (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function sc_money($v)
{
    return number_format((float)$v, 1, '.', '');
}

function sc_pick_multiplier()
{
    $total = 0;
    foreach (SC_PRIZES as $p) $total += $p[1];
    $r = mt_rand(1, $total);
    foreach (SC_PRIZES as $p) {
        if ($r <= $p[1]) return (int)$p[0];
        $r -= $p[1];
    }
    return 0;
}

function sc_scratch($cost)
{
    global $CURUSER;
    $cost = (int)$cost;
    if (!in_array($cost, SC_COST_OPTIONS, true)) {
        return [null, "面额无效。"];
    }
    sc_ensure_table();
    $uid = (int)$CURUSER['id'];
    $now = date('Y-m-d H:i:s');
    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $res = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = $uid FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $row = mysql_fetch_assoc($res);
        if (!$row) {
            sql_query("ROLLBACK");
            return [null, "用户不存在。"];
        }
        $oldBonus = (float)$row['seedbonus'];
        if ($oldBonus < $cost) {
            sql_query("ROLLBACK");
            return [null, "电影票不足，当前只有 " . sc_money($oldBonus) . " 张。"];
        }
        $mult = sc_pick_multiplier();
        $payout = $cost * $mult;
        $delta = $payout - $cost;
        $newBonus = $oldBonus + $delta;
        sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(sc_money($delta)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
        $comment = "[刮刮乐] 面额{$cost} 刮中{$mult}倍，" . ($delta >= 0 ? '盈' : '亏') . abs($delta);
        sql_query(sprintf(
            "INSERT INTO bonus_logs (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
            SC_BUSINESS_TYPE, $uid, sqlesc(sc_money($oldBonus)), sqlesc(sc_money($delta)), sqlesc(sc_money($newBonus)), sqlesc($comment), sqlesc($now), sqlesc($now)
        )) or sqlerr(__FILE__, __LINE__);
        sql_query(sprintf(
            "INSERT INTO `" . SC_TABLE . "` (`uid`,`cost`,`multiplier`,`payout`,`delta`,`created_at`) VALUES (%d,%d,%d,%s,%s,%s)",
            $uid, $cost, $mult, sqlesc(sc_money($payout)), sqlesc(sc_money($delta)), sqlesc($now)
        )) or sqlerr(__FILE__, __LINE__);
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        clear_user_cache($uid);
        $CURUSER['seedbonus'] = $newBonus;
        return [['mult' => $mult, 'payout' => $payout, 'delta' => $delta, 'balance' => $newBonus], ""];
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        throw $e;
    }
}

// ---- AJAX scratch ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'scratch') {
    header('Content-Type: application/json');
    $amountRaw = trim((string)($_POST['cost'] ?? ''));
    if (!preg_match('/^[1-9][0-9]*$/', $amountRaw)) {
        echo json_encode(['ok' => false, 'error' => '面额无效。']);
        exit;
    }
    [$r, $err] = sc_scratch((int)$amountRaw);
    if ($err !== '') {
        echo json_encode(['ok' => false, 'error' => $err]);
        exit;
    }
    echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
    exit;
}

sc_ensure_table();
$myRes = sql_query("SELECT * FROM `" . SC_TABLE . "` WHERE `uid` = " . (int)$CURUSER['id'] . " ORDER BY `id` DESC LIMIT 12") or sqlerr(__FILE__, __LINE__);
$sumRes = sql_query("SELECT COUNT(*) AS n, SUM(`delta`) AS net FROM `" . SC_TABLE . "` WHERE `uid` = " . (int)$CURUSER['id']) or sqlerr(__FILE__, __LINE__);
$sum = mysql_fetch_assoc($sumRes);

stdhead("刮刮乐");
?>
<style>
.sc-wrap { max-width: 760px; margin: 0 auto; }
.sc-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.sc-title { font-size: 24px; font-weight: 800; }
.sc-badge { font-size: 12px; font-weight: 700; color: #e67e22; background: rgba(230,126,34,.12); padding: 2px 8px; border-radius: 999px; vertical-align: middle; }
.sc-balance { font-size: 14px; font-weight: 700; }
.sc-muted { color: #6f7f95; }
.sc-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 16px; margin-bottom: 14px; background: rgba(30,60,100,.06); }
.sc-card { height: 140px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 30px; font-weight: 900; color: #fff; background: linear-gradient(135deg,#c0a060,#8a6d2f); box-shadow: inset 0 2px 6px rgba(255,255,255,.3); transition: background .25s ease; }
.sc-card.win { background: linear-gradient(135deg,#2ecc71,#0f7a42); }
.sc-card.lose { background: linear-gradient(135deg,#95a5a6,#5d6d6e); }
.sc-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 12px; }
.sc-chip { padding: 8px 14px; border: 1px solid rgba(120,150,190,.45); border-radius: 6px; cursor: pointer; font-weight: 700; background: rgba(255,255,255,.55); }
.sc-chip.sel { background: #2ecc71; color: #fff; border-color: #2ecc71; }
.sc-btn { padding: 9px 20px; font-weight: 800; cursor: pointer; border-radius: 6px; border: 1px solid #e67e22; background: #e67e22; color: #fff; }
.sc-btn:disabled { opacity: .5; cursor: not-allowed; }
.sc-msg { margin-top: 10px; font-weight: 800; }
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
            <div class="sc-title">刮刮乐 <span class="sc-badge">内测中 v0.1</span></div>
            <div class="sc-muted">花电影票刮一张，即时开奖。刮中倍数 × 面额即为返还。</div>
        </div>
        <div class="sc-balance">我的电影票：<b id="scBal"><?php echo sc_money($CURUSER['seedbonus']) ?></b> 张</div>
    </div>

    <div class="sc-panel">
        <div class="sc-card" id="scCard">刮一张试试 🎫</div>
        <div class="sc-form">
            <span class="sc-muted">面额：</span>
            <?php foreach (SC_COST_OPTIONS as $i => $c) { ?>
                <span class="sc-chip<?php echo $i === 0 ? ' sel' : '' ?>" data-cost="<?php echo $c ?>"><?php echo $c ?></span>
            <?php } ?>
            <button type="button" class="sc-btn" id="scBtn">刮一张</button>
        </div>
        <div class="sc-msg" id="scMsg"></div>
        <div class="sc-odds" style="margin-top:12px">
            <?php foreach (SC_PRIZES as $p) {
                $label = $p[0] == 0 ? '谢谢惠顾' : ($p[0] == 1 ? '回本(1倍)' : $p[0] . '倍');
                $pct = round($p[1] / 10, 1);
            ?><span><?php echo $label ?>：<?php echo $pct ?>%</span><?php } ?>
        </div>
    </div>

    <div class="sc-panel">
        <h3 style="margin:0 0 10px">我的最近刮奖（共 <?php echo (int)($sum['n'] ?? 0) ?> 次，净 <span class="<?php echo (float)($sum['net'] ?? 0) >= 0 ? 'sc-pos' : 'sc-neg' ?>"><?php echo ((float)($sum['net'] ?? 0) >= 0 ? '+' : '') . number_format((float)($sum['net'] ?? 0), 0) ?></span>）</h3>
        <table class="sc-table">
            <tr><th>时间</th><th>面额</th><th>倍数</th><th>返还</th><th>盈亏</th></tr>
            <tbody id="scRows">
            <?php while ($r = mysql_fetch_assoc($myRes)) { ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['created_at']) ?></td>
                    <td><?php echo (int)$r['cost'] ?></td>
                    <td><?php echo (int)$r['multiplier'] ?> 倍</td>
                    <td><?php echo sc_money($r['payout']) ?></td>
                    <td class="<?php echo (float)$r['delta'] >= 0 ? 'sc-pos' : 'sc-neg' ?>"><?php echo ((float)$r['delta'] >= 0 ? '+' : '') . sc_money($r['delta']) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<script>
(function () {
    var cost = <?php echo (int)SC_COST_OPTIONS[0] ?>, busy = false;
    var card = document.getElementById('scCard'), msg = document.getElementById('scMsg'), btn = document.getElementById('scBtn');
    document.querySelectorAll('.sc-chip').forEach(function (c) {
        c.addEventListener('click', function () {
            document.querySelectorAll('.sc-chip').forEach(function (x) { x.classList.remove('sel'); });
            c.classList.add('sel'); cost = parseInt(c.getAttribute('data-cost'), 10);
        });
    });
    btn.addEventListener('click', function () {
        if (busy) { return; }
        busy = true; btn.disabled = true; card.className = 'sc-card'; card.textContent = '刮开中…'; msg.textContent = '';
        fetch('/games/scratch/', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=scratch&cost=' + cost })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { msg.textContent = d.error || '出错了'; card.className = 'sc-card'; card.textContent = '🎫'; return; }
                var win = d.mult >= 1 && d.delta >= 0;
                card.className = 'sc-card ' + (d.mult >= 2 ? 'win' : (d.mult === 0 ? 'lose' : ''));
                card.textContent = d.mult === 0 ? '谢谢惠顾' : (d.mult + ' 倍');
                msg.innerHTML = (d.delta >= 0 ? '<span class="sc-pos">' : '<span class="sc-neg">') + '返还 ' + Math.round(d.payout) + '（' + (d.delta >= 0 ? '盈 +' : '亏 ') + Math.round(d.delta) + '）</span>';
                document.getElementById('scBal').textContent = (Math.round(d.balance * 10) / 10).toFixed(1);
                var tb = document.getElementById('scRows');
                var tr = document.createElement('tr');
                tr.innerHTML = '<td>刚刚</td><td>' + cost + '</td><td>' + d.mult + ' 倍</td><td>' + Math.round(d.payout) + '</td><td class="' + (d.delta >= 0 ? 'sc-pos' : 'sc-neg') + '">' + (d.delta >= 0 ? '+' : '') + Math.round(d.delta) + '</td>';
                tb.insertBefore(tr, tb.firstChild);
            })
            .catch(function () { msg.textContent = '网络错误'; })
            .finally(function () { busy = false; btn.disabled = false; });
    });
})();
</script>
<?php
stdfoot();
