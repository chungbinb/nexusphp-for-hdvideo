<?php
/**
 * 斗地主 大厅 —— 全屏「游戏中心」式页面（电脑+手机自适应，CSS 近似还原，无美术图时用渐变/图标占位）。
 * 由 ddz/index.php 在大厅状态(未进桌)时 require。复用 ddz 的函数/常量；不经过 stdhead。
 * 金币模式 = 现有电影票对局(创建/加入桌)；排位模式 / 擂台秀暂为「敬请期待」。
 * 左侧排行榜可收起/展开(两种状态)。
 */
if (!defined('DDZ_BUSINESS_TYPE')) { return; }

$uid = (int)$CURUSER['id'];
$uname = (string)($CURUSER['username'] ?? '');
$bal = number_format(floor((float)($CURUSER['seedbonus'] ?? 0)));
$rankRows = ddz_leaderboard('games DESC, net DESC', 12, 1);
$pnlRows  = ddz_leaderboard('net DESC', 12, 1, 'net > 0');
$my = ddz_my_stats($uid);
$tables = ddz_list_tables();
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=no" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
<title>斗地主 · 游戏中心</title>
<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; height: 100%; }
body { background: #0a1430; color: #eaf0ff; font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif; }
a { color: inherit; text-decoration: none; }

.dl { position: fixed; inset: 0; height: 100vh; height: 100dvh; display: flex; flex-direction: column; overflow: hidden;
    background:
      radial-gradient(120% 80% at 80% 0%, rgba(94,84,200,.5), transparent 55%),
      radial-gradient(120% 90% at 0% 100%, rgba(40,120,200,.45), transparent 55%),
      linear-gradient(160deg, #14224f 0%, #0c1733 60%, #0a1330 100%); }

/* 顶部货币栏 */
.dl-top { position: relative; z-index: 6; display: flex; align-items: center; gap: 10px; padding: calc(8px + env(safe-area-inset-top)) 14px 8px; }
.dl-user { display: flex; align-items: center; gap: 8px; min-width: 0; }
.dl-ava { width: 38px; height: 38px; border-radius: 50%; flex: none; background: linear-gradient(135deg,#7c6cff,#3aa0ff); display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 17px; color: #fff; box-shadow: 0 0 0 2px rgba(255,255,255,.3); }
.dl-uname { font-size: 13px; font-weight: 700; max-width: 96px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dl-lv { font-size: 10px; color: #b9c6ee; }
.dl-coins { margin-left: auto; display: flex; align-items: center; gap: 8px; }
.dl-coin { display: flex; align-items: center; gap: 5px; background: rgba(0,0,0,.32); border: 1px solid rgba(150,180,255,.28); border-radius: 999px; padding: 4px 6px 4px 10px; font-weight: 800; font-size: 13px; color: #ffe08a; }
.dl-coin .ic { font-size: 14px; }
.dl-coin .plus { width: 18px; height: 18px; border-radius: 50%; background: #f0b429; color: #4a2c00; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 900; }
.dl-icobtn { width: 30px; height: 30px; border-radius: 50%; background: rgba(0,0,0,.3); border: 1px solid rgba(150,180,255,.28); display: flex; align-items: center; justify-content: center; font-size: 15px; cursor: pointer; }

/* 中部舞台 */
.dl-stage { position: relative; z-index: 4; flex: 1; min-height: 0; display: flex; }

/* 左侧排行榜：收起=竖排小按钮；展开=面板 */
.dl-side { position: relative; z-index: 5; flex: none; width: 64px; transition: width .22s ease; display: flex; flex-direction: column; align-items: stretch; padding: 6px; gap: 8px; }
.dl.is-rank .dl-side { width: min(64vw, 280px); }
.dl-railbtn { display: flex; flex-direction: column; align-items: center; gap: 2px; background: rgba(255,255,255,.06); border: 1px solid rgba(150,180,255,.22); border-radius: 12px; padding: 8px 4px; font-size: 11px; color: #cdd9f7; cursor: pointer; }
.dl-railbtn .ic { font-size: 18px; }
.dl-railbtn.on { background: linear-gradient(135deg,#ffce4f,#f08a1e); color: #3a2400; border-color: #ffce4f; }
.dl-rankpanel { display: none; flex: 1; min-height: 0; background: rgba(8,16,40,.72); border: 1px solid rgba(150,180,255,.25); border-radius: 14px; overflow: hidden; flex-direction: column; }
.dl.is-rank .dl-rankpanel { display: flex; }
.dl.is-rank .dl-railicons { display: none; }
.dl-railicons { display: flex; flex-direction: column; gap: 8px; }
.dl-rank-tabs { display: flex; }
.dl-rank-tab { flex: 1; text-align: center; padding: 9px 0; font-size: 13px; font-weight: 800; color: #aebbe2; cursor: pointer; border-bottom: 2px solid transparent; }
.dl-rank-tab.on { color: #ffd86b; border-bottom-color: #ffd86b; }
.dl-rank-list { flex: 1; overflow-y: auto; padding: 6px; }
.dl-rank-pane { display: none; } .dl-rank-pane.on { display: block; }
.dl-rk { display: flex; align-items: center; gap: 8px; padding: 7px 8px; border-radius: 8px; }
.dl-rk:nth-child(odd) { background: rgba(255,255,255,.04); }
.dl-rk .no { width: 18px; text-align: center; font-weight: 900; color: #8fa0cc; font-size: 12px; }
.dl-rk .no.top { color: #ffce4f; }
.dl-rk .nm { flex: 1; min-width: 0; font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dl-rk .vv { font-size: 12px; font-weight: 800; color: #9ad7a0; }
.dl-rk .vv.neg { color: #f5a3a3; }

/* 角色立绘占位 */
.dl-char { position: absolute; left: 50%; bottom: 0; transform: translateX(-50%); width: 44%; max-width: 360px; height: 90%; z-index: 3; pointer-events: none; display: none;
    background: radial-gradient(55% 65% at 50% 45%, rgba(120,150,255,.3), transparent 70%);
    align-items: flex-end; justify-content: center; }
.dl.is-rank .dl-char { display: flex; }
.dl-char .ph { font-size: 130px; filter: drop-shadow(0 8px 20px rgba(0,0,0,.5)); opacity: .92; }

/* 右侧模式卡 */
/* 默认（收起）：三张卡横排居中 */
.dl-modes { position: relative; z-index: 5; flex: 1; display: flex; flex-direction: row; flex-wrap: wrap; align-items: center; justify-content: center; gap: 16px; padding: 10px 16px; }
.dl-card { position: relative; flex: 1 1 0; min-width: 200px; max-width: 300px; border-radius: 16px; padding: 18px; cursor: pointer; overflow: hidden; border: 1px solid rgba(255,255,255,.18); box-shadow: 0 6px 18px rgba(0,0,0,.4); transition: transform .12s ease; }
/* 展开排行榜：三张卡竖排靠右（角色立绘居中露出） */
.dl.is-rank .dl-modes { flex: none; flex-direction: column; flex-wrap: nowrap; align-items: stretch; justify-content: center; margin-left: auto; }
.dl.is-rank .dl-card { flex: none; width: min(56vw, 300px); }
/* 窄屏：竖排堆叠 */
@media (max-width: 760px) { .dl-modes { flex-direction: column; flex-wrap: nowrap; } .dl-card { flex: none; width: 100%; max-width: 380px; } }
.dl-card:active { transform: scale(.97); }
.dl-card .nm { font-size: 22px; font-weight: 900; text-shadow: 0 2px 6px rgba(0,0,0,.45); }
.dl-card .sub { font-size: 12px; margin-top: 3px; opacity: .9; }
.dl-card .ic { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); font-size: 46px; filter: drop-shadow(0 3px 6px rgba(0,0,0,.4)); }
.dl-card.coin { background: linear-gradient(135deg,#f6a623,#c9760a); }
.dl-card.rank { background: linear-gradient(135deg,#7b8cff,#4150c8); }
.dl-card.show { background: linear-gradient(135deg,#ff7eb3,#a13bd6); }
.dl-card .tag { position: absolute; right: 10px; top: 8px; font-size: 10px; font-weight: 800; background: rgba(0,0,0,.4); padding: 2px 7px; border-radius: 999px; }

/* 底部导航 */
.dl-bottom { position: relative; z-index: 6; display: flex; background: rgba(7,13,32,.92); border-top: 1px solid rgba(150,180,255,.2); padding-bottom: env(safe-area-inset-bottom); }
.dl-nav { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 7px 0; font-size: 11px; color: #9fb0da; cursor: pointer; }
.dl-nav .ic { font-size: 19px; }
.dl-nav.on { color: #ffd86b; }

/* 金币模式弹层（创建/加入桌） */
.dl-modal { position: fixed; inset: 0; z-index: 50; display: none; }
.dl-modal.show { display: block; }
.dl-mask { position: absolute; inset: 0; background: rgba(0,0,0,.6); }
.dl-card2 { position: absolute; left: 50%; top: 50%; transform: translate(-50%,-50%); width: min(560px,94vw); max-height: 86vh; overflow-y: auto; background: #10204a; border: 1px solid rgba(150,180,255,.3); border-radius: 16px; padding: 16px 14px; }
.dl-mh { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.dl-mh h3 { margin: 0; font-size: 17px; }
.dl-x { font-size: 22px; color: #9fb0da; padding: 2px 8px; cursor: pointer; }
.dl-create { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; background: rgba(0,0,0,.2); border-radius: 12px; padding: 12px; margin-bottom: 12px; }
.dl-create select { padding: 9px; border-radius: 8px; background: #0c1a3e; color: #fff; border: 1px solid rgba(150,180,255,.4); font-size: 15px; }
.dl-go { padding: 0 18px; height: 42px; border-radius: 10px; border: none; background: linear-gradient(135deg,#f6a623,#c9760a); color: #3a2400; font-weight: 900; font-size: 15px; cursor: pointer; }
.dl-trow { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 12px; border: 1px solid rgba(150,180,255,.22); border-radius: 10px; margin-bottom: 8px; font-size: 13px; }
.dl-join { margin-left: auto; padding: 0 14px; height: 36px; border-radius: 8px; border: none; background: #2ecc71; color: #fff; font-weight: 800; cursor: pointer; }
.dl-empty { color: #9fb0da; padding: 10px; }

/* 提示气泡 */
.dl-toast { position: fixed; left: 50%; bottom: 80px; transform: translateX(-50%); z-index: 99; background: rgba(0,0,0,.85); color: #fff; padding: 10px 18px; border-radius: 999px; font-size: 14px; font-weight: 700; opacity: 0; transition: opacity .2s ease; pointer-events: none; }
.dl-toast.show { opacity: 1; }

@media (min-width: 760px) {
    .dl { max-width: 1000px; margin: 0 auto; left: 0; right: 0; box-shadow: 0 0 40px rgba(0,0,0,.5); }
}
</style>
</head>
<body>
<div class="dl" id="dl">
    <div class="dl-top">
        <div class="dl-user">
            <div class="dl-ava"><?php echo htmlspecialchars(mb_substr($uname !== '' ? $uname : '玩', 0, 1)) ?></div>
            <div>
                <div class="dl-uname"><?php echo htmlspecialchars($uname) ?></div>
                <div class="dl-lv">斗地主 · 内测</div>
            </div>
        </div>
        <div class="dl-coins">
            <span class="dl-coin"><span class="ic">🎟️</span><?php echo $bal ?><span class="plus">+</span></span>
            <a class="dl-icobtn" href="/games/" title="返回游戏大厅">≡</a>
        </div>
    </div>

    <div class="dl-stage">
        <div class="dl-side">
            <div class="dl-railicons">
                <div class="dl-railbtn" id="dlRankBtn"><span class="ic">🏆</span>排行榜</div>
                <a class="dl-railbtn" href="/attendance.php"><span class="ic">📅</span>签到</a>
                <a class="dl-railbtn" href="/games/chest/"><span class="ic">🎁</span>礼包</a>
            </div>
            <div class="dl-rankpanel">
                <div class="dl-rank-tabs">
                    <div class="dl-rank-tab on" data-rk="win">战绩榜</div>
                    <div class="dl-rank-tab" data-rk="pnl">盈亏榜</div>
                </div>
                <div class="dl-rank-list">
                    <div class="dl-rank-pane on" data-rk="win">
                        <?php foreach ($rankRows as $i => $r) { ?>
                            <div class="dl-rk"><span class="no<?php echo $i < 3 ? ' top' : '' ?>"><?php echo $i + 1 ?></span><span class="nm"><?php echo htmlspecialchars($r['username']) ?></span><span class="vv"><?php echo (int)$r['wins'] ?>胜/<?php echo (int)$r['games'] ?>局</span></div>
                        <?php } if (!$rankRows) { echo '<div class="dl-empty">暂无战绩，快开一局吧。</div>'; } ?>
                    </div>
                    <div class="dl-rank-pane" data-rk="pnl">
                        <?php foreach ($pnlRows as $i => $r) { ?>
                            <div class="dl-rk"><span class="no<?php echo $i < 3 ? ' top' : '' ?>"><?php echo $i + 1 ?></span><span class="nm"><?php echo htmlspecialchars($r['username']) ?></span><span class="vv"><?php echo ddz_points($r['net'], true) ?></span></div>
                        <?php } if (!$pnlRows) { echo '<div class="dl-empty">暂无盈利玩家。</div>'; } ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="dl-char"><div class="ph">🃏</div></div>

        <div class="dl-modes">
            <div class="dl-card coin" id="dlCoinCard"><div class="nm">金币模式</div><div class="sub">电影票对局 · 满3人开局</div><div class="ic">🎲</div></div>
            <div class="dl-card rank" data-soon><div class="tag">敬请期待</div><div class="nm">排位模式</div><div class="sub">段位赛 · 即将开放</div><div class="ic">🏆</div></div>
            <div class="dl-card show" data-soon><div class="tag">敬请期待</div><div class="nm">擂台秀</div><div class="sub">表演赛 · 即将开放</div><div class="ic">⭐</div></div>
        </div>
    </div>

    <nav class="dl-bottom">
        <a class="dl-nav" href="/games/"><span class="ic">🏠</span>大厅</a>
        <div class="dl-nav" data-soon><span class="ic">💬</span>社交</div>
        <div class="dl-nav" data-soon><span class="ic">✅</span>任务</div>
        <a class="dl-nav" href="/games/chest/"><span class="ic">🧰</span>宝箱</a>
        <div class="dl-nav" data-soon><span class="ic">🛍️</span>商城</div>
    </nav>
</div>

<div class="dl-modal" id="dlCoinModal">
    <div class="dl-mask" data-close="1"></div>
    <div class="dl-card2">
        <div class="dl-mh"><h3>🎲 金币模式 · 选择桌子</h3><span class="dl-x" data-close="1">✕</span></div>
        <div class="dl-create">
            <form method="post" action="/games/ddz/" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;width:100%">
                <input type="hidden" name="action" value="create">
                <span style="font-weight:700">底分</span>
                <select name="base">
                    <?php foreach (DDZ_BASE_OPTIONS as $b) { ?><option value="<?php echo $b ?>"><?php echo $b ?> 电影票</option><?php } ?>
                </select>
                <button type="submit" class="dl-go">创建并入座</button>
                <span style="font-size:12px;color:#9fb0da">入座需余额 ≥ 底分 × <?php echo DDZ_JOIN_BALANCE_FACTOR ?></span>
            </form>
        </div>
        <div style="font-weight:800;margin:6px 2px 8px">等待中的桌子</div>
        <?php if (!$tables) { ?><div class="dl-empty">暂无等待中的桌子，创建一个吧。</div><?php } ?>
        <?php foreach ($tables as $t) {
            $names = [];
            foreach ($t['seats'] as $s) { if ($s) { $names[] = htmlspecialchars($s['username']); } }
        ?>
            <div class="dl-trow">
                <b>桌 #<?php echo (int)$t['id'] ?></b>
                <span>底分 <?php echo (int)$t['base'] ?></span>
                <span style="color:#9fb0da"><?php echo ddz_player_count($t) ?>/3 人</span>
                <span style="color:#9fb0da">：<?php echo implode('、', $names) ?></span>
                <form method="post" action="/games/ddz/" style="margin-left:auto">
                    <input type="hidden" name="action" value="join">
                    <input type="hidden" name="table" value="<?php echo (int)$t['id'] ?>">
                    <button type="submit" class="dl-join">加入</button>
                </form>
            </div>
        <?php } ?>
    </div>
</div>

<div class="dl-toast" id="dlToast">敬请期待</div>

<script>
(function () {
    var dl = document.getElementById('dl');
    // 排行榜 收起/展开
    document.getElementById('dlRankBtn').addEventListener('click', function () {
        dl.classList.toggle('is-rank');
        this.classList.toggle('on', dl.classList.contains('is-rank'));
    });
    // 榜单 tab
    document.querySelectorAll('.dl-rank-tab').forEach(function (t) {
        t.addEventListener('click', function () {
            var k = t.getAttribute('data-rk');
            document.querySelectorAll('.dl-rank-tab').forEach(function (x) { x.classList.toggle('on', x === t); });
            document.querySelectorAll('.dl-rank-pane').forEach(function (p) { p.classList.toggle('on', p.getAttribute('data-rk') === k); });
        });
    });
    // 金币模式弹层
    var modal = document.getElementById('dlCoinModal');
    document.getElementById('dlCoinCard').addEventListener('click', function () { modal.classList.add('show'); });
    modal.addEventListener('click', function (e) { if (e.target.getAttribute('data-close')) modal.classList.remove('show'); });
    // 敬请期待提示
    var toast = document.getElementById('dlToast'), tt;
    document.querySelectorAll('[data-soon]').forEach(function (el) {
        el.addEventListener('click', function () {
            toast.classList.add('show');
            clearTimeout(tt); tt = setTimeout(function () { toast.classList.remove('show'); }, 1400);
        });
    });
})();
</script>
</body>
</html>
