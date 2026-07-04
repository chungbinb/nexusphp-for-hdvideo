<?php
require "../include/bittorrent.php";
dbconn();
loggedinorreturn();

if (get_user_class() < UC_MODERATOR)
stderr("Sorry", "Access denied.");

$tabs = ['users', 'peers'];
$tab = 'users';
if (!empty($_REQUEST['tab']) && in_array($_REQUEST['tab'], $tabs)) {
    $tab = $_REQUEST['tab'];
}
$page = $_REQUEST['page'] ?? 0;
$title = 'Duplicate IP users';
stdhead($title);
print '<div class="ipcheck-page">';
print '<h1 class="ipcheck-title">'.$title.'</h1>';
//print '<ul class="menu" style="padding-inline-start: 0">';
//foreach ($tabs as $item) {
//    echo sprintf('<li class="%s"><a href="?tab=%s&page=%s">%s</a></li>', $tab == $item ? 'selected' : '', $item, $page, $item);
//}
//print '</ul>';

if (get_user_class() >= UC_MODERATOR || $CURUSER["guard"] == "yes")
{
 $res = sql_query("SELECT count(*) AS dupl, ip FROM users WHERE enabled = 'yes' AND ip <> '' AND ip <> '127.0.0.0' GROUP BY ip ORDER BY dupl DESC, ip") or sqlerr();
  print("<table class=\"main ipcheck-table\" border=\"1\" cellspacing=\"0\" cellpadding=\"5\"><thead><tr align=center><td class=colhead width=90>User</td>
 <td class=colhead width=70>Email</td>
 <td class=colhead width=70>Registered</td>
 <td class=colhead width=75>Last access</td>
 <td class=colhead width=70>Downloaded</td>
 <td class=colhead width=70>Uploaded</td>
 <td class=colhead width=45>Ratio</td>
 <td class=colhead width=125>IP</td>
 <td class=colhead width=40>Peer</td></tr></thead><tbody>\n");
 $uc = 0;
 $ip = '';
  while($ras = mysql_fetch_assoc($res))
  {
	if ($ras["dupl"] <= 1)
	  break;
	if ($ip <> $ras['ip'])
    {
	  $ros = sql_query("SELECT  id, username, email, added, last_access, downloaded, uploaded, ip, warned, donor, enabled FROM users WHERE ip='".$ras['ip']."' ORDER BY id") or sqlerr();
	  $num2 = mysql_num_rows($ros);
	  if ($num2 > 1)
	  {
		$uc++;
	    while($arr = mysql_fetch_assoc($ros))
		{
		  if ($arr['added'] == '0000-00-00 00:00:00' || $arr['added'] == null)
			$arr['added'] = '-';
		  if ($arr['last_access'] == '0000-00-00 00:00:00' || $arr['last_access'] == null)
			$arr['last_access'] = '-';
		  if($arr["downloaded"] != 0)
			$ratio = number_format($arr["uploaded"] / $arr["downloaded"], 3);
		  else
			$ratio="---";

		  $ratio = "<font color=" . get_ratio_color($ratio) . ">$ratio</font>";
		  $uploaded = mksize($arr["uploaded"]);
		  $downloaded = mksize($arr["downloaded"]);
		  $added = substr($arr['added'],0,10);
		  $last_access = substr($arr['last_access'],0,10);
		  if($uc%2 == 0)
			$utc = " class=\"ipcheck-row\"";
		  else
			$utc = " class=\"ipcheck-row ipcheck-row-alt\" bgcolor=\"ECE9D8\"";

			$peer_res = sql_query("SELECT count(*) FROM peers WHERE ip = " . sqlesc($ras['ip']) . " AND userid = " . $arr['id']);
			$peer_row = mysql_fetch_row($peer_res);
		  print("<tr$utc><td align=left data-label=\"User\">" . get_username($arr["id"])."</td>
				  <td align=center data-label=\"Email\">$arr[email]</td>
				  <td align=center data-label=\"Registered\">$added</td>
				  <td align=center data-label=\"Last access\">$last_access</td>
				  <td align=center data-label=\"Downloaded\">$downloaded</td>
				  <td align=center data-label=\"Uploaded\">$uploaded</td>
				  <td align=center data-label=\"Ratio\">$ratio</td>
				  <td align=center data-label=\"IP\"><a href=\"http://www.whois.sc/$arr[ip]\" target=\"_blank\">$arr[ip]</a></td>\n<td align=center data-label=\"Peer\">" .
				  ($peer_row[0] ? "ja" : "nein") . "</td></tr>\n");
		  $ip = $arr["ip"];
		}
	  }
	}
  }
  print("</tbody></table>\n");
}
else
{
 print("<div class=\"ipcheck-empty\"><h2>Sorry, only for Team</h2></div>");
}
print '</div>';

stdfoot();
?>
