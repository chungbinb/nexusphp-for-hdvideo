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

// 个性化配色：取 PC 端账号设置的个性化主色（UserMeta PERSONALIZE 的 --bili-primary）
$mhPrimary = '#3a6df0';
try {
    $pm = \App\Models\UserMeta::query()->where('uid', $uid)->where('meta_key', 'PERSONALIZE')->where('status', 0)->value('meta_value');
    if ($pm) {
        $arr = json_decode($pm, true);
        if (is_array($arr) && isset($arr['--bili-primary']) && preg_match('/^#[0-9a-fA-F]{6}$/', $arr['--bili-primary'])) {
            $mhPrimary = $arr['--bili-primary'];
        }
    }
} catch (\Throwable $e) {}
function mh_lighten($hex, $pct) {
    $hex = ltrim((string)$hex, '#');
    if (strlen($hex) !== 6) return '#' . $hex;
    $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
    $r = (int)round($r + (255 - $r) * $pct); $g = (int)round($g + (255 - $g) * $pct); $b = (int)round($b + (255 - $b) * $pct);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}
$mhGradEnd = mh_lighten($mhPrimary, 0.40);
$mhSoft = mh_lighten($mhPrimary, 0.86);

$unread = 0;
try { $unread = (int)get_row_count('messages', "WHERE receiver = " . $uid . " AND unread = 'yes' AND location != 0"); } catch (\Throwable $e) {}

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
:root { --mh-primary: <?php echo $mhPrimary ?>; --mh-grad-end: <?php echo $mhGradEnd ?>; --mh-soft: <?php echo $mhSoft ?>; }
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; }
body { background: #eef1f7; color: #1b2230; font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif; padding-bottom: calc(64px + env(safe-area-inset-bottom)); }
a { color: inherit; text-decoration: none; }
img { max-width: 100%; height: auto; }

.m-top { position: sticky; top: 0; z-index: 50; display: flex; align-items: center; gap: 10px;
    padding: calc(10px + env(safe-area-inset-top)) 14px 10px;
    background: linear-gradient(135deg, var(--mh-primary), var(--mh-grad-end)); color: #fff; box-shadow: 0 2px 10px rgba(20,40,90,.25); }
.m-brand { font-size: 20px; font-weight: 900; letter-spacing: .5px; }
.m-brand span { opacity: .85; font-weight: 700; }
.m-burger { margin-left: auto; width: 40px; height: 40px; border: none; background: rgba(255,255,255,.22); border-radius: 11px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; cursor: pointer; padding: 0; }
.m-burger span { width: 20px; height: 2px; background: #fff; border-radius: 2px; transition: transform .25s ease, opacity .2s ease; }
body.menu-open .m-burger span:nth-child(1) { transform: translateY(6px) rotate(45deg); }
body.menu-open .m-burger span:nth-child(2) { opacity: 0; }
body.menu-open .m-burger span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }

/* 顶部下拉导航抽屉 */
.m-mask { position: fixed; inset: 0; z-index: 40; background: rgba(0,0,0,.45); opacity: 0; visibility: hidden; transition: opacity .25s ease; }
body.menu-open .m-mask { opacity: 1; visibility: visible; }
.m-drawer { position: fixed; left: 0; right: 0; top: 0; z-index: 45; background: #fff; border-radius: 0 0 18px 18px;
    padding: calc(60px + env(safe-area-inset-top)) 14px 18px; box-shadow: 0 14px 28px rgba(20,40,90,.22);
    transform: translateY(-100%); transition: transform .3s ease; }
body.menu-open .m-drawer { transform: translateY(0); }
.m-drawer .m-grid { margin: 0; }

.m-stat { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px; margin: 12px; background: linear-gradient(135deg, var(--mh-primary), var(--mh-grad-end)); border-radius: 16px; overflow: hidden; box-shadow: 0 6px 16px rgba(20,40,90,.22); }
.m-stat > div { background: transparent; color: #fff; text-align: center; padding: 12px 4px; }
.m-stat b { display: block; font-size: 15px; font-weight: 800; }
.m-stat span { font-size: 11px; opacity: .85; }
.m-stat .uname { grid-column: 1 / -1; text-align: left; padding: 12px 14px 6px; display: flex; align-items: center; gap: 10px; }
.m-stat .av { flex: none; width: 44px; height: 44px; border-radius: 50%; overflow: hidden; background: rgba(255,255,255,.25); display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 19px; color: #fff; box-shadow: 0 0 0 2px rgba(255,255,255,.55); }
.m-stat .av img { width: 100%; height: 100%; object-fit: cover; }
.m-stat .who { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.m-stat .who b { font-size: 16px; }
.m-stat .uname .tag { font-size: 11px; background: rgba(255,255,255,.22); padding: 2px 8px; border-radius: 999px; font-weight: 700; align-self: flex-start; }

.m-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 0 12px 12px; }
.m-grid a { background: #fff; border-radius: 14px; padding: 12px 4px; display: flex; flex-direction: column; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #2b3550; box-shadow: 0 2px 8px rgba(30,50,100,.06); position: relative; }
.m-grid a .ic { width: 38px; height: 38px; border-radius: 12px; background: var(--mh-soft); display: flex; align-items: center; justify-content: center; }
.m-grid a .ic svg { width: 21px; height: 21px; fill: none; stroke: var(--mh-primary); stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
.m-grid a .badge { position: absolute; top: 6px; right: 12px; min-width: 16px; height: 16px; padding: 0 4px; border-radius: 999px; background: #ff4d5e; color: #fff; font-size: 10px; font-weight: 800; display: flex; align-items: center; justify-content: center; }

.m-card { background: #fff; border-radius: 16px; margin: 0 12px 12px; padding: 4px 14px 10px; box-shadow: 0 2px 10px rgba(30,50,100,.06); }
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

.m-tabbar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 30; display: flex; background: #fff;
    border-top: 1px solid #e6eaf2; padding-bottom: env(safe-area-inset-bottom); box-shadow: 0 -2px 12px rgba(30,50,100,.08); }
.m-tabbar a { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 8px 0 6px; font-size: 11px; color: #8b96ad; position: relative; }
.m-tabbar a.on { color: var(--mh-primary); }
.m-tabbar a svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
.m-tabbar a .badge { position: absolute; top: 4px; right: 50%; margin-right: -22px; min-width: 15px; height: 15px; padding: 0 4px; border-radius: 999px; background: #ff4d5e; color: #fff; font-size: 9px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
.m-empty { color: #9aa6bd; font-size: 13px; padding: 10px 0; }
</style>
</head>
<body>
<header class="m-top">
    <div class="m-brand">HD<span>VIDEO</span></div>
    <button class="m-burger" id="mhMenuBtn" type="button" aria-label="导航菜单"><span></span><span></span><span></span></button>
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
<script>
(function () {
    var btn = document.getElementById('mhMenuBtn'), mask = document.getElementById('mhMask');
    function close() { document.body.classList.remove('menu-open'); }
    if (btn) btn.addEventListener('click', function () { document.body.classList.toggle('menu-open'); });
    if (mask) mask.addEventListener('click', close);
    document.querySelectorAll('#mhDrawer a').forEach(function (a) { a.addEventListener('click', close); });
})();
</script>
</body>
</html>
