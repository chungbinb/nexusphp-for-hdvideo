<?php
/**
 * 压大小 —— 手机版（横屏骰子赌桌）。自带完整 HTML 头尾，不经过桌面版 stdhead。
 * 由 big-small/index.php 在手机 UA 时 require；下注仍是同一个 POST 表单（提交后重定向回本页），
 * 开奖/结算逻辑都在 index.php。榜单/排名收进右上角「排行榜」悬浮弹窗。
 */
if (!defined('GAME_BS_BET_TABLE')) { return; }

$uid = (int)$CURUSER['id'];
$mBal = game_bs_money($CURUSER['seedbonus']);
$mBalInt = (int)floor((float)$CURUSER['seedbonus']);

// 最近开奖（骰子条）
$drawRes = sql_query("SELECT * FROM `" . GAME_BS_ROUND_TABLE . "` WHERE `status` IN ('closed','cancelled') ORDER BY `id` DESC LIMIT 12") or sqlerr(__FILE__, __LINE__);
$draws = [];
while ($d = mysql_fetch_assoc($drawRes)) { $draws[] = $d; }

// 我的最近押注
$myRes = sql_query("SELECT * FROM `" . GAME_BS_BET_TABLE . "` WHERE `uid` = $uid ORDER BY `id` DESC LIMIT 12") or sqlerr(__FILE__, __LINE__);

// 榜单 + 我的胜负（弹窗）
$bsWin = game_bs_leaderboard('(win_points - lose_points) DESC', 20, 1000, '(win_points - lose_points) > 0');
$bsLose = game_bs_leaderboard('(win_points - lose_points) ASC', 20, 1000, '(win_points - lose_points) < 0');
$my = game_bs_my_stats($uid);
$myNet = $my['win_points'] - $my['lose_points'];

$chips = [100, 500, 1000, 5000, 10000];
function bs_chip_label($v) { if ($v >= 1000) { $k = $v / 1000; return (floor($k) == $k ? (int)$k : $k) . 'K'; } return (string)$v; }
function bs_draw_badge($d) {
    if ($d['status'] === 'cancelled') { return ['—', '空', '#5b6b7a']; }
    $n = (int)$d['result_number'];
    $t = game_bs_number_type($n);
    if ($t === 'triple') { return [$n, '豹', '#d4a017']; }
    if ($t === 'straight') { return [$n, '顺', '#b8862b']; }
    return [$n, $d['result'] === 'big' ? '大' : '小', $d['result'] === 'big' ? '#c0392b' : '#2e86c1'];
}
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=no" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
<title>压大小</title>
<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; min-height: 100%; }
body { background: #07140d; color: #fff; font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif; }
a { color: inherit; text-decoration: none; }

.bz { position: fixed; top: 0; left: 0; right: 0; height: 100vh; height: 100dvh; display: flex; flex-direction: column; overflow-y: auto;
    background: radial-gradient(ellipse 120% 90% at 50% -16%, #2f9b72 0%, #14785a 40%, #0c5740 64%, #073f2d 100%); }
.bz::after { content: ""; position: fixed; inset: 0; border: 12px solid #2a1a0e; pointer-events: none;
    box-shadow: inset 0 0 0 2px #6b4a2b, inset 0 0 36px rgba(0,0,0,.5); z-index: 30; }

.bz-top { position: relative; z-index: 5; display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 16px 4px; }
.bz-tbtn { display: flex; flex-direction: column; align-items: center; gap: 2px; font-size: 11px; color: #d8e6df; }
.bz-tbtn svg { width: 21px; height: 21px; }
.bz-mid { text-align: center; flex: 1; }
.bz-issue { font-size: 13px; font-weight: 800; color: #ffe9a8; }
.bz-timer { font-size: 22px; font-weight: 900; color: #fff; letter-spacing: 1px; }

.bz-msg { position: relative; z-index: 6; margin: 2px 14px; padding: 8px 12px; border-radius: 8px; font-size: 13px; font-weight: 700; text-align: center; }
.bz-msg.ok { background: rgba(34,160,90,.85); } .bz-msg.err { background: rgba(200,55,60,.9); }

/* 最近开奖条 */
.bz-draws { position: relative; z-index: 5; display: flex; gap: 6px; overflow-x: auto; padding: 6px 14px; scrollbar-width: none; }
.bz-draws::-webkit-scrollbar { display: none; }
.bz-draw { flex: none; min-width: 46px; text-align: center; background: rgba(0,0,0,.28); border-radius: 8px; padding: 4px 0; }
.bz-draw .n { font-size: 14px; font-weight: 800; }
.bz-draw .t { font-size: 11px; font-weight: 800; }

/* 押注区 */
.bz-felt { position: relative; z-index: 4; flex: 1; padding: 6px 14px 4px; }
.bz-totals { text-align: center; font-size: 12px; color: rgba(255,255,255,.75); margin-bottom: 6px; }
.bz-spots { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.bz-spot { position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 14px 8px; border-radius: 12px; border: 2px solid rgba(255,235,170,.35); background: rgba(0,0,0,.2); cursor: pointer; }
.bz-spot .big { font-size: 26px; font-weight: 900; }
.bz-spot .mul { font-size: 12px; color: #ffe9a8; margin-top: 2px; }
.bz-spot input { position: absolute; opacity: 0; pointer-events: none; }
.bz-spot.small3 { grid-column: span 2; flex-direction: row; gap: 14px; padding: 10px; }
.bz-spot.small3 .seg { flex: 1; text-align: center; }
.bz-spot--sel { border-color: #ffd86b; background: rgba(255,216,107,.18); box-shadow: 0 0 0 2px rgba(255,216,107,.4) inset; }
/* 三个并列小注（豹子/顺子/数字）做成独立可选块 */
.bz-mini { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-top: 10px; }
.bz-opt { display: flex; flex-direction: column; align-items: center; padding: 11px 6px; border-radius: 10px; border: 2px solid rgba(255,235,170,.3); background: rgba(0,0,0,.2); cursor: pointer; }
.bz-opt .l { font-size: 16px; font-weight: 800; } .bz-opt .m { font-size: 11px; color: #ffe9a8; margin-top: 2px; }
.bz-opt--sel { border-color: #ffd86b; background: rgba(255,216,107,.18); }
.bz-opt input { position: absolute; opacity: 0; pointer-events: none; }
.bz-num { margin-top: 10px; text-align: center; }
.bz-num input { width: 150px; max-width: 60%; padding: 10px; border-radius: 8px; border: 1px solid rgba(255,235,170,.5); background: #0e2419; color: #fff; font-size: 18px; text-align: center; letter-spacing: 4px; }

/* 底栏 */
.bz-bar { position: relative; z-index: 5; display: flex; align-items: center; gap: 8px; padding: 8px 16px calc(14px + env(safe-area-inset-bottom)); background: transparent; }
.bz-money { flex: none; min-width: 84px; }
.bz-money .v { font-size: 17px; font-weight: 900; color: #ffd86b; } .bz-money .k { font-size: 10px; color: #9fb6a8; }
.bz-chips { flex: 1; display: flex; gap: 8px; overflow-x: auto; align-items: center; scrollbar-width: none; }
.bz-chips::-webkit-scrollbar { display: none; }
.bz-chip { flex: none; width: 46px; height: 46px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 900; color: #fff; cursor: pointer; border: 3px dashed rgba(255,255,255,.85); text-shadow: 0 1px 2px rgba(0,0,0,.5); }
.bz-amt { flex: none; min-width: 66px; text-align: center; font-weight: 900; color: #ffd86b; font-size: 15px; }
.bz-deal { flex: none; padding: 0 18px; height: 56px; border-radius: 12px; background: linear-gradient(180deg,#e0a82c,#a9781a); border: 2px solid #ffe9a8; color: #2a1c02; font-weight: 900; font-size: 16px; cursor: pointer; }

/* 横屏：腾出竖向空间，让押注区 + 底部控制条都能落在可见区域，不被地址栏/工具栏裁掉。 */
@media (orientation: landscape) {
    .bz::after { border-width: 6px; }          /* 减薄桌沿，少点黑边 */
    .bz-totals { display: none; }              /* 隐藏装饰性「本期合计」文字，腾出竖向空间 */
    .bz-top { padding: 4px 16px 2px; }
    .bz-draws { padding: 3px 14px; }
    .bz-felt { padding: 4px 14px 2px; }
    .bz-spot { padding: 9px 8px; }
    .bz-spot .big { font-size: 22px; }
    .bz-opt { padding: 7px 6px; }
    .bz-mini { margin-top: 7px; }
    .bz-num { margin-top: 7px; }
}

/* 弹窗 */
.bz-modal { position: fixed; inset: 0; z-index: 100; display: none; }
.bz-modal.show { display: block; }
.bz-mask { position: absolute; inset: 0; background: rgba(0,0,0,.62); }
.bz-card { position: absolute; left: 50%; top: 50%; transform: translate(-50%,-50%); width: min(620px,94vw); max-height: 88vh; overflow-y: auto; background: #10202f; border: 1px solid rgba(120,150,190,.3); border-radius: 16px; padding: 16px 14px; }
.bz-mh { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.bz-mh h3 { margin: 0; font-size: 17px; }
.bz-x { font-size: 22px; color: #9fb6cf; padding: 2px 8px; cursor: pointer; }
.bz-tabs2 { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
.bz-t2 { padding: 6px 12px; border-radius: 999px; border: 1px solid rgba(120,150,190,.4); font-size: 13px; font-weight: 700; color: #cfe0f2; cursor: pointer; }
.bz-t2.on { background: #2ecc71; color: #fff; border-color: #2ecc71; }
.bz-pane { display: none; } .bz-pane.on { display: block; }
.bz-tbl { width: 100%; border-collapse: collapse; font-size: 12px; color: #dce8f6; }
.bz-tbl th, .bz-tbl td { padding: 6px 5px; border: 1px solid rgba(120,150,190,.22); text-align: center; }
.bz-pos { color: #4ade80; font-weight: 700; } .bz-neg { color: #f87171; font-weight: 700; }
.bz-mystat { display: flex; flex-wrap: wrap; gap: 10px 16px; font-size: 13px; font-weight: 700; margin-bottom: 12px; color: #cfe0f2; }
</style>
</head>
<body>
<div class="bz">
    <div class="bz-top">
        <a class="bz-tbtn" href="/games/">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"></path></svg>大厅
        </a>
        <div class="bz-mid">
            <div class="bz-issue">第 <?php echo $issueNo ?> 期 · 每分钟开奖</div>
            <div class="bz-timer" id="bsRemain"><?php echo floor($remain / 60) ?>:<?php echo str_pad((string)($remain % 60), 2, '0', STR_PAD_LEFT) ?></div>
        </div>
        <span class="bz-tbtn" id="bzFsBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M21 16v3a2 2 0 0 1-2 2h-3M3 16v3a2 2 0 0 1 2 2h3"></path></svg>全屏
        </span>
        <span class="bz-tbtn" id="bzLbBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0V4z"></path></svg>排行榜
        </span>
    </div>

    <?php if ($message) { ?><div class="bz-msg ok"><?php echo htmlspecialchars($message) ?></div><?php } ?>
    <?php if ($error) { ?><div class="bz-msg err"><?php echo htmlspecialchars($error) ?></div><?php } ?>

    <div class="bz-draws">
        <?php foreach ($draws as $d) { [$n, $t, $c] = bs_draw_badge($d); ?>
            <div class="bz-draw"><div class="n"><?php echo $n ?></div><div class="t" style="color:<?php echo $c ?>"><?php echo $t ?></div></div>
        <?php } if (!$draws) { echo '<div class="bz-draw"><div class="t">暂无</div></div>'; } ?>
    </div>

    <form class="bz-felt" method="post" action="/games/big-small/" id="bsForm">
        <div class="bz-totals">本期：押大 <?php echo game_bs_money($betStats['big']['total']) ?> · 押小 <?php echo game_bs_money($betStats['small']['total']) ?> · 押数字 <?php echo game_bs_money($betStats['number']['total']) ?></div>
        <div class="bz-spots">
            <label class="bz-spot" data-spot><span class="big" style="color:#ff8a7a">大</span><span class="mul">和11-17 · 2倍</span><input type="radio" name="choice" value="big" checked></label>
            <label class="bz-spot" data-spot><span class="big" style="color:#8ec5ff">小</span><span class="mul">和4-10 · 2倍</span><input type="radio" name="choice" value="small"></label>
        </div>
        <div class="bz-mini">
            <label class="bz-opt" data-spot><span class="l">豹子</span><span class="m"><?php echo GAME_BS_TRIPLE_MULT ?>倍</span><input type="radio" name="choice" value="triple"></label>
            <label class="bz-opt" data-spot><span class="l">顺子</span><span class="m"><?php echo GAME_BS_STRAIGHT_MULT ?>倍</span><input type="radio" name="choice" value="straight"></label>
            <label class="bz-opt" data-spot><span class="l">数字</span><span class="m">6-10倍</span><input type="radio" name="choice" value="number"></label>
        </div>
        <div class="bz-num" id="bsNumWrap" style="display:none">
            <input type="text" name="bet_number" id="bsBetNumber" inputmode="numeric" maxlength="3" pattern="[1-6]{3}" placeholder="111-666">
        </div>

        <div class="bz-bar">
            <div class="bz-money"><div class="v" id="bsBal"><?php echo $mBal ?></div><div class="k">电影票</div></div>
            <div class="bz-chips">
                <?php $cc = ['#7f8c8d','#c0392b','#2e86c1','#8e44ad','#d4a017']; foreach ($chips as $i => $c) { ?>
                    <span class="bz-chip" data-amt="<?php echo $c ?>" style="background:<?php echo $cc[$i] ?>"><?php echo bs_chip_label($c) ?></span>
                <?php } ?>
                <span class="bz-chip" data-amt="all" style="background:#16a085">梭哈</span>
            </div>
            <div class="bz-amt" id="bsAmtShow">0</div>
            <input type="hidden" name="amount" id="bsAmount" value="">
            <button type="submit" class="bz-deal">押注</button>
        </div>
    </form>
</div>

<div class="bz-modal" id="bzLbModal">
    <div class="bz-mask" data-close="1"></div>
    <div class="bz-card">
        <div class="bz-mh"><h3>🏆 压大小榜单</h3><span class="bz-x" data-close="1">✕</span></div>
        <div class="bz-mystat">
            <span>我的：总 <?php echo $my['total'] ?></span>
            <span class="bz-pos">盈 <?php echo game_bs_points($my['win_points']) ?></span>
            <span class="bz-neg">亏 <?php echo game_bs_points($my['lose_points']) ?></span>
            <span>胜<?php echo $my['won'] ?>/负<?php echo $my['lost'] ?></span>
            <span>净 <span class="<?php echo $myNet >= 0 ? 'bz-pos' : 'bz-neg' ?>"><?php echo game_bs_points($myNet, true) ?></span></span>
        </div>
        <div class="bz-tabs2">
            <span class="bz-t2 on" data-pane="mine">我的押注</span>
            <span class="bz-t2" data-pane="win">胜榜</span>
            <span class="bz-t2" data-pane="lose">负榜</span>
        </div>
        <div class="bz-pane on" data-pane="mine">
            <table class="bz-tbl">
                <tr><th>期号</th><th>选择</th><th>押注</th><th>状态</th><th>返还</th></tr>
                <?php while ($b = mysql_fetch_assoc($myRes)) {
                    $c = $b['choice']; $cl = $c === 'big' ? '大' : ($c === 'small' ? '小' : ($c === 'triple' ? '豹子' : ($c === 'straight' ? '顺子' : '数字' . (int)$b['bet_number'])));
                    $stm = ['pending' => '待开', 'won' => '中', 'lost' => '未中', 'refunded' => '退回'][$b['status']] ?? $b['status']; ?>
                    <tr><td><?php echo game_bs_issue_no($b['round_id']) ?></td><td><?php echo $cl ?></td><td><?php echo game_bs_money($b['amount']) ?></td>
                        <td><?php echo $stm ?></td><td class="<?php echo (float)$b['payout'] > 0 ? 'bz-pos' : '' ?>"><?php echo game_bs_money($b['payout']) ?></td></tr>
                <?php } ?>
            </table>
        </div>
        <div class="bz-pane" data-pane="win">
            <table class="bz-tbl"><tr><th>#</th><th>用户</th><th>胜场</th><th>总盈利</th></tr>
                <?php foreach ($bsWin as $i => $r) { ?><tr><td><?php echo $i + 1 ?></td><td><?php echo htmlspecialchars($r['username']) ?></td><td><?php echo (int)$r['won_cnt'] ?></td><td class="bz-pos"><?php echo game_bs_points((float)$r['win_points'] - (float)$r['lose_points'], true) ?></td></tr><?php }
                if (!$bsWin) echo '<tr><td colspan="4" style="color:#8aa0b6">暂无</td></tr>'; ?>
            </table>
        </div>
        <div class="bz-pane" data-pane="lose">
            <table class="bz-tbl"><tr><th>#</th><th>用户</th><th>负场</th><th>总亏损</th></tr>
                <?php foreach ($bsLose as $i => $r) { ?><tr><td><?php echo $i + 1 ?></td><td><?php echo htmlspecialchars($r['username']) ?></td><td><?php echo (int)$r['lost_cnt'] ?></td><td class="bz-neg"><?php echo game_bs_points((float)$r['win_points'] - (float)$r['lose_points'], true) ?></td></tr><?php }
                if (!$bsLose) echo '<tr><td colspan="4" style="color:#8aa0b6">暂无</td></tr>'; ?>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    // 倒计时（结束后刷新进入下一期）
    var remain = <?php echo (int)$remain ?>;
    var node = document.getElementById('bsRemain');
    (function tick() {
        node.textContent = Math.floor(remain / 60) + ':' + String(remain % 60).padStart(2, '0');
        if (remain > 0) { remain -= 1; setTimeout(tick, 1000); }
        else { setTimeout(function () { location.href = '/games/big-small/'; }, 1200); }
    })();

    var balInt = <?php echo $mBalInt ?>;
    var amountEl = document.getElementById('bsAmount'), amtShow = document.getElementById('bsAmtShow');
    document.querySelectorAll('.bz-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var a = chip.getAttribute('data-amt');
            var v = a === 'all' ? balInt : parseInt(a, 10);
            amountEl.value = v; amtShow.textContent = v.toLocaleString();
        });
    });

    // 选中押注区高亮
    var numWrap = document.getElementById('bsNumWrap'), numInput = document.getElementById('bsBetNumber');
    function syncSel() {
        var checked = document.querySelector('input[name="choice"]:checked');
        document.querySelectorAll('[data-spot]').forEach(function (s) {
            var on = s.contains(checked);
            s.classList.toggle('bz-spot--sel', on && s.classList.contains('bz-spot'));
            s.classList.toggle('bz-opt--sel', on && s.classList.contains('bz-opt'));
        });
        var isNum = checked && checked.value === 'number';
        numWrap.style.display = isNum ? 'block' : 'none';
        numInput.required = isNum; if (!isNum) numInput.value = '';
    }
    document.querySelectorAll('input[name="choice"]').forEach(function (r) { r.addEventListener('change', syncSel); });
    document.querySelectorAll('[data-spot]').forEach(function (s) {
        s.addEventListener('click', function () { var r = s.querySelector('input'); if (r) { r.checked = true; syncSel(); } });
    });
    syncSel();

    document.getElementById('bsForm').addEventListener('submit', function (e) {
        if (!amountEl.value || parseInt(amountEl.value, 10) < 1) { e.preventDefault(); alert('请先选择押注金额（点下方筹码）'); }
    });

    // 弹窗
    var modal = document.getElementById('bzLbModal');
    document.getElementById('bzLbBtn').addEventListener('click', function () { modal.classList.add('show'); });
    modal.addEventListener('click', function (e) { if (e.target.getAttribute('data-close')) modal.classList.remove('show'); });
    document.querySelectorAll('.bz-t2').forEach(function (t) {
        t.addEventListener('click', function () {
            document.querySelectorAll('.bz-t2').forEach(function (x) { x.classList.remove('on'); });
            t.classList.add('on');
            var p = t.getAttribute('data-pane');
            document.querySelectorAll('.bz-pane').forEach(function (pane) { pane.classList.toggle('on', pane.getAttribute('data-pane') === p); });
        });
    });

    // 顶部「全屏」按钮：进入/退出全屏（安卓 Chrome 有效；iPhone Safari 不支持网页全屏，给出提示）
    var fsBtn = document.getElementById('bzFsBtn');
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
                alert('当前浏览器不支持网页全屏（iPhone Safari 限制）。想要真全屏可用安卓 Chrome，或把页面「添加到主屏幕」后从图标打开。');
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
