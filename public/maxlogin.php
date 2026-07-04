<?php
require "../include/bittorrent.php";
dbconn();
loggedinorreturn();
if (get_user_class() < UC_SYSOP)
 stderr("Error", "Permission denied.");

$action = isset($_POST['action']) ? htmlspecialchars($_POST['action']) : (isset($_GET['action']) ? htmlspecialchars($_GET['action']) : 'showlist');
$id = isset($_POST['id']) ? htmlspecialchars($_POST['id']) : (isset($_GET['id']) ? htmlspecialchars($_GET['id']) : '');
$update = isset($_POST['update']) ? htmlspecialchars($_POST['update']) : (isset($_GET['update']) ? htmlspecialchars($_GET['update']) : '');

function check ($id) {
	if (!is_valid_id($id))
		return stderr("Error","Invalid ID");
	else
		return true;
}
function safe_query ($query,$id,$where = '') {
	$query = sprintf("$query WHERE id ='%s'",
	mysql_real_escape_string($id));
	$result = sql_query($query);
	if (!$result)
		return sqlerr(__FILE__,__LINE__);
    nexus_redirect("maxlogin.php?update=".htmlspecialchars($where));
}
function maxlogin_mobile_username($uid): string
{
	$uid = (int)$uid;
	$user = get_user_row($uid);
	if (!$user) {
		return '<span class="maxlogin-user-color">' . htmlspecialchars(trim(strip_tags(get_username($uid)))) . '</span>';
	}

	$className = preg_replace('/[^A-Za-z0-9_-]/', '', get_user_class_name($user['class'], true, false, false));
	return '<span class="maxlogin-user-color ' . htmlspecialchars($className . '_Name') . '">' . htmlspecialchars($user['username']) . '</span>';
}
function maxlogin_is_mobile(): bool
{
	return function_exists('mobile_is') && mobile_is();
}
function maxlogin_attempt_type_label($type): string
{
	return $type == "recover" ? "Recover Password Attempt!" : "Login Attempt!";
}
function maxlogin_user_for_ip($ip): string
{
	$r2 = sql_query("SELECT id,username FROM users WHERE ip=".sqlesc($ip)) or sqlerr(__FILE__,__LINE__);
	$a2 = mysql_fetch_assoc($r2);
	if (empty($a2['id'])) {
		return "";
	}

	return maxlogin_is_mobile() ? maxlogin_mobile_username($a2['id']) : get_username($a2['id']);
}
function maxlogin_render_attempt_card($arr): void
{
	$id = (int)$arr['id'];
	$isBanned = $arr['banned'] == "yes";
	$user = maxlogin_user_for_ip($arr['ip']);
	$typeLabel = maxlogin_attempt_type_label($arr['type']);
	$statusText = $isBanned ? "banned" : "not banned";
	$statusClass = $isBanned ? "is-banned" : "is-clear";
	$toggleAction = $isBanned ? "unban" : "ban";
	$toggleLabel = $isBanned ? "Unban" : "Ban";
	$toggleClass = $isBanned ? "maxlogin-action-good" : "maxlogin-action-warn";

	print("<article class=\"maxlogin-card $statusClass\">");
	print("<div class=\"maxlogin-card-head\"><span>#".$id."</span><b>".$statusText."</b></div>");
	print("<div class=\"maxlogin-ip\"><span>IP Address</span><b>".htmlspecialchars($arr['ip'])."</b>".($user ? "<em>".$user."</em>" : "")."</div>");
	print("<div class=\"maxlogin-meta\"><div><span>Action Time</span><b>".htmlspecialchars($arr['added'])."</b></div><div><span>Attempts</span><b>".htmlspecialchars($arr['attempts'])."</b></div><div><span>Attempt Type</span><b>".$typeLabel."</b></div></div>");
	print("<div class=\"maxlogin-actions\"><a class=\"".$toggleClass."\" href=\"maxlogin.php?action=".$toggleAction."&id=".$id."\">".$toggleLabel."</a><a href=\"maxlogin.php?action=edit&id=".$id."\">Edit</a><a class=\"maxlogin-danger\" OnClick=\"return confirm('Are you wish to delete this attempt?');\" href=\"maxlogin.php?action=delete&id=".$id."\">Delete</a></div>");
	print("</article>\n");
}
function maxlogin_render_attempt_row($arr): void
{
	$user = maxlogin_user_for_ip($arr['ip']);
	print("<tr><td align=>{$arr['id']}</td><td align=left>".htmlspecialchars($arr['ip'])." " . $user . "</td><td align=left>".htmlspecialchars($arr['added'])."</td><td align=left>".htmlspecialchars($arr['attempts'])."</td><td align=left>".maxlogin_attempt_type_label($arr['type'])."</td><td align=left>".($arr['banned'] == "yes" ? "<font color=red><b>banned</b></font> <a href=maxlogin.php?action=unban&id={$arr['id']}><font color=green>[<b>unban</b>]</font></a>" : "<font color=green><b>not banned</b></font> <a href=maxlogin.php?action=ban&id={$arr['id']}><font color=red>[<b>ban</b>]</font></a>")."  <a OnClick=\"return confirm('Are you wish to delete this attempt?');\" href=maxlogin.php?action=delete&id={$arr['id']}>[<b>delete</b></a>] <a href=maxlogin.php?action=edit&id={$arr['id']}><font color=blue>[<b>edit</b></a>]</font></td></tr>\n");
}
function maxlogin_render_attempts($res, string $emptyText): void
{
	if (maxlogin_is_mobile()) {
		if (mysql_num_rows($res) == 0) {
			print("<div class=\"maxlogin-empty\"><b>".htmlspecialchars($emptyText)."</b></div>\n");
			return;
		}
		print("<div class=\"maxlogin-list\">\n");
		while ($arr = mysql_fetch_assoc($res)) {
			maxlogin_render_attempt_card($arr);
		}
		print("</div>\n");
		return;
	}

	print("<table border=1 cellspacing=0 cellpadding=5 width=100%>\n");
	if (mysql_num_rows($res) == 0)
		print("<tr><td colspan=6><b>".htmlspecialchars($emptyText)."</b></td></tr>\n");
	else
	{
		print("<tr><td class=colhead><a href=?order=id>ID</a></td><td class=colhead align=left><a href=?order=ip>Ip Address</a></td><td class=colhead align=left><a href=?order=added>Action Time</a></td>".
			"<td class=colhead align=left><a href=?order=attempts>Attempts</a></td><td class=colhead align=left><a href=?order=type>Attempt Type</a></td><td class=colhead align=left><a href=?order=status>Status</a></td></tr>\n");

		while ($arr = mysql_fetch_assoc($res))
		{
			maxlogin_render_attempt_row($arr);
		}
	}
	print("</table>\n");
}
function searchform () {
?>
<form method=post name=search action="maxlogin.php" class="maxlogin-search-form">
<input type=hidden name=action value=searchip>
<p class="success maxlogin-search-box" align=center>Search IP <input type=text name=ip size=25> <input type=submit name=submit value='Search IP' class=btn></p>
</form>
<?php
}
$countrows = number_format(get_row_count("loginattempts")) + 1;
$page = intval($_GET["page"] ?? 0);

$order = $_GET['order'] ?? '';
if ($order == 'id')
	$orderby = "id";
elseif ($order == 'ip')
	$orderby = "ip";
elseif ($order == 'added')
	$orderby = "added";
elseif ($order == 'attempts')
	$orderby = "attempts";
elseif ($order == 'type')
	$orderby = "type";
elseif ($order == 'status')
	$orderby = "banned";
else
	$orderby = "attempts";

$perpage = 50;
list($pagertop, $pagerbottom, $limit) = pager($perpage, $countrows, "maxlogin.php?order=$order&");
$msg = '';
if ($update) {
    $msg = "<h3><b>".htmlspecialchars($update)." Successful!</b></h3>";
}
if ($action == 'showlist') {
stdhead ("Max. Login Attemps - Show List");
print("<h1>Failed Login Attempts</h1>");
print($msg);

$res = sql_query("SELECT * FROM  loginattempts ORDER BY $orderby DESC $limit") or sqlerr(__FILE__,__LINE__);
maxlogin_render_attempts($res, "Nothing found");
if ($countrows > $perpage) {
    echo $pagerbottom;
}
searchform();
stdfoot();
}elseif ($action == 'ban') {
	check($id);
	stdhead ("Max. Login Attemps - BAN");
	safe_query("UPDATE loginattempts SET banned = 'yes'",$id,"Ban");
	header("Location: maxlogin.php?update=Ban");
}elseif ($action == 'unban') {
	check($id);
	stdhead ("Max. Login Attemps - UNBAN");
	safe_query("UPDATE loginattempts SET banned = 'no'",$id,"Unban");

}elseif ($action == 'delete') {
	check($id);
	stdhead ("Max. Login Attemps - DELETE");
	safe_query("DELETE FROM loginattempts",$id,"Delete");
}elseif ($action == 'edit') {
	check($id);
	stdhead ("Max. Login Attemps - EDIT (".htmlspecialchars($id).")");
	$query = sprintf("SELECT * FROM loginattempts WHERE id ='%s'",
	mysql_real_escape_string($id));
	$result = sql_query($query) or sqlerr(__FILE__,__LINE__);
	$a = mysql_fetch_array($result);
	print("<table border=1 cellspacing=0 cellpadding=5 width=100% class=\"maxlogin-edit-table\">\n");
	print("<tr><td><p>IP Address: <b>".htmlspecialchars($a['ip'])."</b></p>");
	print("<p>Action Time: <b>".htmlspecialchars($a['added'])."</b></p></tr></td>");
	print("<form method='post' action='maxlogin.php'>");
	print("<input type='hidden' name='action' value='save'>");
	print("<input type='hidden' name='id' value='{$a['id']}'>");
	print("<input type='hidden' name='ip' value='{$a['ip']}'>");
	if (($_GET['return'] ?? '') == 'yes')
		print("<input type='hidden' name='returnto' value='viewunbaniprequest.php'>");
	print("<tr><td>Attempts <input type='text' size='33' name='attempts' value='$a[attempts]'>");
	print("<tr><td>Attempt Type <select name='type'><option value='login' ".($a["type"] == "login" ? "selected" : "").">Login Attempt</option><option value='recover' ".($a["type"] == "recover" ? "selected" : "").">Recover Password Attempts</option></select></tr></td>");
	print("<tr><td>Current Status <select name='banned'><option value='yes' ".($a["banned"] == "yes" ? "selected" : "").">Banned!</option><option value='no' ".($a["banned"] == "no" ? "selected" : "").">Not Banned!</option></select></tr></td>");
	print("<tr><td><input type='submit' name='submit' value='Save' class=btn></tr></td>");
	print("</form>");
	print("</table>");
	stdfoot();

}elseif ($action == 'save') {
	$id = intval($_POST['id'] ?? 0);
	$ip = sqlesc($_POST['ip']);
	$attempts = $_POST['attempts'];
	$type = sqlesc($_POST['type']);
	$banned = sqlesc($_POST['banned']);
		check($id);
		check($attempts);
	sql_query("UPDATE loginattempts SET attempts = $attempts, type = $type, banned = $banned WHERE id = $id LIMIT 1") or sqlerr(__FILE__,__LINE__);
	if (!empty($_POST['returnto'])){
		$returnto = $_POST['returnto'];
		header("Location: $returnto");
	}
	else
		header("Location: maxlogin.php?update=Edit");
}elseif ($action == 'searchip') {
	$ip = mysql_real_escape_string($_POST['ip']);
	$search = sql_query("SELECT * FROM loginattempts WHERE ip LIKE '%$ip%'") or sqlerr(__FILE__,__LINE__);
	stdhead ("Max. Login Attemps - Search");
	print("<h2>Failed Login Attempts</h2>");
	maxlogin_render_attempts($search, "Sorry, nothing found!");
	searchform();
	stdfoot();
}
else
	stderr("Error","Invalid Action");
?>
