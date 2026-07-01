<?php
/**
 * 手机版统一外壳：顶栏(品牌+管理/联系+个性化+三横杆) + 抽屉导航 + 底部Tab + 我的/管理弹层 + 个性化弹层。
 * 用于在 stdhead 页面(如 usercp)上叠加与首页一致的手机外壳；配套 styles/mobile-shell.css。
 * mobile_shell_render($active) 直接 echo 全部外壳标记与脚本（这些元素均 fixed/sticky，放在页面任意处即可）。
 */
if (!function_exists('mobile_shell_render')) {

function mobile_shell_colors(): array
{
    global $CURUSER;
    $c = ['primary' => '#00aeec', 'accent' => '#fb7299', 'bg' => '#f6f7fb', 'surface' => '#ffffff', 'text' => '#18191c'];
    try {
        $uid = (int)$CURUSER['id'];
        $pm = \App\Models\UserMeta::query()->where('uid', $uid)->where('meta_key', 'PERSONALIZE')->where('status', 0)->value('meta_value');
        if ($pm) {
            $a = json_decode($pm, true);
            if (is_array($a)) {
                $re = '/^#[0-9a-fA-F]{6}$/';
                $map = ['primary' => '--bili-primary', 'accent' => '--bili-accent', 'bg' => '--bili-bg', 'surface' => '--bili-surface', 'text' => '--bili-text'];
                foreach ($map as $k => $v) { if (isset($a[$v]) && preg_match($re, $a[$v])) $c[$k] = strtolower($a[$v]); }
            }
        }
    } catch (\Throwable $e) {}
    return $c;
}

function mobile_shell_render(string $active = ''): void
{
    global $CURUSER;
    $uid = (int)$CURUSER['id'];
    $unread = 0;
    try { $unread = (int)get_row_count('messages', "WHERE receiver = $uid AND unread = 'yes' AND location != 0"); } catch (\Throwable $e) {}
    // 底部"种子"点击弹出的分类列表(后台配置的浏览分类，用站点的浏览模式 $browsecatmode)
    $torrentCats = [];
    try {
        global $browsecatmode;
        $bcm = (int)($browsecatmode ?? 0);
        if ($bcm <= 0) $bcm = 1;
        if (function_exists('genrelist')) $torrentCats = genrelist($bcm);
    } catch (\Throwable $e) {}

    $navItems = [
        ['torrents.php?requireseed=1', '保种区', '<path d="M12 3l8 3v6c0 4.2-3.1 6.3-8 8-4.9-1.7-8-3.8-8-8V6z"/>'],
    ];
    if (($GLOBALS['enableoffer'] ?? '') === 'yes') $navItems[] = ['offers.php', '候选', '<path d="M5 6h14M5 12h14M5 18h9"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>'];
    $navItems[] = ['viewrequests.php', '求种', '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/>'];
    $navItems[] = ['subtitles.php', '字幕', '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M7 14h4M13 14h4M7 10h2"/>'];
    $navItems[] = ['upload.php', '发布', '<path d="M12 19V7M6 11l6-6 6 6M5 21h14"/>'];
    $navItems[] = ['topten.php', '排行', '<path d="M5 21V9M12 21V4M19 21v-7"/>'];
    $navItems[] = ['myhr.php', '考核', '<path d="M9 11l3 3 6-6M5 5h9M5 12h3M5 19h6"/>'];
    $navItems[] = ['rules.php', '规则', '<path d="M6 3h9l4 4v14H6z"/><path d="M9 9h6M9 13h6M9 17h4"/>'];
    $navItems[] = ['faq.php', '常见问题', '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.5a2.4 2.4 0 1 1 3.3 2.2c-.8.4-1.4 1-1.4 1.9v.3"/><path d="M12 17h.01"/>'];
    if (function_exists('user_can') && user_can('log')) $navItems[] = ['log.php', '日志', '<path d="M3 12a9 9 0 1 0 3-6.7M3 5v4h4"/><path d="M12 8v4l3 2"/>'];
    $navItems[] = ['user-ban-log.php', '封禁记录', '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>'];
    $navItems[] = ['index.php', '首页', '<path d="M4 11l8-7 8 7M6 10v9h12v-9"/>'];

    $meItems = [
        ['usercp.php', '个人中心', '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>', false],
        ['messages.php', '消息', '<path d="M4 5h16v12H8l-4 4z"/>', true],
        ['attendance.php', '签到', '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 9h18M9 15l2 2 4-4"/>', false],
        ['mybonus.php', '魔力', '<circle cx="12" cy="12" r="8"/><path d="M9.5 12h5"/>', false],
        ['invite.php', '邀请', '<circle cx="9" cy="8" r="4"/><path d="M3 20c0-3.5 3-5 6-5s6 1.5 6 5M19 8v6M16 11h6"/>', false],
        ['medal.php', '勋章', '<circle cx="12" cy="9" r="5"/><path d="M9 13l-2 8 5-3 5 3-2-8"/>', false],
        ['logout.php', '退出登录', '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>', false],
    ];

    $adminItems = [];
    if (function_exists('user_can')) {
        $uclass = function_exists('get_user_class') ? (int)get_user_class() : (int)($CURUSER['class'] ?? 0);
        if (user_can('viewstaff') || (defined('UC_MODERATOR') && $uclass >= UC_MODERATOR)) $adminItems[] = ['staff.php', '管理组', '<path d="M12 3l8 3v6c0 4-3 6.3-8 8-5-1.7-8-4-8-8V6z"/>'];
        if (user_can('staffmem')) {
            $adminItems[] = ['staffbox.php', '管理组信箱', '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>'];
            $adminItems[] = ['reports.php', '举报信箱', '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17h.01"/>'];
            $adminItems[] = ['cheaterbox.php', '作弊者', '<circle cx="9" cy="8" r="4"/><path d="M3 20c0-3.4 3-5 6-5"/><path d="M15.5 9.5l5 5M20.5 9.5l-5 5"/>'];
            $adminItems[] = ['complains.php?action=list', '申诉处理', '<path d="M4 5h16v11H8l-4 4z"/><path d="M9 9h6M9 12h4"/>'];
        }
        if (defined('UC_MODERATOR') && $uclass >= UC_MODERATOR) $adminItems[] = ['staffpanel.php', '管理组面板', '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 9v11"/>'];
        if (defined('UC_SYSOP') && $uclass >= UC_SYSOP) $adminItems[] = ['settings.php', '站点设定', '<circle cx="12" cy="12" r="3"/><path d="M19.4 13a7.8 7.8 0 0 0 0-2l2-1.5-2-3.4-2.4 1a7 7 0 0 0-1.7-1L15 3.5h-4l-.3 2.6a7 7 0 0 0-1.7 1l-2.4-1-2 3.4L4.6 11a7.8 7.8 0 0 0 0 2l-2 1.5 2 3.4 2.4-1a7 7 0 0 0 1.7 1l.3 2.6h4l.3-2.6a7 7 0 0 0 1.7-1l2.4 1 2-3.4z"/>'];
        try { if ($uclass >= \App\Models\User::getAccessAdminClassMin()) $adminItems[] = [nexus_env('FILAMENT_PATH', 'nexusphp'), '管理系统', '<rect x="3" y="4" width="18" height="7" rx="1"/><rect x="3" y="13" width="18" height="7" rx="1"/><path d="M7 7.5h.01M7 16.5h.01"/>', true]; } catch (\Throwable $e) {}
    }

    $presets = [
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
    $col = mobile_shell_colors();
    $badge = $unread > 99 ? '99+' : (string)$unread;
    $tab = function ($key, $href, $svg, $label) use ($active, $unread, $badge) {
        $on = $key === $active ? ' class="on"' : '';
        $b = ($key === 'me' && $unread > 0) ? '<span class="badge">' . $badge . '</span>' : '';
        return '<a' . $on . ' href="/' . ltrim($href, '/') . '"><svg viewBox="0 0 24 24">' . $svg . '</svg>' . $label . $b . '</a>';
    };
    ?>
<div id="mhShell">
<header class="m-top">
    <a class="m-brand" href="/index.php">HD<span>VIDEO</span></a>
    <div class="m-actions">
        <?php if ($adminItems) { ?>
        <button class="m-iconbtn" id="mhAdminBtn" type="button" aria-label="管理"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 13a7.8 7.8 0 0 0 0-2l2-1.5-2-3.4-2.4 1a7 7 0 0 0-1.7-1L15 3.5h-4l-.3 2.6a7 7 0 0 0-1.7 1l-2.4-1-2 3.4L4.6 11a7.8 7.8 0 0 0 0 2l-2 1.5 2 3.4 2.4-1a7 7 0 0 0 1.7 1l.3 2.6h4l.3-2.6a7 7 0 0 0 1.7-1l2.4 1 2-3.4z"/></svg></button>
        <?php } else { ?>
        <a class="m-iconbtn" href="/contactstaff.php" aria-label="联系管理组"><svg viewBox="0 0 24 24"><path d="M5 13v-1a7 7 0 0 1 14 0v1"/><rect x="3" y="13" width="4" height="6" rx="1.6"/><rect x="17" y="13" width="4" height="6" rx="1.6"/><path d="M19 19a3.5 3.5 0 0 1-3.5 3H13"/></svg></a>
        <?php } ?>
        <button class="m-iconbtn" id="mhPzBtn" type="button" aria-label="个性化配色"><svg viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 0 0 18c1.1 0 2-.9 2-2 0-.5-.2-.95-.5-1.3-.3-.35-.5-.8-.5-1.2 0-.83.67-1.5 1.5-1.5H16a5 5 0 0 0 5-5c0-3.87-4.03-7-9-7z"/><circle cx="7.5" cy="11" r="1.1" fill="currentColor" stroke="none"/><circle cx="12" cy="7.5" r="1.1" fill="currentColor" stroke="none"/><circle cx="16.5" cy="11" r="1.1" fill="currentColor" stroke="none"/></svg></button>
        <button class="m-burger" id="mhMenuBtn" type="button" aria-label="导航菜单"><span></span><span></span><span></span></button>
    </div>
</header>

<div class="m-mask" id="mhMask"></div>
<nav class="m-drawer" id="mhDrawer">
    <div class="m-grid">
        <?php foreach ($navItems as $it) { ?>
        <a href="/<?php echo ltrim($it[0], '/') ?>"><span class="ic"><svg viewBox="0 0 24 24"><?php echo $it[2] ?></svg></span><?php echo $it[1] ?></a>
        <?php } ?>
    </div>
</nav>

<nav class="m-tabbar">
    <?php
    echo $tab('home', 'index.php', '<path d="M4 11l8-7 8 7M6 10v9h12v-9"/>', '首页');
    ?>
    <button type="button" id="mhTorrentBtn"<?php echo $active === 'torrents' ? ' class="on"' : '' ?>><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg>种子</button>
    <?php
    echo $tab('forums', 'forums.php', '<path d="M4 5h16v10H9l-4 4z"/>', '论坛');
    echo $tab('games', 'games/', '<rect x="3" y="8" width="18" height="9" rx="4"/><path d="M8 12.5h2M9 11.5v2"/>', '游戏');
    ?>
    <button type="button" id="mhMeBtn"<?php echo $active === 'me' ? ' class="on"' : '' ?>><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>我的<?php if ($unread > 0) { ?><span class="badge"><?php echo $badge ?></span><?php } ?></button>
</nav>

<div class="m-sheet" id="mhTorrentSheet">
    <div class="m-sheet-mask" data-torrent-close></div>
    <div class="m-sheet-card">
        <div class="m-sheet-handle"></div>
        <a class="m-me-item" href="/torrents.php"><span class="ic"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></span><span class="t">全部分类</span><span class="arr">›</span></a>
        <?php foreach ($torrentCats as $tcat) { ?>
        <a class="m-me-item" href="/torrents.php?cat=<?php echo (int)$tcat['id'] ?>"><span class="ic"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3 9h18M8 5v14"/></svg></span><span class="t"><?php echo htmlspecialchars($tcat['name']) ?></span><span class="arr">›</span></a>
        <?php } ?>
    </div>
</div>

<div class="m-sheet" id="mhMeSheet">
    <div class="m-sheet-mask" data-me-close></div>
    <div class="m-sheet-card">
        <div class="m-sheet-handle"></div>
        <?php foreach ($meItems as $mi) { ?>
        <a class="m-me-item" href="/<?php echo ltrim($mi[0], '/') ?>"><span class="ic"><svg viewBox="0 0 24 24"><?php echo $mi[2] ?></svg></span><span class="t"><?php echo $mi[1] ?></span><?php if ($mi[3] && $unread > 0) { ?><span class="badge"><?php echo $badge ?></span><?php } ?><span class="arr">›</span></a>
        <?php } ?>
    </div>
</div>

<?php if ($adminItems) { ?>
<div class="m-sheet" id="mhAdminSheet">
    <div class="m-sheet-mask" data-admin-close></div>
    <div class="m-sheet-card">
        <div class="m-sheet-handle"></div>
        <?php foreach ($adminItems as $ai) { ?>
        <a class="m-me-item" href="<?php echo htmlspecialchars(preg_match('#^https?://#i', (string)$ai[0]) ? $ai[0] : '/' . ltrim((string)$ai[0], '/')) ?>"<?php echo !empty($ai[3]) ? ' target="_blank"' : '' ?>><span class="ic"><svg viewBox="0 0 24 24"><?php echo $ai[2] ?></svg></span><span class="t"><?php echo $ai[1] ?></span><span class="arr">›</span></a>
        <?php } ?>
    </div>
</div>
<?php } ?>

<div class="m-modal" id="mhPzModal" data-shell-modal>
    <div class="m-modal-mask" data-pz-close></div>
    <div class="m-modal-card">
        <div class="m-modal-h"><span>个性化配色</span><span class="m-modal-x" data-pz-close>✕</span></div>
        <div class="m-pz-label">预设配色</div>
        <div class="m-pz-presets">
            <?php foreach ($presets as $i => $p) { ?>
            <button type="button" class="m-pz-preset" data-preset="<?php echo $i ?>" style="--c: <?php echo $p[1] ?>"><span class="dot"></span><?php echo $p[0] ?></button>
            <?php } ?>
        </div>
        <div class="m-pz-row"><label>主色调</label><input type="color" data-var="--bili-primary" value="<?php echo $col['primary'] ?>"></div>
        <div class="m-pz-row"><label>强调色</label><input type="color" data-var="--bili-accent" value="<?php echo $col['accent'] ?>"></div>
        <div class="m-pz-row"><label>背景色</label><input type="color" data-var="--bili-bg" value="<?php echo $col['bg'] ?>"></div>
        <div class="m-pz-row"><label>面板色</label><input type="color" data-var="--bili-surface" value="<?php echo $col['surface'] ?>"></div>
        <div class="m-pz-row"><label>文字色</label><input type="color" data-var="--bili-text" value="<?php echo $col['text'] ?>"></div>
        <div class="m-pz-btns">
            <button type="button" class="m-pz-reset" id="mhPzReset">恢复默认</button>
            <button type="button" class="m-pz-save" id="mhPzSave">保存</button>
        </div>
    </div>
</div>
</div><!-- /#mhShell -->

<script>
(function () {
    var body = document.body;
    body.classList.add('m-shell');
    // 把外壳提到 body 顶层，避免被有 transform 的祖先困住导致 fixed 失效
    var shell = document.getElementById('mhShell');
    if (shell && shell.parentNode !== body) body.appendChild(shell);
    // 任一弹层/抽屉/弹窗打开时锁住背景滚动；全部关闭时解锁
    function syncLock() {
        var anyOpen = body.classList.contains('menu-open')
            || document.querySelector('.m-sheet.open')
            || document.querySelector('.m-modal.open');
        body.classList.toggle('m-locked', !!anyOpen);
    }
    function closeSheets(except) {
        document.querySelectorAll('.m-sheet.open').forEach(function (s) { if (s !== except) s.classList.remove('open'); });
    }
    var menuBtn = document.getElementById('mhMenuBtn'), mask = document.getElementById('mhMask');
    if (menuBtn) menuBtn.addEventListener('click', function () { closeSheets(null); body.classList.toggle('menu-open'); syncLock(); });
    if (mask) mask.addEventListener('click', function () { body.classList.remove('menu-open'); syncLock(); });
    function bindSheet(sheetId, btnId, closeAttr) {
        var sheet = document.getElementById(sheetId), btn = document.getElementById(btnId);
        if (!sheet || !btn) return;
        // 再次点击同一按钮收起；打开时关闭其它弹层/抽屉
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var willOpen = !sheet.classList.contains('open');
            closeSheets(sheet);
            body.classList.remove('menu-open');
            sheet.classList.toggle('open', willOpen);
            syncLock();
        });
        sheet.querySelectorAll('[' + closeAttr + ']').forEach(function (el) { el.addEventListener('click', function () { sheet.classList.remove('open'); syncLock(); }); });
    }
    bindSheet('mhMeSheet', 'mhMeBtn', 'data-me-close');
    bindSheet('mhTorrentSheet', 'mhTorrentBtn', 'data-torrent-close');
    bindSheet('mhAdminSheet', 'mhAdminBtn', 'data-admin-close');

    var modal = document.getElementById('mhPzModal'), pzBtn = document.getElementById('mhPzBtn');
    if (modal && pzBtn) {
        var root = document.documentElement;
        function lighten(hex, pct) { hex = hex.replace('#', ''); var r = parseInt(hex.substr(0,2),16), g = parseInt(hex.substr(2,2),16), b = parseInt(hex.substr(4,2),16); r = Math.round(r+(255-r)*pct); g = Math.round(g+(255-g)*pct); b = Math.round(b+(255-b)*pct); return '#' + [r,g,b].map(function (x) { return ('0'+x.toString(16)).slice(-2); }).join(''); }
        function applyVar(v, hex) { root.style.setProperty(v, hex); if (v === '--bili-primary') root.style.setProperty('--mh-soft', lighten(hex, 0.86)); }
        function closeModal() { modal.classList.remove('open'); syncLock(); }
        pzBtn.addEventListener('click', function () { closeSheets(null); body.classList.remove('menu-open'); modal.classList.add('open'); syncLock(); });
        modal.querySelectorAll('[data-pz-close]').forEach(function (el) { el.addEventListener('click', closeModal); });
        var inputs = modal.querySelectorAll('input[type=color]');
        inputs.forEach(function (inp) { inp.addEventListener('input', function () { applyVar(inp.getAttribute('data-var'), inp.value); }); });
        var MH_PRESETS = <?php echo json_encode(array_map(fn($p) => array_slice($p, 1), $presets)) ?>;
        var PZ_VARS = ['--bili-primary', '--bili-accent', '--bili-bg', '--bili-surface', '--bili-text'];
        modal.querySelectorAll('.m-pz-preset').forEach(function (b) {
            b.addEventListener('click', function () {
                var p = MH_PRESETS[parseInt(b.getAttribute('data-preset'), 10)]; if (!p) return;
                PZ_VARS.forEach(function (v, idx) { var inp = modal.querySelector('input[data-var="' + v + '"]'); if (inp) inp.value = p[idx]; applyVar(v, p[idx]); });
            });
        });
        document.getElementById('mhPzSave').addEventListener('click', function () {
            var data = {}; inputs.forEach(function (inp) { data[inp.getAttribute('data-var')] = inp.value; });
            fetch('/ajax.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=savePersonalize&params%5Bdata%5D=' + encodeURIComponent(JSON.stringify(data)) }).then(function () { closeModal(); }).catch(function () { closeModal(); });
        });
        document.getElementById('mhPzReset').addEventListener('click', function () {
            fetch('/ajax.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=clearPersonalize&params%5Bx%5D=1' }).then(function () { location.reload(); }).catch(function () { location.reload(); });
        });
    }
})();
</script>
    <?php
}

/** 独立手机页头：输出 DOCTYPE/head/body + 打开内容容器 .m-main（外壳的顶栏/底部Tab等由 page_foot 输出，均为 fixed 定位） */
function mobile_shell_page_head(string $title = '', string $active = '', string $pageClass = ''): void
{
    global $SITENAME, $CURUSER, $Advertisement;
    // 独立手机页不走 stdhead，但部分页面(论坛等)依赖 stdhead 初始化的全局对象，这里补上避免致命错误。
    if (empty($Advertisement) && class_exists('ADVERTISEMENT')) {
        $Advertisement = new \ADVERTISEMENT($CURUSER['id'] ?? 0);
    }
    $col = mobile_shell_colors();
    $t = ($title !== '' ? $title . ' · ' : '') . ($SITENAME ?? 'HDvideo');
    $bodyClass = 'm-shell m-page' . ($pageClass !== '' ? ' ' . $pageClass : '');
    ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="mobile-web-app-capable" content="yes" />
<title><?php echo htmlspecialchars($t) ?></title>
<link rel="stylesheet" href="/styles/mobile-shell.css?v=20260701e" type="text/css" />
<style>:root{--bili-primary:<?php echo $col['primary'] ?>;--bili-accent:<?php echo $col['accent'] ?>;--bili-bg:<?php echo $col['bg'] ?>;--bili-surface:<?php echo $col['surface'] ?>;--bili-text:<?php echo $col['text'] ?>;}</style>
</head>
<body class="<?php echo htmlspecialchars($bodyClass) ?>">
<main class="m-main">
<?php
}

/** 独立手机页尾：关闭内容容器，输出外壳(顶栏/抽屉/底部Tab/弹层)与脚本 */
function mobile_shell_page_foot(string $active = ''): void
{
    echo "</main>\n";
    mobile_shell_render($active);
    echo "\n</body>\n</html>";
}

/** 是否手机端访问(UA 命中且未加 ?pc=1 强制电脑版) */
function mobile_is(): bool
{
    static $m = null;
    if ($m === null) {
        $m = empty($_GET['pc'])
            && preg_match('/Mobile|Android|iPhone|iPod|Windows Phone|BlackBerry|webOS|HarmonyOS/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }
    return (bool)$m;
}

/**
 * 通用 stdhead 页面的手机适配头：手机端套统一外壳(顶/底导航)+通用响应式内容样式+常用脚本；
 * 电脑端仍走 stdhead。用法：把页面里的 stdhead($title) 换成 mobile_std_head($title, $active, $pageClass)。
 * $active: 底部Tab高亮键(home/torrents/forums/games/me 或 '')；$pageClass: 内容作用域类名(如 page-messages)。
 */
/** 从当前脚本名推断底部Tab高亮键(未显式传入时)。 */
function mobile_std_active(): string
{
    $s = strtolower(basename((string)($_SERVER['PHP_SELF'] ?? ''), '.php'));
    $map = [
        'index' => 'home', 'torrents' => 'torrents', 'forums' => 'forums', 'forummanage' => 'forums',
        'messages' => 'me', 'usercp' => 'me', 'attendance' => 'me', 'mybonus' => 'me', 'medal' => 'me',
        'invite' => 'me', 'userdetails' => 'me', 'friends' => 'me', 'getrss' => 'me',
    ];
    return $map[$s] ?? '';
}

function mobile_std_head(string $title = '', string $active = '', string $pageClass = ''): void
{
    if (mobile_is() && function_exists('mobile_shell_page_head')) {
        $s = strtolower(basename((string)($_SERVER['PHP_SELF'] ?? ''), '.php'));
        if ($active === '') { $active = mobile_std_active(); }
        if ($pageClass === '') { $pageClass = 'page-' . preg_replace('/[^a-z0-9]+/', '-', $s ?: 'std'); }
        mobile_shell_page_head(trim(strip_tags($title)), $active, 'page-std ' . $pageClass);
        echo '<link rel="stylesheet" type="text/css" href="/styles/mobile-content.css?v=20260702k">';
        echo '<script type="text/javascript" src="js/jquery-1.12.4.min.js"></script>';
        echo '<script>jQuery.noConflict();window.nexusLayerOptions={confirm:{btnAlign:"c",title:"Confirm",btn:["OK","Cancel"]},alert:{btnAlign:"c",title:"Info",btn:["OK","Cancel"]}};</script>';
        echo '<script type="text/javascript" src="vendor/layer-v3.5.1/layer/layer.js"></script>';
        echo '<script type="text/javascript" src="js/common.js"></script>';
        echo '<script type="text/javascript" src="js/ajaxbasic.js"></script>';
    } else {
        stdhead($title);
    }
}

/** 通用 stdhead 页面的手机适配尾。 */
function mobile_std_foot(string $active = ''): void
{
    if (mobile_is() && function_exists('mobile_shell_page_foot')) {
        if (class_exists('\\Nexus\\Nexus')) { foreach (\Nexus\Nexus::getAppendFooters() as $v) { print($v); } }
        mobile_shell_page_foot($active !== '' ? $active : mobile_std_active());
    } else {
        stdfoot();
    }
}

/**
 * 极简套用：页面顶部只需 `require_once ROOT_PATH . 'include/mobile_shell.php';`，
 * 然后把 stdhead(...) → mp_head(...)、stdfoot() → mp_foot()。底部Tab与页类名按脚本名自动推断，桌面端参数原样透传。
 */
function mp_head(...$a): void
{
    if (mobile_is()) { mobile_std_head((string)($a[0] ?? '')); }
    else { stdhead(...$a); }
}
function mp_foot(): void
{
    if (mobile_is()) { mobile_std_foot(); }
    else { stdfoot(); }
}

}
