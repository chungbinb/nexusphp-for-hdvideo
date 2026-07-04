<?php
require "../include/bittorrent.php";
dbconn();
loggedinorreturn();

if (get_user_class() < UC_MODERATOR)
stderr("Sorry", "Access denied.");
$status = $_GET['status'] ?? 0;
	if ($status)
		int_check($status,true);
		
$res = sql_query("SELECT * FROM users WHERE status='pending' ORDER BY username" ) or sqlerr();
if( mysql_num_rows($res) != 0 )
{
	stdhead("Unconfirmed Users");
	print '<div class="unco-page">';
	print '<h1 class="unco-title">Unconfirmed Users</h1>';
	begin_main_frame();
	begin_frame("");
print'<table class="unco-table" width=100% border=1 cellspacing=0 cellpadding=5>';
print'<thead><tr>';
print'<td class=rowhead><center>Name</center></td>';
print'<td class=rowhead><center>eMail</center></td>';
print'<td class=rowhead><center>Added</center></td>';
print'<td class=rowhead><center>Set Status</center></td>';
print'<td class=rowhead><center>Confirm</center></td>';
print'</tr></thead><tbody>';
if ($status)
	print '<tr class="unco-status-row"><td class="unco-status" colspan=5>The User account has been updated!</td></tr>';
while( $row = mysql_fetch_assoc($res) )
{
$id = (int)$row['id'];
$formId = 'unco-confirm-' . $id;
print'<tr class="unco-row">';
print'<td class="unco-user" data-label="Name"><center>' . get_username($id) . '</center></td>';
print'<td data-label="eMail" align=center>' . htmlspecialchars($row['email']) . '</td>';
print'<td data-label="Added" align=center>' . htmlspecialchars($row['added']) . '</td>';
print'<td data-label="Set Status" align=center><select name=confirm form="' . $formId . '"><option value=pending>pending</option><option value=confirmed>confirmed</option></select></td>';
print'<td data-label="Confirm" align=center><form id="' . $formId . '" class="unco-confirm-form" method=post action=modtask.php>';
print'<input type=hidden name="action" value="confirmuser">';
print("<input type=hidden name='userid' value='$id'>");
print'<input class="btn" type=submit value="-Go-">';
print'</form></td></tr>';
}
print '</tbody></table>';
end_frame();
end_main_frame();
print '</div>';
}else{
	if ($status) {
		stderr("Updated!","The user account has been updated.");
	}
	else {
		stderr("Ups!","Nothing Found...");
	}
}

stdfoot();
