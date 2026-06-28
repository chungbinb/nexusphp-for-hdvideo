<?php
/**
 * 刮刮乐 —— 手机版（竖屏）。自带完整 HTML 头尾，不经过桌面版 stdhead。
 * 由 scratch/index.php 在手机 UA 时 require；买/刮的 AJAX 仍在 index.php。
 * 复用同一套前端逻辑（元素 id 与桌面版一致），canvas 自适应宽度；
 * 「我的最近刮奖」与榜单收进右上角悬浮按钮弹窗。
 */
if (!defined('SC_TABLE')) { return; }

$mBal = sc_money($CURUSER['seedbonus'] ?? 0);
$mN = (int)($sum['n'] ?? 0);
$mNet = (float)($sum['net'] ?? 0);
$exhausted = ($dailyLimit > 0 && $todayLeft <= 0);

$scItems = sc_items();
$scTotal = 0;
foreach ($scItems as $it) $scTotal += $it[3];
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<title>刮刮乐</title>
<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; }
body { background: #0c1622; color: #e6eef8; font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif; }
a { color: inherit; text-decoration: none; }

.sc-wrap { max-width: 640px; margin: 0 auto; padding: 0 14px calc(40px + env(safe-area-inset-bottom)); }

/* 顶栏 */
.sc-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 2px 8px; position: sticky; top: 0; background: #0c1622; z-index: 5; }
.sc-back { display: inline-flex; align-items: center; gap: 4px; font-size: 15px; color: #9fb6cf; }
.sc-htitle { font-size: 18px; font-weight: 800; }
.sc-lb-btn { font-size: 13px; font-weight: 800; color: #ffe9a8; background: rgba(230,126,34,.18); border: 1px solid rgba(255,210,120,.4); border-radius: 999px; padding: 6px 12px; cursor: pointer; }

.sc-balance { font-size: 14px; font-weight: 700; text-align: center; margin: 4px 0 6px; color: #cfe0f2; }
.sc-balance b { color: #ffd770; font-size: 17px; }
.sc-sub { text-align: center; font-size: 13px; color: #7f93ab; margin-bottom: 12px; }
.sc-sub b { color: #e6eef8; }
.sc-left { color: #ffb74d; font-weight: 700; }

.sc-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 12px; padding: 16px 14px; margin-bottom: 14px; background: rgba(30,60,100,.12); }

.sc-stage { position: relative; width: 320px; max-width: 100%; aspect-ratio: 320 / 150; margin: 0 auto; border-radius: 12px; overflow: hidden; box-shadow: inset 0 2px 8px rgba(0,0,0,.18); }
.sc-prize { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 8px; background: linear-gradient(135deg,#fff7e6,#ffe6c0); color: #1b2b3a; }
.sc-prize .pz-name { font-size: 24px; font-weight: 900; }
.sc-prize .pz-reward { margin-top: 6px; font-size: 14px; font-weight: 700; }
.sc-prize.win .pz-name { color: #0f7a42; }
.sc-prize.lose .pz-name { color: #7a8794; }
.sc-canvas { position: absolute; inset: 0; width: 100%; height: 100%; touch-action: none; cursor: grab; }
.sc-canvas:active { cursor: grabbing; }
.sc-hint { text-align: center; margin-top: 10px; color: #7f93ab; font-size: 13px; }
.sc-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: center; margin-top: 14px; }
.sc-btn { width: 100%; padding: 15px 22px; font-weight: 900; font-size: 17px; cursor: pointer; border-radius: 12px; border: 1px solid #e67e22; background: linear-gradient(180deg,#f08a30,#e67e22); color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,.4); }
.sc-btn:disabled { opacity: .5; cursor: not-allowed; }
.sc-msg { margin-top: 12px; font-weight: 800; text-align: center; min-height: 22px; }

.sc-panel h3 { margin: 0 0 10px; font-size: 15px; }
.sc-odds { display: flex; flex-wrap: wrap; gap: 8px; }
.sc-odds span { padding: 5px 9px; border-radius: 6px; background: rgba(0,0,0,.25); font-size: 13px; }
.sc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.sc-table th, .sc-table td { padding: 8px 5px; border: 1px solid rgba(120,150,190,.22); text-align: center; }
.sc-pos { color: #4ade80; font-weight: 700; } .sc-neg { color: #f87171; font-weight: 700; }

/* 榜单弹窗 */
.sc-modal { position: fixed; inset: 0; z-index: 300; display: none; }
.sc-modal.show { display: block; }
.sc-modal-mask { position: absolute; inset: 0; background: rgba(0,0,0,.62); }
.sc-modal-card { position: absolute; left: 50%; top: 50%; transform: translate(-50%,-50%); width: min(560px, 92vw); max-height: 88vh; overflow-y: auto; background: #10202f; border: 1px solid rgba(120,150,190,.3); border-radius: 16px; padding: 16px 14px; box-shadow: 0 20px 60px rgba(0,0,0,.6); }
.sc-modal-h { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.sc-modal-h h3 { margin: 0; font-size: 17px; }
.sc-modal-x { font-size: 22px; color: #9fb6cf; padding: 2px 8px; cursor: pointer; }
</style>
</head>
<body>
<div class="sc-wrap">
    <div class="sc-top">
        <a class="sc-back" href="/games/">‹ 大厅</a>
        <div class="sc-htitle">刮刮乐</div>
        <span class="sc-lb-btn" id="scLbBtn">🏆 排行榜</span>
    </div>

    <div class="sc-balance">我的电影票：<b id="scBal"><?php echo $mBal ?></b> 张</div>
    <div class="sc-sub">每张 <b><?php echo (int)$cost ?></b> 电影票，刮中即得。<?php if ($dailyLimit > 0) { ?> <span class="sc-left">今日剩余 <span id="scLeft"><?php echo (int)$todayLeft ?></span> 次</span>（每日上限 <?php echo (int)$dailyLimit ?>）<?php } ?></div>

    <div class="sc-panel">
        <div class="sc-stage" id="scStage">
            <div class="sc-prize" id="scPrize">
                <div class="pz-name">刮刮乐</div>
                <div class="pz-reward">点击下方「买一张」开始</div>
            </div>
            <canvas class="sc-canvas" id="scCanvas" width="320" height="150" style="display:none"></canvas>
        </div>
        <div class="sc-hint" id="scHint">买一张后，按住在卡片上来回涂抹即可刮开</div>
        <div class="sc-actions">
            <button type="button" class="sc-btn" id="scBuy"<?php echo $exhausted ? ' disabled' : '' ?>><?php echo $exhausted ? '今日次数已用完' : '买一张（' . (int)$cost . ' 电影票）' ?></button>
        </div>
        <div class="sc-msg" id="scMsg"></div>
    </div>

    <div class="sc-panel">
        <h3>中奖概率</h3>
        <div class="sc-odds">
            <?php foreach ($scItems as $it) {
                $pct = $scTotal > 0 ? round($it[3] / $scTotal * 100, 2) : 0;
            ?><span><?php echo htmlspecialchars($it[0]) ?>：<?php echo $pct ?>%</span><?php } ?>
        </div>
    </div>
</div>

<div class="sc-modal" id="scLbModal">
    <div class="sc-modal-mask" data-close="1"></div>
    <div class="sc-modal-card">
        <div class="sc-modal-h">
            <h3>🏆 刮刮乐榜单</h3>
            <span class="sc-modal-x" data-close="1">✕</span>
        </div>
        <div class="sc-panel" id="scMine">
            <h3>我的最近刮奖（共 <?php echo $mN ?> 次，电影票净 <span class="<?php echo $mNet >= 0 ? 'sc-pos' : 'sc-neg' ?>"><?php echo ($mNet >= 0 ? '+' : '') . number_format($mNet, 0) ?></span>）</h3>
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
                hint.textContent = '按住在卡片上来回涂抹刮开';
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

    // 榜单弹窗
    var modal = document.getElementById('scLbModal');
    document.getElementById('scLbBtn').addEventListener('click', function () { modal.classList.add('show'); });
    modal.addEventListener('click', function (e) { if (e.target.getAttribute('data-close')) modal.classList.remove('show'); });
})();
</script>
</body>
</html>
