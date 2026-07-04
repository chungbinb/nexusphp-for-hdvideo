<?php
require "../include/bittorrent.php";
dbconn();
loggedinorreturn();
if (get_user_class() < UC_ADMINISTRATOR)
stderr("Sorry", "Access denied.");
stdhead("Mass PM", false);
$classes = array_chunk(\App\Models\User::$classes, 4, true);
?>
<table class="main staffmess-shell" width=737 border=0 cellspacing=0 cellpadding=0><tr><td class=embedded>
<div align=center class="staffmess-wrap">
<h1>Mass PM to all Staff members and users:</h1>
<form method=post action=takestaffmess.php class="staffmess-form">
<?php

if (isset($_GET["returnto"]) || !empty($_SERVER["HTTP_REFERER"]))
{
$returnTo = isset($_GET["returnto"]) && $_GET["returnto"] !== '' ? $_GET["returnto"] : ($_SERVER["HTTP_REFERER"] ?? '');
?>
<input type=hidden name=returnto value="<?php echo htmlspecialchars($returnTo) ?>">
<?php
}
?>
<table cellspacing=0 cellpadding=5 class="staffmess-table">
<?php
if (isset($_GET["sent"]) && $_GET["sent"] == 1) {
?>
<tr><td colspan=2 class="staffmess-alert"><font color=red><b>The message has ben sent.</b></font></td></tr>
<?php
}
?>
<tr>
    <td class="rowhead" valign="top"><b>Send to class:</b></td>
    <td class="rowfollow">
        <div class="staffmess-class-grid">
            <?php
            foreach ($classes as $chunk) {
                foreach ($chunk as $class => $info) {
                    printf('<label class="staffmess-choice"><input type="checkbox" name="classes[]" value="%s" /><span>%s</span></label>', $class, $info['text']);
                }
            }
            ?>
        </div>
    </td>
</tr>
<?php do_action('form_role_filter', 'Send to Role:') ?>
<tr>
    <td class="rowhead">Subject</td>
    <td class="rowfollow"><input type=text name=subject size=75></td>
</tr>
<tr>
    <td class="rowhead">Message</td>
    <td class="rowfollow"><textarea name=msg cols=80 rows=15><?php echo $body ?? ''?></textarea></td>
</tr>
<tr>
<td colspan=2 class="rowfollow staffmess-sender"><div align="center" class="staffmess-sender-box"><b>Sender:</b>
<label><input name="sender" type="radio" value="self" checked><?php echo $CURUSER['username']?></label>
<label><input name="sender" type="radio" value="system">System</label>
</div></td></tr>
<tr><td colspan=2 align=center class="rowfollow staffmess-submit"><input type=submit value="Send!" class=btn></td></tr>
</table>
<input type=hidden name=receiver value=<?php echo $receiver ?? ''?>>
</form>

 </div></td></tr></table>
<p class="staffmess-note">NOTE: Do not user BB codes. (NO HTML)</p>
<?php
stdfoot();
