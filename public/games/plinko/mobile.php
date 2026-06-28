<?php
/**
 * Plinko 弹珠 —— 手机版（竖屏）。自带完整 HTML 头尾，不经过桌面版 stdhead。
 * 由 plinko/index.php 在手机 UA 时 require；放球的 AJAX 仍在 index.php。
 * 复用同一套前端逻辑（元素 id 与桌面版一致），换成手机皮肤；榜单收进右上角悬浮按钮弹窗。
 */
if (!defined('PK_TABLE')) { return; }

$mBal = pk_money($CURUSER['seedbonus'] ?? 0);
$mN = (int)($sum['n'] ?? 0);
$mNet = (float)($sum['net'] ?? 0);

$pkNet = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, SUM(`s`.`delta`) AS amt FROM `" . PK_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
$pkNetLow = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, SUM(`s`.`delta`) AS amt FROM `" . PK_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt ASC LIMIT 10");
$pkCnt = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, COUNT(*) AS amt FROM `" . PK_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
$pkLuck = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, MAX(`s`.`delta`) AS amt, COUNT(*) AS cnt FROM `" . PK_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC, cnt DESC LIMIT 10");
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=no" />
<title>Plinko 弹珠</title>
<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; }
body { min-height: 100%; background: #07131f; color: #fff; font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif; }
a { color: inherit; text-decoration: none; }

.pk-app { max-width: 520px; margin: 0 auto; padding: 0 12px calc(40px + env(safe-area-inset-bottom)); }

/* 顶栏 */
.pk-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 2px 6px; }
.pk-top .back { font-size: 15px; font-weight: 700; color: #9fd0f5; }
.pk-top .ttl { font-size: 18px; font-weight: 900; text-align: center; flex: 1; }
.pk-top .lb { font-size: 13px; font-weight: 800; color: #ffe9a8; background: rgba(0,0,0,.35); border: 1px solid rgba(255,210,120,.4); border-radius: 999px; padding: 5px 11px; cursor: pointer; white-space: nowrap; }

.pk-balance { text-align: center; font-size: 14px; font-weight: 700; margin: 2px 0 12px; color: #cfe6dd; }
.pk-balance b { color: #ffd86b; font-size: 16px; }
.pk-muted { color: #6f8aa8; }

.pk-stage { border: 1px solid rgba(120,150,190,.34); border-radius: 14px; padding: 16px 12px; margin-bottom: 14px; background: linear-gradient(135deg,#10243a,#08131f); }
.pk-board { position: relative; width: 100%; max-width: 100%; height: 320px; margin: 0 auto; }
.pk-pegs { position: absolute; inset: 0; display: flex; flex-direction: column; justify-content: space-between; padding: 16px 0; }
.pk-prow { display: flex; justify-content: center; gap: 24px; }
.pk-peg { width: 7px; height: 7px; border-radius: 50%; background: rgba(255,255,255,.65); }
.pk-ball { position: absolute; top: 0; left: 50%; width: 18px; height: 18px; margin-left: -9px; border-radius: 50%; background: radial-gradient(circle at 35% 30%, #fff, #ffcf3f 60%, #e08a00); box-shadow: 0 0 8px rgba(255,200,60,.8); }
.pk-slots { display: flex; gap: 4px; max-width: 100%; margin: 8px auto 0; }
.pk-slot { flex: 1; text-align: center; padding: 7px 1px; border-radius: 5px; font-size: 12px; font-weight: 800; color: #fff; }
.pk-slot.hit { outline: 3px solid #fff; transform: translateY(-3px); }
.pk-c-hot { background: #c0392b; } .pk-c-warm { background: #e67e22; } .pk-c-mid { background: #2e8b57; } .pk-c-cold { background: #2c6fb0; }
.pk-result { text-align: center; min-height: 28px; margin: 12px 0 6px; font-size: 19px; font-weight: 900; color: #ffd770; }

.pk-controls { display: flex; flex-wrap: wrap; gap: 9px; align-items: center; justify-content: center; }
.pk-chip { padding: 9px 13px; border: 1px solid rgba(120,150,190,.45); border-radius: 8px; cursor: pointer; font-weight: 800; background: rgba(255,255,255,.08); color: #dce8f6; }
.pk-chip.sel { background: #2980b9; color: #fff; border-color: #2980b9; }
.pk-bet { width: 96px; padding: 9px; border: 1px solid rgba(120,150,190,.45); border-radius: 8px; background: rgba(255,255,255,.06); color: #fff; font-size: 15px; }
.pk-btn { width: 100%; margin-top: 12px; padding: 15px 22px; font-size: 17px; font-weight: 900; cursor: pointer; border-radius: 12px; border: none; background: linear-gradient(180deg,#2e9bd6,#1f6fb0); color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,.4); }
.pk-btn:disabled { opacity: .5; cursor: not-allowed; }

/* 榜单弹窗 */
.pk-modal { position: fixed; inset: 0; z-index: 300; display: none; }
.pk-modal.show { display: block; }
.pk-modal-mask { position: absolute; inset: 0; background: rgba(0,0,0,.62); }
.pk-modal-card { position: absolute; left: 50%; top: 50%; transform: translate(-50%,-50%); width: min(560px, 92vw); max-height: 88vh; overflow-y: auto; background: #10202f; border: 1px solid rgba(120,150,190,.3); border-radius: 16px; padding: 16px 14px; box-shadow: 0 20px 60px rgba(0,0,0,.6); }
.pk-modal-h { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.pk-modal-h h3 { margin: 0; font-size: 17px; }
.pk-modal-x { font-size: 22px; color: #9fb6cf; padding: 2px 8px; cursor: pointer; }
.pk-mine { margin-bottom: 16px; }
.pk-mine h4 { margin: 0 0 8px; font-size: 14px; color: #cfe0f2; }
.pk-tbl { width: 100%; border-collapse: collapse; font-size: 12px; color: #dce8f6; }
.pk-tbl th, .pk-tbl td { padding: 6px 5px; border: 1px solid rgba(120,150,190,.22); text-align: center; }
.pk-pos { color: #4ade80; font-weight: 700; } .pk-neg { color: #f87171; font-weight: 700; }
</style>
</head>
<body>
<div class="pk-app">
    <div class="pk-top">
        <a class="back" href="/games/">‹ 大厅</a>
        <div class="ttl">Plinko 弹珠</div>
        <span class="lb" id="pkLbBtn">🏆 排行榜</span>
    </div>
    <div class="pk-balance">我的电影票：<b id="pkBal"><?php echo $mBal ?></b> 张</div>

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
        </div>
        <button type="button" class="pk-btn" id="pkDrop">放球</button>
    </div>
</div>

<div class="pk-modal" id="pkLbModal">
    <div class="pk-modal-mask" data-close="1"></div>
    <div class="pk-modal-card">
        <div class="pk-modal-h">
            <h3>🏆 Plinko 榜单</h3>
            <span class="pk-modal-x" data-close="1">✕</span>
        </div>
        <div class="pk-mine" id="pkMine">
            <h4>我的最近战绩（共 <?php echo $mN ?> 次，净 <span class="<?php echo $mNet >= 0 ? 'pk-pos' : 'pk-neg' ?>"><?php echo ($mNet >= 0 ? '+' : '') . number_format($mNet, 0) ?></span>）</h4>
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
        <div id="pkLb">
            <?php
            echo game_lb_css();
            echo game_lb_table('💰 盈亏榜', $pkNet, '净盈亏', function ($r) { return ((float)$r['amt'] >= 0 ? '+' : '') . game_lb_money($r['amt']); }, function ($r) { return (float)$r['amt'] >= 0 ? 'glb-pos' : 'glb-neg'; }, $pkNetLow);
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
    function refreshPanels() {
        fetch(location.href, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                ['pkMine', 'pkLb'].forEach(function (id) {
                    var f = doc.getElementById(id), cur = document.getElementById(id);
                    if (f && cur) cur.innerHTML = f.innerHTML;
                });
            }).catch(function () {});
    }

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
        refreshPanels();
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

    // 榜单弹窗
    var modal = document.getElementById('pkLbModal');
    document.getElementById('pkLbBtn').addEventListener('click', function () { modal.classList.add('show'); });
    modal.addEventListener('click', function (e) { if (e.target.getAttribute('data-close')) modal.classList.remove('show'); });
})();
</script>
</body>
</html>
