<?php
require "../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
if (get_user_class() < UC_MODERATOR)
stderr("Sorry", "Access denied.");

stdhead("Warned Users");
$isMobileWarned = function_exists('mobile_is') && mobile_is();
$warned = number_format(get_row_count("users", "WHERE warned='yes'"));
begin_frame("Warned Users: ($warned)", true);
if (!$isMobileWarned) {
begin_table();
}

$res = sql_query("SELECT * FROM users WHERE warned=1 AND enabled='yes' ORDER BY (users.uploaded/users.downloaded)") or sqlerr();
$num = mysql_num_rows($res);
if ($isMobileWarned) {
print("<form action=\"nowarn.php\" method=post class=\"warned-form\"><div class=\"warned-list\">\n");
} else {
print("<form action=\"nowarn.php\" method=post><table border=1 width=675 cellspacing=0 cellpadding=2 class=\"warned-table\">\n");
print("<tr align=center><td class=colhead width=90>User Name</td>
 <td class=colhead width=70>Registered</td>
 <td class=colhead width=75>Last access</td>
 <td class=colhead width=75>User Class</td>
 <td class=colhead width=70>Downloaded</td>
 <td class=colhead width=70>UpLoaded</td>
 <td class=colhead width=45>Ratio</td>
 <td class=colhead width=125>End<br>Of Warning</td>
 <td class=colhead width=65>Remove<br>Warning</td>
 <td class=colhead width=65>Disable<br>Account</td></tr>\n");
}
for ($i = 1; $i <= $num; $i++)
{
$arr = mysql_fetch_assoc($res);
if ($arr['added'] == '0000-00-00 00:00:00' || $arr['added'] == null)
  $arr['added'] = '-';
if ($arr['last_access'] == '0000-00-00 00:00:00' || $arr['added'] == null)
  $arr['last_access'] = '-';
if($arr["downloaded"] != 0){
$ratio = number_format($arr["uploaded"] / $arr["downloaded"], 3);
} else {
$ratio="---";
}
$ratio = "<font color=" . get_ratio_color($ratio) . ">$ratio</font>";
  $uploaded = mksize($arr["uploaded"]);
  $downloaded = mksize($arr["downloaded"]);
// $uploaded = str_replace(" ", "<br>", mksize($arr["uploaded"]));
// $downloaded = str_replace(" ", "<br>", mksize($arr["downloaded"]));

$added = substr($arr['added'],0,10);
$last_access = substr($arr['last_access'],0,10);
$class=get_user_class_name($arr["class"],false,true,true);

if ($isMobileWarned) {
print("<article class=\"warned-card\">
  <div class=\"warned-card-head\"><div class=\"warned-user\">" . get_username($arr['id']) . "</div><div class=\"warned-ratio\">$ratio</div></div>
  <div class=\"warned-meta\">
    <div><span>Registered</span><b>" . htmlspecialchars((string)$added, ENT_QUOTES) . "</b></div>
    <div><span>Last access</span><b>" . htmlspecialchars((string)$last_access, ENT_QUOTES) . "</b></div>
    <div><span>User Class</span><b>$class</b></div>
    <div><span>End Of Warning</span><b>" . htmlspecialchars((string)$arr['warneduntil'], ENT_QUOTES) . "</b></div>
  </div>
  <div class=\"warned-transfer\">
    <div><span>Downloaded</span><b>" . htmlspecialchars((string)$downloaded, ENT_QUOTES) . "</b></div>
    <div><span>Uploaded</span><b>" . htmlspecialchars((string)$uploaded, ENT_QUOTES) . "</b></div>
  </div>
  <div class=\"warned-actions\">
    <label class=\"warned-check warned-remove\"><input type=\"checkbox\" name=\"usernw[]\" value=\"{$arr['id']}\"><span>Remove Warning</span></label>
    <label class=\"warned-check warned-disable\"><input type=\"checkbox\" name=\"desact[]\" value=\"{$arr['id']}\"><span>Disable Account</span></label>
  </div>
</article>\n");
} else {
print("<tr><td align=left>" . get_username($arr['id']) ."</td>
  <td align=center>$added</td>
  <td align=center>$last_access</td>
  <td align=center>$class</td>
  <td align=center>$downloaded</td>
  <td align=center>$uploaded</td>
  <td align=center>$ratio</td>
  <td align=center>{$arr['warneduntil']}</td>
  <td bgcolor=\"#008000\" align=center><input type=\"checkbox\" name=\"usernw[]\" value=\"{$arr['id']}\"></td>
  <td bgcolor=\"#FF000\" align=center><input type=\"checkbox\" name=\"desact[]\" value=\"{$arr['id']}\"></td></tr>\n");
}
}
if (get_user_class() >= UC_ADMINISTRATOR) {
if ($isMobileWarned) {
print("<div class=\"warned-submit\"><input type=\"submit\" name=\"submit\" value=\"Apply Changes\"></div>\n");
print("<input type=\"hidden\" name=\"nowarned\" value=\"nowarned\">");
} else {
print("<tr><td colspan=10 align=right><input type=\"hidden\" name=\"nowarned\" value=\"nowarned\"><input type=\"submit\" name=\"submit\" value=\"Apply Changes\"></td></tr>\n");
}
}
if ($isMobileWarned) {
if ($num == 0) {
print("<div class=\"warned-empty\">No warned users.</div>\n");
}
print("</div></form>\n");
} else {
print("</table></form>\n");
end_table();
}
print("<p class=\"warned-menu\">" . ($pagemenu ?? '') . "<br>" . ($browsemenu ?? '') . "</p>");
end_frame();

stdfoot();

?>
