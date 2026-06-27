<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;
require_once "../../../include/game_control.php";
game_guard('plinko');
require_once "../../../include/game_leaderboard.php";

/**
 * Plinko 弹珠 — drop a ball through 8 rows of pegs; it lands in one of 9 slots with a
 * multiplier. The path & landing slot are decided server-side (binomial); the client
 * just animates the drop. Edge slots pay big, center pays under 1×.
 */
const PK_BUSINESS_TYPE = 13;
const PK_TABLE = 'hdvideo_plinko_records';
const PK_ROWS = 8;
const PK_CHIPS = [100, 500, 1000, 5000, 10000];
const PK_MULTS = [9, 2.5, 1.1, 0.6, 0.4, 0.6, 1.1, 2.5, 9];

function pk_money($v) { return number_format((float)$v, 1, '.', ''); }

function pk_ensure_table()
{
    static $done = false;
    if ($done) return;
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . PK_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `uid` int unsigned NOT NULL,
            `bet` bigint NOT NULL DEFAULT 0,
            `mult` decimal(8,2) NOT NULL DEFAULT '0.00',
            `delta` decimal(20,1) NOT NULL DEFAULT '0.0',
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_uid` (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function pk_drop($bet)
{
    global $CURUSER;
    $bet = (int)$bet;
    if ($bet < 1) return [null, '请输入有效的下注。'];
    pk_ensure_table();
    $uid = (int)$CURUSER['id'];
    $now = date('Y-m-d H:i:s');
    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $res = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = $uid FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $row = mysql_fetch_assoc($res);
        if (!$row) { sql_query("ROLLBACK"); return [null, '用户不存在。']; }
        $old = (float)$row['seedbonus'];
        if ($old < $bet) { sql_query("ROLLBACK"); return [null, '电影票不足，当前 ' . pk_money($old) . ' 张。']; }

        $path = []; $pos = 0;
        for ($i = 0; $i < PK_ROWS; $i++) { $b = mt_rand(0, 1); $path[] = $b; $pos += $b; }
        $mult = (float)PK_MULTS[$pos];
        $payout = round($bet * $mult, 1);
        $net = $payout - $bet;
        $new = $old + $net;
        sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(pk_money($net)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
        sql_query(sprintf(
            "INSERT INTO bonus_logs (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
            PK_BUSINESS_TYPE, $uid, sqlesc(pk_money($old)), sqlesc(pk_money($net)), sqlesc(pk_money($new)),
            sqlesc("[Plinko] 落点{$pos} ×{$mult}"), sqlesc($now), sqlesc($now)
        )) or sqlerr(__FILE__, __LINE__);
        sql_query(sprintf(
            "INSERT INTO `" . PK_TABLE . "` (`uid`,`bet`,`mult`,`delta`,`created_at`) VALUES (%d,%d,%s,%s,%s)",
            $uid, $bet, sqlesc(number_format($mult, 2, '.', '')), sqlesc(pk_money($net)), sqlesc($now)
        )) or sqlerr(__FILE__, __LINE__);
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        clear_user_cache($uid);
        $CURUSER['seedbonus'] = $new;
        return [['path' => $path, 'pos' => $pos, 'mult' => $mult, 'payout' => $payout, 'delta' => $net, 'balance' => $new], ''];
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'drop') {
    header('Content-Type: application/json');
    [$r, $err] = pk_drop((int)($_POST['bet'] ?? 0));
    echo json_encode($err !== '' ? ['ok' => false, 'error' => $err] : (['ok' => true] + $r), JSON_UNESCAPED_UNICODE);
    exit;
}

pk_ensure_table();
$myRes = sql_query("SELECT * FROM `" . PK_TABLE . "` WHERE `uid` = " . (int)$CURUSER['id'] . " ORDER BY `id` DESC LIMIT 10") or sqlerr(__FILE__, __LINE__);
$sumRes = sql_query("SELECT COUNT(*) AS n, SUM(`delta`) AS net FROM `" . PK_TABLE . "` WHERE `uid` = " . (int)$CURUSER['id']) or sqlerr(__FILE__, __LINE__);
$sum = mysql_fetch_assoc($sumRes);

stdhead("Plinko 弹珠");
echo game_back_link();
?>
<style>
.pk-wrap { max-width: 720px; margin: 0 auto; }
.pk-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.pk-title { font-size: 24px; font-weight: 800; }
.pk-badge { font-size: 12px; font-weight: 700; color: #2980b9; background: rgba(41,128,185,.14); padding: 2px 8px; border-radius: 999px; vertical-align: middle; }
.pk-balance { font-size: 14px; font-weight: 700; }
.pk-muted { color: #6f7f95; }
.pk-stage { border: 1px solid rgba(120,150,190,.34); border-radius: 12px; padding: 16px; margin-bottom: 14px; background: linear-gradient(135deg,#10243a,#08131f); }
.pk-board { position: relative; width: 100%; max-width: 360px; height: 300px; margin: 0 auto; }
.pk-pegs { position: absolute; inset: 0; display: flex; flex-direction: column; justify-content: space-between; padding: 16px 0; }
.pk-prow { display: flex; justify-content: center; gap: 22px; }
.pk-peg { width: 7px; height: 7px; border-radius: 50%; background: rgba(255,255,255,.65); }
.pk-ball { position: absolute; top: 0; left: 50%; width: 16px; height: 16px; margin-left: -8px; border-radius: 50%; background: radial-gradient(circle at 35% 30%, #fff, #ffcf3f 60%, #e08a00); box-shadow: 0 0 8px rgba(255,200,60,.8); }
.pk-slots { display: flex; gap: 4px; max-width: 360px; margin: 8px auto 0; }
.pk-slot { flex: 1; text-align: center; padding: 6px 2px; border-radius: 5px; font-size: 12px; font-weight: 800; color: #fff; }
.pk-slot.hit { outline: 3px solid #fff; transform: translateY(-3px); }
.pk-c-hot { background: #c0392b; } .pk-c-warm { background: #e67e22; } .pk-c-mid { background: #2e8b57; } .pk-c-cold { background: #2c6fb0; }
.pk-result { text-align: center; min-height: 26px; margin: 12px 0 4px; font-size: 18px; font-weight: 900; color: #ffd770; }
.pk-controls { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: center; }
.pk-chip { padding: 7px 12px; border: 1px solid rgba(120,150,190,.45); border-radius: 6px; cursor: pointer; font-weight: 700; background: rgba(255,255,255,.6); }
.pk-chip.sel { background: #2980b9; color: #fff; border-color: #2980b9; }
.pk-bet { width: 110px; padding: 8px; border: 1px solid rgba(120,150,190,.45); border-radius: 6px; }
.pk-btn { padding: 10px 22px; font-weight: 800; cursor: pointer; border-radius: 6px; border: 1px solid #2980b9; background: #2980b9; color: #fff; }
.pk-btn:disabled { opacity: .5; cursor: not-allowed; }
.pk-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 16px; margin-bottom: 14px; background: rgba(30,60,100,.06); }
.pk-tbl { width: 100%; border-collapse: collapse; }
.pk-tbl th, .pk-tbl td { padding: 8px; border: 1px solid rgba(120,150,190,.26); text-align: center; }
.pk-pos { color: #16a34a; font-weight: 700; } .pk-neg { color: #dc2626; font-weight: 700; }
</style>
<div class="pk-wrap">
    <div class="pk-head">
        <div>
            <div class="pk-title">Plinko 弹珠 <span class="pk-badge">内测中 v0.1</span></div>
            <div class="pk-muted">投入电影票放下小球，落到不同格子按倍数派彩，越靠边倍数越高。</div>
        </div>
        <div class="pk-balance">我的电影票：<b id="pkBal"><?php echo pk_money($CURUSER['seedbonus']) ?></b> 张</div>
    </div>

    <div class="pk-stage">
        <div class="pk-board" id="pkBoard">
            <div class="pk-pegs">
                <?php for ($r = 0; $r < PK_ROWS; $r++) { ?>
                    <div class="pk-prow"><?php for ($p = 0; $p <= $r + 1; $p++) { ?><span class="pk-peg"></span><?php } ?></div>
                <?php } ?>
            </div>
            <div class="pk-ball" id="pkBall" style="display:none"></div>
        </div>
        <div class="pk-slots" id="pkSlots">
            <?php foreach (PK_MULTS as $i => $mv) {
                $cls = $mv >= 5 ? 'pk-c-hot' : ($mv >= 2 ? 'pk-c-warm' : ($mv >= 1 ? 'pk-c-mid' : 'pk-c-cold'));
            ?><div class="pk-slot <?php echo $cls ?>" data-i="<?php echo $i ?>"><?php echo rtrim(rtrim(number_format($mv, 1), '0'), '.') ?>×</div><?php } ?>
        </div>
        <div class="pk-result" id="pkResult"></div>
        <div class="pk-controls">
            <span class="pk-muted" style="color:#9fd0f5">下注</span>
            <?php foreach (PK_CHIPS as $i => $c) { ?>
                <span class="pk-chip<?php echo $i === 0 ? ' sel' : '' ?>" data-bet="<?php echo $c ?>"><?php echo number_format($c) ?></span>
            <?php } ?>
            <input type="number" min="1" class="pk-bet" id="pkBet" value="<?php echo PK_CHIPS[0] ?>">
            <button type="button" class="pk-btn" id="pkDrop">放球</button>
        </div>
    </div>

    <div class="pk-panel">
        <h3 style="margin:0 0 10px">我的最近战绩（共 <?php echo (int)($sum['n'] ?? 0) ?> 次，净 <span class="<?php echo (float)($sum['net'] ?? 0) >= 0 ? 'pk-pos' : 'pk-neg' ?>"><?php echo ((float)($sum['net'] ?? 0) >= 0 ? '+' : '') . number_format((float)($sum['net'] ?? 0), 0) ?></span>）</h3>
        <table class="pk-tbl">
            <tr><th>时间</th><th>下注</th><th>倍数</th><th>盈亏</th></tr>
            <tbody id="pkRows">
            <?php while ($r = mysql_fetch_assoc($myRes)) { ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['created_at']) ?></td>
                    <td><?php echo (int)$r['bet'] ?></td>
                    <td><?php echo rtrim(rtrim(number_format($r['mult'], 2), '0'), '.') ?>×</td>
                    <td class="<?php echo (float)$r['delta'] >= 0 ? 'pk-pos' : 'pk-neg' ?>"><?php echo ((float)$r['delta'] >= 0 ? '+' : '') . pk_money($r['delta']) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>

    <?php
    $pkNet = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, SUM(`s`.`delta`) AS amt FROM `" . PK_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
    $pkCnt = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, COUNT(*) AS amt FROM `" . PK_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
    $pkLuck = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, MAX(`s`.`delta`) AS amt, COUNT(*) AS cnt FROM `" . PK_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC, cnt DESC LIMIT 10");
    echo game_lb_css();
    ?>
    <div class="pk-panel">
        <h3 style="margin:0 0 12px">🏆 Plinko 榜单</h3>
        <div class="glb-grid">
            <?php
            echo game_lb_table('💰 盈亏榜', $pkNet, '净盈亏', function ($r) { return ((float)$r['amt'] >= 0 ? '+' : '') . game_lb_money($r['amt']); }, function ($r) { return (float)$r['amt'] >= 0 ? 'glb-pos' : 'glb-neg'; });
            echo game_lb_table('🔥 活跃榜', $pkCnt, '次数', function ($r) { return number_format((int)$r['amt']) . ' 次'; });
            echo game_lb_table('🍀 手气榜', $pkLuck, '单次最高赢', function ($r) { return game_lb_money($r['amt']); }, function ($r) { return (float)$r['amt'] > 0 ? 'glb-pos' : ''; });
            ?>
        </div>
    </div>
</div>
<script>
(function () {
    var ROWS = <?php echo PK_ROWS ?>, SLOTS = ROWS + 1;
    var busy = false, bet = <?php echo PK_CHIPS[0] ?>;
    var betInput = document.getElementById('pkBet'), dropBtn = document.getElementById('pkDrop');
    var board = document.getElementById('pkBoard'), ball = document.getElementById('pkBall'), resultEl = document.getElementById('pkResult');
    function fmt(n) { return (Math.round(n * 10) / 10).toFixed(1); }

    document.querySelectorAll('.pk-chip').forEach(function (c) {
        c.addEventListener('click', function () {
            document.querySelectorAll('.pk-chip').forEach(function (x) { x.classList.remove('sel'); });
            c.classList.add('sel'); bet = parseInt(c.getAttribute('data-bet'), 10); betInput.value = bet;
        });
    });
    betInput.addEventListener('input', function () { bet = Math.max(1, parseInt(betInput.value, 10) || 1); document.querySelectorAll('.pk-chip').forEach(function (x) { x.classList.remove('sel'); }); });

    function animate(d) {
        document.querySelectorAll('.pk-slot').forEach(function (s) { s.classList.remove('hit'); });
        var W = board.clientWidth, H = board.clientHeight;
        var finalFrac = (d.pos + 0.5) / SLOTS;
        ball.style.display = 'block';
        ball.style.transition = 'none';
        ball.style.top = '0px'; ball.style.left = (0.5 * W) + 'px';
        var step = 0;
        function next() {
            step++;
            var t = step / ROWS;
            var frac = 0.5 * (1 - t) + finalFrac * t;
            ball.style.transition = 'top .13s ease-in, left .13s linear';
            ball.style.top = (t * (H - 16)) + 'px';
            ball.style.left = (frac * W) + 'px';
            if (step < ROWS) { setTimeout(next, 135); }
            else { setTimeout(function () { land(d); }, 160); }
        }
        next();
    }
    function land(d) {
        var slot = document.querySelector('.pk-slot[data-i="' + d.pos + '"]');
        if (slot) slot.classList.add('hit');
        document.getElementById('pkBal').textContent = fmt(d.balance);
        resultEl.style.color = d.delta >= 0 ? '#ffd770' : '#ff9a9a';
        resultEl.textContent = (d.delta >= 0 ? '🎉 ' : '') + '×' + d.mult + '，' + (d.delta >= 0 ? '+' : '') + Math.round(d.delta);
        var tb = document.getElementById('pkRows'); var tr = document.createElement('tr');
        var cls = d.delta >= 0 ? 'pk-pos' : 'pk-neg';
        tr.innerHTML = '<td>刚刚</td><td>' + bet + '</td><td>' + d.mult + '×</td><td class="' + cls + '">' + (d.delta >= 0 ? '+' : '') + Math.round(d.delta) + '</td>';
        tb.insertBefore(tr, tb.firstChild);
        busy = false; dropBtn.disabled = false;
    }

    dropBtn.addEventListener('click', function () {
        if (busy) return;
        busy = true; dropBtn.disabled = true; resultEl.textContent = '';
        fetch('/games/plinko/', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=drop&bet=' + bet })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { resultEl.style.color = '#ff9a9a'; resultEl.textContent = d.error || '出错了'; busy = false; dropBtn.disabled = false; return; }
                animate(d);
            })
            .catch(function () { resultEl.style.color = '#ff9a9a'; resultEl.textContent = '网络错误'; busy = false; dropBtn.disabled = false; });
    });
})();
</script>
<?php
stdfoot();
