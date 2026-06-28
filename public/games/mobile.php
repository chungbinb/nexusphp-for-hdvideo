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

.gm-tabs { display: flex; gap: 22px; overflow-x: auto; margin: 2px 0 16px; padding-bottom: 6px; color: #9eb4ca; font-size: 16px; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
.gm-tabs::-webkit-scrollbar { display: none; }
.gm-tab2 { white-space: nowrap; padding-bottom: 7px; position: relative; }
.gm-tab2.on { color: #fff; font-weight: 700; }
.gm-tab2.on::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 3px; background: #35b8f1; border-radius: 2px; }

.gm-sec { font-size: 15px; font-weight: 800; color: #d4e3f4; margin: 20px 2px 12px; }

.gm-list { display: flex; flex-direction: column; gap: 16px; }
.gm-sc { display: block; background: #16222f; border: 1px solid rgba(91,129,166,.2); border-radius: 12px; overflow: hidden; transition: transform .12s ease; }
.gm-sc:active { transform: scale(.99); }
.gm-sc-banner { position: relative; aspect-ratio: 2 / 1; background-color: var(--game-b,#0a1622); background-image: radial-gradient(circle at 20% 22%, rgba(255,255,255,.24), transparent 24%), linear-gradient(135deg, var(--game-a,#2a4a66), var(--game-b,#0a1622)); background-size: cover; background-position: center; }
.gm-sc-ttl { position: absolute; left: 16px; right: 14px; bottom: 12px; font-size: 26px; font-weight: 900; color: #fff; text-shadow: 0 3px 12px rgba(0,0,0,.6); }
.gm-sc-ver { position: absolute; top: 11px; right: 11px; font-size: 11px; font-weight: 700; color: #fff; background: rgba(0,0,0,.42); padding: 3px 9px; border-radius: 999px; }
.gm-sc-foot { display: flex; align-items: center; gap: 10px; padding: 11px 13px; }
.gm-sc-tags { min-width: 0; flex: 1; font-size: 12px; color: #90a8c0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.gm-sc-go { flex: none; background: #1f6fb0; color: #fff; font-size: 13px; font-weight: 800; padding: 9px 16px; border-radius: 8px; }
.gm-sc.off { opacity: .6; }
.gm-sc.off .gm-sc-go { background: #7a2b2b; }
.theme-dice{--game-a:#1e88e5;--game-b:#07182d;} .theme-sports{--game-a:#2ecc71;--game-b:#0b3d1f;}
.theme-ddz{--game-a:#e74c3c;--game-b:#2c1a0c;} .theme-scratch{--game-a:#f1c232;--game-b:#6b3f00;}
.theme-wheel{--game-a:#b84cff;--game-b:#18224f;} .theme-quiz{--game-a:#13b58a;--game-b:#092c38;}
.theme-chest{--game-a:#ff7f50;--game-b:#371323;} .theme-blackjack{--game-a:#1f9a52;--game-b:#07210f;}
.theme-slots{--game-a:#d4a017;--game-b:#3a2a10;} .theme-plinko{--game-a:#2980b9;--game-b:#0a1a2a;}
.theme-hilo{--game-a:#8e44ad;--game-b:#1a0b26;} .theme-moviequiz{--game-a:#9b59b6;--game-b:#161226;}

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

    <div class="gm-tabs">
        <span class="gm-tab2 on">热门新品</span>
        <span class="gm-tab2">热销游戏</span>
        <span class="gm-tab2">即将推出</span>
        <span class="gm-tab2">优惠</span>
        <span class="gm-tab2">免费畅玩</span>
    </div>

    <div class="gm-list">
        <?php foreach ($games as $game) {
            $ctrlKey = preg_match('#^/games/([^/]+)/#', $game['href'], $m) ? $m[1] : null;
            $gClosed = $ctrlKey ? !game_is_open($ctrlKey) : false;
            $gCanAccess = $ctrlKey ? game_user_can_access($ctrlKey) : true;
            $gBlocked = $gClosed && !$gCanAccess;
            $disabled = $game['href'] === '#' || $gBlocked;
            $href = $disabled ? '#' : $game['href'];
            $go = $gClosed ? ($gCanAccess ? '预览' : '未开放') : '进入';
            $tags = !empty($game['tags']) ? implode(' · ', $game['tags']) : htmlspecialchars($game['subtitle'] ?? '');
            // 海报：/games/posters/<theme>.jpg 或 .png（设计员按规格出图后即自动生效）
            $poster = '';
            foreach (['jpg', 'png', 'webp'] as $ext) {
                if (is_file(__DIR__ . '/posters/' . $game['theme'] . '.' . $ext)) {
                    $poster = '/games/posters/' . $game['theme'] . '.' . $ext . '?v=1';
                    break;
                }
            }
            ?>
            <a class="gm-sc<?php echo $disabled ? ' off' : '' ?>" href="<?php echo htmlspecialchars($href) ?>"<?php echo $disabled ? ' onclick="return false;"' : '' ?>>
                <div class="gm-sc-banner theme-<?php echo htmlspecialchars($game['theme']) ?>"<?php if ($poster) { echo ' style="background-image:url(\'' . htmlspecialchars($poster) . '\')"'; } ?>>
                    <?php if (!empty($game['badge'])) { ?><span class="gm-sc-ver"><?php echo htmlspecialchars($game['badge']) ?></span><?php } ?>
                    <?php if (!$poster) { ?><div class="gm-sc-ttl"><?php echo htmlspecialchars($game['title']) ?></div><?php } ?>
                </div>
                <div class="gm-sc-foot">
                    <div class="gm-sc-tags"><?php echo htmlspecialchars($tags) ?></div>
                    <span class="gm-sc-go"><?php echo htmlspecialchars($go) ?> ›</span>
                </div>
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
