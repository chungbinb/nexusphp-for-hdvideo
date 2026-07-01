<?php
/**
 * 首页 手机版 —— 专门的手机排版。导航(顶栏/抽屉/底部Tab/我的/管理/个性化)统一复用
 * include/mobile_shell.php 这套公共外壳，与论坛/种子/个人中心等手机页完全一致。
 * 由 public/index.php 在手机 UA 时 require；?pc=1 可强制看电脑版。
 * 复用 bittorrent.php 已加载的环境：$CURUSER、sql_query、mksize、format_comment 等。
 */
if (!isset($CURUSER) || empty($CURUSER['id'])) { return; }
require_once ROOT_PATH . 'include/mobile_shell.php';

$uid = (int)$CURUSER['id'];
$uname = (string)($CURUSER['username'] ?? '');
$avatar = trim((string)($CURUSER['avatar'] ?? ''));
$up = (float)($CURUSER['uploaded'] ?? 0);
$down = (float)($CURUSER['downloaded'] ?? 0);
$ratio = $down > 0 ? number_format($up / $down, 2) : ($up > 0 ? '∞' : '---');
$bonus = number_format(floor((float)($CURUSER['seedbonus'] ?? 0)));
$classText = function_exists('get_user_class_name') ? get_user_class_name((int)($CURUSER['class'] ?? 0)) : '';

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

// 输出统一手机外壳头部（DOCTYPE/head/body/.m-main + 顶栏/底部Tab 由 page_foot 输出）
mobile_shell_page_head('首页', 'home', 'page-home');
?>
<style>
/* 首页专属内容样式（导航相关样式均来自 mobile-shell.css，这里只放内容卡片） */
.page-home .m-card h3 .more { margin-left: auto; font-size: 12px; color: var(--mh-primary); font-weight: 600; }

.page-home .m-stat { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px 4px; margin: 12px; background: var(--mh-surface); border: 1px solid rgba(20,40,90,.06); border-radius: 16px; padding: 6px 4px 12px; box-shadow: 0 4px 14px rgba(20,40,90,.08); }
.page-home .m-stat > div { background: transparent; color: var(--mh-text); text-align: center; padding: 6px 2px; }
.page-home .m-stat b { display: block; font-size: 15px; font-weight: 800; color: var(--mh-primary); }
.page-home .m-stat span { font-size: 11px; color: #8a96ad; }
.page-home .m-stat .uname { grid-column: 1 / -1; text-align: left; padding: 10px 12px 8px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid rgba(20,40,90,.06); margin-bottom: 4px; }
.page-home .m-stat .av { flex: none; width: 44px; height: 44px; border-radius: 50%; overflow: hidden; background: var(--mh-soft); display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 19px; color: var(--mh-primary); box-shadow: 0 0 0 2px var(--mh-soft); }
.page-home .m-stat .av img { width: 100%; height: 100%; object-fit: cover; }
.page-home .m-stat .who { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.page-home .m-stat .who b { font-size: 16px; color: var(--mh-text); }
.page-home .m-stat .uname .tag { font-size: 11px; background: var(--mh-soft); color: var(--mh-primary); padding: 2px 8px; border-radius: 999px; font-weight: 700; align-self: flex-start; }

.page-home .m-news { border-top: 1px solid #eef1f7; padding: 9px 0; }
.page-home .m-news:first-of-type { border-top: none; }
.page-home .m-news .nt { display: flex; align-items: flex-start; gap: 8px; font-weight: 700; font-size: 14px; line-height: 1.4; cursor: pointer; }
.page-home .m-news .nt .date { margin-left: auto; font-size: 11px; color: #9aa6bd; font-weight: 500; white-space: nowrap; flex: none; }
.page-home .m-news .nb { font-size: 13px; color: #3a455c; line-height: 1.6; margin-top: 6px; overflow-wrap: anywhere; word-break: break-word; display: none; }
.page-home .m-news.open .nb { display: block; }
.page-home .m-news .nb img { border-radius: 8px; }
.page-home .m-news .arrow { transition: transform .2s; color: #9aa6bd; }
.page-home .m-news.open .arrow { transform: rotate(90deg); }

.page-home .m-row { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-top: 1px solid #eef1f7; }
.page-home .m-row:first-of-type { border-top: none; }
.page-home .m-row .info { min-width: 0; flex: 1; }
.page-home .m-row .nm { font-size: 14px; font-weight: 600; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.page-home .m-row .sub { font-size: 12px; color: #8f9bb3; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.page-home .m-row .sl { flex: none; text-align: right; font-size: 12px; }
.page-home .m-row .sl .s { color: #14a44d; font-weight: 800; }
.page-home .m-row .sl .l { color: #e4564b; font-weight: 800; }
.page-home .m-empty { color: #9aa6bd; font-size: 13px; padding: 10px 0; }

.page-home .m-pcfoot { text-align: center; margin: 4px 12px 18px; }
.page-home .m-pcfoot a { font-size: 12px; color: #8f9bb3; }

.page-home .m-frame { width: 100%; height: 230px; border: 1px solid rgba(20,40,90,.1); border-radius: 10px; background: var(--mh-surface); display: block; }
.page-home .m-sbox-form { display: flex; gap: 8px; margin-top: 8px; }
.page-home .m-sbox-form input[type="text"] { flex: 1; min-width: 0; padding: 10px 12px; font-size: 15px; border: 1px solid rgba(20,40,90,.15); border-radius: 10px; background: var(--mh-bg); color: var(--mh-text); }
.page-home .m-sbox-form button { flex: none; padding: 0 16px; border: none; border-radius: 10px; background: var(--mh-primary); color: #fff; font-weight: 700; cursor: pointer; }

.page-home .m-poll-q { font-weight: 700; font-size: 14px; margin: 6px 0 10px; line-height: 1.5; }
.page-home .m-poll-opt { display: flex; align-items: center; gap: 10px; padding: 10px; border: 1px solid rgba(20,40,90,.1); border-radius: 10px; margin-bottom: 8px; font-size: 14px; cursor: pointer; }
.page-home .m-poll-opt input { width: 18px; height: 18px; flex: none; }
.page-home .m-poll-btn { width: 100%; height: 44px; border: none; border-radius: 10px; background: var(--mh-primary); color: #fff; font-weight: 800; font-size: 15px; margin-top: 4px; cursor: pointer; }
.page-home .m-poll-res { margin-bottom: 11px; }
.page-home .m-poll-res-h { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 5px; }
.page-home .m-poll-res.me .m-poll-res-h { color: var(--mh-primary); font-weight: 700; }
.page-home .m-poll-bar { height: 8px; background: var(--mh-bg); border-radius: 999px; overflow: hidden; }
.page-home .m-poll-bar i { display: block; height: 100%; background: var(--mh-primary); }
.page-home .m-poll-total { font-size: 12px; color: #8a96ad; text-align: right; margin-top: 4px; }
</style>

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
<?php $mc = function_exists('mobile_shell_colors') ? mobile_shell_colors() : ['bg'=>'#f6f7fb','surface'=>'#ffffff','text'=>'#18191c','primary'=>'#00aeec']; ?>
<script>
/* 趣味盒/群聊区 iframe(fun.php/shoutbox.php,body.inframe,自带深色theme.css)注入个性化浅色；每次刷新后重注入 */
(function () {
	var C = { bg: '<?php echo $mc['surface'] ?>', tx: '<?php echo $mc['text'] ?>', pr: '<?php echo $mc['primary'] ?>' };
	function themeFrame(f) {
		try {
			var d = f.contentDocument || (f.contentWindow && f.contentWindow.document);
			if (!d || !d.head) return;
			var s = d.getElementById('mhFrameTheme') || d.createElement('style');
			s.id = 'mhFrameTheme';
			s.textContent = 'html,body{background:' + C.bg + ' !important;} '
				+ 'body,td,.shoutrow,.text,.embedded{color:' + C.tx + ' !important;} '
				+ 'table,tr,tbody,td,.shoutrow,.text,.embedded{background:transparent !important;border-color:rgba(20,40,90,.08) !important;} '
				+ '.date{color:#6a7589 !important;} '
				+ 'a{color:' + C.pr + ' !important;} '
				+ '[class*="_Name"],[class*="_Name"] *{color:' + C.pr + ' !important;}';
			if (!s.parentNode) d.head.appendChild(s);
		} catch (e) {}
	}
	document.querySelectorAll('iframe.m-frame').forEach(function (f) {
		f.addEventListener('load', function () { themeFrame(f); });
		try { if (f.contentDocument && f.contentDocument.readyState === 'complete') themeFrame(f); } catch (e) {}
	});
})();
</script>
<?php
// 输出统一手机外壳尾部（顶栏/抽屉/底部Tab/我的/管理/个性化 + 脚本）
mobile_shell_page_foot('home');
