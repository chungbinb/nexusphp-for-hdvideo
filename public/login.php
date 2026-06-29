<?php
require_once("../include/bittorrent.php");
dbconn();

$langid = intval($_GET['sitelanguage'] ?? 0);
if ($langid)
{
	$lang_folder = validlang($langid);
	$enabled = \App\Models\Language::listEnabled();
	if (!in_array($lang_folder, $enabled)) {
	    nexus_redirect(getBaseUrl());
    }
	if(get_langfolder_cookie() != $lang_folder)
	{
		set_langfolder_cookie($lang_folder);
		nexus_redirect($_SERVER['REQUEST_URI']);
	}
}
require_once(get_langfile_path("", false, $CURLANGDIR));

failedloginscheck ();
cur_user_check () ;

// 手机端：走独立的手机版登录页（完全自带头尾，不经过桌面 stdhead，无站点外框）；?pc=1 强制电脑版。
if (empty($_GET['pc'])
    && preg_match('/Mobile|Android|iPhone|iPod|Windows Phone|BlackBerry|webOS|HarmonyOS/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
    require __DIR__ . '/mobile/login.php';
    exit;
}

$loginTheme = $_GET['theme'] ?? 'modern';
if ($loginTheme === 'modern') {
	\Nexus\Nexus::css('css/login-modern.css?v=20260610-login7', 'header', true);
}
// 登录页不显示顶部海报轮播（对访客是一片空白「广告区」）。
$GLOBALS['nexus_hide_top_banner'] = true;
stdhead($lang_login['head_login']);

$s = "<select name=\"sitelanguage\" onchange='submit()'>\n";
$secret = htmlspecialchars($_GET['secret'] ?? '');
$langs = langlist("site_lang", true);
foreach ($langs as $row)
{
	if ($row["site_lang_folder"] == get_langfolder_cookie()) $se = "selected=\"selected\""; else $se = "";
	$s .= "<option value=\"". $row["id"] ."\" ". $se. ">" . htmlspecialchars($row["lang_name"]) . "</option>\n";
}
$s .= "\n</select>";
 
unset($returnto);
$returnNotice = '';
if (!empty($_GET["returnto"])) {
	$returnto = $_GET["returnto"];
	if (empty($_GET["nowarn"])) {
		$returnNotice .= "<h2>" . $lang_login['h1_not_logged_in'] . "</h2>\n";
		$returnNotice .= "<p><b>" . $lang_login['p_error'] . "</b> " . $lang_login['p_after_logged_in'] . "</p>\n";
	}
}
$useChallengeResponseAuthentication = \App\Models\Setting::getIsUseChallengeResponseAuthentication();
$passwordName = 'class="password"';
if (!$useChallengeResponseAuthentication) {
    $passwordName .= ' name="password"';
}
$siteName = get_setting('basic.SITENAME') ?: ($SITENAME ?? 'HDvideo');
?>
<div class="login-page-wrap">
	<div class="login-shell">
		<aside class="login-hero">
			<div class="login-brand-card">
				<span class="login-brand-title"><?php echo htmlspecialchars($siteName); ?></span>
			</div>
			<div class="login-hero-copy">
				<p class="hero-title"><?php echo htmlspecialchars($lang_login['head_login']); ?></p>
				<p class="hero-text"><?php echo strip_tags($lang_login['p_need_cookies_enables']); ?></p>
				<ul class="login-hero-points">
					<li><?php echo htmlspecialchars($lang_login['text_advanced_options']); ?></li>
					<li><?php echo htmlspecialchars($lang_login['other_methods']); ?></li>
					<li><?php echo htmlspecialchars($lang_login['text_helpbox']); ?></li>
				</ul>
			</div>
		</aside>
		<section class="login-panel">
			<form method="get" action="<?php echo $_SERVER['REQUEST_URI'] ?>" class="lang-form-wrap">
				<input type="hidden" name="secret" value="<?php echo $secret ?>">
				<button class="login-theme-toggle" type="button" data-login-theme-toggle aria-label="Toggle day or night mode">
					<span class="login-theme-toggle__icon" aria-hidden="true"></span>
					<span class="login-theme-toggle__text" data-login-theme-label>Day</span>
				</button>
				<div class="login-language-select">
					<span><?php echo $lang_login['text_select_lang']; ?></span>
					<?php echo $s; ?>
				</div>
			</form>

			<h1><?php echo $lang_login['head_login']; ?></h1>
			<p class="login-subtitle"><?php echo $lang_login['p_you_have']; ?> <b><?php echo remaining ();?></b> <?php echo $lang_login['p_remaining_tries']?></p>
			<?php if (!empty($returnNotice)) { ?>
			<div class="login-return-tip"><?php echo $returnNotice; ?></div>
			<?php } ?>
<form id="login-form" method="post" action="takelogin.php">
    <input type="hidden" name="secret" value="<?php echo $secret?>">
<p><?php echo $lang_login['p_need_cookies_enables']?><br /> [<b><?php echo $maxloginattempts;?></b>] <?php echo $lang_login['p_fail_ban']?></p>
<table border="0" cellpadding="5">
<?php $formInputStyle = 'style="width: min(100%, 320px); min-width: 180px; border: 1px solid gray; box-sizing: border-box"'; ?>
<tr><td class="rowhead"><?php echo $lang_login['rowhead_username']?></td><td class="rowfollow" align="left"><input type="text" class="username" name="username" autocomplete="username" <?php echo $formInputStyle; ?> /></td></tr>
<tr><td class="rowhead"><?php echo $lang_login['rowhead_password']?></td><td class="rowfollow" align="left"><input type="password" <?php echo $passwordName ?> autocomplete="current-password" <?php echo $formInputStyle; ?> /></td></tr>
<tr><td class="rowhead"><?php echo $lang_login['rowhead_two_step_code']?></td><td class="rowfollow" align="left"><input type="text" name="two_step_code" inputmode="numeric" pattern="[0-9]*" placeholder="<?php echo $lang_login['two_step_code_tooltip'] ?>" <?php echo $formInputStyle; ?> /></td></tr>
<?php
show_image_code ();
if ($securelogin == "yes")
	$sec = "checked=\"checked\" disabled=\"disabled\"";
elseif ($securelogin == "no")
	$sec = "disabled=\"disabled\"";
elseif ($securelogin == "op")
	$sec = "";

if ($securetracker == "yes")
	$sectra = "checked=\"checked\" disabled=\"disabled\"";
elseif ($securetracker == "no")
	$sectra = "disabled=\"disabled\"";
elseif ($securetracker == "op")
	$sectra = "";
?>
<tr><td class="toolbox" colspan="2" align="left"><?php echo $lang_login['text_advanced_options']?></td></tr>
<tr><td class="rowhead"><?php echo $lang_login['text_auto_logout']?></td><td class="rowfollow" align="left"><input class="checkbox" type="checkbox" name="logout" value="yes" /><?php echo $lang_login['checkbox_auto_logout']?></td></tr>
<!--<tr><td class="rowhead">--><?php //echo $lang_login['text_restrict_ip']?><!--</td><td class="rowfollow" align="left"><input class="checkbox" type="checkbox" name="securelogin" value="yes" />--><?php //echo $lang_login['checkbox_restrict_ip']?><!--</td></tr>-->
<!--<tr><td class="rowhead">--><?php //echo $lang_login['text_ssl']?><!--</td><td class="rowfollow" align="left"><input class="checkbox" type="checkbox" name="ssl" value="yes" --><?php //echo $sec?><!-- />--><?php //echo $lang_login['checkbox_ssl']?><!--<br /><input class="checkbox" type="checkbox" name="trackerssl" value="yes" --><?php //echo $sectra?><!-- />--><?php //echo $lang_login['checkbox_ssl_tracker']?><!--</td></tr>-->
<tr><td class="toolbox" colspan="2" align="right"><input id="submit-btn" type="button" value="<?php echo $lang_login['button_login']?>" class="btn" /> <input type="reset" value="<?php echo $lang_login['button_reset']?>" class="btn" /></td></tr>
</table>
<?php
if (isset($returnto)) {
    print("<input type=\"hidden\" name=\"returnto\" value=\"" . htmlspecialchars($returnto) . "\" />\n");
}
if ($useChallengeResponseAuthentication) {
    print('<input type="hidden" name="response" />');
}
?>
</form>
<?php
$oauthProviders = \App\Models\OauthProvider::query()
    ->orderBy("priority", 'desc')
    ->where('enabled', '=', 1)
    ->get();
$items = [];
foreach ($oauthProviders as $oauthProvider) {
    $items[] = sprintf('[<b><a href="oauth/redirect/%s">%s</a></b>]', $oauthProvider->uuid, $oauthProvider->name);
}
echo '<div class="login-extra-links">';
if (!empty($items)) {
    echo sprintf("<p>%s: %s</p>", $lang_login['other_methods'], implode("&nbsp;&nbsp;", $items));
}
if (\App\Models\Setting::getIsComplainEnabled()) {
    echo sprintf('<p>[<b><a href="complains.php">%s</a></b>]</p>', $lang_login['text_complain']);
}
?>
<p><?php echo $lang_login['p_no_account_signup']?></p>
<?php
if ($smtptype != 'none'){
?>
<p><?php echo $lang_login['p_forget_pass_recover']?></p>
<p><?php echo $lang_login['p_account_banned']?></p>
<p><?php echo $lang_login['p_resend_confirm']?></p>
<?php
}
echo '</div>';
?>
		</section>
	</div>
</div>
<?php
if ($showhelpbox_main != 'no'){?>
<table width="100%" class="main" border="0" cellspacing="0" cellpadding="0"><tr><td class="embedded">
<h2><?php echo $lang_login['text_helpbox'] ?><font class="small"> - <?php echo $lang_login['text_helpbox_note'] ?><font id= "waittime" color="red"></font></h2>
<?php
print("<table width='100%' border='1' cellspacing='0' cellpadding='1'><tr><td class=\"text\">\n");
print("<iframe src='" . get_protocol_prefix() . $BASEURL . "/shoutbox.php?type=helpbox' width='100%' height='180' frameborder='0' name='sbox' marginwidth='0' marginheight='0'></iframe><br /><br />\n");
print("<form action='" . get_protocol_prefix() . $BASEURL . "/shoutbox.php' id='helpbox' method='get' target='sbox' name='shbox'>\n");
print("<div style='display: flex'>" . $lang_login['text_message']."<input type='text' id=\"hbtext\" name='shbox_text' autocomplete='off' style='flex-grow: 1;width: 500px; border: 1px solid gray' ><input type='submit' id='hbsubmit' class='btn' name='shout' value=\"".$lang_login['sumbit_shout']."\" /><input type='reset' class='btn' value=".$lang_login['submit_clear']." /> <input type='hidden' name='sent' value='yes'><input type='hidden' name='type' value='helpbox' /></div>\n");
print("<div id=sbword style=\"display: none\">".$lang_login['sumbit_shout']."</div>");
print(smile_row("shbox","shbox_text"));
print("</td></tr></table></form></td></tr></table>");
}
?>
<?php
render_password_challenge_js("login-form", "username", "password");
\Nexus\Nexus::js('document.addEventListener("DOMContentLoaded", function () { var nav = document.getElementById("nav_block"); if (!nav) return; var wrap = nav.querySelector(".login-page-wrap"); if (!wrap) return; while (nav.firstChild && nav.firstChild !== wrap) { nav.removeChild(nav.firstChild); } });', 'footer', false);
\Nexus\Nexus::js('document.addEventListener("DOMContentLoaded", function () { var wrap = document.querySelector(".login-page-wrap"); var toggle = document.querySelector("[data-login-theme-toggle]"); var label = document.querySelector("[data-login-theme-label]"); if (!wrap || !toggle) return; var storageKey = "hdvideo-login-theme"; var siteThemeKey = "nexus_site_theme"; function readTheme() { try { return localStorage.getItem(storageKey) || localStorage.getItem(siteThemeKey); } catch (e) { return ""; } } function saveTheme(theme) { try { localStorage.setItem(storageKey, theme); localStorage.setItem(siteThemeKey, theme); } catch (e) {} } function applyTheme(theme) { var isNight = theme === "night"; wrap.setAttribute("data-theme", isNight ? "night" : "day"); document.documentElement.setAttribute("data-site-theme", isNight ? "night" : "day"); if (document.body) { document.body.classList.toggle("login-theme-night", isNight); document.body.classList.toggle("theme-night", isNight); document.body.classList.toggle("theme-day", !isNight); } if (label) label.textContent = isNight ? "Night" : "Day"; toggle.setAttribute("aria-pressed", isNight ? "true" : "false"); } var saved = readTheme(); var preferred = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches ? "night" : "day"; applyTheme(saved || preferred); toggle.addEventListener("click", function () { var next = wrap.getAttribute("data-theme") === "night" ? "day" : "night"; saveTheme(next); applyTheme(next); }); });', 'footer', false);
stdfoot();
