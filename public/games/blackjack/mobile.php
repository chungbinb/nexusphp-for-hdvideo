<?php
/**
 * 二十一点 —— 手机版独立页面（自带完整 HTML 头尾，不经过桌面版 stdhead）。
 * 由 blackjack/index.php 在检测到手机 UA 时 require；游戏的 AJAX 逻辑仍在 index.php 里，
 * 本页只负责手机版界面 + 复用同一套前端 JS（元素 id 与桌面版一致）。
 * 特性：支持横屏（全屏+锁定）、榜单收进右上角「排行榜」悬浮按钮，点击弹出。
 */
if (!defined('BJ_GAME_TABLE')) { return; }

$mBal = bj_money($CURUSER['seedbonus'] ?? 0);
$mN = (int)($sum['n'] ?? 0);
$mNet = (float)($sum['net'] ?? 0);

// 榜单数据
$bjNet = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, SUM(`s`.`delta`) AS amt FROM `" . BJ_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
$bjNetLow = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, SUM(`s`.`delta`) AS amt FROM `" . BJ_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt ASC LIMIT 10");
$bjCnt = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, COUNT(*) AS amt FROM `" . BJ_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
$bjLuck = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, MAX(`s`.`delta`) AS amt, COUNT(*) AS cnt FROM `" . BJ_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC, cnt DESC LIMIT 10");
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1" />
<title>二十一点</title>
<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; }
body { background: #0c1622; color: #e7eef7; font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif; line-height: 1.45; }
a { color: inherit; text-decoration: none; }
.bj { max-width: 760px; margin: 0 auto; padding: 8px 12px calc(14px + env(safe-area-inset-bottom)); }

.bj-bar { display: flex; align-items: center; gap: 8px; margin: 2px 0 10px; }
.bj-back { font-size: 14px; color: #9fb6cf; padding: 6px 4px; flex: none; }
.bj-tt { font-size: 18px; font-weight: 800; flex: 1; }
.bj-pill { flex: none; font-size: 13px; font-weight: 700; color: #cfe6ff; background: rgba(53,184,241,.16); border: 1px solid rgba(53,184,241,.4); padding: 6px 11px; border-radius: 999px; cursor: pointer; }
.bj-bal { font-size: 13px; color: #9fb6cf; margin: 0 2px 10px; }
.bj-bal b { color: #ffd770; font-size: 15px; }

.bj-felt { border: 1px solid rgba(120,150,190,.3); border-radius: 14px; padding: 14px; margin-bottom: 12px; background: radial-gradient(circle at 50% 0, #1d6b40, #0d3a22); color: #eafff1; }
.bj-lab { font-size: 13px; opacity: .85; margin-bottom: 7px; }
.bj-cards { display: flex; flex-wrap: wrap; gap: 7px; min-height: 78px; }
.bj-card { width: 52px; height: 74px; border-radius: 7px; background: #fff; color: #1b2b3a; display: flex; flex-direction: column; justify-content: space-between; padding: 5px 7px; font-weight: 800; font-size: 17px; box-shadow: 0 2px 6px rgba(0,0,0,.35); }
.bj-card.red { color: #d8362f; }
.bj-card .bot { align-self: flex-end; transform: rotate(180deg); }
.bj-card.back { background: repeating-linear-gradient(45deg, #2b5c8a, #2b5c8a 6px, #21476b 6px, #21476b 12px); }
.bj-val { font-size: 13px; font-weight: 700; opacity: .9; }
.bj-result { text-align: center; font-size: 21px; font-weight: 900; margin: 10px 0; min-height: 26px; }
.bj-win { color: #7CFFB0; } .bj-lose { color: #ff9a9a; } .bj-push { color: #ffe08a; }

.bj-ctl { border: 1px solid rgba(120,150,190,.3); border-radius: 12px; padding: 13px; background: rgba(30,60,100,.1); }
.bj-row { display: flex; flex-wrap: wrap; gap: 9px; align-items: center; }
.bj-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
.bj-chip { flex: 1 1 auto; min-width: 58px; text-align: center; padding: 9px 6px; border: 1px solid rgba(120,150,190,.45); border-radius: 8px; cursor: pointer; font-weight: 700; background: rgba(255,255,255,.06); color: #dce8f6; }
.bj-chip.sel { background: #1f9a52; color: #fff; border-color: #1f9a52; }
.bj-bet-input { flex: 1 1 90px; min-width: 80px; padding: 11px 9px; border: 1px solid rgba(120,150,190,.45); border-radius: 8px; background: #0e1c2c; color: #fff; font-size: 16px; }
.bj-btn { flex: 1 1 auto; min-height: 46px; padding: 0 18px; font-weight: 800; font-size: 15px; cursor: pointer; border-radius: 8px; border: 1px solid #1f9a52; background: #1f9a52; color: #fff; }
.bj-btn.alt { border-color: #3a6ea5; background: #3a6ea5; }
.bj-btn.warn { border-color: #c0883a; background: #c0883a; }
.bj-btn:disabled { opacity: .5; }
.bj-msg { font-size: 13px; color: #9fb6cf; margin-top: 10px; min-height: 18px; }

/* 横屏：拉大牌面、左右铺开 */
@media (orientation: landscape) {
    .bj { max-width: 100%; padding: 6px 16px; }
    .bj-card { width: 58px; height: 82px; }
    .bj-felt { padding: 10px 14px; margin-bottom: 8px; }
    .bj-result { margin: 6px 0; }
}

/* 榜单弹窗 */
.bj-modal { position: fixed; inset: 0; z-index: 100; display: none; }
.bj-modal.show { display: block; }
.bj-modal-mask { position: absolute; inset: 0; background: rgba(0,0,0,.6); }
.bj-modal-card { position: absolute; left: 0; right: 0; bottom: 0; max-height: 86vh; overflow-y: auto; background: #10202f; border-top-left-radius: 18px; border-top-right-radius: 18px; padding: 16px 14px calc(20px + env(safe-area-inset-bottom)); box-shadow: 0 -8px 30px rgba(0,0,0,.5); }
.bj-modal-h { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.bj-modal-h h3 { margin: 0; font-size: 17px; }
.bj-modal-x { font-size: 22px; color: #9fb6cf; padding: 2px 8px; cursor: pointer; }
.bj-mine { margin-bottom: 16px; }
.bj-mine h4 { margin: 0 0 8px; font-size: 14px; color: #cfe0f2; }
.bj-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
.bj-tbl th, .bj-tbl td { padding: 6px 5px; border: 1px solid rgba(120,150,190,.22); text-align: center; }
.bj-pos { color: #4ade80; font-weight: 700; } .bj-neg { color: #f87171; font-weight: 700; }
</style>
</head>
<body>
<div class="bj">
    <div class="bj-bar">
        <a class="bj-back" href="/games/">‹ 大厅</a>
        <div class="bj-tt">二十一点</div>
        <span class="bj-pill" id="bjLandBtn">⤢ 横屏</span>
        <span class="bj-pill" id="bjLbBtn">🏆 排行榜</span>
    </div>
    <div class="bj-bal">我的电影票：<b id="bjBal"><?php echo $mBal ?></b> 张</div>

    <div class="bj-felt">
        <div class="bj-lab">庄家 <span id="bjDealerVal" class="bj-val"></span></div>
        <div class="bj-cards" id="bjDealer"></div>
        <div class="bj-result" id="bjResult"></div>
        <div class="bj-lab">玩家 <span id="bjPlayerVal" class="bj-val"></span></div>
        <div class="bj-cards" id="bjPlayer"></div>
    </div>

    <div class="bj-ctl">
        <div id="bjBetRow">
            <div class="bj-chips">
                <?php foreach (BJ_CHIPS as $i => $c) { ?>
                    <span class="bj-chip<?php echo $i === 0 ? ' sel' : '' ?>" data-bet="<?php echo $c ?>"><?php echo number_format($c) ?></span>
                <?php } ?>
                <span class="bj-chip" data-allin="1">梭哈</span>
            </div>
            <div class="bj-row">
                <input type="number" min="1" class="bj-bet-input" id="bjBet" value="<?php echo BJ_CHIPS[0] ?>">
                <button type="button" class="bj-btn" id="bjDeal">发牌</button>
            </div>
        </div>
        <div class="bj-row" id="bjActionRow" style="display:none">
            <button type="button" class="bj-btn" id="bjHit">要牌</button>
            <button type="button" class="bj-btn alt" id="bjStand">停牌</button>
            <button type="button" class="bj-btn warn" id="bjDouble">加倍</button>
        </div>
        <div class="bj-msg" id="bjMsg"></div>
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
        var cls = 'bj-card' + (c.red ? ' red' : '');
        return '<div class="' + cls + '"><span class="top">' + c.r + c.s + '</span><span class="bot">' + c.r + c.s + '</span></div>';
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
        document.getElementById('bjPlayerVal').textContent = d.playerValue != null ? ('(' + d.playerValue + ')') : '';
        document.getElementById('bjDealerVal').textContent = d.dealerValue != null ? ('(' + d.dealerValue + ')') : '';
        if (d.balance != null) balEl.textContent = fmt(d.balance);
        if (d.status === 'playing') {
            betRow.style.display = 'none';
            actionRow.style.display = 'flex';
            doubleBtn.style.display = d.canDouble ? '' : 'none';
            result.textContent = '';
        } else {
            actionRow.style.display = 'none';
            betRow.style.display = 'block';
            dealBtn.textContent = '再来一局';
            if (d.outcomeLabel) {
                var cls = (d.outcome === 'win' || d.outcome === 'blackjack') ? 'bj-win' : (d.outcome === 'push' ? 'bj-push' : 'bj-lose');
                result.className = 'bj-result ' + cls;
                result.textContent = d.outcomeLabel;
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

    document.querySelectorAll('.bj-chip').forEach(function (c) {
        c.addEventListener('click', function () {
            document.querySelectorAll('.bj-chip').forEach(function (x) { x.classList.remove('sel'); });
            c.classList.add('sel');
            bet = c.getAttribute('data-allin') ? Math.max(1, Math.floor(parseFloat(balEl.textContent) || 0)) : parseInt(c.getAttribute('data-bet'), 10);
            betInput.value = bet;
        });
    });
    betInput.addEventListener('input', function () {
        bet = Math.max(1, parseInt(betInput.value, 10) || 1);
        document.querySelectorAll('.bj-chip').forEach(function (x) { x.classList.remove('sel'); });
    });
    dealBtn.addEventListener('click', function () { post('deal', '&bet=' + bet); });
    hitBtn.addEventListener('click', function () { post('hit'); });
    standBtn.addEventListener('click', function () { post('stand'); });
    doubleBtn.addEventListener('click', function () { post('double'); });
    if (initState) { render(initState); msg.textContent = '继续上一局未打完的牌局'; }

    // 排行榜弹窗
    var modal = document.getElementById('bjLbModal');
    document.getElementById('bjLbBtn').addEventListener('click', function () { modal.classList.add('show'); });
    modal.addEventListener('click', function (e) { if (e.target.getAttribute('data-close')) modal.classList.remove('show'); });

    // 横屏（全屏 + 尝试锁定，安卓有效；iPhone 仅全屏，方向跟随系统）
    document.getElementById('bjLandBtn').addEventListener('click', function () {
        var el = document.documentElement;
        var fs = document.fullscreenElement || document.webkitFullscreenElement;
        if (!fs) {
            var req = el.requestFullscreen || el.webkitRequestFullscreen;
            try {
                var p = req ? req.call(el) : null;
                Promise.resolve(p).then(function () {
                    if (screen.orientation && screen.orientation.lock) return screen.orientation.lock('landscape');
                }).catch(function () {});
            } catch (e) {}
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
