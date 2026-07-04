<?php
require "../include/bittorrent.php";
dbconn();
loggedinorreturn();
if (get_user_class() < UC_ADMINISTRATOR)
stderr("Sorry", "Access denied.");

$remove = intval($_GET['remove'] ?? 0);
if (is_valid_id($remove))
{
  sql_query("DELETE FROM bans WHERE id=".mysql_real_escape_string($remove)) or sqlerr();
  write_log("Ban ".htmlspecialchars($remove)." was removed by {$CURUSER['id']} ($CURUSER[username])",'mod');
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && get_user_class() >= UC_ADMINISTRATOR)
{
	$first = trim($_POST["first"]);
	$last = trim($_POST["last"]);
	$comment = trim($_POST["comment"]);
	if (!$first || !$last || !$comment)
		stderr("Error", "Missing form data.");
	$firstlong = ip2long($first);
	$lastlong = ip2long($last);
	if ($firstlong == -1 || $lastlong == -1)
		stderr("Error", "Bad IP address.");
	$comment = sqlesc($comment);
	$added = sqlesc(date("Y-m-d H:i:s"));
	sql_query("INSERT INTO bans (added, addedby, first, last, comment) VALUES($added, ".mysql_real_escape_string($CURUSER['id']).", $firstlong, $lastlong, $comment)") or sqlerr(__FILE__, __LINE__);
	header("Location: {$_SERVER['REQUEST_URI']}");
	die;
}

//ob_start("ob_gzhandler");

$res = sql_query("SELECT * FROM bans ORDER BY added DESC") or sqlerr();

stdhead("Bans");
$isMobileBans = function_exists('mobile_is') && mobile_is();

print("<h1>Current Bans</h1>\n");

if (mysql_num_rows($res) == 0)
  print("<p align=center><b>Nothing found</b></p>\n");
else
{
  if ($isMobileBans) {
    print("<div class=\"bans-list\">\n");
  } else {
    print("<table border=1 cellspacing=0 cellpadding=5>\n");
    print("<tr><td class=colhead>Added</td><td class=colhead align=left>First IP</td><td class=colhead align=left>Last IP</td>".
      "<td class=colhead align=left>By</td><td class=colhead align=left>Comment</td><td class=colhead>Remove</td></tr>\n");
  }

  while ($arr = mysql_fetch_assoc($res))
  {
    if ($isMobileBans) {
      print("<article class=\"bans-card\">");
      print("<div class=\"bans-card-head\"><span>IP Range</span><a class=\"bans-remove\" href=\"bans.php?remove=".(int)$arr['id']."\">Remove</a></div>");
      print("<div class=\"bans-ip-range\"><b>".long2ip($arr['first'])."</b><span>to</span><b>".long2ip($arr['last'])."</b></div>");
      print("<div class=\"bans-meta\"><div><span>Added</span><b>".gettime($arr['added'])."</b></div><div><span>By</span><b>".get_username($arr['addedby'])."</b></div></div>");
      print("<div class=\"bans-comment\"><span>Comment</span><p>".htmlspecialchars($arr['comment'])."</p></div>");
      print("</article>\n");
    } else {
      print("<tr><td>".gettime($arr['added'])."</td><td align=left>".long2ip($arr['first'])."</td><td align=left>".long2ip($arr['last'])."</td><td align=left>". get_username($arr['addedby']) .
        "</td><td align=left>{$arr['comment']}</td><td><a href=bans.php?remove={$arr['id']}>Remove</a></td></tr>\n");
    }
  }
  print($isMobileBans ? "</div>\n" : "</table>\n");
}

if (get_user_class() >= UC_ADMINISTRATOR)
{
	print("<h1>Add ban</h1>\n");
	print("<table border=1 cellspacing=0 cellpadding=5 class=\"bans-form-table\">\n");
	print("<form method=post action=bans.php>\n");
	print("<tr><td class=rowhead>First IP</td><td><input type=text name=first size=40></td></tr>\n");
	print("<tr><td class=rowhead>Last IP</td><td><input type=text name=last size=40></td></tr>\n");
	print("<tr><td class=rowhead>Comment</td><td><input type=text name=comment size=40></td></tr>\n");
	print("<tr><td colspan=2 align=center><input type=submit value='Okay' class=btn></td></tr>\n");
	print("</form>\n</table>\n");
}

stdfoot();

?>
