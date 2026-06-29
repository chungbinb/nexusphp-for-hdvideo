<?php
/**
 * 首页 手机版 —— 专门的手机排版（自带 头/尾，不经过桌面版 stdhead），与电脑版互不影响。
 * 由 public/index.php 在手机 UA 时 require；?pc=1 可强制看电脑版。
 * 复用 bittorrent.php 已加载的环境：$CURUSER、sql_query、mksize、format_comment 等。
 */
if (!isset($CURUSER) || empty($CURUSER['id'])) { return; }

$uid = (int)$CURUSER['id'];
$uname = (string)($CURUSER['username'] ?? '');
$avatar = trim((string)($CURUSER['avatar'] ?? ''));
$up = (float)($CURUSER['uploaded'] ?? 0);
$down = (float)($CURUSER['downloaded'] ?? 0);
$ratio = $down > 0 ? number_format($up / $down, 2) : ($up > 0 ? '∞' : '---');
$bonus = number_format(floor((float)($CURUSER['seedbonus'] ?? 0)));
$classText = function_exists('get_user_class_name') ? get_user_class_name((int)($CURUSER['class'] ?? 0)) : '';

// 个性化配色：完全对齐 PC 端映射（UserMeta PERSONALIZE）：
//   --bili-bg=页面背景, --bili-surface=卡片/栏面板, --bili-primary=强调色(文字/图标/选中)
$mhPrimary = '#00aeec';
$mhAccent = '#fb7299';
$mhBg = '#f6f7fb';
$mhSurface = '#ffffff';
$mhText = '#1b2230';
try {
    $pm = \App\Models\UserMeta::query()->where('uid', $uid)->where('meta_key', 'PERSONALIZE')->where('status', 0)->value('meta_value');
    if ($pm) {
        $arr = json_decode($pm, true);
        if (is_array($arr)) {
            $re = '/^#[0-9a-fA-F]{6}$/';
            if (isset($arr['--bili-primary']) && preg_match($re, $arr['--bili-primary'])) $mhPrimary = $arr['--bili-primary'];
            if (isset($arr['--bili-accent']) && preg_match($re, $arr['--bili-accent'])) $mhAccent = $arr['--bili-accent'];
            if (isset($arr['--bili-bg']) && preg_match($re, $arr['--bili-bg'])) $mhBg = $arr['--bili-bg'];
            if (isset($arr['--bili-surface']) && preg_match($re, $arr['--bili-surface'])) $mhSurface = $arr['--bili-surface'];
            if (isset($arr['--bili-text']) && preg_match($re, $arr['--bili-text'])) $mhText = $arr['--bili-text'];
        }
    }
} catch (\Throwable $e) {}
$mhPrimary = strtolower($mhPrimary); $mhAccent = strtolower($mhAccent);
$mhBg = strtolower($mhBg); $mhSurface = strtolower($mhSurface); $mhText = strtolower($mhText);

// 预设配色（与电脑版一致）：[名称, 主色, 强调, 背景, 面板, 文字]
$mhPresets = [
    ['默认', '#00aeec', '#fb7299', '#f6f7fb', '#ffffff', '#18191c'],
    ['樱花粉', '#fb7299', '#f8a5c2', '#fcdfe9', '#fbcfdf', '#5a3a44'],
    ['抹茶绿', '#689f38', '#9ccc65', '#d3e8bb', '#c6e0a8', '#2e3d22'],
    ['海洋蓝', '#1976d2', '#4fc3f7', '#cfe5f6', '#bfddf2', '#13314a'],
    ['暮光紫', '#7c4dff', '#b388ff', '#ddd0f5', '#cfbef0', '#2a2340'],
    ['落日橙', '#f4511e', '#ff8a65', '#ffdcc9', '#ffceb6', '#4a2818'],
    ['性感紫', '#9c27b0', '#ff4081', '#ecd6f4', '#ddbbeb', '#3b1d49'],
    ['妖娆紫', '#ba68c8', '#f06292', '#f2e2f7', '#e6cdf0', '#45284f'],
    ['魅惑紫', '#6a1b9a', '#c2185b', '#e3cbee', '#cfaae2', '#2f1340'],
    ['清纯粉', '#f48fb1', '#f8bbd0', '#fdf0f5', '#fcdde9', '#5a3a48'],
];
function mh_lighten($hex, $pct) {
    $hex = ltrim((string)$hex, '#');
    if (strlen($hex) !== 6) return '#' . $hex;
    $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
    $r = (int)round($r + (255 - $r) * $pct); $g = (int)round($g + (255 - $g) * $pct); $b = (int)round($b + (255 - $b) * $pct);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}
$mhSoft = mh_lighten($mhPrimary, 0.86); // 主色淡化版：图标底/头像底/标签底/选中底

$unread = 0;
try { $unread = (int)get_row_count('messages', "WHERE receiver = " . $uid . " AND unread = 'yes' AND location != 0"); } catch (\Throwable $e) {}

// 更多个人数据（对齐电脑版右上角用户栏）
$invites = (int)($CURUSER['invites'] ?? 0);
$activeSeed = $activeLeech = $hrCount = 0;
try { $activeSeed = (int)get_row_count('peers', "WHERE userid = $uid AND seeder = 'yes'"); } catch (\Throwable $e) {}
try { $activeLeech = (int)get_row_count('peers', "WHERE userid = $uid AND seeder = 'no'"); } catch (\Throwable $e) {}
try { $hrCount = (int)\App\Models\HitAndRun::query()->where('uid', $uid)->where('status', \App\Models\HitAndRun::STATUS_INSPECTING)->count(); } catch (\Throwable $e) {}

// 公告
$newsRows = [];
$rn = sql_query("SELECT id, title, body, added FROM news ORDER BY added DESC LIMIT 5");
while ($rn && ($a = mysql_fetch_assoc($rn))) { $newsRows[] = $a; }

// 最新种子
$torRows = [];
$rt = sql_query("SELECT id, name, small_descr, seeders, leechers FROM torrents WHERE visible = 'yes' ORDER BY id DESC LIMIT 8");
while ($rt && ($a = mysql_fetch_assoc($rt))) { $torRows[] = $a; }

// 最新主题
$postRows = [];
$cls = function_exists('get_user_class') ? (int)get_user_class() : (int)($CURUSER['class'] ?? 0);
$rp = sql_query("SELECT posts.id AS pid, topics.id AS tid, topics.subject, topics.forumid, forums.name
    FROM posts, topics, forums
    WHERE posts.topicid = topics.id AND topics.forumid = forums.id AND forums.minclassread <= " . $cls . "
    ORDER BY posts.id DESC LIMIT 5");
while ($rp && ($a = mysql_fetch_assoc($rp))) { $postRows[] = $a; }

// 投票（最新一条）
$pollRow = null; $pollVoted = null; $pollCounts = []; $pollTotal = 0; $pollOpts = [];
if (($showpolls_main ?? '') === 'yes') {
    $pr = sql_query("SELECT * FROM polls ORDER BY id DESC LIMIT 1");
    $pollRow = $pr ? mysql_fetch_assoc($pr) : null;
    if ($pollRow) {
        $pid = (int)$pollRow['id'];
        for ($i = 0; $i < 20; $i++) { $k = 'option' . $i; if (isset($pollRow[$k]) && trim((string)$pollRow[$k]) !== '') $pollOpts[$i] = $pollRow[$k]; }
        $vr = sql_query("SELECT selection FROM pollanswers WHERE pollid = $pid AND userid = $uid LIMIT 1");
        $vv = $vr ? mysql_fetch_assoc($vr) : null;
        $pollVoted = $vv ? (int)$vv['selection'] : null;
        if ($pollVoted !== null) {
            $cr = sql_query("SELECT selection, COUNT(*) AS c FROM pollanswers WHERE pollid = $pid AND selection < 20 GROUP BY selection");
            while ($cr && ($cc = mysql_fetch_assoc($cr))) { $pollCounts[(int)$cc['selection']] = (int)$cc['c']; $pollTotal += (int)$cc['c']; }
        }
    }
}

function mh_avatar($avatar, $uname)
{
    if ($avatar !== '') return '<img src="' . htmlspecialchars($avatar) . '" alt="" onerror="this.style.display=\'none\'">';
    return '<b>' . htmlspecialchars(mb_substr($uname !== '' ? $uname : '?', 0, 1)) . '</b>';
}

$navItems = [
    ['torrents.php', '种子', '<path d="M4 7h16M4 12h16M4 17h10"/>'],
    ['forums.php', '论坛', '<path d="M4 5h16v10H9l-4 4z"/>'],
    ['upload.php', '发布', '<path d="M12 19V7M6 11l6-6 6 6M5 21h14"/>'],
    ['messages.php', '消息', '<path d="M4 5h16v12H8l-4 4z"/>'],
    ['topten.php', '排行', '<path d="M5 21V9M12 21V4M19 21v-7"/>'],
    ['mybonus.php', '魔力', '<circle cx="12" cy="12" r="8"/><path d="M9.5 12h5"/>'],
    ['myhr.php', '考核', '<path d="M9 11l3 3 6-6M5 5h9M5 12h3M5 19h6"/>'],
    ['games/', '游戏', '<rect x="3" y="8" width="18" height="9" rx="4"/><path d="M8 12.5h2M9 11.5v2"/>'],
];
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="mobile-web-app-capable" content="yes" />
<title><?php echo htmlspecialchars(($SITENAME ?? 'HDvideo')) ?> · 首页</title>
<style>
:root { --mh-primary: <?php echo $mhPrimary ?>; --mh-soft: <?php echo $mhSoft ?>; --mh-bg: <?php echo $mhBg ?>; --mh-surface: <?php echo $mhSurface ?>; --mh-text: <?php echo $mhText ?>; }
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; }
body { background: var(--mh-bg); color: var(--mh-text); font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif; padding-bottom: calc(64px + env(safe-area-inset-bottom)); min-height: 100vh; min-height: 100dvh; }
a { color: inherit; text-decoration: none; }
img { max-width: 100%; height: auto; }

.m-top { position: sticky; top: 0; z-index: 50; display: flex; align-items: center; gap: 10px;
    padding: calc(10px + env(safe-area-inset-top)) 14px 10px;
    background: var(--mh-surface); color: var(--mh-primary); box-shadow: 0 1px 10px rgba(20,40,90,.10); border-bottom: 1px solid rgba(20,40,90,.06); }
.m-brand { font-size: 20px; font-weight: 900; letter-spacing: .5px; color: var(--mh-primary); }
.m-brand span { opacity: .7; font-weight: 700; }
.m-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }
.m-iconbtn { width: 40px; height: 40px; border: none; background: var(--mh-soft); border-radius: 11px; display: flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; }
.m-iconbtn svg { width: 22px; height: 22px; fill: none; stroke: var(--mh-primary); stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
.m-burger { width: 40px; height: 40px; border: none; background: var(--mh-soft); border-radius: 11px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; cursor: pointer; padding: 0; }
.m-burger span { width: 20px; height: 2px; background: var(--mh-primary); border-radius: 2px; transition: transform .25s ease, opacity .2s ease; }
body.menu-open .m-burger span:nth-child(1) { transform: translateY(6px) rotate(45deg); }
body.menu-open .m-burger span:nth-child(2) { opacity: 0; }
body.menu-open .m-burger span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }

/* 顶部下拉导航抽屉 */
.m-mask { position: fixed; inset: 0; z-index: 40; background: rgba(0,0,0,.45); opacity: 0; visibility: hidden; transition: opacity .25s ease; }
body.menu-open .m-mask { opacity: 1; visibility: visible; }
.m-drawer { position: fixed; left: 0; right: 0; top: 0; z-index: 45; background: var(--mh-surface); border-radius: 0 0 18px 18px;
    padding: calc(60px + env(safe-area-inset-top)) 14px 18px; box-shadow: 0 14px 28px rgba(20,40,90,.18);
    transform: translateY(-100%); transition: transform .3s ease; }
body.menu-open .m-drawer { transform: translateY(0); }
.m-drawer .m-grid { margin: 0; }

.m-stat { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px 4px; margin: 12px; background: var(--mh-surface); border: 1px solid rgba(20,40,90,.06); border-radius: 16px; padding: 6px 4px 12px; box-shadow: 0 4px 14px rgba(20,40,90,.08); }
.m-stat > div { background: transparent; color: var(--mh-text); text-align: center; padding: 6px 2px; }
.m-stat b { display: block; font-size: 15px; font-weight: 800; color: var(--mh-primary); }
.m-stat span { font-size: 11px; color: #8a96ad; }
.m-stat .uname { grid-column: 1 / -1; text-align: left; padding: 10px 12px 8px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid rgba(20,40,90,.06); margin-bottom: 4px; }
.m-stat .av { flex: none; width: 44px; height: 44px; border-radius: 50%; overflow: hidden; background: var(--mh-soft); display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 19px; color: var(--mh-primary); box-shadow: 0 0 0 2px var(--mh-soft); }
.m-stat .av img { width: 100%; height: 100%; object-fit: cover; }
.m-stat .who { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.m-stat .who b { font-size: 16px; color: var(--mh-text); }
.m-stat .uname .tag { font-size: 11px; background: var(--mh-soft); color: var(--mh-primary); padding: 2px 8px; border-radius: 999px; font-weight: 700; align-self: flex-start; }

.m-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 0 12px 12px; }
.m-grid a { background: var(--mh-bg); border-radius: 14px; padding: 12px 4px; display: flex; flex-direction: column; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: var(--mh-text); position: relative; }
.m-grid a .ic { width: 38px; height: 38px; border-radius: 12px; background: var(--mh-soft); display: flex; align-items: center; justify-content: center; }
.m-grid a .ic svg { width: 21px; height: 21px; fill: none; stroke: var(--mh-primary); stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
.m-grid a .badge { position: absolute; top: 6px; right: 12px; min-width: 16px; height: 16px; padding: 0 4px; border-radius: 999px; background: #ff4d5e; color: #fff; font-size: 10px; font-weight: 800; display: flex; align-items: center; justify-content: center; }

.m-card { background: var(--mh-surface); border-radius: 16px; margin: 0 12px 12px; padding: 4px 14px 10px; border: 1px solid rgba(20,40,90,.06); box-shadow: 0 2px 10px rgba(20,40,90,.06); }
.m-card h3 { font-size: 15px; margin: 12px 0 6px; display: flex; align-items: center; }
.m-card h3 .more { margin-left: auto; font-size: 12px; color: var(--mh-primary); font-weight: 600; }
.m-card h3::before { content: ""; width: 4px; height: 15px; border-radius: 2px; background: var(--mh-primary); margin-right: 8px; }

.m-news { border-top: 1px solid #eef1f7; padding: 9px 0; }
.m-news:first-of-type { border-top: none; }
.m-news .nt { display: flex; align-items: flex-start; gap: 8px; font-weight: 700; font-size: 14px; line-height: 1.4; cursor: pointer; }
.m-news .nt .date { margin-left: auto; font-size: 11px; color: #9aa6bd; font-weight: 500; white-space: nowrap; flex: none; }
.m-news .nb { font-size: 13px; color: #3a455c; line-height: 1.6; margin-top: 6px; overflow-wrap: anywhere; word-break: break-word; display: none; }
.m-news.open .nb { display: block; }
.m-news .nb img { border-radius: 8px; }
.m-news .arrow { transition: transform .2s; color: #9aa6bd; }
.m-news.open .arrow { transform: rotate(90deg); }

.m-row { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-top: 1px solid #eef1f7; }
.m-row:first-of-type { border-top: none; }
.m-row .info { min-width: 0; flex: 1; }
.m-row .nm { font-size: 14px; font-weight: 600; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.m-row .sub { font-size: 12px; color: #8f9bb3; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.m-row .sl { flex: none; text-align: right; font-size: 12px; }
.m-row .sl .s { color: #14a44d; font-weight: 800; }
.m-row .sl .l { color: #e4564b; font-weight: 800; }

.m-pcfoot { text-align: center; margin: 4px 12px 18px; }
.m-pcfoot a { font-size: 12px; color: #8f9bb3; }

.m-tabbar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 30; display: flex; background: var(--mh-surface);
    border-top: 1px solid rgba(20,40,90,.08); padding-bottom: env(safe-area-inset-bottom); box-shadow: 0 -2px 12px rgba(20,40,90,.08); }
.m-tabbar a { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 8px 0 6px; font-size: 11px; color: #8b96ad; position: relative; }
.m-tabbar a.on { color: var(--mh-primary); }
.m-tabbar a svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
.m-tabbar a .badge { position: absolute; top: 4px; right: 50%; margin-right: -22px; min-width: 15px; height: 15px; padding: 0 4px; border-radius: 999px; background: #ff4d5e; color: #fff; font-size: 9px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
.m-empty { color: #9aa6bd; font-size: 13px; padding: 10px 0; }

/* 趣味盒 / 群聊区 iframe */
.m-frame { width: 100%; height: 230px; border: 1px solid rgba(20,40,90,.1); border-radius: 10px; background: #fff; display: block; }
.m-sbox-form { display: flex; gap: 8px; margin-top: 8px; }
.m-sbox-form input[type="text"] { flex: 1; min-width: 0; padding: 10px 12px; font-size: 15px; border: 1px solid rgba(20,40,90,.15); border-radius: 10px; background: var(--mh-bg); color: var(--mh-text); }
.m-sbox-form button { flex: none; padding: 0 16px; border: none; border-radius: 10px; background: var(--mh-primary); color: #fff; font-weight: 700; cursor: pointer; }

/* 投票 */
.m-poll-q { font-weight: 700; font-size: 14px; margin: 6px 0 10px; line-height: 1.5; }
.m-poll-opt { display: flex; align-items: center; gap: 10px; padding: 10px; border: 1px solid rgba(20,40,90,.1); border-radius: 10px; margin-bottom: 8px; font-size: 14px; cursor: pointer; }
.m-poll-opt input { width: 18px; height: 18px; flex: none; }
.m-poll-btn { width: 100%; height: 44px; border: none; border-radius: 10px; background: var(--mh-primary); color: #fff; font-weight: 800; font-size: 15px; margin-top: 4px; cursor: pointer; }
.m-poll-res { margin-bottom: 11px; }
.m-poll-res-h { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 5px; }
.m-poll-res.me .m-poll-res-h { color: var(--mh-primary); font-weight: 700; }
.m-poll-bar { height: 8px; background: var(--mh-bg); border-radius: 999px; overflow: hidden; }
.m-poll-bar i { display: block; height: 100%; background: var(--mh-primary); }
.m-poll-total { font-size: 12px; color: #8a96ad; text-align: right; margin-top: 4px; }

/* 个性化配色弹层（底部抽屉式） */
.m-modal { position: fixed; inset: 0; z-index: 60; display: none; }
.m-modal.open { display: block; }
.m-modal-mask { position: absolute; inset: 0; background: rgba(0,0,0,.5); }
.m-modal-card { position: absolute; left: 50%; top: 0; transform: translateX(-50%); width: 100%; max-width: 480px;
    max-height: 92vh; overflow-y: auto; background: var(--mh-surface); color: var(--mh-text); border-radius: 0 0 18px 18px;
    padding: calc(14px + env(safe-area-inset-top)) 18px 18px; box-shadow: 0 8px 30px rgba(0,0,0,.28); }
.m-modal-h { display: flex; align-items: center; justify-content: space-between; font-size: 17px; font-weight: 800; margin-bottom: 8px; }
.m-modal-x { font-size: 20px; color: #9aa6bd; padding: 2px 8px; cursor: pointer; }
.m-pz-label { font-size: 13px; font-weight: 700; color: #8a96ad; margin: 4px 2px 2px; }
.m-pz-presets { display: flex; gap: 8px; overflow-x: auto; padding: 6px 0 10px; -webkit-overflow-scrolling: touch; }
.m-pz-preset { flex: 0 0 auto; display: flex; align-items: center; gap: 6px; padding: 7px 12px; border: 1px solid rgba(20,40,90,.14); border-radius: 999px; background: var(--mh-bg); color: var(--mh-text); font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.m-pz-preset .dot { width: 14px; height: 14px; border-radius: 50%; background: var(--c); box-shadow: 0 0 0 1px rgba(0,0,0,.12); }
.m-pz-row { display: flex; align-items: center; justify-content: space-between; padding: 11px 2px; border-bottom: 1px solid rgba(20,40,90,.07); }
.m-pz-row label { font-size: 14px; font-weight: 600; }
.m-pz-row input[type="color"] { width: 52px; height: 34px; border: 1px solid rgba(20,40,90,.18); border-radius: 8px; background: none; padding: 2px; cursor: pointer; }
.m-pz-btns { display: flex; gap: 12px; margin-top: 16px; }
.m-pz-btns button { flex: 1; height: 46px; border: none; border-radius: 12px; font-size: 15px; font-weight: 800; cursor: pointer; }
.m-pz-reset { background: var(--mh-bg); color: var(--mh-text); }
.m-pz-save { background: var(--mh-primary); color: #fff; }
</style>
</head>
<body>
<header class="m-top">
    <div class="m-brand">HD<span>VIDEO</span></div>
    <div class="m-actions">
        <button class="m-iconbtn" id="mhPzBtn" type="button" aria-label="个性化配色">
            <svg viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 0 0 18c1.1 0 2-.9 2-2 0-.5-.2-.95-.5-1.3-.3-.35-.5-.8-.5-1.2 0-.83.67-1.5 1.5-1.5H16a5 5 0 0 0 5-5c0-3.87-4.03-7-9-7z"/><circle cx="7.5" cy="11" r="1.1" fill="currentColor" stroke="none"/><circle cx="12" cy="7.5" r="1.1" fill="currentColor" stroke="none"/><circle cx="16.5" cy="11" r="1.1" fill="currentColor" stroke="none"/></svg>
        </button>
        <button class="m-burger" id="mhMenuBtn" type="button" aria-label="导航菜单"><span></span><span></span><span></span></button>
    </div>
</header>

<div class="m-mask" id="mhMask"></div>
<nav class="m-drawer" id="mhDrawer">
    <div class="m-grid">
        <?php foreach ($navItems as $it) { ?>
        <a href="<?php echo $it[0] ?>">
            <span class="ic"><svg viewBox="0 0 24 24"><?php echo $it[2] ?></svg></span>
            <?php echo $it[1] ?>
            <?php if ($it[0] === 'messages.php' && $unread > 0) { ?><span class="badge"><?php echo $unread > 99 ? '99+' : $unread ?></span><?php } ?>
        </a>
        <?php } ?>
    </div>
</nav>

<section class="m-stat">
    <div class="uname">
        <a class="av" href="usercp.php"><?php echo mh_avatar($avatar, $uname) ?></a>
        <div class="who"><b><?php echo htmlspecialchars($uname) ?></b><?php if ($classText !== '') { ?><span class="tag"><?php echo strip_tags($classText) ?></span><?php } ?></div>
    </div>
    <div><b>↑<?php echo mksize($up) ?></b><span>上传</span></div>
    <div><b>↓<?php echo mksize($down) ?></b><span>下载</span></div>
    <div><b><?php echo $ratio ?></b><span>分享率</span></div>
    <div><b><?php echo $bonus ?></b><span>魔力</span></div>
    <div><b><?php echo $invites ?></b><span>邀请</span></div>
    <div><b><?php echo $activeSeed ?></b><span>做种</span></div>
    <div><b><?php echo $activeLeech ?></b><span>下载中</span></div>
    <div><b><?php echo $hrCount ?></b><span>待考核</span></div>
</section>

<?php if ($newsRows) { ?>
<section class="m-card">
    <h3>公告</h3>
    <?php foreach ($newsRows as $i => $n) { ?>
    <div class="m-news<?php echo $i === 0 ? ' open' : '' ?>">
        <div class="nt" onclick="this.parentNode.classList.toggle('open')">
            <span><?php echo htmlspecialchars($n['title']) ?></span>
            <span class="date"><?php echo date('m-d', strtotime($n['added'])) ?></span>
            <span class="arrow">›</span>
        </div>
        <div class="nb"><?php echo format_comment($n['body'], 0) ?></div>
    </div>
    <?php } ?>
</section>
<?php } ?>

<?php if (($showfunbox_main ?? '') === 'yes' && (($CURUSER['showfb'] ?? '') === 'yes')) { ?>
<section class="m-card">
    <h3>趣味盒</h3>
    <iframe class="m-frame" src="fun.php?action=view" frameborder="0" scrolling="auto"></iframe>
</section>
<?php } ?>

<?php if (($showshoutbox_main ?? '') === 'yes') { ?>
<section class="m-card">
    <h3>群聊区</h3>
    <iframe class="m-frame" id="mhSbox" name="mhSbox" src="shoutbox.php?type=shoutbox" frameborder="0" scrolling="auto"></iframe>
    <form class="m-sbox-form" action="shoutbox.php" method="get" target="mhSbox">
        <input type="text" name="shbox_text" placeholder="说点什么…" autocomplete="off" maxlength="250">
        <input type="hidden" name="sent" value="yes">
        <input type="hidden" name="type" value="shoutbox">
        <button type="submit" name="shout" value="发送">发送</button>
    </form>
</section>
<?php } ?>

<?php if ($pollRow && $pollOpts) { ?>
<section class="m-card">
    <h3>投票</h3>
    <div class="m-poll-q"><?php echo htmlspecialchars($pollRow['question']) ?></div>
    <?php if ($pollVoted === null) { ?>
    <form class="m-poll-form" method="post" action="index.php">
        <?php foreach ($pollOpts as $i => $o) { ?>
        <label class="m-poll-opt"><input type="radio" name="choice" value="<?php echo $i ?>"><span><?php echo htmlspecialchars($o) ?></span></label>
        <?php } ?>
        <button type="submit" class="m-poll-btn">投票</button>
    </form>
    <?php } else { ?>
        <?php foreach ($pollOpts as $i => $o) { $c = $pollCounts[$i] ?? 0; $pct = $pollTotal > 0 ? round($c * 100 / $pollTotal) : 0; ?>
        <div class="m-poll-res<?php echo $i === $pollVoted ? ' me' : '' ?>">
            <div class="m-poll-res-h"><span><?php echo htmlspecialchars($o) ?><?php echo $i === $pollVoted ? ' ✓' : '' ?></span><span><?php echo $pct ?>% · <?php echo $c ?></span></div>
            <div class="m-poll-bar"><i style="width:<?php echo $pct ?>%"></i></div>
        </div>
        <?php } ?>
        <div class="m-poll-total">共 <?php echo $pollTotal ?> 票</div>
    <?php } ?>
</section>
<?php } ?>

<section class="m-card">
    <h3>最新种子<a class="more" href="torrents.php">更多 ›</a></h3>
    <?php if (!$torRows) { ?><div class="m-empty">暂无种子。</div><?php } ?>
    <?php foreach ($torRows as $t) { ?>
    <a class="m-row" href="details.php?id=<?php echo (int)$t['id'] ?>&hit=1">
        <div class="info">
            <div class="nm"><?php echo htmlspecialchars($t['name']) ?></div>
            <?php if (!empty($t['small_descr'])) { ?><div class="sub"><?php echo htmlspecialchars($t['small_descr']) ?></div><?php } ?>
        </div>
        <div class="sl"><span class="s"><?php echo (int)$t['seeders'] ?>↑</span> · <span class="l"><?php echo (int)$t['leechers'] ?>↓</span></div>
    </a>
    <?php } ?>
</section>

<?php if ($postRows) { ?>
<section class="m-card">
    <h3>最新主题<a class="more" href="forums.php">更多 ›</a></h3>
    <?php foreach ($postRows as $p) { ?>
    <a class="m-row" href="forums.php?action=viewtopic&topicid=<?php echo (int)$p['tid'] ?>&page=p<?php echo (int)$p['pid'] ?>#pid<?php echo (int)$p['pid'] ?>">
        <div class="info">
            <div class="nm"><?php echo htmlspecialchars($p['subject']) ?></div>
            <div class="sub"><?php echo htmlspecialchars($p['name']) ?></div>
        </div>
    </a>
    <?php } ?>
</section>
<?php } ?>

<div class="m-pcfoot"><a href="?pc=1">切换到电脑版 ›</a></div>

<nav class="m-tabbar">
    <a class="on" href="index.php"><svg viewBox="0 0 24 24"><path d="M4 11l8-7 8 7M6 10v9h12v-9"/></svg>首页</a>
    <a href="torrents.php"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg>种子</a>
    <a href="forums.php"><svg viewBox="0 0 24 24"><path d="M4 5h16v10H9l-4 4z"/></svg>论坛</a>
    <a href="messages.php"><svg viewBox="0 0 24 24"><path d="M4 5h16v12H8l-4 4z"/></svg>消息<?php if ($unread > 0) { ?><span class="badge"><?php echo $unread > 99 ? '99+' : $unread ?></span><?php } ?></a>
    <a href="usercp.php"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>我的</a>
</nav>
<div class="m-modal" id="mhPzModal">
    <div class="m-modal-mask" data-pz-close></div>
    <div class="m-modal-card">
        <div class="m-modal-h"><span>个性化配色</span><span class="m-modal-x" data-pz-close>✕</span></div>
        <div class="m-pz-label">预设配色</div>
        <div class="m-pz-presets">
            <?php foreach ($mhPresets as $i => $p) { ?>
            <button type="button" class="m-pz-preset" data-preset="<?php echo $i ?>" style="--c: <?php echo $p[1] ?>"><span class="dot"></span><?php echo $p[0] ?></button>
            <?php } ?>
        </div>
        <div class="m-pz-row"><label>主色调</label><input type="color" data-var="--bili-primary" value="<?php echo $mhPrimary ?>"></div>
        <div class="m-pz-row"><label>强调色</label><input type="color" data-var="--bili-accent" value="<?php echo $mhAccent ?>"></div>
        <div class="m-pz-row"><label>背景色</label><input type="color" data-var="--bili-bg" value="<?php echo $mhBg ?>"></div>
        <div class="m-pz-row"><label>面板色</label><input type="color" data-var="--bili-surface" value="<?php echo $mhSurface ?>"></div>
        <div class="m-pz-row"><label>文字色</label><input type="color" data-var="--bili-text" value="<?php echo $mhText ?>"></div>
        <div class="m-pz-btns">
            <button type="button" class="m-pz-reset" id="mhPzReset">恢复默认</button>
            <button type="button" class="m-pz-save" id="mhPzSave">保存</button>
        </div>
    </div>
</div>

<script>
(function () {
    var btn = document.getElementById('mhMenuBtn'), mask = document.getElementById('mhMask');
    function close() { document.body.classList.remove('menu-open'); }
    if (btn) btn.addEventListener('click', function () { document.body.classList.toggle('menu-open'); });
    if (mask) mask.addEventListener('click', close);
    document.querySelectorAll('#mhDrawer a').forEach(function (a) { a.addEventListener('click', close); });
})();
(function () {
    var modal = document.getElementById('mhPzModal'), openBtn = document.getElementById('mhPzBtn');
    if (!modal || !openBtn) return;
    var root = document.documentElement;
    function lighten(hex, pct) {
        hex = hex.replace('#', '');
        var r = parseInt(hex.substr(0,2),16), g = parseInt(hex.substr(2,2),16), b = parseInt(hex.substr(4,2),16);
        r = Math.round(r+(255-r)*pct); g = Math.round(g+(255-g)*pct); b = Math.round(b+(255-b)*pct);
        return '#' + [r,g,b].map(function (x) { return ('0'+x.toString(16)).slice(-2); }).join('');
    }
    function applyVar(v, hex) {
        if (v === '--bili-primary') { root.style.setProperty('--mh-primary', hex); root.style.setProperty('--mh-soft', lighten(hex, 0.86)); }
        else if (v === '--bili-bg') { root.style.setProperty('--mh-bg', hex); }
        else if (v === '--bili-surface') { root.style.setProperty('--mh-surface', hex); }
        else if (v === '--bili-text') { root.style.setProperty('--mh-text', hex); }
    }
    function closeModal() { modal.classList.remove('open'); }
    openBtn.addEventListener('click', function () { modal.classList.add('open'); });
    modal.querySelectorAll('[data-pz-close]').forEach(function (el) { el.addEventListener('click', closeModal); });
    var inputs = modal.querySelectorAll('input[type=color]');
    inputs.forEach(function (inp) { inp.addEventListener('input', function () { applyVar(inp.getAttribute('data-var'), inp.value); }); });
    // 预设：[主色,强调,背景,面板,文字]
    var MH_PRESETS = <?php echo json_encode(array_map(fn($p) => array_slice($p, 1), $mhPresets)) ?>;
    var PZ_VARS = ['--bili-primary', '--bili-accent', '--bili-bg', '--bili-surface', '--bili-text'];
    modal.querySelectorAll('.m-pz-preset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var p = MH_PRESETS[parseInt(btn.getAttribute('data-preset'), 10)];
            if (!p) return;
            PZ_VARS.forEach(function (v, idx) {
                var inp = modal.querySelector('input[data-var="' + v + '"]');
                if (inp) inp.value = p[idx];
                applyVar(v, p[idx]);
            });
        });
    });
    document.getElementById('mhPzSave').addEventListener('click', function () {
        var data = {};
        inputs.forEach(function (inp) { data[inp.getAttribute('data-var')] = inp.value; });
        fetch('ajax.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=savePersonalize&params%5Bdata%5D=' + encodeURIComponent(JSON.stringify(data)) })
            .then(function () { closeModal(); }).catch(function () { closeModal(); });
    });
    document.getElementById('mhPzReset').addEventListener('click', function () {
        fetch('ajax.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=clearPersonalize&params%5Bx%5D=1' })
            .then(function () { location.reload(); }).catch(function () { location.reload(); });
    });
})();
</script>
</body>
</html>
