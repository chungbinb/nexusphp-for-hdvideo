<?php
/**
 * 个人中心 手机版（独立页，与首页同一套手机外壳）。
 * 由 public/usercp.php 在手机 UA 且总览(无 action)时 require；?pc=1 强制电脑版。
 * 设置子页(action=personal/tracker/forum/security)仍由 usercp.php 在同一外壳内渲染。
 * 复用 bittorrent.php 环境 + include/mobile_shell.php。
 */
if (!isset($CURUSER) || empty($CURUSER['id']) || !function_exists('mobile_shell_page_head')) { return; }

$uid = (int)$CURUSER['id'];
$uname = (string)($CURUSER['username'] ?? '');
$avatar = trim((string)($CURUSER['avatar'] ?? ''));
$classText = function_exists('get_user_class_name') ? strip_tags(get_user_class_name((int)($CURUSER['class'] ?? 0))) : '';
$up = (float)($CURUSER['uploaded'] ?? 0);
$down = (float)($CURUSER['downloaded'] ?? 0);
$ratio = $down > 0 ? number_format($up / $down, 2) : ($up > 0 ? '∞' : '---');
$bonus = number_format(floor((float)($CURUSER['seedbonus'] ?? 0)));
$invites = (int)($CURUSER['invites'] ?? 0);
$join = (string)($CURUSER['added'] ?? '');
$email = (string)($CURUSER['email'] ?? '');
$ip = (string)($CURUSER['ip'] ?? '');
$passkey = (string)($CURUSER['passkey'] ?? '');

function mu_avatar($avatar, $uname)
{
    if ($avatar !== '') return '<img src="' . htmlspecialchars($avatar) . '" alt="" onerror="this.style.display=\'none\'">';
    return '<b>' . htmlspecialchars(mb_substr($uname !== '' ? $uname : '?', 0, 1)) . '</b>';
}

$settingLinks = [
    ['usercp.php?action=personal', '个人设定', '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>'],
    ['usercp.php?action=tracker', '网站设定', '<circle cx="12" cy="12" r="3"/><path d="M19.4 13a7.8 7.8 0 0 0 0-2l2-1.5-2-3.4-2.4 1a7 7 0 0 0-1.7-1L15 3.5h-4l-.3 2.6a7 7 0 0 0-1.7 1l-2.4-1-2 3.4L4.6 11a7.8 7.8 0 0 0 0 2l-2 1.5 2 3.4 2.4-1a7 7 0 0 0 1.7 1l.3 2.6h4l.3-2.6a7 7 0 0 0 1.7-1l2.4 1 2-3.4z"/>'],
    ['usercp.php?action=forum', '论坛设定', '<path d="M4 5h16v10H9l-4 4z"/>'],
    ['usercp.php?action=security', '安全设定', '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>'],
];

mobile_shell_page_head('个人中心', 'me');
?>
<div class="m-prof">
    <span class="av"><?php echo mu_avatar($avatar, $uname) ?></span>
    <div>
        <div class="nm"><?php echo htmlspecialchars($uname) ?></div>
        <?php if ($classText !== '') { ?><span class="tag"><?php echo htmlspecialchars($classText) ?></span><?php } ?>
    </div>
</div>

<div class="m-statline">
    <div><b>↑<?php echo mksize($up) ?></b><span>上传</span></div>
    <div><b>↓<?php echo mksize($down) ?></b><span>下载</span></div>
    <div><b><?php echo $ratio ?></b><span>分享率</span></div>
    <div><b><?php echo $bonus ?></b><span>魔力</span></div>
</div>

<section class="m-card">
    <h3>账号信息</h3>
    <?php if ($join !== '') { ?><div class="m-info"><span class="k">加入日期</span><span class="v"><?php echo htmlspecialchars($join) ?></span></div><?php } ?>
    <?php if ($email !== '') { ?><div class="m-info"><span class="k">邮箱</span><span class="v"><?php echo htmlspecialchars($email) ?></span></div><?php } ?>
    <?php if ($ip !== '') { ?><div class="m-info"><span class="k">IP / 地点</span><span class="v"><?php echo htmlspecialchars($ip) ?></span></div><?php } ?>
    <div class="m-info"><span class="k">邀请</span><span class="v"><?php echo $invites ?></span></div>
    <?php if ($passkey !== '') { ?><div class="m-info"><span class="k">Passkey</span><span class="v"><span id="muPk"><?php echo htmlspecialchars(substr($passkey, 0, 6)) ?>••••</span><span class="cp" id="muPkCopy">复制</span></span></div><?php } ?>
</section>

<section class="m-card">
    <h3>账号设置</h3>
    <?php foreach ($settingLinks as $s) { ?>
    <a class="m-link" href="<?php echo $s[0] ?>">
        <span class="ic"><svg viewBox="0 0 24 24"><?php echo $s[2] ?></svg></span>
        <span class="t"><?php echo $s[1] ?></span>
        <span class="arr">›</span>
    </a>
    <?php } ?>
</section>

<section class="m-card">
    <a class="m-link danger" href="logout.php">
        <span class="ic"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg></span>
        <span class="t">退出登录</span>
        <span class="arr">›</span>
    </a>
</section>

<div style="text-align:center;margin:6px 12px 18px"><a href="usercp.php?pc=1" style="font-size:12px;color:#8f9bb3">切换到电脑版 ›</a></div>

<script>
(function () {
    var btn = document.getElementById('muPkCopy');
    if (!btn) return;
    btn.style.cursor = 'pointer';
    btn.addEventListener('click', function () {
        var pk = <?php echo json_encode($passkey) ?>;
        if (navigator.clipboard) { navigator.clipboard.writeText(pk).then(function () { btn.textContent = '已复制'; setTimeout(function () { btn.textContent = '复制'; }, 1500); }); }
    });
})();
</script>
<?php
mobile_shell_page_foot('me');
