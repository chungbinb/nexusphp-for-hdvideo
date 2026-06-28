<?php
/**
 * 老虎机 —— 手机版（竖屏）。自带完整 HTML 头尾，不经过桌面版 stdhead。
 * 由 slots/index.php 在手机 UA 时 require；拉杆 spin 的 AJAX 仍在 index.php。
 * 复用同一套前端逻辑（元素 id 与桌面版一致）；榜单收进右上角悬浮按钮弹窗。
 */
if (!defined('SL_TABLE')) { return; }

$mBal = sl_money($CURUSER['seedbonus'] ?? 0);
$mN = (int)($sum['n'] ?? 0);
$mNet = (float)($sum['net'] ?? 0);

$slNet = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, SUM(`s`.`delta`) AS amt FROM `" . SL_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
$slNetLow = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, SUM(`s`.`delta`) AS amt FROM `" . SL_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt ASC LIMIT 10");
$slCnt = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, COUNT(*) AS amt FROM `" . SL_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
$slLuck = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, MAX(`s`.`delta`) AS amt, COUNT(*) AS cnt FROM `" . SL_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC, cnt DESC LIMIT 10");
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<title>老虎机</title>
<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; }
body { background: #0c1622; color: #e6eef8; font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif; }
a { color: inherit; text-decoration: none; }

.sl-wrap { max-width: 640px; margin: 0 auto; padding: 0 14px calc(40px + env(safe-area-inset-bottom)); }

/* 顶栏 */
.sl-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 2px 8px; position: sticky; top: 0; background: #0c1622; z-index: 5; }
.sl-back { display: inline-flex; align-items: center; gap: 4px; font-size: 15px; color: #9fb6cf; }
.sl-htitle { font-size: 18px; font-weight: 800; }
.sl-lb-btn { font-size: 13px; font-weight: 800; color: #ffe9a8; background: rgba(184,134,11,.18); border: 1px solid rgba(255,210,120,.4); border-radius: 999px; padding: 6px 12px; cursor: pointer; }

.sl-balance { font-size: 14px; font-weight: 700; text-align: center; margin: 4px 0 12px; color: #cfe0f2; }
.sl-balance b { color: #ffd770; font-size: 17px; }
.sl-muted { color: #7f93ab; }

.sl-machine { border: 1px solid rgba(120,150,190,.34); border-radius: 16px; padding: 20px 14px; margin-bottom: 14px; background: linear-gradient(135deg,#3a2a10,#1c1206); }
.sl-reels { display: flex; gap: 14px; justify-content: center; }
.sl-reel { width: 84px; height: 84px; max-width: 28vw; max-height: 28vw; border-radius: 12px; background: #fff; display: flex; align-items: center; justify-content: center; font-size: 46px; box-shadow: inset 0 3px 8px rgba(0,0,0,.25); }
.sl-result { text-align: center; min-height: 26px; margin: 14px 0; font-size: 18px; font-weight: 900; color: #ffd770; }
.sl-controls { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: center; }
.sl-chip { padding: 10px 16px; border: 1px solid rgba(255,210,120,.45); border-radius: 999px; cursor: pointer; font-weight: 800; background: rgba(255,255,255,.06); color: #ffe9a8; font-size: 15px; }
.sl-chip.sel { background: #b8860b; color: #fff; border-color: #b8860b; }
.sl-bet { width: 110px; padding: 11px; border: 1px solid rgba(120,150,190,.45); border-radius: 8px; background: rgba(255,255,255,.95); color: #1b2b3a; font-size: 15px; text-align: center; }
.sl-btn { width: 100%; margin-top: 14px; padding: 16px 22px; font-weight: 900; font-size: 18px; cursor: pointer; border-radius: 12px; border: 1px solid #b8860b; background: linear-gradient(180deg,#e0a82a,#b8860b); color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,.4); }
.sl-btn:disabled { opacity: .5; cursor: not-allowed; }

.sl-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 12px; padding: 14px; margin-bottom: 14px; background: rgba(30,60,100,.12); }
.sl-panel h3 { margin: 0 0 10px; font-size: 15px; }
.sl-pay { display: flex; flex-wrap: wrap; gap: 8px; }
.sl-pay span { padding: 5px 9px; border-radius: 6px; background: rgba(0,0,0,.25); font-size: 13px; }
.sl-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.sl-tbl th, .sl-tbl td { padding: 8px 5px; border: 1px solid rgba(120,150,190,.22); text-align: center; }
.sl-pos { color: #4ade80; font-weight: 700; } .sl-neg { color: #f87171; font-weight: 700; }

/* 榜单弹窗 */
.sl-modal { position: fixed; inset: 0; z-index: 300; display: none; }
.sl-modal.show { display: block; }
.sl-modal-mask { position: absolute; inset: 0; background: rgba(0,0,0,.62); }
.sl-modal-card { position: absolute; left: 50%; top: 50%; transform: translate(-50%,-50%); width: min(560px, 92vw); max-height: 88vh; overflow-y: auto; background: #10202f; border: 1px solid rgba(120,150,190,.3); border-radius: 16px; padding: 16px 14px; box-shadow: 0 20px 60px rgba(0,0,0,.6); }
.sl-modal-h { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.sl-modal-h h3 { margin: 0; font-size: 17px; }
.sl-modal-x { font-size: 22px; color: #9fb6cf; padding: 2px 8px; cursor: pointer; }
</style>
</head>
<body>
<div class="sl-wrap">
    <div class="sl-top">
        <a class="sl-back" href="/games/">‹ 大厅</a>
        <div class="sl-htitle">老虎机</div>
        <span class="sl-lb-btn" id="slLbBtn">🏆 排行榜</span>
    </div>

    <div class="sl-balance">我的电影票：<b id="slBal"><?php echo $mBal ?></b> 张</div>

    <div class="sl-machine">
        <div class="sl-reels">
            <div class="sl-reel" id="slR0">🍒</div>
            <div class="sl-reel" id="slR1">🔔</div>
            <div class="sl-reel" id="slR2">7️⃣</div>
        </div>
        <div class="sl-result" id="slResult"></div>
        <div class="sl-controls">
            <?php foreach (SL_CHIPS as $i => $c) { ?>
                <span class="sl-chip<?php echo $i === 0 ? ' sel' : '' ?>" data-bet="<?php echo $c ?>"><?php echo number_format($c) ?></span>
            <?php } ?>
            <input type="number" min="1" class="sl-bet" id="slBet" value="<?php echo SL_CHIPS[0] ?>">
        </div>
        <button type="button" class="sl-btn" id="slSpin">拉一把 🎰</button>
    </div>

    <div class="sl-panel">
        <h3>赔率表（三个相同）</h3>
        <div class="sl-pay">
            <?php foreach (SL_SYMBOLS as $s) { ?><span><?php echo $s[0] ?>×<?php echo $s[0] ?><?php echo $s[0] ?> = <?php echo $s[2] ?>倍</span><?php } ?>
            <span>🍒🍒(两个) = 回本</span>
        </div>
    </div>
</div>

<div class="sl-modal" id="slLbModal">
    <div class="sl-modal-mask" data-close="1"></div>
    <div class="sl-modal-card">
        <div class="sl-modal-h">
            <h3>🏆 老虎机榜单</h3>
            <span class="sl-modal-x" data-close="1">✕</span>
        </div>
        <div class="sl-panel" id="slMine">
            <h3>我的最近战绩（共 <?php echo $mN ?> 把，净 <span class="<?php echo $mNet >= 0 ? 'sl-pos' : 'sl-neg' ?>"><?php echo ($mNet >= 0 ? '+' : '') . number_format($mNet, 0) ?></span>）</h3>
            <table class="sl-tbl">
                <tr><th>时间</th><th>结果</th><th>下注</th><th>盈亏</th></tr>
                <tbody id="slRows">
                <?php while ($r = mysql_fetch_assoc($myRes)) { ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['created_at']) ?></td>
                        <td style="font-size:18px"><?php echo htmlspecialchars($r['result']) ?></td>
                        <td><?php echo (int)$r['bet'] ?></td>
                        <td class="<?php echo (float)$r['delta'] >= 0 ? 'sl-pos' : 'sl-neg' ?>"><?php echo ((float)$r['delta'] >= 0 ? '+' : '') . sl_money($r['delta']) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <div id="slBoard">
            <?php
            echo game_lb_css();
            echo game_lb_table('💰 盈亏榜', $slNet, '净盈亏', function ($r) { return ((float)$r['amt'] >= 0 ? '+' : '') . game_lb_money($r['amt']); }, function ($r) { return (float)$r['amt'] >= 0 ? 'glb-pos' : 'glb-neg'; }, $slNetLow);
            echo game_lb_table('🔥 活跃榜', $slCnt, '次数', function ($r) { return number_format((int)$r['amt']) . ' 把'; });
            echo game_lb_table('🍀 手气榜', $slLuck, '单把最高赢', function ($r) { return game_lb_money($r['amt']); }, function ($r) { return (float)$r['amt'] > 0 ? 'glb-pos' : ''; });
            ?>
        </div>
    </div>
</div>

<script>
(function () {
    var SYMS = <?php echo json_encode(array_map(function ($s) { return $s[0]; }, SL_SYMBOLS), JSON_UNESCAPED_UNICODE) ?>;
    var busy = false, bet = <?php echo SL_CHIPS[0] ?>;
    var betInput = document.getElementById('slBet'), spinBtn = document.getElementById('slSpin'), resultEl = document.getElementById('slResult');
    var reelEls = [document.getElementById('slR0'), document.getElementById('slR1'), document.getElementById('slR2')];
    function fmt(n) { return (Math.round(n * 10) / 10).toFixed(1); }
    function refreshPanels() {
        fetch(location.href, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                ['slMine', 'slBoard'].forEach(function (id) {
                    var f = doc.getElementById(id), c = document.getElementById(id);
                    if (f && c) c.innerHTML = f.innerHTML;
                });
            }).catch(function () {});
    }
    document.querySelectorAll('.sl-chip').forEach(function (c) {
        c.addEventListener('click', function () {
            document.querySelectorAll('.sl-chip').forEach(function (x) { x.classList.remove('sel'); });
            c.classList.add('sel'); bet = parseInt(c.getAttribute('data-bet'), 10); betInput.value = bet;
        });
    });
    betInput.addEventListener('input', function () { bet = Math.max(1, parseInt(betInput.value, 10) || 1); document.querySelectorAll('.sl-chip').forEach(function (x) { x.classList.remove('sel'); }); });

    spinBtn.addEventListener('click', function () {
        if (busy) return;
        busy = true; spinBtn.disabled = true; resultEl.textContent = '';
        var spinners = reelEls.map(function (el) {
            return setInterval(function () { el.textContent = SYMS[Math.floor(Math.random() * SYMS.length)]; }, 70);
        });
        fetch('/games/slots/', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=spin&bet=' + bet })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                setTimeout(function () { clearInterval(spinners[0]); if (d.ok) reelEls[0].textContent = SYMS[d.reels[0]]; }, 500);
                setTimeout(function () { clearInterval(spinners[1]); if (d.ok) reelEls[1].textContent = SYMS[d.reels[1]]; }, 800);
                setTimeout(function () {
                    clearInterval(spinners[2]);
                    if (!d.ok) { resultEl.style.color = '#ff9a9a'; resultEl.textContent = d.error || '出错了'; busy = false; spinBtn.disabled = false; return; }
                    reelEls[2].textContent = SYMS[d.reels[2]];
                    if (d.mult > 0) { resultEl.style.color = '#ffd770'; resultEl.textContent = '🎉 ' + d.label + '（' + (d.delta >= 0 ? '+' : '') + Math.round(d.delta) + '）'; }
                    else { resultEl.style.color = '#ff9a9a'; resultEl.textContent = '未中奖（' + Math.round(d.delta) + '）'; }
                    document.getElementById('slBal').textContent = fmt(d.balance);
                    var tb = document.getElementById('slRows'); var tr = document.createElement('tr');
                    var cls = d.delta >= 0 ? 'sl-pos' : 'sl-neg';
                    tr.innerHTML = '<td>刚刚</td><td style="font-size:18px">' + SYMS[d.reels[0]] + SYMS[d.reels[1]] + SYMS[d.reels[2]] + '</td><td>' + bet + '</td><td class="' + cls + '">' + (d.delta >= 0 ? '+' : '') + Math.round(d.delta) + '</td>';
                    tb.insertBefore(tr, tb.firstChild);
                    busy = false; spinBtn.disabled = false;
                    refreshPanels();
                }, 1100);
            })
            .catch(function () { spinners.forEach(clearInterval); resultEl.style.color = '#ff9a9a'; resultEl.textContent = '网络错误'; busy = false; spinBtn.disabled = false; });
    });

    // 榜单弹窗
    var modal = document.getElementById('slLbModal');
    document.getElementById('slLbBtn').addEventListener('click', function () { modal.classList.add('show'); });
    modal.addEventListener('click', function (e) { if (e.target.getAttribute('data-close')) modal.classList.remove('show'); });
})();
</script>
</body>
</html>
