<?php
/**
 * 斗地主 牌桌 —— 全屏「欢乐斗地主」式牌桌（电脑+手机自适应，纯 HTML5+CSS3，无美术图）。
 * 由 ddz/index.php 在进桌(等待/匹配/叫分/出牌/结算)时 require。复用 ddz 函数/常量；不经过 stdhead。
 * 数据全部走既有轮询接口 ?ajax=poll；动作走既有 POST(bid/play/pass/replay) 与 ?ajax=hint。
 */
if (!defined('DDZ_BUSINESS_TYPE') || !$room) { return; }

$uid = (int)$CURUSER['id'];
$myBean = floor((float)($CURUSER['seedbonus'] ?? 0));
$myAvatar = trim((string)($CURUSER['avatar'] ?? ''));
$roomId = (int)$room['id'];
$base = (int)$room['base'];
$inviteUrl = get_protocol_prefix() . $BASEURL . '/games/ddz/?table=' . $roomId;
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=no" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
<title>斗地主 · 牌桌</title>
<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; height: 100%; }
body { background: #0a1430; color: #fff; font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif; overflow: hidden; }
a { color: inherit; text-decoration: none; }

.gt { position: fixed; inset: 0; height: 100vh; height: 100dvh; overflow: hidden; display: flex; flex-direction: column;
    padding-left: env(safe-area-inset-left); padding-right: env(safe-area-inset-right);
    background:
      linear-gradient(180deg, #2a3a6e 0%, #243358 14%, #14224a 30%, #0e1838 60%);
}
/* 远景天际线（纯 CSS 剪影） */
.gt-sky { position: absolute; left: 0; right: 0; top: 0; height: 34%; z-index: 0; overflow: hidden; opacity: .55;
    background:
      radial-gradient(60% 80% at 80% 10%, rgba(120,150,255,.35), transparent 60%),
      radial-gradient(50% 70% at 15% 0%, rgba(90,200,255,.25), transparent 60%); }
.gt-sky::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 60%;
    background:
      repeating-linear-gradient(90deg, rgba(20,30,70,.0) 0 14px, rgba(30,45,95,.55) 14px 30px),
      linear-gradient(180deg, transparent, rgba(12,24,60,.9));
    -webkit-mask-image: linear-gradient(180deg, transparent, #000 40%);
            mask-image: linear-gradient(180deg, transparent, #000 40%); }

/* 顶栏 */
.gt-top { position: relative; z-index: 6; display: flex; align-items: center; gap: 8px; padding: calc(6px + env(safe-area-inset-top)) 12px 4px; }
.gt-chip { display: inline-flex; align-items: center; gap: 5px; background: rgba(0,0,0,.4); border: 1px solid rgba(150,180,255,.3); border-radius: 999px; padding: 4px 11px; font-size: 12px; font-weight: 800; }
.gt-chip b { color: #ffe08a; }
.gt-top .sp { margin-left: auto; }
.gt-iconbtn { width: 32px; height: 32px; border-radius: 50%; background: rgba(0,0,0,.4); border: 1px solid rgba(150,180,255,.3); display: flex; align-items: center; justify-content: center; cursor: pointer; }
.gt-iconbtn svg { width: 16px; height: 16px; }
.gt-pill { display: inline-flex; align-items: center; gap: 5px; height: 32px; padding: 0 12px; border-radius: 999px; cursor: pointer; font-size: 13px; font-weight: 800; color: #cfe0ff; background: rgba(0,0,0,.4); border: 1px solid rgba(150,180,255,.3); }
.gt-pill svg { width: 16px; height: 16px; }
.gt-pill.on { color: #3a2400; background: linear-gradient(135deg,#ffce4f,#f0980c); border-color: #ffce4f; }
/* 左下/右下角 HUD */
.gt-hud { position: absolute; z-index: 6; bottom: calc(12px + env(safe-area-inset-bottom)); display: flex; gap: 6px; }
.gt-hud.bl { left: 12px; }
.gt-hud.br { right: 12px; }

/* 牌桌区 */
.gt-table { position: relative; z-index: 2; flex: 1; min-height: 0; margin: 2px 8px 0; }
.gt-felt { position: absolute; left: 3%; right: 3%; top: 14%; bottom: 6%; border-radius: 50%/40%;
    background:
      radial-gradient(120% 100% at 50% 30%, #2aa05a 0%, #1f8a4d 40%, #15613a 80%, #0f4a2c 100%);
    box-shadow: inset 0 0 0 6px rgba(255,255,255,.06), inset 0 10px 40px rgba(0,0,0,.45), 0 14px 40px rgba(0,0,0,.5);
    border: 3px solid rgba(255,215,120,.25); }
.gt-felt::before { content: ""; position: absolute; inset: 12px; border-radius: 50%/42%; border: 1px dashed rgba(255,255,255,.14); }

/* 座位 */
.gt-seat { position: absolute; z-index: 4; display: flex; align-items: center; gap: 8px; }
.gt-seat.left { left: 2%; top: 16%; }
.gt-seat.right { right: 2%; top: 16%; flex-direction: row-reverse; }
.gt-ava { position: relative; width: 56px; height: 56px; border-radius: 50%; flex: none; overflow: hidden;
    background: linear-gradient(135deg,#7c6cff,#3aa0ff); display: flex; align-items: center; justify-content: center;
    font-weight: 900; font-size: 22px; color: #fff; box-shadow: 0 0 0 2px rgba(255,255,255,.35), 0 4px 10px rgba(0,0,0,.4); }
.gt-ava img { width: 100%; height: 100%; object-fit: cover; }
.gt-seat.turn .gt-ava { box-shadow: 0 0 0 3px #ffd44f, 0 0 16px 2px rgba(255,212,79,.7); }
.gt-meta { display: flex; flex-direction: column; gap: 2px; max-width: 110px; }
.gt-seat.right .gt-meta { align-items: flex-end; }
.gt-nm { font-size: 12px; font-weight: 800; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100px; text-shadow: 0 1px 2px rgba(0,0,0,.6); }
.gt-cnt { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; color: #ffe08a; font-weight: 800; background: rgba(0,0,0,.4); padding: 1px 8px; border-radius: 999px; }
.gt-role { position: absolute; left: -6px; top: -8px; z-index: 5; font-size: 10px; font-weight: 900; padding: 1px 6px; border-radius: 6px; background: linear-gradient(135deg,#ff5d6c,#c8324a); box-shadow: 0 2px 5px rgba(0,0,0,.4); }
.gt-seat.right .gt-role { left: auto; right: -6px; }
.gt-role.lord::before { content: "👑 "; }
/* 倒计时小圈 */
.gt-timer { position: absolute; z-index: 6; width: 30px; height: 30px; border-radius: 50%; display: none; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 900; color: #3a2400; background: radial-gradient(circle,#ffe9a8,#ffce4f); box-shadow: 0 0 0 2px rgba(0,0,0,.25), 0 3px 8px rgba(0,0,0,.4); }
.gt-seat.turn .gt-timer { display: flex; }
.gt-seat.left .gt-timer { left: 40px; top: 40px; }
.gt-seat.right .gt-timer { right: 40px; top: 40px; }

/* 出牌区（按出牌者方位摆放） */
.gt-play { position: absolute; z-index: 3; display: flex; }
.gt-play .gt-card { margin-right: -14px; }
.gt-play.left { left: 12%; top: 40%; }
.gt-play.right { right: 12%; top: 40%; }
.gt-play.mine { left: 50%; bottom: 2px; transform: translateX(-50%); }
.gt-pass { position: absolute; z-index: 4; color: #ffd44f; font-weight: 900; font-size: 18px; text-shadow: 0 2px 4px rgba(0,0,0,.6); display: none; }
.gt-pass.left { left: 13%; top: 44%; }
.gt-pass.right { right: 13%; top: 44%; }
/* 底牌 */
.gt-bottom3 { position: absolute; z-index: 4; left: 50%; top: 1%; transform: translateX(-50%); display: flex; gap: 4px; align-items: center; }
.gt-bottom3 .lab { font-size: 11px; color: #ffe08a; margin-right: 4px; }

/* 中央状态/匹配 */
.gt-center { position: absolute; z-index: 4; left: 50%; top: 46%; transform: translate(-50%,-50%); text-align: center; pointer-events: none; }
.gt-center .big { font-size: 18px; font-weight: 900; text-shadow: 0 2px 6px rgba(0,0,0,.6); }
.gt-center .sub { font-size: 13px; color: #cfe0ff; margin-top: 6px; }
.gt-spin { width: 38px; height: 38px; margin: 0 auto 10px; border-radius: 50%; border: 4px solid rgba(255,255,255,.25); border-top-color: #ffd44f; animation: gtspin 1s linear infinite; }
@keyframes gtspin { to { transform: rotate(360deg); } }

/* 卡牌 */
.gt-card { position: relative; width: 38px; height: 54px; border-radius: 6px; background: #fff; color: #1b2b3a;
    border: 1px solid #d7dbe4; box-shadow: 0 2px 5px rgba(0,0,0,.3); flex: none; }
.gt-card.red { color: #d62828; }
.gt-card .r { position: absolute; left: 3px; top: 1px; font-size: 15px; font-weight: 900; font-style: normal; line-height: 1; }
.gt-card .s { position: absolute; right: 3px; bottom: 2px; font-size: 14px; font-style: normal; }
.gt-card .r.sm { font-size: 11px; top: 3px; }
/* 牌背 */
.gt-back { width: 30px; height: 44px; border-radius: 5px; flex: none; border: 1px solid rgba(255,255,255,.5);
    background: repeating-linear-gradient(45deg,#3a57c8 0 6px,#2c44a0 6px 12px); box-shadow: 0 2px 4px rgba(0,0,0,.35); }

/* 我的手牌 */
.gt-hand-wrap { position: relative; z-index: 5; padding: 0 10px calc(6px + env(safe-area-inset-bottom)); }
.gt-hand { display: flex; justify-content: center; min-height: 70px; padding-top: 16px; }
.gt-hand .gt-card { width: 46px; height: 66px; margin-right: -24px; cursor: pointer; transition: transform .1s ease; touch-action: none; user-select: none; -webkit-user-select: none; }
.gt-hand .gt-card:last-child { margin-right: 0; }
.gt-hand .gt-card .r { font-size: 18px; }
.gt-hand .gt-card .s { font-size: 16px; }
.gt-hand .gt-card.sel { transform: translateY(-18px); box-shadow: 0 8px 14px rgba(0,0,0,.4); border-color: #2ecc71; }
/* 发牌动画：一张一张飞入 */
.gt-hand .gt-card.deal { animation: gtdeal .34s cubic-bezier(.2,.7,.3,1) backwards; }
@keyframes gtdeal { from { transform: translate(-40vw, -150px) rotate(-25deg) scale(.5); opacity: 0; } to { transform: none; opacity: 1; } }

/* 操作区 */
.gt-actions { position: relative; z-index: 6; display: flex; align-items: center; justify-content: center; gap: 14px; min-height: 50px; padding: 0 12px 4px; }
.gt-btn { min-width: 96px; height: 44px; padding: 0 18px; border: none; border-radius: 24px; font-size: 16px; font-weight: 900; cursor: pointer; color: #fff;
    box-shadow: 0 5px 0 rgba(0,0,0,.22), 0 6px 14px rgba(0,0,0,.35); transition: transform .08s ease, box-shadow .08s ease; }
.gt-btn:active { transform: translateY(3px); box-shadow: 0 2px 0 rgba(0,0,0,.22); }
.gt-btn.play { background: linear-gradient(180deg,#ff8a3d,#e8590c); }
.gt-btn.pass { background: linear-gradient(180deg,#8ea0c8,#5b6a90); }
.gt-btn.hint { background: linear-gradient(180deg,#3ec6ff,#1f86d0); }
.gt-btn.bid { background: linear-gradient(180deg,#ffce4f,#f0980c); color: #4a2c00; min-width: 70px; }
.gt-btn.bid.no { background: linear-gradient(180deg,#9fb0d6,#6677a0); color: #fff; }
.gt-btn:disabled { opacity: .45; cursor: not-allowed; }
.gt-actbtn-timer { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%;
    background: radial-gradient(circle,#ffe9a8,#ffce4f); color: #3a2400; font-weight: 900; font-size: 16px; box-shadow: 0 3px 8px rgba(0,0,0,.4); }
.gt-actlabel { font-size: 14px; font-weight: 800; color: #cfe0ff; }

/* 结算遮罩 */
.gt-result { position: fixed; inset: 0; z-index: 30; display: none; align-items: center; justify-content: center; background: rgba(4,8,22,.72); }
.gt-result.show { display: flex; }
.gt-rcard { width: min(440px, 92vw); text-align: center; }
.gt-rtitle { font-size: 46px; font-weight: 900; letter-spacing: 4px; margin-bottom: 4px;
    background: linear-gradient(180deg,#fff,#ffd44f); -webkit-background-clip: text; background-clip: text; color: transparent;
    filter: drop-shadow(0 4px 10px rgba(0,0,0,.5)); }
.gt-rtitle.lose { background: linear-gradient(180deg,#fff,#9fb0d6); -webkit-background-clip: text; background-clip: text; }
.gt-rspring { font-size: 14px; font-weight: 800; color: #ff6f7d; margin-bottom: 12px; }
.gt-rlist { background: rgba(8,16,40,.85); border: 1px solid rgba(150,180,255,.3); border-radius: 16px; padding: 10px; margin: 12px 0 16px; }
.gt-rrow { display: flex; align-items: center; gap: 10px; padding: 9px 8px; border-radius: 10px; }
.gt-rrow:nth-child(odd) { background: rgba(255,255,255,.04); }
.gt-rrow.win { background: linear-gradient(90deg, rgba(255,206,79,.22), transparent); box-shadow: inset 0 0 0 1px rgba(255,206,79,.4); }
.gt-rrow .av { width: 38px; height: 38px; border-radius: 50%; overflow: hidden; flex: none; background: linear-gradient(135deg,#7c6cff,#3aa0ff); display: flex; align-items: center; justify-content: center; font-weight: 900; }
.gt-rrow .av img { width: 100%; height: 100%; object-fit: cover; }
.gt-rrow .info { flex: 1; min-width: 0; text-align: left; }
.gt-rrow .info .n { font-size: 14px; font-weight: 800; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.gt-rrow .info .m { font-size: 11px; color: #9fb0da; }
.gt-rrow .delta { font-size: 17px; font-weight: 900; }
.gt-rrow .delta.pos { color: #ffd44f; }
.gt-rrow .delta.neg { color: #7fc9ff; }
.gt-rbtns { display: flex; gap: 12px; justify-content: center; }
.gt-rbtns .gt-btn { min-width: 130px; }

.gt-toast { position: fixed; left: 50%; bottom: 92px; transform: translateX(-50%); z-index: 40; background: rgba(0,0,0,.85); color: #fff; padding: 9px 18px; border-radius: 999px; font-size: 14px; font-weight: 700; opacity: 0; transition: opacity .2s ease; pointer-events: none; }
.gt-toast.show { opacity: 1; }

@media (min-width: 820px) {
    .gt { max-width: 1100px; margin: 0 auto; box-shadow: 0 0 50px rgba(0,0,0,.6); }
    .gt-card { width: 42px; height: 60px; }
    .gt-hand .gt-card { width: 54px; height: 78px; margin-right: -28px; }
    .gt-ava { width: 64px; height: 64px; }
}
@media (max-width: 560px) {
    .gt-hand .gt-card { width: 34px; height: 50px; margin-right: -19px; }
    .gt-hand .gt-card .r { font-size: 14px; }
    .gt-hand .gt-card .s { font-size: 12px; }
    .gt-ava { width: 46px; height: 46px; font-size: 18px; }
    .gt-btn { min-width: 78px; height: 40px; font-size: 14px; }
}
</style>
</head>
<body>
<div class="gt" id="gt">
    <div class="gt-sky"></div>

    <div class="gt-top">
        <div class="gt-iconbtn" id="gtExit" title="退出"><svg viewBox="0 0 24 24" fill="none" stroke="#ffb4b4" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg></div>
        <span class="sp"></span>
        <div class="gt-pill" id="gtTrustee" title="托管给机器人代打"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="8" width="16" height="11" rx="3"/><path d="M12 8V4M9 4h6M8.5 13h.01M15.5 13h.01M9 16.5h6"/></svg>托管</div>
        <div class="gt-iconbtn" id="gtInvite" title="复制邀请链接"><svg viewBox="0 0 24 24" fill="none" stroke="#cfe0ff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4M18 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM18 22a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/></svg></div>
    </div>

    <div class="gt-table">
        <div class="gt-felt"></div>

        <div class="gt-seat left" id="gtSeatLeft">
            <div class="gt-ava"><b>?</b></div>
            <div class="gt-meta"><span class="gt-nm">等待中</span><span class="gt-cnt" style="display:none">剩 0</span></div>
            <div class="gt-timer">0</div>
        </div>
        <div class="gt-seat right" id="gtSeatRight">
            <div class="gt-ava"><b>?</b></div>
            <div class="gt-meta"><span class="gt-nm">等待中</span><span class="gt-cnt" style="display:none">剩 0</span></div>
            <div class="gt-timer">0</div>
        </div>

        <div class="gt-bottom3" id="gtBottom3" style="display:none"><span class="lab">底牌</span></div>
        <div class="gt-play left" id="gtPlayLeft"></div>
        <div class="gt-play right" id="gtPlayRight"></div>
        <div class="gt-play mine" id="gtPlayMine"></div>
        <div class="gt-pass left" id="gtPassLeft">不出</div>
        <div class="gt-pass right" id="gtPassRight">不出</div>

        <div class="gt-center" id="gtCenter"></div>
    </div>

    <div class="gt-hand-wrap">
        <div class="gt-actions" id="gtActions"></div>
        <div class="gt-hand" id="gtHand"></div>
    </div>

    <div class="gt-hud bl"><span class="gt-chip">🎟️ <b id="gtBean"><?php echo number_format($myBean) ?></b></span></div>
    <div class="gt-hud br">
        <span class="gt-chip">底分 <b><?php echo $base ?></b></span>
        <span class="gt-chip" id="gtMult" style="display:none">倍数 <b>1</b></span>
    </div>
</div>

<div class="gt-result" id="gtResult">
    <div class="gt-rcard">
        <div class="gt-rtitle" id="gtRTitle">胜利</div>
        <div class="gt-rspring" id="gtRSpring" style="display:none">🌸 春天 ×2</div>
        <div class="gt-rlist" id="gtRList"></div>
        <div class="gt-rbtns">
            <button class="gt-btn pass" id="gtRExit">返回大厅</button>
            <button class="gt-btn play" id="gtRReplay">继续游戏</button>
        </div>
    </div>
</div>

<div class="gt-toast" id="gtToast"></div>

<form method="post" action="/games/ddz/" id="gtLeaveForm" style="display:none">
    <input type="hidden" name="action" value="leave">
    <input type="hidden" name="table" value="<?php echo $roomId ?>">
</form>
<form method="post" action="/games/ddz/" id="gtJoinForm" style="display:none">
    <input type="hidden" name="action" value="join">
    <input type="hidden" name="table" value="<?php echo $roomId ?>">
</form>

<script>
(function () {
    var tableId = <?php echo $roomId ?>;
    var mySeatInit = <?php echo (int)$mySeat ?>;
    var myBean = <?php echo (int)$myBean ?>;
    var $ = function (id) { return document.getElementById(id); };
    var busy = false, handKey = '', selected = {}, finishedShown = false;
    var cur = null, prevStatus = '', dealAnim = false;
    var dragging = false, dragMode = false;
    var trustee = false, autoKey = '';

    function esc(s) { return (s || '').replace(/[&<>"]/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]); }); }
    function toast(t) { var el = $('gtToast'); el.textContent = t; el.classList.add('show'); clearTimeout(el._t); el._t = setTimeout(function () { el.classList.remove('show'); }, 1500); }

    function cardFace(c, big) {
        var lab = c.label, rank, suit;
        if (lab === '小王') { rank = '王'; suit = '小'; }
        else if (lab === '大王') { rank = '王'; suit = '大'; }
        else { suit = lab.charAt(0); rank = lab.slice(1); }
        var rcl = rank.length > 1 ? ' sm' : '';
        return '<span class="gt-card' + (c.red ? ' red' : '') + '"><i class="r' + rcl + '">' + rank + '</i><i class="s">' + suit + '</i></span>';
    }
    function avatarHtml(s) {
        if (s && s.avatar) return '<img src="' + esc(s.avatar) + '" alt="" onerror="this.style.display=\'none\'">';
        if (s && s.bot) return '<b>🤖</b>';
        return '<b>' + esc(((s && s.username) || '?').slice(0, 1)) + '</b>';
    }

    function post(action, extra) {
        if (busy) { return; }
        busy = true;
        var body = 'action=' + action + '&table=' + tableId + (extra || '');
        fetch('/games/ddz/', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (!j.ok && j.error) { toast(j.error); } poll(); })
            .catch(function () {}).finally(function () { busy = false; });
    }
    function hint() {
        fetch('/games/ddz/?ajax=hint&table=' + tableId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok || !j.cards.length) { toast('没有能压过的牌，建议过牌'); return; }
                selected = {};
                $('gtHand').querySelectorAll('.gt-card').forEach(function (el) { el.classList.remove('sel'); });
                j.cards.forEach(function (id) {
                    selected[id] = 1;
                    var el = $('gtHand').querySelector('.gt-card[data-id="' + id + '"]');
                    if (el) el.classList.add('sel');
                });
            }).catch(function () {});
    }

    function slotOf(seat, mySeat) {
        var me = mySeat >= 0 ? mySeat : 0;
        if (seat === me) return 'mine';
        if (seat === (me + 1) % 3) return 'right';
        return 'left';
    }

    function renderSeat(slot, s, d, isTurn, role) {
        var box = slot === 'left' ? $('gtSeatLeft') : $('gtSeatRight');
        box.classList.toggle('turn', !!isTurn);
        box.querySelector('.gt-ava').innerHTML = avatarHtml(s);
        var nm = box.querySelector('.gt-nm'), cnt = box.querySelector('.gt-cnt');
        nm.textContent = s ? s.username : (d.mm ? '匹配中…' : '等待中');
        if (s && (d.status === 'playing' || d.status === 'bidding' || d.status === 'finished')) {
            cnt.style.display = ''; cnt.textContent = '剩 ' + s.cards;
        } else { cnt.style.display = 'none'; }
        var old = box.querySelector('.gt-role'); if (old) old.remove();
        if (role) {
            var r = document.createElement('span');
            r.className = 'gt-role' + (role === '地主' ? ' lord' : '');
            r.textContent = role;
            box.appendChild(r);
        }
        if (isTurn && d.timeLeft != null) box.querySelector('.gt-timer').textContent = d.timeLeft;
    }

    function renderPlays(d, mySeat) {
        ['gtPlayLeft', 'gtPlayRight', 'gtPlayMine'].forEach(function (id) { $(id).innerHTML = ''; });
        $('gtPassLeft').style.display = 'none'; $('gtPassRight').style.display = 'none';
        var lp = d.lastPlay;
        if (lp && lp.cards && lp.cards.length) {
            var slot = slotOf(lp.seat, mySeat);
            var box = slot === 'left' ? $('gtPlayLeft') : (slot === 'right' ? $('gtPlayRight') : $('gtPlayMine'));
            box.innerHTML = lp.cards.map(function (c) { return cardFace(c); }).join('');
        }
        // 底牌
        var b3 = $('gtBottom3');
        if (d.bottom && (d.status === 'playing' || d.status === 'finished')) {
            b3.style.display = ''; b3.innerHTML = '<span class="lab">底牌</span>' + d.bottom.map(function (c) { return cardFace(c); }).join('');
        } else { b3.style.display = 'none'; }
    }

    function renderHand(d) {
        var handEl = $('gtHand');
        var key = (d.myHand || []).map(function (c) { return c.id; }).join(',');
        if (key === handKey) { return; }
        handKey = key; selected = {};
        if (!d.myHand || !d.myHand.length) { handEl.innerHTML = ''; return; }
        var deal = dealAnim; dealAnim = false;
        handEl.innerHTML = d.myHand.map(function (c) {
            return cardFace(c).replace('<span class="gt-card', '<span data-id="' + c.id + '" class="gt-card' + (deal ? ' deal' : ''));
        }).join('');
        if (deal) handEl.querySelectorAll('.gt-card').forEach(function (el, i) { el.style.animationDelay = (i * 45) + 'ms'; });
    }

    // —— 出牌/过牌/选牌 ——
    function doPlay() {
        var ids = Object.keys(selected);
        if (!ids.length) { toast('请先点选要出的牌'); return; }
        post('play', '&cards=' + ids.join(','));
    }
    function doPass() { post('pass', ''); }
    function applyCard(el) {
        if (!el) return; var id = el.getAttribute('data-id'); if (!id) return;
        if (dragMode) { selected[id] = 1; el.classList.add('sel'); }
        else { delete selected[id]; el.classList.remove('sel'); }
    }

    // —— 托管代打 ——
    function clientBid(d) {
        var rk = { '3':0,'4':1,'5':2,'6':3,'7':4,'8':5,'9':6,'10':7,'J':8,'Q':9,'K':10,'A':11,'2':12 };
        var cnt = {};
        (d.myHand || []).forEach(function (c) {
            var r = c.label === '小王' ? 13 : (c.label === '大王' ? 14 : rk[c.label.slice(1)]);
            cnt[r] = (cnt[r] || 0) + 1;
        });
        var score = 0;
        if (cnt[13] && cnt[14]) score += 7; else { if (cnt[14]) score += 3.5; if (cnt[13]) score += 2.5; }
        Object.keys(cnt).forEach(function (r) { r = +r; var c = cnt[r]; if (c === 4) score += 6; if (r === 12) score += c * 1.6; if (r === 11) score += c * 0.8; });
        var high = d.bid ? d.bid.high : 0;
        var want = score >= 9 ? 3 : (score >= 5.5 ? 2 : (score >= 3 ? 1 : 0));
        if (want > 0 && want <= high) want = 0;
        return want;
    }
    function maybeAuto(d) {
        if (busy) return;
        if (d.status === 'bidding' && d.bid && d.mySeat === d.bid.turn) {
            var k = 'b' + d.bid.acted;
            if (k === autoKey) return; autoKey = k;
            var sc = clientBid(d);
            setTimeout(function () { if (trustee && cur && cur.status === 'bidding' && cur.mySeat === cur.bid.turn) post('bid', '&score=' + sc); }, 700);
        } else if (d.status === 'playing' && d.mySeat === d.turn) {
            var leading = (!d.lastPlay) || (d.lastPlay.seat === d.mySeat);
            var k2 = 'p' + (d.myHand ? d.myHand.length : 0) + '_' + (d.lastPlay ? d.lastPlay.seat + 'x' + d.lastPlay.cards.length : 'L');
            if (k2 === autoKey) return; autoKey = k2;
            setTimeout(function () {
                if (!trustee || !cur || cur.status !== 'playing' || cur.mySeat !== cur.turn) return;
                fetch('/games/ddz/?ajax=hint&table=' + tableId, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (j.ok && j.cards.length) post('play', '&cards=' + j.cards.join(','));
                        else if (!leading) post('pass', '');
                    }).catch(function () {});
            }, 700);
        }
    }

    function renderActions(d) {
        var act = $('gtActions'); act.innerHTML = '';
        var turn = d.turn != null ? d.turn : -1;
        var bidTurn = d.bid ? d.bid.turn : -1;
        if (d.mySeat < 0) {
            if (d.status === 'waiting' && d.count < 3) {
                act.innerHTML = '<button class="gt-btn play" id="gtJoin">加入牌桌</button>';
                $('gtJoin').addEventListener('click', function () { $('gtJoinForm').submit(); });
            } else { act.innerHTML = '<span class="gt-actlabel">观战中</span>'; }
            return;
        }
        if (d.status === 'bidding' && d.mySeat === bidTurn) {
            var high = d.bid.high;
            var h = '<button class="gt-btn bid no" data-bid="0">不叫</button>';
            [1, 2, 3].forEach(function (v) { h += '<button class="gt-btn bid" data-bid="' + v + '"' + (v <= high ? ' disabled' : '') + '>' + v + ' 分</button>'; });
            if (d.timeLeft != null) h += '<span class="gt-actbtn-timer">' + d.timeLeft + '</span>';
            act.innerHTML = h;
            act.querySelectorAll('button[data-bid]').forEach(function (b) { b.addEventListener('click', function () { post('bid', '&score=' + b.getAttribute('data-bid')); }); });
        } else if (d.status === 'playing' && d.mySeat === turn) {
            var leading = (!d.lastPlay) || (d.lastPlay.seat === d.mySeat);
            act.innerHTML =
                '<button class="gt-btn pass" id="gtPass"' + (leading ? ' disabled' : '') + '>不出</button>' +
                (d.timeLeft != null ? '<span class="gt-actbtn-timer">' + d.timeLeft + '</span>' : '') +
                '<button class="gt-btn hint" id="gtHint">提示</button>' +
                '<button class="gt-btn play" id="gtPlay">出牌</button>';
            $('gtPlay').addEventListener('click', doPlay);
            $('gtHint').addEventListener('click', hint);
            if (!leading) $('gtPass').addEventListener('click', doPass);
        } else if (d.status === 'waiting') {
            act.innerHTML = '<span class="gt-actlabel">' + (d.mm ? (d.mmLeft > 0 ? '匹配中…' + d.mmLeft + ' 秒后补机器人' : '正在加入机器人…') : '等待玩家加入 ' + d.count + '/3') + '</span>';
        } else {
            var who = '';
            if (d.status === 'bidding' && d.seats[bidTurn]) who = d.seats[bidTurn].username + ' 叫分中…';
            else if (d.status === 'playing' && d.seats[turn]) who = d.seats[turn].username + ' 出牌中…';
            act.innerHTML = '<span class="gt-actlabel">' + esc(who) + '</span>';
        }
    }

    function renderCenter(d) {
        var c = $('gtCenter');
        if (d.status === 'waiting') {
            c.innerHTML = '<div class="gt-spin"></div><div class="big">' + (d.mm ? '正在匹配' : '等待加入') + '</div><div class="sub">' +
                (d.mm ? (d.mmLeft > 0 ? d.mmLeft + ' 秒内无人加入将补机器人开局' : '正在加入机器人…') : (d.count + ' / 3 人')) + '</div>';
            c.style.display = '';
        } else if (d.status === 'bidding') {
            c.innerHTML = '<div class="big">叫地主</div><div class="sub">最高 ' + (d.bid ? d.bid.high : 0) + ' 分</div>';
            c.style.display = '';
        } else { c.style.display = 'none'; c.innerHTML = ''; }
    }

    function showResult(d) {
        if (finishedShown) { return; }
        finishedShown = true;
        var iWon = d.mySeat >= 0 && ((d.landlordWon && d.mySeat === d.landlord) || (!d.landlordWon && d.mySeat !== d.landlord));
        var title = $('gtRTitle');
        if (d.mySeat < 0) { title.textContent = d.landlordWon ? '地主胜' : '农民胜'; title.className = 'gt-rtitle'; }
        else { title.textContent = iWon ? '胜利' : '失败'; title.className = 'gt-rtitle' + (iWon ? '' : ' lose'); }
        $('gtRSpring').style.display = d.spring ? '' : 'none';
        var rows = '';
        for (var i = 0; i < 3; i++) {
            var s = d.seats[i]; if (!s) continue;
            var dv = (d.deltas && d.deltas[i] != null) ? d.deltas[i] : 0;
            var rowWin = (d.landlordWon && i === d.landlord) || (!d.landlordWon && i !== d.landlord);
            rows += '<div class="gt-rrow' + (rowWin ? ' win' : '') + '">' +
                '<span class="av">' + avatarHtml(s) + '</span>' +
                '<span class="info"><span class="n">' + esc(s.username) + (i === d.landlord ? ' 👑' : '') + '</span>' +
                '<span class="m">底分 ' + d.base + ' · 倍数 ' + d.finalMult + '</span></span>' +
                '<span class="delta ' + (dv >= 0 ? 'pos' : 'neg') + '">' + (dv >= 0 ? '+' : '') + dv + '</span></div>';
        }
        $('gtRList').innerHTML = rows;
        // 更新自己豆子
        if (d.mySeat >= 0 && d.deltas) { myBean += Math.round(d.deltas[d.mySeat] || 0); $('gtBean').textContent = myBean.toLocaleString(); }
        $('gtResult').classList.add('show');
    }

    function render(d) {
        cur = d;
        if (d.status === 'bidding' && prevStatus !== 'bidding') dealAnim = true;
        var mySeat = d.mySeat;
        var lord = d.landlord != null ? d.landlord : -1, turn = d.turn != null ? d.turn : -1;
        var bidTurn = d.bid ? d.bid.turn : -1;
        var activeSeat = d.status === 'bidding' ? bidTurn : (d.status === 'playing' ? turn : -1);

        // 倍数
        var mult = $('gtMult');
        if (d.status === 'playing' || d.status === 'finished') { mult.style.display = ''; mult.querySelector('b').textContent = d.finalMult || d.multiplier || 1; }
        else mult.style.display = 'none';

        // 两个对手座位
        for (var i = 0; i < 3; i++) {
            var slot = slotOf(i, mySeat);
            if (slot === 'mine') continue;
            var role = (i === lord) ? '地主' : (lord >= 0 ? '农民' : '');
            renderSeat(slot, d.seats[i], d, i === activeSeat, role);
        }

        renderPlays(d, mySeat);
        renderCenter(d);
        renderActions(d);
        renderHand(d);

        if (d.status === 'finished') { showResult(d); }
        if (trustee && d.mySeat >= 0) maybeAuto(d);
        prevStatus = d.status;
    }

    function poll() {
        fetch('/games/ddz/?ajax=poll&table=' + tableId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) {
                    if (d.gone) { $('gtCenter').style.display = ''; $('gtCenter').innerHTML = '<div class="big">该桌已解散</div><div class="sub">即将返回大厅…</div>'; setTimeout(function () { location.href = '/games/ddz/'; }, 1200); }
                    return;
                }
                render(d);
            }).catch(function () {});
    }

    // 退出
    $('gtExit').addEventListener('click', function () {
        if (confirm('确认退出？游戏进行中退出会解散整桌。')) { $('gtLeaveForm').submit(); }
    });
    $('gtInvite').addEventListener('click', function () {
        var url = '<?php echo htmlspecialchars($inviteUrl, ENT_QUOTES) ?>';
        if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function () { toast('已复制邀请链接'); }, function () { toast(url); }); }
        else { toast(url); }
    });
    $('gtRExit').addEventListener('click', function () { location.href = '/games/ddz/'; });
    $('gtRReplay').addEventListener('click', function () {
        $('gtResult').classList.remove('show'); finishedShown = false; handKey = '';
        post('replay', '');
    });

    // 拖动多选（指针/触摸通用）
    $('gtHand').addEventListener('pointerdown', function (e) {
        if (e.button && e.button !== 0) return; // 右键留给出牌
        var el = e.target.closest ? e.target.closest('.gt-card') : null;
        if (!el) return;
        dragging = true;
        dragMode = !selected[el.getAttribute('data-id')];
        applyCard(el);
        e.preventDefault();
    });
    document.addEventListener('pointermove', function (e) {
        if (!dragging) return;
        var t = document.elementFromPoint(e.clientX, e.clientY);
        var el = t && t.closest ? t.closest('.gt-card') : null;
        if (el && el.parentNode === $('gtHand')) applyCard(el);
    });
    document.addEventListener('pointerup', function () { dragging = false; });

    // 右键出牌
    $('gt').addEventListener('contextmenu', function (e) {
        if (cur && cur.status === 'playing' && cur.mySeat >= 0 && cur.mySeat === cur.turn) {
            e.preventDefault();
            doPlay();
        }
    });

    // 托管代打
    $('gtTrustee').addEventListener('click', function () {
        trustee = !trustee;
        this.classList.toggle('on', trustee);
        autoKey = '';
        toast(trustee ? '已托管，机器人代打' : '已取消托管');
        if (trustee && cur) maybeAuto(cur);
    });

    poll();
    setInterval(poll, 1500);
})();
</script>
</body>
</html>
