<?php
/**
 * 登录页 手机版 —— 专门的手机排版（完全自带 头/尾，不经过桌面 stdhead，无站点外框）。
 * 由 public/login.php 在手机 UA 时 require；?pc=1 可强制看电脑版。
 * 复用 bittorrent.php 已加载环境：$lang_login、$iv、show_image_code()、langlist() 等。
 * 表单字段/提交目标(takelogin.php)与电脑版完全一致，确保登录逻辑不变。
 */
$secret = htmlspecialchars((string)($_GET['secret'] ?? ''));
$returnto = (string)($_GET['returnto'] ?? '');
$siteName = (function_exists('get_setting') ? (get_setting('basic.SITENAME') ?: '') : '') ?: ($SITENAME ?? 'HDvideo');
$useCR = false;
try { $useCR = \App\Models\Setting::getIsUseChallengeResponseAuthentication(); } catch (\Throwable $e) {}
$maxAttempts = (int)($maxloginattempts ?? 5);
$remainingTries = function_exists('remaining') ? remaining() : '';

// 语言下拉
$langOptions = '';
foreach (langlist("site_lang", true) as $row) {
    $sel = ($row["site_lang_folder"] == get_langfolder_cookie()) ? ' selected' : '';
    $langOptions .= '<option value="' . (int)$row["id"] . '"' . $sel . '>' . htmlspecialchars($row["lang_name"]) . '</option>';
}

// 第三方登录
$oauthItems = [];
try {
    foreach (\App\Models\OauthProvider::query()->orderBy("priority", 'desc')->where('enabled', 1)->get() as $op) {
        $oauthItems[] = '<a class="m-oauth" href="oauth/redirect/' . htmlspecialchars($op->uuid) . '">' . htmlspecialchars($op->name) . '</a>';
    }
} catch (\Throwable $e) {}

$pwAttr = 'class="password"' . ($useCR ? '' : ' name="password"');
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="mobile-web-app-capable" content="yes" />
<title><?php echo htmlspecialchars($siteName) ?> · 登录</title>
<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; min-height: 100%; }
body { background: linear-gradient(170deg, #eaf0ff 0%, #f4f6fb 40%, #eef1f7 100%); color: #1b2230;
    font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif;
    min-height: 100vh; min-height: 100dvh; }
a { color: #3a6df0; text-decoration: none; }

.ml-wrap { max-width: 440px; margin: 0 auto; padding: calc(20px + env(safe-area-inset-top)) 16px calc(28px + env(safe-area-inset-bottom)); }

.ml-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; }
.ml-brand { font-size: 22px; font-weight: 900; letter-spacing: .5px; color: #2b3550; }
.ml-brand span { color: #3a6df0; }
.ml-lang { margin-left: auto; display: flex; align-items: center; gap: 6px; font-size: 12px; color: #7a86a0; }
.ml-lang select { padding: 6px 8px; border-radius: 8px; border: 1px solid #d3dbec; background: #fff; font-size: 13px; color: #2b3550; }

.ml-card { background: #fff; border-radius: 18px; padding: 22px 18px 18px; box-shadow: 0 10px 30px rgba(40,70,150,.12); }
.ml-title { font-size: 24px; font-weight: 900; text-align: center; margin: 2px 0 4px; }
.ml-sub { text-align: center; font-size: 13px; color: #8a96ad; margin-bottom: 6px; }
.ml-sub b { color: #2ecc71; }
.ml-note { font-size: 12px; color: #c0392b; background: rgba(220,60,70,.08); border-radius: 10px; padding: 8px 12px; margin: 10px 0 4px; line-height: 1.6; }

.ml-form { margin-top: 14px; }
.ml-field { margin-bottom: 14px; }
.ml-field > label { display: block; font-size: 13px; font-weight: 700; color: #3a455c; margin-bottom: 6px; }
.ml-field input[type="text"], .ml-field input[type="password"] {
    width: 100%; padding: 12px 13px; font-size: 16px; border: 1px solid #d3dbec; border-radius: 10px;
    background: #f7f9fe; color: #1b2230; }
.ml-field input:focus { outline: none; border-color: #3a6df0; background: #fff; box-shadow: 0 0 0 3px rgba(58,109,240,.15); }

/* 验证码（复用 show_image_code 的 <tr> 输出，置于此表内并改为竖排） */
.ml-cap { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
.ml-cap tr, .ml-cap td { display: block; width: 100%; padding: 0; border: none; text-align: left; }
.ml-cap td.rowhead { font-size: 13px; font-weight: 700; color: #3a455c; margin: 4px 0 6px; background: none; }
.ml-cap img { max-width: 100%; height: auto; border-radius: 8px; border: 1px solid #e1e7f3; }
.ml-cap input[type="text"] { width: 100% !important; padding: 12px 13px; font-size: 16px; border: 1px solid #d3dbec !important; border-radius: 10px; background: #f7f9fe; margin-top: 6px; box-sizing: border-box; }
.ml-cap input[type="text"]:focus { outline: none; border-color: #3a6df0; background: #fff; }

.ml-opt { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #6b7793; margin-bottom: 16px; }
.ml-opt input { width: 18px; height: 18px; }

.ml-btn { width: 100%; height: 50px; border: none; border-radius: 12px; font-size: 17px; font-weight: 800; cursor: pointer;
    background: linear-gradient(135deg, #3a6df0, #5b86ff); color: #fff; box-shadow: 0 6px 16px rgba(58,109,240,.35); }
.ml-btn:active { transform: translateY(1px); }

.ml-links { margin-top: 16px; text-align: center; font-size: 13px; line-height: 2; color: #6b7793; }
.ml-links a { font-weight: 600; }
.ml-oauths { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; margin-top: 12px; }
.ml-oauth { padding: 8px 14px; border: 1px solid #d3dbec; border-radius: 999px; background: #fff; font-size: 13px; font-weight: 600; }
.ml-pc { text-align: center; margin-top: 18px; }
.ml-pc a { font-size: 12px; color: #9aa6bd; }
</style>
</head>
<body>
<div class="ml-wrap">
    <div class="ml-bar">
        <div class="ml-brand">HD<span>VIDEO</span></div>
        <form class="ml-lang" method="get" action="login.php">
            <input type="hidden" name="secret" value="<?php echo $secret ?>">
            <span><?php echo htmlspecialchars($lang_login['text_select_lang'] ?? '语言') ?></span>
            <select name="sitelanguage" onchange="this.form.submit()"><?php echo $langOptions ?></select>
        </form>
    </div>

    <div class="ml-card">
        <div class="ml-title"><?php echo htmlspecialchars($lang_login['head_login'] ?? '登录') ?></div>
        <div class="ml-sub"><?php echo htmlspecialchars($lang_login['p_you_have'] ?? '你还有') ?> <b><?php echo $remainingTries ?></b> <?php echo htmlspecialchars($lang_login['p_remaining_tries'] ?? '次尝试机会') ?></div>
        <div class="ml-note"><?php echo strip_tags($lang_login['p_need_cookies_enables'] ?? '需启用 cookies 才能登录') ?><br>[<b><?php echo $maxAttempts ?></b>] <?php echo strip_tags($lang_login['p_fail_ban'] ?? '次连续登录失败将导致 IP 被禁用') ?></div>

        <form id="login-form" class="ml-form" method="post" action="takelogin.php">
            <input type="hidden" name="secret" value="<?php echo $secret ?>">
            <?php if ($returnto !== '') { ?><input type="hidden" name="returnto" value="<?php echo htmlspecialchars($returnto) ?>"><?php } ?>
            <?php if ($useCR) { ?><input type="hidden" name="response"><?php } ?>

            <div class="ml-field">
                <label><?php echo htmlspecialchars($lang_login['rowhead_username'] ?? '用户名') ?></label>
                <input type="text" class="username" name="username" autocomplete="username" autocapitalize="none" autocorrect="off">
            </div>
            <div class="ml-field">
                <label><?php echo htmlspecialchars($lang_login['rowhead_password'] ?? '密码') ?></label>
                <input type="password" <?php echo $pwAttr ?> autocomplete="current-password">
            </div>
            <div class="ml-field">
                <label><?php echo htmlspecialchars($lang_login['rowhead_two_step_code'] ?? '两步验证') ?></label>
                <input type="text" name="two_step_code" inputmode="numeric" pattern="[0-9]*" placeholder="<?php echo htmlspecialchars($lang_login['two_step_code_tooltip'] ?? '如有设置必须填写') ?>">
            </div>

            <?php if (($iv ?? '') === 'yes') { ?>
            <table class="ml-cap"><?php show_image_code(); ?></table>
            <?php } ?>

            <label class="ml-opt"><input type="checkbox" name="logout" value="yes"><?php echo htmlspecialchars($lang_login['checkbox_auto_logout'] ?? '15分钟后自动登出') ?></label>

            <?php if ($useCR) { ?>
            <button type="button" id="ml-login-btn" class="ml-btn"><?php echo htmlspecialchars($lang_login['button_login'] ?? '登录') ?></button>
            <?php } else { ?>
            <button type="submit" class="ml-btn"><?php echo htmlspecialchars($lang_login['button_login'] ?? '登录') ?></button>
            <?php } ?>
        </form>

        <div class="ml-links">
            <?php if ($oauthItems) { ?><div class="ml-oauths"><?php echo implode('', array_map(fn($i) => str_replace('class="m-oauth"', 'class="ml-oauth"', $i), $oauthItems)) ?></div><?php } ?>
            <div><?php echo $lang_login['p_no_account_signup'] ?? '<a href="signup.php">没有账号？注册</a>' ?></div>
            <?php if (($smtptype ?? 'none') !== 'none') { ?>
            <div><?php echo $lang_login['p_forget_pass_recover'] ?? '' ?></div>
            <?php } ?>
            <?php if (\App\Models\Setting::getIsComplainEnabled()) { ?><div><a href="complains.php"><?php echo htmlspecialchars($lang_login['text_complain'] ?? '申诉通道') ?></a></div><?php } ?>
        </div>
    </div>

    <div class="ml-pc"><a href="?pc=1<?php echo $secret !== '' ? '&secret=' . $secret : '' ?>">切换到电脑版 ›</a></div>
</div>

<?php if ($useCR) { ?>
<script src="js/crypto-js.js"></script>
<script>
(function () {
    var btn = document.getElementById('ml-login-btn');
    var form = document.getElementById('login-form');
    if (!btn || !form) return;
    btn.addEventListener('click', function () {
        var u = (form.querySelector('[name=username]') || {}).value || '';
        var p = (form.querySelector('.password') || {}).value || '';
        btn.disabled = true;
        fetch('/api/challenge', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: u }) })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || d.ret !== 0) { btn.disabled = false; alert((d && d.msg) || 'challenge failed'); return; }
                var hashed = sha256(p);
                var serverSide = sha256(d.data.secret + hashed);
                form.querySelector('input[name=response]').value = hmacSha256(d.data.challenge, serverSide);
                form.submit();
            })
            .catch(function (e) { btn.disabled = false; alert(String(e)); });
    });
})();
</script>
<?php } ?>
</body>
</html>
