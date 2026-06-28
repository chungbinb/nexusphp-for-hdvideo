<?php
/**
 * 游戏大厅 —— 手机版独立页面（仿手机游戏中心 App）。
 * 由 games/index.php 在检测到手机 UA 时 require 进来，复用其作用域里的
 * $games / $CURUSER 及 game_*() 函数。本文件自带完整 HTML 头尾，不经过桌面版
 * stdhead/stdfoot，因此完全不影响电脑端。
 */
if (!isset($games) || !is_array($games)) { return; }

$mUid = (int)($CURUSER['id'] ?? 0);
$mName = (string)($CURUSER['username'] ?? '');
$mBonus = number_format(floor((float)($CURUSER['seedbonus'] ?? 0)));

// 未读消息数（底部铃铛红点）
$mUnread = 0;
if ($mUid > 0) {
    $ur = sql_query("SELECT COUNT(*) FROM messages WHERE receiver = " . $mUid . " AND unread = 'yes'");
    if ($ur) { $urow = mysql_fetch_row($ur); $mUnread = (int)$urow[0]; }
}

// 总榜数据
$mProfit = game_lb_bonus('profit', null, 10);
$mProfitLow = game_lb_bonus('profit', null, 10, 'ASC');
$mActive = game_lb_bonus('active', null, 10);
$mWin = game_lb_bonus('wincount', null, 10);

function gm_icon_style($theme)
{
    if (is_file(__DIR__ . '/icons/' . $theme . '.png')) {
        return ' style="background-image:url(\'/games/icons/' . htmlspecialchars($theme) . '.png?v=2\')"';
    }
    return '';
}
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<title>游戏中心 · <?php echo htmlspecialchars($SITENAME ?? 'HDVIDEO') ?></title>
<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; }
body { background: #0c1622; color: #e7eef7; font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif; line-height: 1.5; -webkit-font-smoothing: antialiased; }
a { text-decoration: none; color: inherit; }
.gm { max-width: 640px; margin: 0 auto; padding: 12px 14px calc(72px + env(safe-area-inset-bottom)); }

.gm-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 6px 2px 16px; }
.gm-top-title { font-size: 21px; font-weight: 800; letter-spacing: .5px; }
.gm-bal { font-size: 13px; color: #9fb6cf; background: rgba(120,150,190,.16); border: 1px solid rgba(120,150,190,.3); padding: 6px 12px; border-radius: 999px; white-space: nowrap; }
.gm-bal b { color: #ffd770; }

.gm-feature { display: flex; align-items: center; gap: 13px; background: linear-gradient(135deg,#16324f,#0b1c2e); border: 1px solid rgba(91,160,230,.32); border-radius: 16px; padding: 14px; margin-bottom: 8px; }
.gm-feature-icon { width: 60px; height: 60px; border-radius: 15px; background: #1c3550 center/cover no-repeat; flex: none; box-shadow: 0 3px 10px rgba(0,0,0,.4); }
.gm-feature-txt { min-width: 0; flex: 1; }
.gm-feature-name { font-size: 17px; font-weight: 800; }
.gm-feature-sub { font-size: 12px; color: #92a8c0; margin-top: 4px; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.gm-feature-go { flex: none; color: #5bb8f1; font-size: 13px; font-weight: 800; }

.gm-sec { font-size: 15px; font-weight: 800; color: #d4e3f4; margin: 20px 2px 12px; }

.gm-list { display: flex; flex-direction: column; gap: 10px; }
.gm-card { display: flex; align-items: center; gap: 13px; background: rgba(255,255,255,.05); border: 1px solid rgba(120,150,190,.2); border-radius: 14px; padding: 12px; transition: transform .12s ease; }
.gm-card:active { transform: scale(.985); }
.gm-card-icon { width: 56px; height: 56px; border-radius: 14px; background: linear-gradient(135deg,#33567c,#1d3450) center/cover no-repeat; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800; color: #fff; flex: none; box-shadow: 0 3px 9px rgba(0,0,0,.32); }
.gm-card-body { min-width: 0; flex: 1; }
.gm-card-name { font-size: 15px; font-weight: 800; }
.gm-card-badge { font-size: 11px; font-weight: 700; color: #9fd0ff; margin-left: 5px; }
.gm-card-sub { font-size: 12px; color: #92a8c0; margin-top: 3px; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }
.gm-card-go { flex: none; font-size: 12px; font-weight: 700; color: #5bb8f1; white-space: nowrap; }
.gm-card.off { opacity: .55; }
.gm-card.off .gm-card-go { color: #ff9d9d; }

.gm-board { margin-top: 4px; }

.gm-tabbar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 50; display: flex; background: rgba(10,20,32,.97); border-top: 1px solid rgba(120,150,190,.2); padding-bottom: env(safe-area-inset-bottom); box-shadow: 0 -2px 12px rgba(0,0,0,.35); }
.gm-tab { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; height: 56px; color: #8aa0b6; font-size: 11px; position: relative; }
.gm-tab.on { color: #35b8f1; }
.gm-tab svg { width: 23px; height: 23px; }
.gm-tab-badge { position: absolute; top: 7px; left: 50%; margin-left: 4px; min-width: 16px; height: 16px; padding: 0 4px; border-radius: 8px; background: #e8453c; color: #fff; font-size: 10px; font-weight: 800; line-height: 16px; text-align: center; box-sizing: border-box; }
</style>
</head>
<body>
<div class="gm">
    <div class="gm-top">
        <div class="gm-top-title">🎮 游戏中心</div>
        <div class="gm-bal">电影票 <b><?php echo $mBonus ?></b></div>
    </div>

    <?php $f = $games[0]; ?>
    <a class="gm-feature" href="<?php echo htmlspecialchars($f['href']) ?>">
        <div class="gm-feature-icon"<?php echo gm_icon_style($f['theme']) ?>></div>
        <div class="gm-feature-txt">
            <div class="gm-feature-name"><?php echo htmlspecialchars($f['title']) ?></div>
            <div class="gm-feature-sub"><?php echo htmlspecialchars($f['subtitle']) ?></div>
        </div>
        <span class="gm-feature-go">进入 ›</span>
    </a>

    <div class="gm-sec">全部游戏</div>
    <div class="gm-list">
        <?php foreach ($games as $game) {
            $ctrlKey = preg_match('#^/games/([^/]+)/#', $game['href'], $m) ? $m[1] : null;
            $gClosed = $ctrlKey ? !game_is_open($ctrlKey) : false;
            $gCanAccess = $ctrlKey ? game_user_can_access($ctrlKey) : true;
            $gBlocked = $gClosed && !$gCanAccess;
            $disabled = $game['href'] === '#' || $gBlocked;
            $href = $disabled ? '#' : $game['href'];
            $go = $gClosed ? ($gCanAccess ? '预览' : '未开放') : '进入 ›';
            $hasIcon = is_file(__DIR__ . '/icons/' . $game['theme'] . '.png');
            ?>
            <a class="gm-card<?php echo $disabled ? ' off' : '' ?>" href="<?php echo htmlspecialchars($href) ?>"<?php echo $disabled ? ' onclick="return false;"' : '' ?>>
                <div class="gm-card-icon"<?php echo gm_icon_style($game['theme']) ?>><?php echo $hasIcon ? '' : htmlspecialchars(mb_substr($game['title'], 0, 1)) ?></div>
                <div class="gm-card-body">
                    <div class="gm-card-name"><?php echo htmlspecialchars($game['title']) ?><?php if (!empty($game['badge'])) { ?><span class="gm-card-badge"><?php echo htmlspecialchars($game['badge']) ?></span><?php } ?></div>
                    <div class="gm-card-sub"><?php echo htmlspecialchars($game['subtitle']) ?></div>
                </div>
                <span class="gm-card-go"><?php echo htmlspecialchars($go) ?></span>
            </a>
        <?php } ?>
    </div>

    <div class="gm-sec">🏆 游戏大厅总榜</div>
    <div class="gm-board">
        <?php
        echo game_lb_css();
        echo game_lb_table('💰 盈亏榜', $mProfit, '净盈亏',
            function ($r) { return ((float)$r['amt'] >= 0 ? '+' : '') . game_lb_money($r['amt']); },
            function ($r) { return (float)$r['amt'] >= 0 ? 'glb-pos' : 'glb-neg'; }, $mProfitLow);
        echo game_lb_table('🔥 活跃榜', $mActive, '参与次数',
            function ($r) { return number_format((int)$r['amt']) . ' 次'; });
        echo game_lb_table('🎉 中奖榜', $mWin, '中奖次数',
            function ($r) { return number_format((int)$r['amt']) . ' 次'; },
            function ($r) { return 'glb-pos'; });
        ?>
    </div>
</div>

<nav class="gm-tabbar">
    <a class="gm-tab on" href="/games/">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 9h-2M6 8v2M15.5 9.5h.01M18 11h.01"></path><path d="M7 6h10a3.5 3.5 0 0 1 3.45 2.9l.8 4.6a2.6 2.6 0 0 1-5.05.9l-.4-1H8.2l-.4 1a2.6 2.6 0 0 1-5.05-.9l.8-4.6A3.5 3.5 0 0 1 7 6Z"></path></svg>
        <span>游戏</span>
    </a>
    <a class="gm-tab" href="/torrents.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"></path><path d="M8 11l4 4 4-4"></path><path d="M5 21h14"></path></svg>
        <span>种子</span>
    </a>
    <a class="gm-tab" href="/forums.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v10H8l-4 4z"></path></svg>
        <span>论坛</span>
    </a>
    <a class="gm-tab" href="/messages.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
        <?php if ($mUnread > 0) { ?><span class="gm-tab-badge"><?php echo $mUnread > 99 ? '99+' : $mUnread ?></span><?php } ?>
        <span>消息</span>
    </a>
    <a class="gm-tab" href="/userdetails.php?id=<?php echo $mUid ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"></circle><path d="M4 21c0-4 3.5-6 8-6s8 2 8 6"></path></svg>
        <span>我的</span>
    </a>
</nav>
</body>
</html>
<?php
// 结束，不再输出桌面版内容。
