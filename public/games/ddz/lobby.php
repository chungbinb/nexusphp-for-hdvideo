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
$myAvatar = trim((string)($CURUSER['avatar'] ?? ''));
$bal = number_format(floor((float)($CURUSER['seedbonus'] ?? 0)));
$rankRows = ddz_leaderboard('games DESC, net DESC', 12, 1);
$pnlRows  = ddz_leaderboard('net DESC', 12, 1, 'net > 0');
$my = ddz_my_stats($uid);

// 取榜上玩家头像（收起时只显示头像，展开再显示名字/战绩）
$dlAvatars = [];
$dlIds = array_filter(array_map('intval', array_merge(array_column($rankRows, 'uid'), array_column($pnlRows, 'uid'))));
if ($dlIds) {
    $ar = sql_query("SELECT `id`,`avatar` FROM `users` WHERE `id` IN (" . implode(',', array_unique($dlIds)) . ")");
    while ($ar && ($a = mysql_fetch_assoc($ar))) { $dlAvatars[(int)$a['id']] = trim((string)$a['avatar']); }
}
function dl_avatar($uid, $uname, $avatars) {
    $av = $avatars[(int)$uid] ?? '';
    if ($av !== '') return '<img src="' . htmlspecialchars($av) . '" alt="" loading="lazy" onerror="this.style.display=\'none\'">';
    return '<b>' . htmlspecialchars(mb_substr($uname !== '' ? $uname : '?', 0, 1)) . '</b>';
}
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
.dl-ava { width: 38px; height: 38px; border-radius: 50%; flex: none; overflow: hidden; background: linear-gradient(135deg,#7c6cff,#3aa0ff); display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 17px; color: #fff; box-shadow: 0 0 0 2px rgba(255,255,255,.3); }
.dl-ava img { width: 100%; height: 100%; object-fit: cover; }
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
.dl-side { position: relative; z-index: 5; flex: none; width: 60px; overflow: hidden; transition: width .22s ease; display: flex; flex-direction: column; align-items: stretch; padding: 6px; gap: 8px; }
.dl.is-rank .dl-side { width: min(72vw, 300px); padding: 8px; }
/* 左侧榜单的 展开/收起 把手（V 形箭头，随面板滑动、收起朝右展开朝左） */
.dl-handle { position: absolute; left: 60px; top: 50%; transform: translateY(-50%); z-index: 6; width: 22px; height: 58px; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,.1); border: 1px solid rgba(150,180,255,.3); border-left: none; border-radius: 0 12px 12px 0; cursor: pointer; transition: left .22s ease; }
.dl.is-rank .dl-handle { left: min(72vw, 300px); }
.dl-handle svg { width: 16px; height: 16px; transition: transform .22s ease; }
.dl.is-rank .dl-handle svg { transform: rotate(180deg); }
.dl-coin.on { background: linear-gradient(135deg,#ffce4f,#f08a1e); color: #3a2400; border-color: #ffce4f; }
.dl-coin.on svg { stroke: #5a3a00; }
.dl-chev { width: 13px; height: 13px; margin-left: 1px; transition: transform .22s ease; }
.dl-coin.on .dl-chev { transform: rotate(180deg); }
.dl-railbtn { display: flex; flex-direction: column; align-items: center; gap: 2px; background: rgba(255,255,255,.06); border: 1px solid rgba(150,180,255,.22); border-radius: 12px; padding: 8px 4px; font-size: 11px; color: #cdd9f7; cursor: pointer; }
.dl-railbtn .ic { font-size: 18px; }
.dl-railbtn.on { background: linear-gradient(135deg,#ffce4f,#f08a1e); color: #3a2400; border-color: #ffce4f; }
.dl-rankpanel { display: flex; flex: 1; min-height: 0; background: rgba(8,16,40,.72); border: 1px solid rgba(150,180,255,.25); border-radius: 14px; overflow: hidden; flex-direction: column; }
.dl-rank-tabs { display: none; }
.dl.is-rank .dl-rank-tabs { display: flex; }
.dl-rank-tab { flex: 1; text-align: center; padding: 9px 0; font-size: 13px; font-weight: 800; color: #aebbe2; cursor: pointer; border-bottom: 2px solid transparent; }
.dl-rank-tab.on { color: #ffd86b; border-bottom-color: #ffd86b; }
.dl-rank-list { flex: 1; overflow-y: auto; padding: 6px; }
.dl-rank-pane { display: none; } .dl-rank-pane.on { display: block; }
.dl-rk { display: flex; align-items: center; justify-content: center; gap: 9px; padding: 5px 4px; border-radius: 10px; }
.dl.is-rank .dl-rk { justify-content: flex-start; padding: 5px 6px; }
.dl-rk:nth-child(odd) { background: rgba(255,255,255,.04); }
.dl-rk .av { flex: none; width: 40px; height: 40px; border-radius: 11px; overflow: hidden; background: linear-gradient(135deg,#5566cc,#7c6cff); display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 900; font-size: 16px; box-shadow: 0 0 0 2px rgba(255,255,255,.15); }
.dl-rk .av img { width: 100%; height: 100%; object-fit: cover; display: block; }
.dl-rk .av.top { box-shadow: 0 0 0 2px #ffce4f; }
.dl-rk .bd { display: none; min-width: 0; flex: 1; flex-direction: column; }
.dl.is-rank .dl-rk .bd { display: flex; }
.dl-rk .nm { font-size: 13px; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dl-rk .vv { font-size: 11px; color: #9ad7a0; margin-top: 2px; }

/* 角色立绘占位 */
.dl-char { position: absolute; left: 50%; bottom: 0; transform: translateX(-50%); width: 44%; max-width: 360px; height: 90%; z-index: 3; pointer-events: none; display: none;
    background: radial-gradient(55% 65% at 50% 45%, rgba(120,150,255,.3), transparent 70%);
    align-items: flex-end; justify-content: center; }
.dl.is-rank .dl-char { display: flex; }
.dl-char .ph { font-size: 130px; filter: drop-shadow(0 8px 20px rgba(0,0,0,.5)); opacity: .92; }

/* 右侧模式卡 */
/* 默认（收起）：三张卡横排居中 */
/* 模式卡：电影海报式（竖版，高而窄）。收起时横排居中，展开时靠右。 */
.dl-modes { position: relative; z-index: 5; flex: 1; display: flex; flex-direction: row; flex-wrap: wrap; align-items: center; justify-content: center; gap: 18px; padding: 10px 16px; }
.dl-card { position: relative; flex: 0 0 auto; width: clamp(140px, 44vw, 184px); aspect-ratio: 2 / 3; border-radius: 18px; padding: 14px; cursor: pointer; overflow: hidden; border: 1px solid rgba(255,255,255,.2); box-shadow: 0 10px 26px rgba(0,0,0,.5); transition: transform .12s ease; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; text-align: center; }
/* 展开排行榜：恢复成之前的样子——卡片竖排靠右、宽矮横版(图标在右)，中间露出扑克扇 */
.dl.is-rank .dl-modes { flex: none; flex-direction: column; flex-wrap: nowrap; align-items: stretch; justify-content: center; margin-left: auto; }
.dl.is-rank .dl-card { display: block; width: min(56vw, 300px); aspect-ratio: auto; padding: 16px 18px; text-align: left; }
.dl.is-rank .dl-card .ic { left: auto; right: 14px; top: 50%; transform: translateY(-50%); }
.dl.is-rank .dl-card .ic svg { width: 52px; height: 52px; }
.dl-card:active { transform: scale(.97); }
.dl-card .nm { font-size: 21px; font-weight: 900; text-shadow: 0 2px 6px rgba(0,0,0,.45); }
.dl-card .sub { font-size: 12px; margin-top: 4px; opacity: .92; line-height: 1.35; }
.dl-card .ic { position: absolute; left: 50%; top: 34%; transform: translate(-50%,-50%); filter: drop-shadow(0 3px 6px rgba(0,0,0,.4)); }
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
.dl-base { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; background: rgba(0,0,0,.2); border-radius: 12px; padding: 12px; margin-bottom: 14px; }
.dl-base select { padding: 9px; border-radius: 8px; background: #0c1a3e; color: #fff; border: 1px solid rgba(150,180,255,.4); font-size: 15px; }
.dl-fields { display: flex; gap: 12px; flex-wrap: wrap; }
.dl-field { flex: 1 1 200px; display: flex; }
.dl-field form, .dl-field { min-width: 0; }
.dl-field-btn { position: relative; width: 100%; display: flex; flex-direction: column; align-items: flex-start; gap: 6px; text-align: left; cursor: pointer; border: 1px solid rgba(255,255,255,.18); border-radius: 14px; padding: 18px 16px; color: #fff; overflow: hidden; }
.dl-field-btn .t { font-size: 19px; font-weight: 900; text-shadow: 0 2px 5px rgba(0,0,0,.4); }
.dl-field-btn .d { font-size: 12px; opacity: .92; line-height: 1.35; }
.dl-field-btn.classic { background: linear-gradient(135deg,#f6a623,#c9760a); }
.dl-field-btn.laizi { background: linear-gradient(135deg,#5b6bd6,#3a47a8); opacity: .85; }
.dl-field-btn .soon { position: absolute; right: 10px; top: 10px; font-size: 10px; font-weight: 800; background: linear-gradient(135deg,#ff5d6c,#c8324a); padding: 2px 8px; border-radius: 999px; box-shadow: 0 2px 5px rgba(0,0,0,.35); }
.dl-field-btn:active { transform: scale(.98); }
.dl-tip { margin-top: 14px; font-size: 12px; color: #9fb0da; line-height: 1.5; }
.dl-field form { width: 100%; padding: 0; background: none; margin: 0; border: 0; }

/* 提示气泡 */
.dl-toast { position: fixed; left: 50%; bottom: 80px; transform: translateX(-50%); z-index: 99; background: rgba(0,0,0,.85); color: #fff; padding: 10px 18px; border-radius: 999px; font-size: 14px; font-weight: 700; opacity: 0; transition: opacity .2s ease; pointer-events: none; }
.dl-toast.show { opacity: 1; }

@media (min-width: 760px) {
    .dl { max-width: 1000px; margin: 0 auto; left: 0; right: 0; box-shadow: 0 0 40px rgba(0,0,0,.5); }
}

/* —— 纯 SVG/CSS 质感（不用图片） —— */
.dl-card::before { content: ""; position: absolute; left: 0; right: 0; top: 0; height: 46%; background: linear-gradient(180deg, rgba(255,255,255,.32), rgba(255,255,255,0)); pointer-events: none; }
.dl-card .ic svg { width: 88px; height: 88px; display: block; filter: drop-shadow(0 3px 5px rgba(0,0,0,.35)); }
.dl-coin .ic { display: flex; }
.dl-coin .ic svg { width: 16px; height: 16px; display: block; }
.dl-icobtn svg { width: 16px; height: 16px; }
.dl-railbtn .ic svg { width: 22px; height: 22px; display: block; }
.dl-nav .ic svg { width: 22px; height: 22px; display: block; }
.dl-card .tag { background: linear-gradient(135deg,#ff5d6c,#c8324a); box-shadow: 0 2px 5px rgba(0,0,0,.35); }
/* 中央卡牌扇（角色位的纯 CSS 替代） */
.dl-fan { position: relative; width: 210px; height: 170px; }
.dl-fan .pc { position: absolute; left: 50%; top: 50%; width: 86px; height: 122px; margin: -64px 0 0 -43px; border-radius: 11px; background: #fff; border: 2px solid #e6e9f2; box-shadow: 0 14px 30px rgba(0,0,0,.5); display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 30px; }
.dl-fan .pc small { position: absolute; top: 6px; left: 8px; font-size: 14px; }
.dl-fan .pc.l { transform: rotate(-20deg) translateX(-34px) translateY(6px); color: #d62828; }
.dl-fan .pc.m { transform: translateY(-14px); color: #1b2b3a; z-index: 2; }
.dl-fan .pc.r { transform: rotate(20deg) translateX(34px) translateY(6px); color: #d62828; }
</style>
</head>
<body>
<div class="dl" id="dl">
    <div class="dl-top">
        <div class="dl-user">
            <div class="dl-ava"><?php if ($myAvatar !== '') { ?><img src="<?php echo htmlspecialchars($myAvatar) ?>" alt="" onerror="this.style.display='none'"><?php } else { echo htmlspecialchars(mb_substr($uname !== '' ? $uname : '玩', 0, 1)); } ?></div>
            <div>
                <div class="dl-uname"><?php echo htmlspecialchars($uname) ?></div>
                <div class="dl-lv">斗地主 · 内测</div>
            </div>
        </div>
        <div class="dl-coins">
            <span class="dl-coin"><span class="ic"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#f6c544" stroke="#a9760a" stroke-width="1.5"/><circle cx="12" cy="12" r="6.4" fill="none" stroke="#a9760a" stroke-width="1.2"/></svg></span><?php echo $bal ?><span class="plus">+</span></span>
            <div class="dl-coin" id="dlRankBtn" style="cursor:pointer"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="#ffe08a" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4h10v4a5 5 0 0 1-10 0V4zM7 6H4v2a3 3 0 0 0 3 3M17 6h3v2a3 3 0 0 1-3 3M9 17h6M12 13v4M8 21h8"/></svg></span>排行榜<svg class="dl-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></div>
        </div>
    </div>

    <div class="dl-stage">
        <div class="dl-handle" id="dlHandle" title="展开/收起排行榜"><svg viewBox="0 0 24 24" fill="none" stroke="#cdd9f7" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></div>
        <div class="dl-side">
            <div class="dl-rankpanel">
                <div class="dl-rank-tabs">
                    <div class="dl-rank-tab on" data-rk="win">战绩榜</div>
                    <div class="dl-rank-tab" data-rk="pnl">盈亏榜</div>
                </div>
                <div class="dl-rank-list">
                    <div class="dl-rank-pane on" data-rk="win">
                        <?php foreach ($rankRows as $i => $r) { ?>
                            <div class="dl-rk"><span class="av<?php echo $i < 3 ? ' top' : '' ?>"><?php echo dl_avatar($r['uid'], $r['username'], $dlAvatars) ?></span><div class="bd"><span class="nm"><?php echo htmlspecialchars($r['username']) ?></span><span class="vv">胜局：<?php echo (int)$r['wins'] ?> · 共 <?php echo (int)$r['games'] ?> 局</span></div></div>
                        <?php } if (!$rankRows) { echo '<div class="dl-empty">暂无战绩，快开一局吧。</div>'; } ?>
                    </div>
                    <div class="dl-rank-pane" data-rk="pnl">
                        <?php foreach ($pnlRows as $i => $r) { ?>
                            <div class="dl-rk"><span class="av<?php echo $i < 3 ? ' top' : '' ?>"><?php echo dl_avatar($r['uid'], $r['username'], $dlAvatars) ?></span><div class="bd"><span class="nm"><?php echo htmlspecialchars($r['username']) ?></span><span class="vv">净盈亏：<?php echo ddz_points($r['net'], true) ?></span></div></div>
                        <?php } if (!$pnlRows) { echo '<div class="dl-empty">暂无盈利玩家。</div>'; } ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="dl-char"><div class="dl-fan"><div class="pc l"><small>♦</small>K</div><div class="pc m"><small>♠</small>A</div><div class="pc r"><small>♥</small>Q</div></div></div>

        <div class="dl-modes">
            <div class="dl-card coin" id="dlCoinCard"><div class="nm">匹配模式</div><div class="sub">经典 / 癞子 · 满3人或补机器人</div><div class="ic"><svg viewBox="0 0 64 64"><rect x="7" y="7" width="50" height="50" rx="12" fill="#fff" stroke="#d8dce6" stroke-width="2"/><circle cx="21" cy="21" r="5" fill="#c0392b"/><circle cx="43" cy="21" r="5" fill="#1b2b3a"/><circle cx="32" cy="32" r="5" fill="#1b2b3a"/><circle cx="21" cy="43" r="5" fill="#1b2b3a"/><circle cx="43" cy="43" r="5" fill="#c0392b"/></svg></div></div>
            <div class="dl-card rank" data-soon><div class="tag">敬请期待</div><div class="nm">排位模式</div><div class="sub">段位赛 · 即将开放</div><div class="ic"><svg viewBox="0 0 64 64" fill="none"><path d="M18 10h28v9a14 14 0 0 1-28 0v-9z" fill="#ffd86b" stroke="#9a6c0a" stroke-width="2"/><path d="M18 14h-8v3a8 8 0 0 0 8 8M46 14h8v3a8 8 0 0 1-8 8" stroke="#ffe9a8" stroke-width="3"/><rect x="28" y="34" width="8" height="9" fill="#d9a93a"/><rect x="20" y="46" width="24" height="6" rx="2" fill="#d9a93a"/></svg></div></div>
        </div>
    </div>

    <nav class="dl-bottom">
        <a class="dl-nav" href="/games/"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11l8-7 8 7M6 10v9h12v-9"/></svg></span>大厅</a>
        <div class="dl-nav" data-soon><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v11H8l-4 4z"/></svg></span>社交</div>
    </nav>
</div>

<div class="dl-modal" id="dlCoinModal">
    <div class="dl-mask" data-close="1"></div>
    <div class="dl-card2">
        <div class="dl-mh"><h3>🎲 匹配模式 · 选择场次</h3><span class="dl-x" data-close="1">✕</span></div>
        <div class="dl-base">
            <span style="font-weight:700">底分</span>
            <select id="dlBase">
                <?php foreach (DDZ_BASE_OPTIONS as $b) { ?><option value="<?php echo $b ?>"><?php echo $b ?> 电影票</option><?php } ?>
            </select>
            <span style="font-size:12px;color:#9fb0da">入座需余额 ≥ 底分 × <?php echo DDZ_JOIN_BALANCE_FACTOR ?></span>
        </div>
        <div class="dl-fields">
            <form method="post" action="/games/ddz/" class="dl-field" id="dlFieldClassic">
                <input type="hidden" name="action" value="match">
                <input type="hidden" name="base" id="dlBaseClassic" value="<?php echo (int)DDZ_BASE_OPTIONS[0] ?>">
                <button type="submit" class="dl-field-btn classic">
                    <span class="t">经典场</span>
                    <span class="d">标准斗地主 · 满3人开局，10 秒无人则补机器人</span>
                </button>
            </form>
            <div class="dl-field">
                <div class="dl-field-btn laizi" data-soon>
                    <span class="soon">敬请期待</span>
                    <span class="t">癞子场</span>
                    <span class="d">含癞子玩法 · 即将开放</span>
                </div>
            </div>
        </div>
        <div class="dl-tip">匹配后进入牌桌等待，10 秒内没有真人加入将自动补机器人立即开局。</div>
    </div>
</div>

<div class="dl-toast" id="dlToast">敬请期待</div>

<script>
(function () {
    var dl = document.getElementById('dl');
    // 排行榜 收起/展开（右上角按钮 + 左侧把手 都可切换）
    var rankBtn = document.getElementById('dlRankBtn');
    function toggleRank() {
        var on = dl.classList.toggle('is-rank');
        rankBtn.classList.toggle('on', on);
    }
    rankBtn.addEventListener('click', toggleRank);
    document.getElementById('dlHandle').addEventListener('click', toggleRank);
    // 榜单 tab
    document.querySelectorAll('.dl-rank-tab').forEach(function (t) {
        t.addEventListener('click', function () {
            var k = t.getAttribute('data-rk');
            document.querySelectorAll('.dl-rank-tab').forEach(function (x) { x.classList.toggle('on', x === t); });
            document.querySelectorAll('.dl-rank-pane').forEach(function (p) { p.classList.toggle('on', p.getAttribute('data-rk') === k); });
        });
    });
    // 匹配模式弹层
    var modal = document.getElementById('dlCoinModal');
    document.getElementById('dlCoinCard').addEventListener('click', function () { modal.classList.add('show'); });
    modal.addEventListener('click', function (e) { if (e.target.getAttribute('data-close')) modal.classList.remove('show'); });
    // 底分选择 → 同步到经典场表单
    var baseSel = document.getElementById('dlBase'), baseClassic = document.getElementById('dlBaseClassic');
    if (baseSel && baseClassic) { baseSel.addEventListener('change', function () { baseClassic.value = baseSel.value; }); }
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
