<?php
require "../include/bittorrent.php";
dbconn();
require_once ROOT_PATH . 'include/mobile_shell.php';
require_once(get_langfile_path());
loggedinorreturn();

$action = $_GET["action"] ?? '';

function can_access_staff_message($msg)
{
    global $CURUSER;
    if (user_can('staffmem')) {
        return true;
    }
    if (is_numeric($msg)) {
        $msg = \App\Models\StaffMessage::query()->findOrFail($msg)->toArray();
    }
    if (empty($msg['permission']) || !in_array($msg['permission'], \App\Repositories\ToolRepository::listUserAllPermissions($CURUSER['id']))) {
        permissiondenied(get_setting('authority.staffmem'));
    }
}

///////////////////////////
//        SHOW PM'S        //
/////////////////////////

if (!$action) {
	mp_head($lang_staffbox['head_staff_pm']);
	$url = $_SERVER['PHP_SELF']."?";
    $query = \App\Repositories\MessageRepository::buildStaffMessageQuery($CURUSER['id']);
    $count = $query->count();
	$perpage = 20;
	list($pagertop, $pagerbottom, $limit, $offset, $pageSize, $pageNum) = pager($perpage, $count, $url);
	print ("<h1 align=center>".$lang_staffbox['text_staff_pm']."</h1>");
	if ($count == 0)
	{
	    do_log(last_query());
		stdmsg($lang_staffbox['std_sorry'], $lang_staffbox['std_no_messages_yet']);
	}
	else
	{
        $isMobileStaffbox = function_exists('mobile_is') && mobile_is();
		begin_main_frame();
		print("<form method=post action=\"?action=takecontactanswered\" class=\"staffbox-form\">");
        if ($isMobileStaffbox) {
            if ($count > $perpage) {
                echo $pagertop;
            }
            print("<div class=\"staffbox-list\">\n");
        } else {
            print("<table width=940 border=1 cellspacing=0 cellpadding=5 align=center>\n");
            print("<tr>
                <td class=colhead align=left>".$lang_staffbox['col_subject']."</td>
                <td class=colhead align=center>".$lang_staffbox['col_sender']."</td>
                <td class=colhead align=center><nobr>".$lang_staffbox['col_added']."</nobr></td>
                <td class=colhead align=center>".$lang_staffbox['col_answered']."</td>
                <td class=colhead align=center><nobr>".$lang_staffbox['col_action']."</nobr></td>
            </tr>");
        }

	$res = $query->forPage($pageNum + 1, $perpage)->orderBy('id', 'desc')->get()->toArray();
	do_log(last_query());
	foreach ($res as $arr)
	{
    		if ($arr['answered'])
    		{
       			$answered = "<nobr><font color=green>".$lang_staffbox['text_yes']."</font> - " . get_username($arr['answeredby']) . "</nobr>";
    		}
   		else
			$answered = "<font color=red>".$lang_staffbox['text_no']."</font>";

    		$pmid = $arr["id"];
            $viewUrl = "staffbox.php?action=viewpm&pmid=$pmid&return=".urlencode($_SERVER['QUERY_STRING']);
            if ($isMobileStaffbox) {
                $answeredClass = $arr['answered'] ? 'is-answered' : 'is-open';
                $answeredLabel = $arr['answered'] ? $lang_staffbox['text_yes'] : $lang_staffbox['text_no'];
                $answeredByName = $arr['answered'] ? trim(strip_tags(get_username($arr['answeredby']))) : '';
                $senderName = trim(strip_tags(get_username($arr['sender'])));
                print("<article class=\"staffbox-card $answeredClass\">");
                print("<label class=\"staffbox-select\"><input type=\"checkbox\" name=\"setanswered[]\" value=\"" . (int)$arr['id'] . "\" /><span></span></label>");
                print("<a class=\"staffbox-card-body\" href=\"".htmlspecialchars($viewUrl)."\">");
                print("<div class=\"staffbox-subject\">".htmlspecialchars($arr['subject'])."</div>");
                print("<div class=\"staffbox-meta\"><div class=\"staffbox-meta-row staffbox-sender\"><span class=\"staffbox-meta-label\">".$lang_staffbox['col_sender']."</span><span class=\"staffbox-meta-value\">" . htmlspecialchars($senderName) . "</span></div><div class=\"staffbox-meta-row staffbox-time\"><span class=\"staffbox-meta-label\">".$lang_staffbox['col_added']."</span><span class=\"staffbox-meta-value\">".gettime($arr['added'], true, false)."</span></div></div>");
                print("<div class=\"staffbox-answer\"><span class=\"staffbox-answer-label\">".$lang_staffbox['col_answered']."</span><span class=\"staffbox-answer-value\"><b>".$answeredLabel."</b>".($answeredByName ? " " . htmlspecialchars($answeredByName) : "")."</span></div>");
                print("</a>");
                print("</article>\n");
            } else {
                print("<tr><td width=100% class=rowfollow align=left><a href=".htmlspecialchars($viewUrl).">".htmlspecialchars($arr['subject'])."</a></td><td class=rowfollow align=center>" . get_username($arr['sender']) . "</td><td class=rowfollow align=center><nobr>".gettime($arr['added'], true, false)."</nobr></td><td class=rowfollow align=center>$answered</td><td class=rowfollow align=center><input type=\"checkbox\" name=\"setanswered[]\" value=\"" . $arr['id'] . "\" /></td></tr>\n");
            }
	}
    $checkAll = $lang_functions['input_check_all'];
    $uncheckAll = $lang_functions['input_uncheck_all'];
    if ($isMobileStaffbox) {
        print("</div>\n");
        print("<div class=\"staffbox-actions\"><input type=\"button\" value=\"$checkAll\" onclick=\"this.value=check(form, '$checkAll', '$uncheckAll')\"/><input type=\"submit\" name=\"setdealt\" value=\"".$lang_staffbox['submit_set_answered']."\" /><input class=\"staffbox-danger\" type=\"submit\" name=\"delete\" value=\"".$lang_staffbox['submit_delete']."\" /></div>");
    } else {
        print("<tr><td class=rowfollow align=right colspan=5><input type=\"button\" value=\"$checkAll\" onclick=\"this.value=check(form, '$checkAll', '$uncheckAll')\"/><input type=\"submit\" name=\"setdealt\" value=\"".$lang_staffbox['submit_set_answered']."\" /><input type=\"submit\" name=\"delete\" value=\"".$lang_staffbox['submit_delete']."\" /></td></tr>");
        print("</table>\n");
    }
	print("</form>");
	echo $pagerbottom;
	end_main_frame();
	}
	mp_foot();
}

         //////////////////////////
        //        VIEW PM'S        //
       //////////////////////////

if ($action == "viewpm")
{
$pmid = intval($_GET["pmid"] ?? 0);

$ress4 = sql_query("SELECT * FROM staffmessages WHERE id=".sqlesc($pmid));
$arr4 = mysql_fetch_assoc($ress4);
can_access_staff_message($arr4);
$answeredby = get_username($arr4["answeredby"]);

if (is_valid_id($arr4["sender"]))
{
$sender = get_username($arr4["sender"]);
}
else
$sender = $lang_staffbox['text_system'];

$subject = htmlspecialchars($arr4["subject"]);
if ($arr4["answered"] == 1){
$colspan = "3";
$width = "33";
}
else{
$colspan = "2";
$width = "50";
}
mp_head($lang_staffbox['head_view_staff_pm']);
print("<h1 align=\"center\"><a class=\"faqlink\" href=\"staffbox.php\">".$lang_staffbox['text_staff_pm']."</a>-->".$subject."</h1>");
$isMobileStaffbox = function_exists('mobile_is') && mobile_is();
if ($isMobileStaffbox) {
    print("<section class=\"staffbox-detail\">");
    print("<div class=\"staffbox-detail-head\">");
    print("<div><span>".$lang_staffbox['col_from']."</span><b>".$sender."</b></div>");
    if ($arr4["answered"] == 1) {
        print("<div><span>".$lang_staffbox['col_answered_by']."</span><b>".$answeredby."</b></div>");
    }
    print("<div><span>".$lang_staffbox['col_date']."</span><b>".gettime($arr4["added"])."</b></div>");
    print("</div>");
    print("<div class=\"staffbox-message\"><div class=\"staffbox-message-label\">".$lang_staffbox['col_subject']."</div>".format_comment($arr4["msg"])."</div>");
    if ($arr4["answered"] == 1 && $arr4["answer"]) {
        print("<div class=\"staffbox-message staffbox-reply\"><div class=\"staffbox-message-label\">".$lang_staffbox['col_answered']."</div>".format_comment($arr4["answer"])."</div>");
    }
    print("<div class=\"staffbox-detail-actions\">");
    if ($arr4["answered"] == 0) {
        print("<a href=\"staffbox.php?action=answermessage&receiver=" . $arr4['sender'] . "&answeringto=".$arr4['id']."\">".$lang_staffbox['text_reply']."</a>");
        print("<a href=\"staffbox.php?action=setanswered&id=".$arr4['id']."&return=".urlencode($_GET['return'] ?? '')."\">".$lang_staffbox['text_mark_answered']."</a>");
    }
    print("<a class=\"staffbox-danger\" href=\"staffbox.php?action=deletestaffmessage&id=" . $arr4["id"] . "\">".$lang_staffbox['text_delete']."</a>");
    print("</div></section>");
} else {
    print("<table width=\"737\" border=\"0\" cellpadding=\"4\" cellspacing=\"0\">");
    print("<tr><td width=\"".$width."%\" class=\"colhead\" align=\"left\">".$lang_staffbox['col_from']."</td>");
    if ($arr4["answered"] == 1)
    print("<td width=\"34%\" class=\"colhead\" align=\"left\">".$lang_staffbox['col_answered_by']."</td>");
    print("<td width=\"".$width."%\" class=\"colhead\" align=\"left\">".$lang_staffbox['col_date']."</td></tr>");
    print("<tr><td class=\"rowfollow\" align=\"left\">".$sender."</td>");
    if ($arr4["answered"] == 1)
    print("<td class=\"rowfollow\" align=\"left\">".$answeredby."</td>");
    print("<td class=\"rowfollow\" align=\"left\">".gettime($arr4["added"])."</td></tr>");
    print("<tr><td colspan=\"".$colspan."\" align=\"left\">".format_comment($arr4["msg"])."</td></tr>");
    if ($arr4["answered"] == 1 && $arr4["answer"])
    {
    print("<tr><td colspan=\"".$colspan."\" align=\"left\">".format_comment($arr4["answer"])."</td></tr>");
    }
    print("<tr><td colspan=\"".$colspan."\" align=\"right\">");
    print("<font color=white>");
    if ($arr4["answered"] == 0)
    print("[ <a href=\"staffbox.php?action=answermessage&receiver=" . $arr4['sender'] . "&answeringto=".$arr4['id']."\">".$lang_staffbox['text_reply']."</a> ] [ <a href=\"staffbox.php?action=setanswered&id=".$arr4['id']."&return=".urlencode($_GET['return'] ?? '')."\">".$lang_staffbox['text_mark_answered']."</a> ] ");
    print("[ <a href=\"staffbox.php?action=deletestaffmessage&id=" . $arr4["id"] . "\">".$lang_staffbox['text_delete']."</a> ]");
    print("</font>");
    print("</td></tr>");
    print("</table>");
}
mp_foot();
}
         //////////////////////////
        //        ANSWER MESSAGE        //
       //////////////////////////

if ($action == "answermessage") {
        $answeringto = intval($_GET["answeringto"] ?? 0);
        $receiver = intval($_GET["receiver"] ?? 0);

        int_check($receiver,true);

        $res = sql_query("SELECT * FROM users WHERE id=" . sqlesc($receiver));
        $user = mysql_fetch_assoc($res);

        if (!$user)
   		stderr($lang_staffbox['std_error'], $lang_staffbox['std_no_user_id']);

        $res2 = sql_query("SELECT * FROM staffmessages WHERE id=" . sqlesc($answeringto));
        $staffmsg = mysql_fetch_assoc($res2);

        can_access_staff_message($staffmsg);

	mp_head($lang_staffbox['head_answer_to_staff_pm']);
	begin_main_frame();
        ?>
	<form method="post" id="compose" name="message" action="?action=takeanswer">
<?php if ($_GET["returnto"] || $_SERVER["HTTP_REFERER"]) { ?>
        <input type=hidden name=returnto value="<?php echo htmlspecialchars($_GET["returnto"] ?? '') ? htmlspecialchars($_GET["returnto"]) : htmlspecialchars($_SERVER["HTTP_REFERER"])?>">
<?php } ?>
        <input type=hidden name=receiver value=<?php echo $receiver?>>
        <input type=hidden name=answeringto value=<?php echo $answeringto?>>
<?php
	$title = $lang_staffbox['text_answering_to']."<a href=\"staffbox.php?action=viewpm&pmid=".$staffmsg['id']."\">".htmlspecialchars($staffmsg['subject'])."</a>".$lang_staffbox['text_sent_by'].get_username($staffmsg['sender']);
	begin_compose($title, "reply", "", false);
	end_compose();
	print("</form>");
	end_main_frame();
	mp_foot();
}

         //////////////////////////
        //        TAKE ANSWER        //
       //////////////////////////
if ($action == "takeanswer") {
  if ($_SERVER["REQUEST_METHOD"] != "POST")
    die();

     $receiver = intval($_POST["receiver"] ?? 0);
   $answeringto = $_POST["answeringto"];

   int_check($receiver,true);

          $userid = $CURUSER["id"];

   			$msg = trim($_POST["body"]);

          $message = sqlesc($msg);

          $added = "'" . date("Y-m-d H:i:s") . "'";

   if (!$msg)
     stderr($lang_staffbox['std_error'], $lang_staffbox['std_body_is_empty']);

    can_access_staff_message($answeringto);

$subject = \App\Models\StaffMessage::query()->findOrFail($answeringto)->toArray()['subject'];
    
\App\Models\Message::add([
    'sender' => $userid,
    'receiver' => $receiver,
    'subject' => $subject,
    'added' => now(),
    'msg' => $msg,
]);

sql_query("UPDATE staffmessages SET answer=$message, answered='1', answeredby='$userid' WHERE id=$answeringto") or sqlerr(__FILE__, __LINE__);
$Cache->delete_value('staff_new_message_count');
clear_staff_message_cache();
        header("Location: staffbox.php?action=viewpm&pmid=$answeringto");
        die;
}
         //////////////////////////
        // DELETE STAFF MESSAGE        //
       //////////////////////////

if ($action == "deletestaffmessage") {

   $id = intval($_GET["id"] ?? 0);

    if (!is_numeric($id) || $id < 1 || floor($id) != $id)
    die;

    can_access_staff_message($id);
    sql_query("DELETE FROM staffmessages WHERE id=" . sqlesc($id)) or die();
$Cache->delete_value('staff_message_count');
$Cache->delete_value('staff_new_message_count');
clear_staff_message_cache();
  header("Location: " . get_protocol_prefix() . "$BASEURL/staffbox.php");
}

         //////////////////////////
        // MARK AS ANSWERED        //
       //////////////////////////

if ($action == "setanswered") {


$id = intval($_GET["id"] ?? 0);
    can_access_staff_message($id);
sql_query ("UPDATE staffmessages SET answered=1, answeredby = {$CURUSER['id']} WHERE id = $id") or sqlerr();
$Cache->delete_value('staff_new_message_count');
    clear_staff_message_cache();
header("Location: staffbox.php" . (!empty($_GET['return']) ? "?" . $_GET['return'] : ''));
}

         //////////////////////////
        // MARK AS ANSWERED #2        //
       //////////////////////////

if ($action == "takecontactanswered") {
    if (empty($_POST['setanswered'])) {
        stderr($lang_staffbox['std_sorry'], nexus_trans('nexus.select_one_please'));
    }

if ($_POST['setdealt']){
	$res = sql_query ("SELECT * FROM staffmessages WHERE answered=0 AND id IN (" . implode(", ", $_POST['setanswered']) . ")");
	while ($arr = mysql_fetch_assoc($res)) {
	    can_access_staff_message($arr);
        sql_query ("UPDATE staffmessages SET answered=1, answeredby = {$CURUSER['id']} WHERE id = {$arr['id']}") or sqlerr();
    }
}
elseif ($_POST['delete']){
	$res = sql_query ("SELECT * FROM staffmessages WHERE id IN (" . implode(", ", $_POST['setanswered']) . ")");
	while ($arr = mysql_fetch_assoc($res)) {
        can_access_staff_message($arr);
        sql_query ("DELETE FROM staffmessages WHERE id = {$arr['id']}") or sqlerr();
    }
}
$Cache->delete_value('staff_new_message_count');
    clear_staff_message_cache();
header("Location: staffbox.php");
}

?>
