<?php
/**
 * 二十一点 —— 手机版（横屏赌桌风格）。自带完整 HTML 头尾，不经过桌面版 stdhead。
 * 由 blackjack/index.php 在手机 UA 时 require；发牌/要牌/停牌/加倍的 AJAX 仍在 index.php。
 * 复用同一套前端逻辑（元素 id 与桌面版一致），换成赌桌皮肤；榜单收进右上角悬浮按钮弹窗。
 */
if (!defined('BJ_GAME_TABLE')) { return; }

$mBal = bj_money($CURUSER['seedbonus'] ?? 0);
$mN = (int)($sum['n'] ?? 0);
$mNet = (float)($sum['net'] ?? 0);

$bjNet = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, SUM(`s`.`delta`) AS amt FROM `" . BJ_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
$bjNetLow = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, SUM(`s`.`delta`) AS amt FROM `" . BJ_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt ASC LIMIT 10");
$bjCnt = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, COUNT(*) AS amt FROM `" . BJ_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
$bjLuck = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, MAX(`s`.`delta`) AS amt, COUNT(*) AS cnt FROM `" . BJ_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC, cnt DESC LIMIT 10");

// 筹码颜色（按 BJ_CHIPS 顺序）
$chipColors = ['#7f8c8d', '#c0392b', '#2e86c1', '#8e44ad', '#d4a017'];
function bj_chip_label($v) {
    if ($v >= 1000) { $k = $v / 1000; return (floor($k) == $k ? (int)$k : $k) . 'K'; }
    return (string)$v;
}
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=no" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
<title>二十一点</title>
<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; height: 100%; overflow: hidden; }
body { background: #07120d; color: #fff; font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif; }
a { color: inherit; text-decoration: none; }

.cz { position: fixed; top: 0; left: 0; right: 0; height: 100vh; height: 100dvh; display: flex; flex-direction: column; background:
    radial-gradient(ellipse 120% 90% at 50% -16%, #2f9b72 0%, #14785a 38%, #0c5740 62%, #073f2d 100%);
}
.cz::after { content: ""; position: absolute; inset: 0; border: 14px solid #2a1a0e; border-radius: 0; pointer-events: none;
    box-shadow: inset 0 0 0 2px #6b4a2b, inset 0 0 40px rgba(0,0,0,.55); }

/* 顶栏 */
.cz-top { position: relative; z-index: 5; display: flex; align-items: flex-start; justify-content: space-between; padding: 10px 14px 0; }
.cz-tbtn { display: flex; flex-direction: column; align-items: center; gap: 2px; font-size: 11px; color: #d8e6df; opacity: .92; }
.cz-tbtn svg { width: 22px; height: 22px; }
.cz-mid { text-align: center; margin-top: 2px; }
.cz-minmax { display: inline-block; font-size: 11px; font-weight: 800; color: #ffe9a8; background: rgba(0,0,0,.35); border: 1px solid rgba(255,210,120,.4); border-radius: 6px; padding: 3px 9px; }
.cz-tnum { font-size: 11px; color: #cfe6dd; margin-top: 3px; }

/* 桌面文字弧 */
.cz-arc { position: absolute; top: 8%; left: 50%; transform: translateX(-50%); width: 70%; max-width: 560px; pointer-events: none; z-index: 2; }
.cz-arc text { fill: #ffd86b; font-size: 30px; font-weight: 800; letter-spacing: 3px; }
.cz-rules { position: absolute; top: calc(8% + 64px); left: 0; right: 0; text-align: center; font-size: 11px; color: rgba(255,255,255,.5); z-index: 2; letter-spacing: 1px; }

/* 牌区 */
.cz-felt { position: relative; z-index: 3; flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: space-between; padding: 4px 0; min-height: 0; }
.cz-hand { text-align: center; }
.cz-hand-lab { font-size: 12px; color: rgba(255,255,255,.7); margin-bottom: 4px; }
.cz-dealer { margin-top: 54px; }
.cz-player { margin-bottom: 4px; }
.bj-cards { display: flex; gap: 8px; justify-content: center; min-height: 92px; }
.bj-card { width: 66px; height: 92px; border-radius: 8px; background: #fff; color: #1b2b3a; display: flex; flex-direction: column; justify-content: space-between; padding: 6px 8px; font-weight: 800; font-size: 20px; box-shadow: 0 3px 9px rgba(0,0,0,.45); }
.bj-card.red { color: #d8362f; }
.bj-card .bot { align-self: flex-end; transform: rotate(180deg); }
.bj-card.back { background: repeating-linear-gradient(45deg, #b8472f, #b8472f 7px, #8f3322 7px, #8f3322 14px); border: 2px solid #f4e3c0; }
.cz-pts { display: inline-block; margin-top: 6px; font-size: 13px; font-weight: 800; color: #07221a; background: #ffd86b; padding: 2px 11px; border-radius: 999px; min-height: 19px; }
.cz-pts:empty { display: none; }

/* 结果横幅 */
.cz-result { position: absolute; top: 42%; left: 50%; transform: translate(-50%,-50%); z-index: 6; text-align: center; font-size: 34px; font-weight: 900; padding: 8px 30px; border-radius: 8px; white-space: nowrap; text-shadow: 0 2px 8px rgba(0,0,0,.6); display: none; }
.cz-result.show { display: block; }
.cz-result.win { color: #fff; background: linear-gradient(180deg,#1f6fb0,#0b3f6b); box-shadow: 0 0 0 2px #ffd86b, 0 8px 24px rgba(0,0,0,.5); }
.cz-result.lose { color: #ffd9d9; background: linear-gradient(180deg,#7a2b2b,#4a1414); box-shadow: 0 0 0 2px #d98a8a, 0 8px 24px rgba(0,0,0,.5); }
.cz-result.push { color: #2a2009; background: linear-gradient(180deg,#ffd86b,#d9a93a); box-shadow: 0 8px 24px rgba(0,0,0,.5); }

/* 底栏 */
.cz-bar { position: relative; z-index: 5; display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 16px calc(8px + env(safe-area-inset-bottom)); background: transparent; }
.cz-money { flex: none; min-width: 96px; }
.cz-money .v { font-size: 19px; font-weight: 900; color: #ffd86b; }
.cz-money .k { font-size: 10px; color: #9fb6a8; }
.cz-money .st { font-size: 10px; color: #8fb8a4; min-height: 12px; }

.cz-mid-ctl { flex: 1; display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: nowrap; }
.cz-chip { width: 52px; height: 52px; border-radius: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center; font-size: 13px; font-weight: 900; color: #fff; cursor: pointer; flex: none; border: 3px dashed rgba(255,255,255,.85); box-shadow: 0 3px 8px rgba(0,0,0,.45); text-shadow: 0 1px 2px rgba(0,0,0,.5); position: relative; }
.cz-chip.sel { outline: 3px solid #ffd86b; outline-offset: 1px; }
.cz-act { min-width: 64px; height: 50px; border-radius: 25px; border: none; font-size: 15px; font-weight: 900; color: #fff; cursor: pointer; padding: 0 16px; box-shadow: 0 3px 9px rgba(0,0,0,.4); }
.cz-act.hit { background: #1f9a52; }
.cz-act.stand { background: #3a6ea5; }
.cz-act.dbl { background: #c0883a; }
.cz-act:disabled { opacity: .45; }

.cz-deal { flex: none; width: 86px; height: 64px; border-radius: 12px; background: linear-gradient(180deg,#c0392b,#7d1f16); border: 2px solid #ffd86b; color: #fff; font-weight: 900; cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1px; box-shadow: 0 4px 12px rgba(0,0,0,.5); }
.cz-deal .big { font-size: 20px; letter-spacing: 1px; }
.cz-deal .sub { font-size: 10px; opacity: .85; font-weight: 700; }
.cz-deal:disabled { opacity: .5; }

/* 横屏：下注区(筹码/余额/发牌)仍在底部不动；只把「下注后」的要牌/停牌/加倍移到屏幕左右两侧，
   避免被浏览器上下地址栏/工具栏遮挡。牌区两侧留出空间。 */
@media (orientation: landscape) {
    .cz-arc, .cz-rules { display: none; }           /* 横屏隐藏装饰弧字/规则，腾出顶部空间 */
    .cz::after { border-width: 7px; }               /* 减薄桌沿，少点黑边 */
    .cz-top { padding-top: 4px; }
    .cz-dealer { margin-top: 8px; }
    .bj-card { width: 54px; height: 78px; font-size: 17px; }
    .cz-felt { padding: 0 124px; }
    .cz-act { min-width: 110px; height: 54px; }
    /* 停牌：左侧居中 */
    #bjActionRow #bjStand { position: fixed; left: calc(10px + env(safe-area-inset-left)); top: 50%; transform: translateY(-50%); z-index: 9; }
    /* 要牌 / 加倍：右侧上下排 */
    #bjActionRow #bjHit { position: fixed; right: calc(10px + env(safe-area-inset-right)); top: calc(50% - 36px); transform: translateY(-50%); z-index: 9; }
    #bjActionRow #bjDouble { position: fixed; right: calc(10px + env(safe-area-inset-right)); top: calc(50% + 36px); transform: translateY(-50%); z-index: 9; }
}

/* 竖屏提示 */
.cz-rotate { position: fixed; inset: 0; z-index: 200; background: #07120d; display: none; flex-direction: column; align-items: center; justify-content: center; gap: 18px; text-align: center; padding: 30px; }
@media (orientation: portrait) { body:not(.force-portrait) .cz-rotate { display: flex; } }
.cz-rotate .ico { font-size: 56px; animation: czspin 2.2s ease-in-out infinite; }
@keyframes czspin { 0%,55% { transform: rotate(0); } 80%,100% { transform: rotate(-90deg); } }
.cz-rotate p { margin: 0; color: #cfe6dd; font-size: 16px; }
.cz-rotate .btns { display: flex; gap: 12px; }
.cz-rotate button { padding: 11px 20px; border-radius: 10px; border: 1px solid rgba(255,210,120,.5); background: #14785a; color: #fff; font-size: 15px; font-weight: 800; cursor: pointer; }
.cz-rotate button.ghost { background: transparent; color: #9fb6a8; border-color: rgba(255,255,255,.2); }

/* 榜单弹窗 */
.bj-modal { position: fixed; inset: 0; z-index: 300; display: none; }
.bj-modal.show { display: block; }
.bj-modal-mask { position: absolute; inset: 0; background: rgba(0,0,0,.62); }
.bj-modal-card { position: absolute; left: 50%; top: 50%; transform: translate(-50%,-50%); width: min(560px, 92vw); max-height: 88vh; overflow-y: auto; background: #10202f; border: 1px solid rgba(120,150,190,.3); border-radius: 16px; padding: 16px 14px; box-shadow: 0 20px 60px rgba(0,0,0,.6); }
.bj-modal-h { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.bj-modal-h h3 { margin: 0; font-size: 17px; }
.bj-modal-x { font-size: 22px; color: #9fb6cf; padding: 2px 8px; cursor: pointer; }
.bj-mine { margin-bottom: 16px; }
.bj-mine h4 { margin: 0 0 8px; font-size: 14px; color: #cfe0f2; }
.bj-tbl { width: 100%; border-collapse: collapse; font-size: 12px; color: #dce8f6; }
.bj-tbl th, .bj-tbl td { padding: 6px 5px; border: 1px solid rgba(120,150,190,.22); text-align: center; }
.bj-pos { color: #4ade80; font-weight: 700; } .bj-neg { color: #f87171; font-weight: 700; }
</style>
</head>
<body>
<div class="cz">
    <div class="cz-top">
        <a class="cz-tbtn" href="/games/">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"></path></svg>
            大厅
        </a>
        <div class="cz-mid">
            <div class="cz-minmax">Min/Max <?php echo bj_chip_label(BJ_CHIPS[0]) ?> - <?php echo bj_chip_label(BJ_CHIPS[count(BJ_CHIPS) - 1]) ?></div>
            <div class="cz-tnum">二十一点 · 黑杰克 1.5 倍</div>
        </div>
        <span class="cz-tbtn" id="bjFsBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M21 16v3a2 2 0 0 1-2 2h-3M3 16v3a2 2 0 0 1 2 2h3"></path></svg>
            全屏
        </span>
        <span class="cz-tbtn" id="bjLbBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0V4zM7 6H4v2a3 3 0 0 0 3 3M17 6h3v2a3 3 0 0 1-3 3"></path></svg>
            排行榜
        </span>
    </div>

    <svg class="cz-arc" viewBox="0 0 600 130" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
        <defs><path id="bjArc" d="M30,120 A320,320 0 0,1 570,120" fill="none"></path></defs>
        <text text-anchor="middle"><textPath href="#bjArc" startOffset="50%">BLACKJACK PAYS 3 TO 2</textPath></text>
    </svg>
    <div class="cz-rules">庄家必须停在 17 点以上　·　保险 1:2</div>

    <div class="cz-felt">
        <div class="cz-hand cz-dealer">
            <div class="cz-hand-lab">庄家</div>
            <div class="bj-cards" id="bjDealer"></div>
            <span class="cz-pts" id="bjDealerVal"></span>
        </div>
        <div class="cz-hand cz-player">
            <div class="bj-cards" id="bjPlayer"></div>
            <span class="cz-pts" id="bjPlayerVal"></span>
            <div class="cz-hand-lab" style="margin-top:4px">玩家</div>
        </div>
    </div>

    <div class="cz-result" id="bjResult"></div>

    <div class="cz-bar">
        <div class="cz-money">
            <div class="v" id="bjBal"><?php echo $mBal ?></div>
            <div class="k">电影票</div>
            <div class="st" id="bjMsg"></div>
        </div>

        <div class="cz-mid-ctl" id="bjBetRow">
            <?php foreach (BJ_CHIPS as $i => $c) { ?>
                <span class="cz-chip<?php echo $i === 0 ? ' sel' : '' ?>" data-bet="<?php echo $c ?>" style="background:<?php echo $chipColors[$i] ?? '#555' ?>"><?php echo bj_chip_label($c) ?></span>
            <?php } ?>
            <span class="cz-chip" data-allin="1" style="background:#16a085">梭哈</span>
        </div>
        <div class="cz-mid-ctl" id="bjActionRow" style="display:none">
            <button type="button" class="cz-act hit" id="bjHit">要牌</button>
            <button type="button" class="cz-act stand" id="bjStand">停牌</button>
            <button type="button" class="cz-act dbl" id="bjDouble">加倍 ×2</button>
        </div>

        <button type="button" class="cz-deal" id="bjDeal"><span class="big">DEAL</span><span class="sub">发牌</span></button>
        <input type="hidden" id="bjBet" value="<?php echo BJ_CHIPS[0] ?>">
    </div>
</div>

<div class="cz-rotate">
    <div class="ico">📱</div>
    <p>横屏体验更佳</p>
    <div class="btns">
        <button type="button" id="bjGoLand">进入横屏</button>
        <button type="button" class="ghost" id="bjStayPort">继续竖屏</button>
    </div>
</div>

<div class="bj-modal" id="bjLbModal">
    <div class="bj-modal-mask" data-close="1"></div>
    <div class="bj-modal-card">
        <div class="bj-modal-h">
            <h3>🏆 二十一点榜单</h3>
            <span class="bj-modal-x" data-close="1">✕</span>
        </div>
        <div class="bj-mine" id="bjMine">
            <h4>我的最近战绩（共 <?php echo $mN ?> 局，净 <span class="<?php echo $mNet >= 0 ? 'bj-pos' : 'bj-neg' ?>"><?php echo ($mNet >= 0 ? '+' : '') . number_format($mNet, 0) ?></span>）</h4>
            <table class="bj-tbl">
                <tr><th>时间</th><th>下注</th><th>结果</th><th>盈亏</th></tr>
                <?php while ($r = mysql_fetch_assoc($myRes)) { ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['created_at']) ?></td>
                        <td><?php echo (int)$r['bet'] ?></td>
                        <td><?php echo htmlspecialchars(bj_outcome_label($r['outcome'])) ?></td>
                        <td class="<?php echo (float)$r['delta'] >= 0 ? 'bj-pos' : 'bj-neg' ?>"><?php echo ((float)$r['delta'] >= 0 ? '+' : '') . bj_money($r['delta']) ?></td>
                    </tr>
                <?php } ?>
            </table>
        </div>
        <div id="bjLb">
            <?php
            echo game_lb_css();
            echo game_lb_table('💰 盈亏榜', $bjNet, '净盈亏',
                function ($r) { return ((float)$r['amt'] >= 0 ? '+' : '') . game_lb_money($r['amt']); },
                function ($r) { return (float)$r['amt'] >= 0 ? 'glb-pos' : 'glb-neg'; }, $bjNetLow);
            echo game_lb_table('🔥 活跃榜', $bjCnt, '对局数',
                function ($r) { return number_format((int)$r['amt']) . ' 局'; });
            echo game_lb_table('🍀 手气榜', $bjLuck, '单局最高赢',
                function ($r) { return game_lb_money($r['amt']); },
                function ($r) { return (float)$r['amt'] > 0 ? 'glb-pos' : ''; });
            ?>
        </div>
    </div>
</div>

<script>
(function () {
    var busy = false, bet = <?php echo BJ_CHIPS[0] ?>;
    var betInput = document.getElementById('bjBet');
    var betRow = document.getElementById('bjBetRow'), actionRow = document.getElementById('bjActionRow');
    var dealBtn = document.getElementById('bjDeal'), hitBtn = document.getElementById('bjHit'), standBtn = document.getElementById('bjStand'), doubleBtn = document.getElementById('bjDouble');
    var msg = document.getElementById('bjMsg'), result = document.getElementById('bjResult');
    var balEl = document.getElementById('bjBal');
    var initState = <?php echo json_encode($initState, JSON_UNESCAPED_UNICODE) ?: 'null' ?>;

    function cardHtml(c) {
        if (c.hidden) return '<div class="bj-card back"></div>';
        return '<div class="bj-card' + (c.red ? ' red' : '') + '"><span class="top">' + c.r + c.s + '</span><span class="bot">' + c.r + c.s + '</span></div>';
    }
    function fmt(n) { return (Math.round(n * 10) / 10).toFixed(1); }
    function refreshPanels() {
        fetch(location.href, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                ['bjMine', 'bjLb'].forEach(function (id) {
                    var f = doc.getElementById(id), cur = document.getElementById(id);
                    if (f && cur) cur.innerHTML = f.innerHTML;
                });
            }).catch(function () {});
    }

    function render(d) {
        document.getElementById('bjPlayer').innerHTML = d.player.map(cardHtml).join('');
        document.getElementById('bjDealer').innerHTML = d.dealer.map(cardHtml).join('');
        document.getElementById('bjPlayerVal').textContent = d.playerValue != null ? d.playerValue : '';
        document.getElementById('bjDealerVal').textContent = d.dealerValue != null ? d.dealerValue : '';
        if (d.balance != null) balEl.textContent = fmt(d.balance);
        if (d.status === 'playing') {
            betRow.style.display = 'none';
            dealBtn.style.display = 'none';
            actionRow.style.display = 'flex';
            doubleBtn.style.display = d.canDouble ? '' : 'none';
            result.className = 'cz-result';
        } else {
            actionRow.style.display = 'none';
            betRow.style.display = 'flex';
            dealBtn.style.display = 'flex';
            dealBtn.querySelector('.sub').textContent = '再来一局';
            if (d.outcomeLabel) {
                var cls = (d.outcome === 'win' || d.outcome === 'blackjack') ? 'win' : (d.outcome === 'push' ? 'push' : 'lose');
                result.className = 'cz-result show ' + cls;
                result.textContent = d.outcomeLabel + (d.doubled ? '（加倍）' : '');
                refreshPanels();
            }
        }
    }

    function post(action, extra) {
        if (busy) return;
        busy = true; msg.textContent = '';
        hitBtn.disabled = standBtn.disabled = doubleBtn.disabled = dealBtn.disabled = true;
        fetch('/games/blackjack/', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=' + action + (extra || '') })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { msg.innerHTML = '<span style="color:#ff9a9a">' + (d.error || '出错了') + '</span>'; return; }
                render(d);
            })
            .catch(function () { msg.innerHTML = '<span style="color:#ff9a9a">网络错误</span>'; })
            .finally(function () { busy = false; hitBtn.disabled = standBtn.disabled = doubleBtn.disabled = dealBtn.disabled = false; });
    }

    document.querySelectorAll('.cz-chip').forEach(function (c) {
        c.addEventListener('click', function () {
            document.querySelectorAll('.cz-chip').forEach(function (x) { x.classList.remove('sel'); });
            c.classList.add('sel');
            bet = c.getAttribute('data-allin') ? Math.max(1, Math.floor(parseFloat(balEl.textContent.replace(/,/g, '')) || 0)) : parseInt(c.getAttribute('data-bet'), 10);
            betInput.value = bet;
        });
    });
    dealBtn.addEventListener('click', function () { post('deal', '&bet=' + bet); });
    hitBtn.addEventListener('click', function () { post('hit'); });
    standBtn.addEventListener('click', function () { post('stand'); });
    doubleBtn.addEventListener('click', function () { post('double'); });
    if (initState) { render(initState); msg.textContent = '继续上一局'; }

    // 榜单弹窗
    var modal = document.getElementById('bjLbModal');
    document.getElementById('bjLbBtn').addEventListener('click', function () { modal.classList.add('show'); });
    modal.addEventListener('click', function (e) { if (e.target.getAttribute('data-close')) modal.classList.remove('show'); });

    // 横屏
    function goLandscape() {
        var el = document.documentElement;
        var req = el.requestFullscreen || el.webkitRequestFullscreen;
        try {
            var p = req ? req.call(el) : null;
            Promise.resolve(p).then(function () {
                if (screen.orientation && screen.orientation.lock) return screen.orientation.lock('landscape');
            }).catch(function () {});
        } catch (e) {}
    }
    document.getElementById('bjGoLand').addEventListener('click', goLandscape);
    document.getElementById('bjStayPort').addEventListener('click', function () { document.body.classList.add('force-portrait'); });

    // 顶部「全屏」按钮：进入/退出全屏（安卓 Chrome 有效；iPhone Safari 不支持网页全屏，给出提示）
    var fsBtn = document.getElementById('bjFsBtn');
    if (fsBtn) fsBtn.addEventListener('click', function () {
        var el = document.documentElement;
        var fs = document.fullscreenElement || document.webkitFullscreenElement;
        if (!fs) {
            var req = el.requestFullscreen || el.webkitRequestFullscreen;
            if (req) {
                try {
                    var p = req.call(el);
                    Promise.resolve(p).then(function () {
                        if (screen.orientation && screen.orientation.lock) return screen.orientation.lock('landscape');
                    }).catch(function () {});
                } catch (e) {}
            } else {
                alert('当前浏览器不支持网页全屏（iPhone Safari 限制）。已把操作按钮放到屏幕左右两侧，横屏也不会被地址栏挡住；想要真全屏可用安卓 Chrome，或把页面「添加到主屏幕」后从图标打开。');
            }
        } else {
            try { if (screen.orientation && screen.orientation.unlock) screen.orientation.unlock(); } catch (e) {}
            var ex = document.exitFullscreen || document.webkitExitFullscreen;
            if (ex) ex.call(document);
        }
    });
})();
</script>
</body>
</html>
