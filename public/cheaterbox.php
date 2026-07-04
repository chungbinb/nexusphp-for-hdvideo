<?php
require "../include/bittorrent.php";
dbconn();
require_once ROOT_PATH . 'include/mobile_shell.php';
require_once(get_langfile_path());
loggedinorreturn();
parked();

function cheaterbox_mobile_username($uid): string
{
	$uid = (int)$uid;
	$user = get_user_row($uid);
	if (!$user) {
		return '<span class="cheaterbox-user-color">' . htmlspecialchars(trim(strip_tags(get_username($uid)))) . '</span>';
	}

	$className = preg_replace('/[^A-Za-z0-9_-]/', '', get_user_class_name($user['class'], true, false, false));
	return '<span class="cheaterbox-user-color ' . htmlspecialchars($className . '_Name') . '">' . htmlspecialchars($user['username']) . '</span>';
}

user_can('staffmem', true);


if (!empty($_POST['setdealt'])) {
    if (empty($_POST['delcheater'])) {
        stderr("Error", $lang_functions['select_at_least_one_record']);
    }
//	$res = sql_query ("SELECT id FROM cheaters WHERE dealtwith=0 AND id IN (" . implode(", ", $_POST['delcheater']) . ")");
//	while ($arr = mysql_fetch_assoc($res))
//		sql_query ("UPDATE cheaters SET dealtwith=1, dealtby = {$CURUSER['id']} WHERE id = {$arr['id']}") or sqlerr();

	\App\Models\Cheater::query()->whereIn('id', $_POST['delcheater'])
        ->where('dealtwith', 0)
        ->update(['dealtwith' => 1, 'dealtby' => $CURUSER['id']])
    ;
	$Cache->delete_value('staff_new_cheater_count');
}
elseif (!empty($_POST['delete'])) {
    if (empty($_POST['delcheater'])) {
        stderr("Error", $lang_functions['select_at_least_one_record']);
    }
//	$res = sql_query ("SELECT id FROM cheaters WHERE id IN (" . implode(", ", $_POST['delcheater']) . ")");
//	while ($arr = mysql_fetch_assoc($res))
//		sql_query ("DELETE from cheaters WHERE id = {$arr['id']}") or sqlerr();

	\App\Models\Cheater::query()->whereIn('id', $_POST['delcheater'])->delete();
	$Cache->delete_value('staff_new_cheater_count');
}

$count = get_row_count("cheaters");
if (!$count){
	stderr($lang_cheaterbox['std_oho'], $lang_cheaterbox['std_no_suspect_detected']);
}
$perpage = 50;
list($pagertop, $pagerbottom, $limit) = pager($perpage, $count, "cheaterbox.php?");
mp_head($lang_cheaterbox['head_cheaterbox']);
$isMobileCheaterbox = function_exists('mobile_is') && mobile_is();
?>
<style type="text/css">
table.cheaterbox td
{
	text-align: center;
}
</style>
<?php
begin_main_frame();
print("<h1 align=center>".$lang_cheaterbox['text_cheaterbox']."</h1>");
print("<form method=post action=cheaterbox.php class=\"cheaterbox-form\" name=\"cheaterboxform\">");
if ($isMobileCheaterbox) {
	if ($count > $perpage) {
		echo $pagertop;
	}
	print("<div class=\"cheaterbox-list\">\n");
} else {
	print("<table class=cheaterbox border=1 cellspacing=0 cellpadding=5 align=center>\n");
	print("<tr><td class=colhead><nobr>".$lang_cheaterbox['col_added']."</nobr></td><td class=colhead>".$lang_cheaterbox['col_suspect']."</td><td class=colhead><nobr>".$lang_cheaterbox['col_hit']."</nobr></td><td class=colhead>".$lang_cheaterbox['col_torrent']."</td><td class=colhead>".$lang_cheaterbox['col_ul']."</td><td class=colhead>".$lang_cheaterbox['col_dl']."</td><td class=colhead><nobr>".$lang_cheaterbox['col_ann_time']."</nobr></td><td class=colhead><nobr>".$lang_cheaterbox['col_seeders']."</nobr></td><td class=colhead><nobr>".$lang_cheaterbox['col_leechers']."</nobr></td><td class=colhead>".$lang_cheaterbox['col_comment']."</td><td class=colhead><nobr>".$lang_cheaterbox['col_dealt_with']."</nobr></td><td class=colhead><nobr>".$lang_cheaterbox['col_action']."</nobr></td></tr>");
}
$cheatersres = sql_query("SELECT * FROM cheaters ORDER BY dealtwith ASC, id DESC $limit");

while ($row = mysql_fetch_array($cheatersres))
{
	$anctime = (int)$row['anctime'];
	$upspeed = ($row['uploaded'] > 0 && $anctime > 0 ? $row['uploaded'] / $anctime : 0);
	$lespeed = ($row['downloaded'] > 0 && $anctime > 0 ? $row['downloaded'] / $anctime : 0);
	$torrentres = sql_query("SELECT name FROM torrents WHERE id=".sqlesc($row['torrentid']));
	$torrentrow = mysql_fetch_array($torrentres);
	if ($torrentrow)
		$torrent = "<a href=\"details.php?id=".(int)$row['torrentid']."\">".htmlspecialchars($torrentrow['name'])."</a>";
	else $torrent = $lang_cheaterbox['text_torrent_does_not_exist'];
	if ($row['dealtwith'])
		$dealtwith = "<font color=green>".$lang_cheaterbox['text_yes']."</font> - " . get_username($row['dealtby']);
	else
		$dealtwith = "<font color=red>".$lang_cheaterbox['text_no']."</font>";

	$uploadText = mksize($row['uploaded']).($upspeed ? " @ ".mksize($upspeed)."/s" : "");
	$downloadText = mksize($row['downloaded']).($lespeed ? " @ ".mksize($lespeed)."/s" : "");
	$announceText = $anctime." sec";
	if ($isMobileCheaterbox) {
		$dealtClass = $row['dealtwith'] ? 'is-dealt' : 'is-open';
		$dealtLabel = $row['dealtwith'] ? $lang_cheaterbox['text_yes'] : $lang_cheaterbox['text_no'];
		$dealtByName = $row['dealtwith'] ? cheaterbox_mobile_username($row['dealtby']) : '';
		$comment = trim($row['comment']) !== '' ? htmlspecialchars($row['comment']) : '-';

		print("<article class=\"cheaterbox-card $dealtClass\">");
		print("<label class=\"cheaterbox-select\"><input type=\"checkbox\" name=\"delcheater[]\" value=\"" . (int)$row['id'] . "\" /><span></span></label>");
		print("<div class=\"cheaterbox-card-body\">");
		print("<div class=\"cheaterbox-card-head\"><span class=\"cheaterbox-suspect\">" . cheaterbox_mobile_username($row['userid']) . "</span><span class=\"cheaterbox-hit\">".$lang_cheaterbox['col_hit']." ".(int)$row['hit']."</span></div>");
		print("<div class=\"cheaterbox-time\"><span>".$lang_cheaterbox['col_added']."</span><b>".gettime($row['added'])."</b></div>");
		print("<div class=\"cheaterbox-torrent\"><span>".$lang_cheaterbox['col_torrent']."</span><p>".$torrent."</p></div>");
		print("<div class=\"cheaterbox-stats\"><div><span>".$lang_cheaterbox['col_ul']."</span><b>".$uploadText."</b></div><div><span>".$lang_cheaterbox['col_dl']."</span><b>".$downloadText."</b></div><div><span>".$lang_cheaterbox['col_ann_time']."</span><b>".$announceText."</b></div><div><span>".$lang_cheaterbox['col_seeders']."</span><b>".(int)$row['seeders']."</b></div><div><span>".$lang_cheaterbox['col_leechers']."</span><b>".(int)$row['leechers']."</b></div></div>");
		print("<div class=\"cheaterbox-comment\"><span>".$lang_cheaterbox['col_comment']."</span><p>".$comment."</p></div>");
		print("<div class=\"cheaterbox-status\"><span class=\"cheaterbox-status-label\">".$lang_cheaterbox['col_dealt_with']."</span><span class=\"cheaterbox-status-value\"><b>".$dealtLabel."</b>".($dealtByName ? " " . $dealtByName : "")."</span></div>");
		print("</div>");
		print("</article>\n");
	} else {
		print("<tr><td class=rowfollow>".gettime($row['added'])."</td><td class=rowfollow>" . get_username($row['userid']) . "</td><td class=rowfollow>" . $row['hit'] . "</td><td class=rowfollow>" . $torrent . "</td><td class=rowfollow>".$uploadText."</td><td class=rowfollow>".$downloadText."</td><td class=rowfollow>".$announceText."</td><td class=rowfollow>".$row['seeders']."</td><td class=rowfollow>".$row['leechers']."</td><td class=rowfollow>".htmlspecialchars($row['comment'])."</td><td class=rowfollow>".$dealtwith."</td><td class=rowfollow><input type=\"checkbox\" name=\"delcheater[]\" value=\"" . $row['id'] . "\" /></td></tr>\n");
	}
}
if ($isMobileCheaterbox) {
	print("</div>");
	print("<div class=\"cheaterbox-actions\"><input class=\"btn\" type=\"button\" value=\"".$lang_functions['input_check_all']."\" onClick=\"this.value=check(this.form,'".$lang_functions['input_check_all']."','".$lang_functions['input_uncheck_all']."')\" /><input type=\"submit\" name=\"setdealt\" value=\"".$lang_cheaterbox['submit_set_dealt']."\" /><input class=\"cheaterbox-danger\" type=\"submit\" name=\"delete\" value=\"".$lang_cheaterbox['submit_delete']."\" /></div>");
} else {
	print("<tr><td class=\"colhead\" colspan=\"12\" style=\"text-align: right\"><input class=btn type=\"button\" value=\"".$lang_functions['input_check_all']."\" onClick=\"this.value=check(this.form,'".$lang_functions['input_check_all']."','".$lang_functions['input_uncheck_all']."')\"><input type=\"submit\" name=\"setdealt\" value=\"".$lang_cheaterbox['submit_set_dealt']."\" /><input type=\"submit\" name=\"delete\" value=\"".$lang_cheaterbox['submit_delete']."\" /></td></tr>");
	print("</table>");
}
print("</form>");
print($pagerbottom);
end_main_frame();
mp_foot();
?>
