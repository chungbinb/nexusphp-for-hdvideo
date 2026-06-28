<?php
/**
 * 猜高低 —— 手机版。自带完整 HTML 头尾，不经过桌面版 stdhead。
 * 由 hilo/index.php 在手机 UA 时 require；开始/猜/收手的 AJAX 仍在 index.php。
 * 复用同一套前端逻辑（元素 id 与桌面版一致），换成手机皮肤；榜单收进右上角悬浮按钮弹窗。
 */
if (!defined('HL_RESULT_TABLE')) { return; }

$mBal = hl_money($CURUSER['seedbonus'] ?? 0);
$mN = (int)($sum['n'] ?? 0);
$mNet = (float)($sum['net'] ?? 0);

$hlNet = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, SUM(`s`.`delta`) AS amt FROM `" . HL_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
$hlNetLow = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, SUM(`s`.`delta`) AS amt FROM `" . HL_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt ASC LIMIT 10");
$hlCnt = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, COUNT(*) AS amt FROM `" . HL_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
$hlStreak = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, MAX(`s`.`streak`) AS amt, COUNT(*) AS cnt FROM `" . HL_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC, cnt DESC LIMIT 10");
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=no" />
<title>猜高低</title>
<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; min-height: 100%; }
body { background: #160b22; color: #efe6f7; font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif; }
a { color: inherit; text-decoration: none; }

.hl-app { min-height: 100vh; display: flex; flex-direction: column; background: radial-gradient(ellipse 120% 80% at 50% -10%, #3a2350 0%, #2a1640 40%, #190c28 70%, #120819 100%); }

/* 顶栏 */
.hl-top { position: relative; z-index: 5; display: flex; align-items: center; justify-content: space-between; padding: 12px 14px calc(10px + env(safe-area-inset-top)); padding-top: calc(12px + env(safe-area-inset-top)); }
.hl-tbtn { display: flex; align-items: center; gap: 4px; font-size: 13px; color: #d8c8ec; opacity: .92; cursor: pointer; }
.hl-tbtn svg { width: 20px; height: 20px; }
.hl-ttl { font-size: 18px; font-weight: 900; letter-spacing: 1px; }
.hl-ttl .b { font-size: 10px; font-weight: 700; color: #d6b3f2; background: rgba(142,68,173,.3); padding: 2px 7px; border-radius: 999px; margin-left: 4px; vertical-align: middle; }
.hl-trt { display: flex; align-items: center; gap: 14px; }

/* 余额 */
.hl-bal { text-align: center; margin: 2px 0 6px; font-size: 13px; color: #c4b0dd; }
.hl-bal b { color: #ffd770; font-size: 16px; }

/* 规则 */
.hl-rules { text-align: center; font-size: 11px; color: rgba(255,255,255,.45); padding: 0 22px 8px; line-height: 1.5; }

/* 牌区 */
.hl-stage { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 10px 16px; min-height: 0; }
.hl-card { display: inline-flex; flex-direction: column; justify-content: space-between; width: 130px; height: 180px; border-radius: 14px; background: #fff; color: #1b2b3a; padding: 12px 14px; font-weight: 800; font-size: 36px; box-shadow: 0 6px 18px rgba(0,0,0,.5); }
.hl-card.red { color: #d8362f; }
.hl-card .bot { align-self: flex-end; transform: rotate(180deg); }
.hl-info { margin: 18px 0 6px; font-weight: 700; font-size: 15px; text-align: center; }
.hl-pot { color: #ffd770; font-weight: 900; }
.hl-result { min-height: 28px; margin-top: 8px; font-size: 19px; font-weight: 900; text-align: center; }
.hl-pos { color: #6ee7a0; } .hl-neg { color: #ff9a9a; }

/* 底部操作区 */
.hl-bar { position: relative; z-index: 5; padding: 10px 16px calc(14px + env(safe-area-inset-bottom)); background: linear-gradient(180deg, rgba(18,8,25,0), rgba(12,5,17,.85) 40%); }
.hl-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
.hl-btn { flex: 1; min-width: 92px; max-width: 160px; padding: 15px 10px; font-weight: 900; cursor: pointer; border-radius: 12px; border: none; background: #8e44ad; color: #fff; font-size: 17px; box-shadow: 0 4px 10px rgba(0,0,0,.4); }
.hl-btn small { display: block; font-size: 12px; opacity: .85; font-weight: 700; margin-top: 2px; }
.hl-btn.up { background: linear-gradient(180deg,#37b06a,#2e8b57); }
.hl-btn.down { background: linear-gradient(180deg,#d6473b,#c0392b); }
.hl-btn.cash { background: linear-gradient(180deg,#e8b220,#d4a017); flex: 0 0 auto; min-width: 76px; }
.hl-btn.start { flex: 0 0 auto; min-width: 120px; background: linear-gradient(180deg,#9b50bb,#7d3a9e); }
.hl-btn:disabled { opacity: .45; }

.hl-chips { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; margin-bottom: 12px; }
.hl-chip { width: 56px; height: 56px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 900; color: #fff; cursor: pointer; border: 3px dashed rgba(255,255,255,.7); box-shadow: 0 3px 8px rgba(0,0,0,.4); text-shadow: 0 1px 2px rgba(0,0,0,.5); background: #8e44ad; }
.hl-chip.sel { outline: 3px solid #ffd770; outline-offset: 1px; }
.hl-bet { display: none; }
.hl-betwrap { display: flex; align-items: center; justify-content: center; gap: 10px; }

/* 榜单弹窗 */
.hl-modal { position: fixed; inset: 0; z-index: 300; display: none; }
.hl-modal.show { display: block; }
.hl-modal-mask { position: absolute; inset: 0; background: rgba(0,0,0,.65); }
.hl-modal-card { position: absolute; left: 50%; top: 50%; transform: translate(-50%,-50%); width: min(560px, 92vw); max-height: 88vh; overflow-y: auto; background: #1a1028; border: 1px solid rgba(150,120,190,.3); border-radius: 16px; padding: 16px 14px; box-shadow: 0 20px 60px rgba(0,0,0,.6); }
.hl-modal-h { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.hl-modal-h h3 { margin: 0; font-size: 17px; }
.hl-modal-x { font-size: 22px; color: #c4b0dd; padding: 2px 8px; cursor: pointer; }
.hl-mine { margin-bottom: 16px; }
.hl-mine h3, .hl-mine h4 { margin: 0 0 8px; font-size: 14px; color: #e0d2f2; }
.hl-tbl { width: 100%; border-collapse: collapse; font-size: 12px; color: #e0d4f2; }
.hl-tbl th, .hl-tbl td { padding: 6px 5px; border: 1px solid rgba(150,120,190,.22); text-align: center; }
</style>
</head>
<body>
<div class="hl-app">
    <div class="hl-top">
        <a class="hl-tbtn" href="/games/">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"></path></svg>
            大厅
        </a>
        <div class="hl-ttl">猜高低<span class="b">内测中 v0.3</span></div>
        <div class="hl-trt">
            <span class="hl-tbtn" id="hlFsBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M16 21h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"></path></svg>
            </span>
            <span class="hl-tbtn" id="hlLbBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0V4zM7 6H4v2a3 3 0 0 0 3 3M17 6h3v2a3 3 0 0 1-3 3"></path></svg>
            </span>
        </div>
    </div>

    <div class="hl-bal">我的电影票：<b id="hlBal"><?php echo $mBal ?></b> 张</div>
    <div class="hl-rules">猜下一张牌比当前大还是小（A 最小、K 最大）。猜中可叠倍续猜，随时收手，猜错或相同则归零。</div>

    <div class="hl-stage">
        <div class="hl-card" id="hlCard"><span class="top">?</span><span class="bot">?</span></div>
        <div class="hl-info" id="hlInfo">投入电影票开始</div>
        <div class="hl-result" id="hlResult"></div>
    </div>

    <div class="hl-bar">
        <div class="hl-actions" id="hlGuessRow" style="display:none">
            <button type="button" class="hl-btn up" id="hlHi">高 ↑<small id="hlHiM"></small></button>
            <button type="button" class="hl-btn down" id="hlLo">低 ↓<small id="hlLoM"></small></button>
            <button type="button" class="hl-btn cash" id="hlCash">收手</button>
        </div>
        <div id="hlBetRow">
            <div class="hl-chips">
                <?php foreach (HL_CHIPS as $i => $c) { ?>
                    <span class="hl-chip<?php echo $i === 0 ? ' sel' : '' ?>" data-bet="<?php echo $c ?>"><?php echo number_format($c) ?></span>
                <?php } ?>
            </div>
            <div class="hl-betwrap">
                <input type="number" min="1" class="hl-bet" id="hlBet" value="<?php echo HL_CHIPS[0] ?>">
                <button type="button" class="hl-btn start" id="hlStart">开始</button>
            </div>
        </div>
    </div>
</div>

<div class="hl-modal" id="hlLbModal">
    <div class="hl-modal-mask" data-close="1"></div>
    <div class="hl-modal-card">
        <div class="hl-modal-h">
            <h3>🏆 猜高低榜单</h3>
            <span class="hl-modal-x" data-close="1">✕</span>
        </div>
        <div class="hl-mine" id="hlMine">
            <h3 style="margin:0 0 10px">我的最近战绩（共 <?php echo $mN ?> 局，净 <span class="<?php echo $mNet >= 0 ? 'hl-pos' : 'hl-neg' ?>"><?php echo ($mNet >= 0 ? '+' : '') . number_format($mNet, 0) ?></span>）</h3>
            <table class="hl-tbl">
                <tr><th>时间</th><th>下注</th><th>连胜</th><th>结果</th><th>盈亏</th></tr>
                <tbody>
                <?php while ($r = mysql_fetch_assoc($myRes)) { ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['created_at']) ?></td>
                        <td><?php echo (int)$r['bet'] ?></td>
                        <td><?php echo (int)$r['streak'] ?></td>
                        <td><?php echo $r['outcome'] === 'cashout' ? '收手' : '失败' ?></td>
                        <td class="<?php echo (float)$r['delta'] >= 0 ? 'hl-pos' : 'hl-neg' ?>"><?php echo ((float)$r['delta'] >= 0 ? '+' : '') . hl_money($r['delta']) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <div id="hlBoard">
            <?php
            echo game_lb_css();
            echo game_lb_table('💰 盈亏榜', $hlNet, '净盈亏', function ($r) { return ((float)$r['amt'] >= 0 ? '+' : '') . game_lb_money($r['amt']); }, function ($r) { return (float)$r['amt'] >= 0 ? 'glb-pos' : 'glb-neg'; }, $hlNetLow);
            echo game_lb_table('🔥 活跃榜', $hlCnt, '局数', function ($r) { return number_format((int)$r['amt']) . ' 局'; });
            echo game_lb_table('🍀 连胜榜', $hlStreak, '最高连胜', function ($r) { return number_format((int)$r['amt']) . ' 连'; });
            ?>
        </div>
    </div>
</div>

<script>
(function () {
    var busy = false, bet = <?php echo HL_CHIPS[0] ?>;
    var betInput = document.getElementById('hlBet');
    var betRow = document.getElementById('hlBetRow'), guessRow = document.getElementById('hlGuessRow');
    var cardEl = document.getElementById('hlCard'), infoEl = document.getElementById('hlInfo'), resultEl = document.getElementById('hlResult');
    var hiBtn = document.getElementById('hlHi'), loBtn = document.getElementById('hlLo'), cashBtn = document.getElementById('hlCash'), startBtn = document.getElementById('hlStart');
    var hiM = document.getElementById('hlHiM'), loM = document.getElementById('hlLoM');
    var initState = <?php echo json_encode($initState, JSON_UNESCAPED_UNICODE) ?: 'null' ?>;
    function fmt(n) { return (Math.round(n * 10) / 10).toFixed(1); }
    function refreshPanels() {
        fetch(location.href, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                ['hlMine', 'hlBoard'].forEach(function (id) {
                    var f = doc.getElementById(id), cur = document.getElementById(id);
                    if (f && cur) cur.innerHTML = f.innerHTML;
                });
            }).catch(function () {});
    }

    function showCard(c) {
        cardEl.className = 'hl-card' + (c.red ? ' red' : '');
        cardEl.innerHTML = '<span class="top">' + c.r + c.s + '</span><span class="bot">' + c.r + c.s + '</span>';
    }

    function renderPlaying(d) {
        showCard(d.card);
        betRow.style.display = 'none';
        guessRow.style.display = 'flex';
        infoEl.innerHTML = '当前累计 <span class="hl-pot">' + Math.round(d.pot) + '</span> 电影票（×' + d.mult.toFixed(2) + '，连胜 ' + d.streak + '）';
        hiBtn.disabled = !d.options.hi.avail; loBtn.disabled = !d.options.lo.avail;
        hiM.textContent = d.options.hi.avail ? '×' + d.options.hi.mult.toFixed(2) : '—';
        loM.textContent = d.options.lo.avail ? '×' + d.options.lo.mult.toFixed(2) : '—';
        if (d.balance != null) document.getElementById('hlBal').textContent = fmt(d.balance);
    }

    function renderDone(d, msg, cls) {
        showCard(d.card);
        guessRow.style.display = 'none';
        betRow.style.display = 'block';
        startBtn.textContent = '再来一局';
        infoEl.textContent = '投入电影票开始';
        resultEl.className = 'hl-result ' + cls;
        resultEl.textContent = msg;
        if (d.balance != null) document.getElementById('hlBal').textContent = fmt(d.balance);
        refreshPanels();
    }

    function post(action, extra) {
        if (busy) return;
        busy = true; hiBtn.disabled = loBtn.disabled = cashBtn.disabled = startBtn.disabled = true; resultEl.textContent = '';
        fetch('/games/hilo/', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=' + action + (extra || '') })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { resultEl.className = 'hl-result hl-neg'; resultEl.textContent = d.error || '出错了'; return; }
                if (d.status === 'playing') { renderPlaying(d); }
                else if (d.cashout) { renderDone(d, '🎉 收手成功，赢得 ' + Math.round(d.pot) + '（连胜 ' + d.streak + '）', 'hl-pos'); }
                else { renderDone(d, d.tie ? '😱 相同点数，归零' : '💥 猜错了，归零', 'hl-neg'); }
            })
            .catch(function () { resultEl.className = 'hl-result hl-neg'; resultEl.textContent = '网络错误'; })
            .finally(function () { busy = false; cashBtn.disabled = false; startBtn.disabled = false; });
    }

    document.querySelectorAll('.hl-chip').forEach(function (c) {
        c.addEventListener('click', function () {
            document.querySelectorAll('.hl-chip').forEach(function (x) { x.classList.remove('sel'); });
            c.classList.add('sel'); bet = parseInt(c.getAttribute('data-bet'), 10); betInput.value = bet;
        });
    });
    betInput.addEventListener('input', function () { bet = Math.max(1, parseInt(betInput.value, 10) || 1); document.querySelectorAll('.hl-chip').forEach(function (x) { x.classList.remove('sel'); }); });

    startBtn.addEventListener('click', function () { post('start', '&bet=' + bet); });
    hiBtn.addEventListener('click', function () { post('guess', '&dir=hi'); });
    loBtn.addEventListener('click', function () { post('guess', '&dir=lo'); });
    cashBtn.addEventListener('click', function () { post('cashout'); });

    if (initState) { renderPlaying(initState); resultEl.textContent = '继续上一局'; }

    // 榜单弹窗
    var modal = document.getElementById('hlLbModal');
    document.getElementById('hlLbBtn').addEventListener('click', function () { modal.classList.add('show'); });
    modal.addEventListener('click', function (e) { if (e.target.getAttribute('data-close')) modal.classList.remove('show'); });

    // 横屏
    document.getElementById('hlFsBtn').addEventListener('click', function () {
        var el = document.documentElement;
        var req = el.requestFullscreen || el.webkitRequestFullscreen;
        try {
            var p = req ? req.call(el) : null;
            Promise.resolve(p).then(function () {
                if (screen.orientation && screen.orientation.lock) return screen.orientation.lock('landscape');
            }).catch(function () {});
        } catch (e) {}
    });
})();
</script>
</body>
</html>
