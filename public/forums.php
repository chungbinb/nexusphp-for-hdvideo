<?php
require "../include/bittorrent.php";
dbconn();
require_once(get_langfile_path());
loggedinorreturn();
parked();
if ($enableextforum == 'yes') //check whether internal forum is disabled
	permissiondenied();

// ------------- start: functions ------------------//
//print forum stats
function forum_stats ()
{
	global $lang_forums, $Cache, $today_date;

	if (!$activeforumuser_num = $Cache->get_value('active_forum_user_count')){
		$secs = 900;
		$dt = date("Y-m-d H:i:s",(TIMENOW - $secs));
		$activeforumuser_num = get_row_count("users","WHERE forum_access >= ".sqlesc($dt));
		$Cache->cache_value('active_forum_user_count', $activeforumuser_num, 300);
	}
	if ($activeforumuser_num){
		$forumusers = $lang_forums['text_there'].is_or_are($activeforumuser_num)."<b>".$activeforumuser_num."</b>".$lang_forums['text_online_user'].add_s($activeforumuser_num).$lang_forums['text_in_forum_now'];
	}
	else
		$forumusers = $lang_forums['text_no_active_users'];
?>
<h2 align="left"><?php echo $lang_forums['text_stats'] ?></h2>
<table width="100%"><tr><td class="text">
<?php
	if (!$postcount = $Cache->get_value('total_posts_count')){
		$postcount = get_row_count("posts");
		$Cache->cache_value('total_posts_count', $postcount, 96400);
	}
	if (!$topiccount = $Cache->get_value('total_topics_count')){
		$topiccount = get_row_count("topics");
		$Cache->cache_value('total_topics_count', $topiccount, 96500);
	}
	if (!$todaypostcount = $Cache->get_value('today_'.$today_date.'_posts_count')) {
		$todaypostcount = get_row_count("posts", "WHERE added > ".sqlesc(date("Y-m-d")));
		$Cache->cache_value('today_'.$today_date.'_posts_count', $todaypostcount, 700);
	}
	print($lang_forums['text_our_members_have'] ."<b>".$postcount."</b>". $lang_forums['text_posts_in_topics']."<b>".$topiccount."</b>".$lang_forums['text_in_topics']."<b><font class=\"new\">".$todaypostcount."</font></b>".$lang_forums['text_new_post'].add_s($todaypostcount).$lang_forums['text_posts_today']."<br /><br />");
	print($forumusers);
?>
</td></tr></table>
<?php
}

//set all topics as read
function catch_up()
{
	global $CURUSER, $Cache;

	if (!$CURUSER)
		die;
	sql_query("DELETE FROM readposts WHERE userid=".sqlesc($CURUSER['id']));
	$Cache->delete_value('user_'.$CURUSER['id'].'_last_read_post_list');
	$lastpostid=get_single_value("posts","id","ORDER BY id DESC");
	if ($lastpostid){
		$CURUSER['last_catchup'] = $lastpostid;
		sql_query("UPDATE users SET last_catchup = ".sqlesc($lastpostid)." WHERE id=".sqlesc($CURUSER['id']));
	}
}

//return image
function get_topic_image($status= "read"){
	global $lang_forums;
	switch($status){
		case "read": {
			return "<img class=\"unlocked\" src=\"pic/trans.gif\" alt=\"read\" title=\"".$lang_forums['title_read']."\" />";
			break;
			}
		case "unread": {
			return "<img class=\"unlockednew\" src=\"pic/trans.gif\" alt=\"unread\" title=\"".$lang_forums['title_unread']."\" />";
			break;
		}
		case "locked": {
			return "<img class=\"locked\" src=\"pic/trans.gif\" alt=\"locked\" title=\"".$lang_forums['title_locked']."\" />";
			break;
		}
		case "lockednew": {
			return "<img class=\"lockednew\" src=\"pic/trans.gif\" alt=\"lockednew\" title=\"".$lang_forums['title_locked_new']."\" />";
			break;
		}
	}
}

function highlight_topic($subject, $hlcolor=0)
{
	$colorname=get_hl_color($hlcolor);
	if ($colorname)
		$subject = "<b><font color=\"".$colorname."\">".$subject."</font></b>";
	return $subject;
}

function check_whether_exist($id, $place='forum'){
	global $lang_forums;
	int_check($id,true);
	switch ($place){
		case 'forum':
		{
			$count = get_row_count("forums","WHERE id=".sqlesc($id));
			if (!$count)
				stderr($lang_forums['std_error'],$lang_forums['std_no_forum_id']);
			break;
		}
		case 'topic':
		{
			$count = get_row_count("topics","WHERE id=".sqlesc($id));
			if (!$count)
				stderr($lang_forums['std_error'],$lang_forums['std_bad_topic_id']);
			$forumid = get_single_value("topics","forumid","WHERE id=".sqlesc($id));
			check_whether_exist($forumid, 'forum');
			break;
		}
		case 'post':
		{
			$count = get_row_count("posts","WHERE id=".sqlesc($id));
			if (!$count)
				stderr($lang_forums['std_error'],$lang_forums['std_no_post_id']);
			$topicid = get_single_value("posts","topicid","WHERE id=".sqlesc($id));
			check_whether_exist($topicid, 'topic');
			break;
		}
	}
}

//update the last post of a topic
function update_topic_last_post($topicid)
{
	global $lang_forums;
	$res = sql_query("SELECT id FROM posts WHERE topicid=".sqlesc($topicid)." ORDER BY id DESC LIMIT 1") or sqlerr(__FILE__, __LINE__);
	$arr = mysql_fetch_row($res) or die($lang_forums['std_no_post_found']);
	$postid = $arr[0];
	sql_query("UPDATE topics SET lastpost=".sqlesc($postid)." WHERE id=".sqlesc($topicid)) or sqlerr(__FILE__, __LINE__);
}

function get_forum_row($forumid = 0)
{
	global $Cache;
	if (!$forums = $Cache->get_value('forums_list')){
		$forums = array();
		$res2 = sql_query("SELECT * FROM forums ORDER BY forid ASC, sort ASC") or sqlerr(__FILE__, __LINE__);
		while ($row2 = mysql_fetch_array($res2))
			$forums[$row2['id']] = $row2;
		$Cache->cache_value('forums_list', $forums, 86400);
	}
	if (!$forumid)
		return $forums;
	else return $forums[$forumid];
}
function get_last_read_post_id($topicid) {
	global $CURUSER, $Cache;
	static $ret;
	if (!$ret && !$ret = $Cache->get_value('user_'.$CURUSER['id'].'_last_read_post_list')){
		$ret = array();
		$res = sql_query("SELECT * FROM readposts WHERE userid=" . sqlesc($CURUSER['id']));
		if (mysql_num_rows($res) != 0){
			while ($row = mysql_fetch_array($res))
			$ret[$row['topicid']] = $row['lastpostread'];
			$Cache->cache_value('user_'.$CURUSER['id'].'_last_read_post_list', $ret, 900);
		}
		else $Cache->cache_value('user_'.$CURUSER['id'].'_last_read_post_list', 'no record', 900);
	}
	if ($ret != "no record" && isset($ret[$topicid]) && $CURUSER['last_catchup'] < $ret[$topicid]){
		return $ret[$topicid];
	}
	elseif ($CURUSER['last_catchup'])
		return $CURUSER['last_catchup'];
	else return 0;
}

function forum_posts_support_anonymous(): bool
{
	static $support = null;
	if ($support === null) {
		$support = \Nexus\Database\NexusDB::hasColumn('posts', 'anonymous');
	}
	return $support;
}

function forum_post_is_anonymous($post): bool
{
	return forum_posts_support_anonymous() && (($post['anonymous'] ?? 'no') === 'yes');
}

function forum_can_view_anonymous_author($post): bool
{
	global $CURUSER;
	$posterId = intval($post['userid'] ?? 0);
	return $posterId > 0
		&& (intval($CURUSER['id'] ?? 0) === $posterId || get_user_class() >= UC_ADMINISTRATOR || user_can('postmanage'));
}

function forum_anonymous_name(): string
{
	return "<i class=\"forum-post-anonymous\">匿名用户</i>";
}

function forum_strip_username_medals($usernameHtml): string
{
	return preg_replace('/<img\b[^>]*\bnexus-username-medal(?:-big)?\b[^>]*>/i', '', (string)$usernameHtml);
}

function forum_post_author_name($post, $withAnonymousMarker = false): string
{
	$posterId = intval($post['userid'] ?? 0);
	if (forum_post_is_anonymous($post)) {
		if (!forum_can_view_anonymous_author($post)) {
			return forum_anonymous_name();
		}
		$name = forum_strip_username_medals(get_username($posterId,false,true,true,false,false,true));
		return $withAnonymousMarker ? $name . " <span class=\"forum-post-anonymous-note\">(匿名)</span>" : $name;
	}
	return forum_strip_username_medals(get_username($posterId,false,true,true,false,false,true));
}

function forum_posts_support_reply_to(): bool
{
	static $support = null;
	if ($support === null) {
		$support = \Nexus\Database\NexusDB::hasColumn('posts', 'reply_to_post_id');
		if (!$support) {
			@sql_query("ALTER TABLE posts ADD COLUMN reply_to_post_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0 AFTER userid, ADD KEY posts_reply_to_post_id_index (reply_to_post_id)");
			$support = \Nexus\Database\NexusDB::hasColumn('posts', 'reply_to_post_id');
		}
	}
	return $support;
}

function forum_reply_root_post_id($postId, $topicId): int
{
	if (!forum_posts_support_reply_to()) {
		return (int)$postId;
	}
	$postId = (int)$postId;
	$topicId = (int)$topicId;
	$seen = [];
	for ($i = 0; $i < 20 && $postId > 0 && empty($seen[$postId]); $i++) {
		$seen[$postId] = true;
		$res = sql_query("SELECT id, reply_to_post_id FROM posts WHERE id=" . sqlesc($postId) . " AND topicid=" . sqlesc($topicId) . " LIMIT 1") or sqlerr(__FILE__, __LINE__);
		$row = mysql_fetch_assoc($res);
		if (!$row) {
			break;
		}
		$parentId = (int)($row['reply_to_post_id'] ?? 0);
		if ($parentId <= 0) {
			return (int)$row['id'];
		}
		$postId = $parentId;
	}
	return (int)$postId;
}

function forum_render_user_medals($user): string
{
	if (!$user || !$user->relationLoaded('wearing_medals') || $user->wearing_medals->isEmpty()) {
		return '';
	}

	$html = '<div class="forum-user-medals">';
	foreach ($user->wearing_medals as $medal) {
		$image = trim((string)($medal->image_small ?: $medal->image_large));
		if ($image === '') {
			continue;
		}
		$name = htmlspecialchars((string)$medal->name, ENT_QUOTES);
		$html .= '<img class="forum-user-medal" src="' . htmlspecialchars($image, ENT_QUOTES) . '" alt="' . $name . '" title="' . $name . '" />';
	}
	$html .= '</div>';

	return $html === '<div class="forum-user-medals"></div>' ? '' : $html;
}

function forum_inline_quote_body($post): string
{
	$author = forum_post_is_anonymous($post) && !forum_can_view_anonymous_author($post) ? '匿名用户' : get_single_value("users", "username", "WHERE id=" . sqlesc($post['userid']));
	return "[quote=" . htmlspecialchars($author) . "]" . htmlspecialchars(unesc($post["body"])) . "[/quote]\n";
}

function forum_render_inline_reply_form($post, $topicId): string
{
	global $lang_functions;
	$postId = (int)$post['id'];
	$formName = "replypost" . $postId;
	$textareaId = "replybody" . $postId;
	$submitId = "qrpost" . $postId;
	$body = htmlspecialchars(forum_inline_quote_body($post));
	$html = "<div id=\"forum-inline-reply-" . $postId . "\" class=\"forum-inline-reply\" style=\"display: none;\">";
	$html .= "<form id=\"" . $formName . "\" name=\"" . $formName . "\" method=\"post\" action=\"?action=post\">";
	$html .= "<input type=\"hidden\" name=\"id\" value=\"" . (int)$topicId . "\" />";
	$html .= "<input type=\"hidden\" name=\"type\" value=\"reply\" />";
	$html .= "<input type=\"hidden\" name=\"postid\" value=\"" . $postId . "\" />";
	$html .= "<textarea class=\"forum-inline-reply-body\" name=\"body\" id=\"" . $textareaId . "\" rows=\"8\" onkeydown=\"ctrlenter(event,'" . $formName . "','" . $submitId . "')\">" . $body . "</textarea>";
	$html .= smile_row($formName, "body");
	if (forum_posts_support_anonymous()) {
		$html .= "<label class=\"forum-anonymous-option\"><input type=\"checkbox\" name=\"anonymous\" value=\"yes\" /> 匿名发表</label>";
	}
	$html .= "<div class=\"forum-inline-reply-actions\"><a class=\"btn forum-inline-advanced\" href=\"" . htmlspecialchars("?action=quotepost&postid=" . $postId) . "\">高级模式</a>";
	$html .= "<input type=\"submit\" id=\"" . $submitId . "\" class=\"btn\" value=\"" . $lang_functions['submit_submit'] . "\" />";
	$html .= "<input type=\"button\" class=\"btn2\" value=\"取消\" onclick=\"return forumCancelInlineReply(" . $postId . ");\" /></div>";
	$html .= "</form></div>";
	return $html;
}

function forum_inline_reply_link($postId, $title): string
{
	$postId = (int)$postId;
	return "<a href=\"" . htmlspecialchars("?action=quotepost&postid=" . $postId) . "\" onclick=\"return forumToggleInlineReply(" . $postId . ");\"><img class=\"f_quote\" src=\"pic/trans.gif\" alt=\"Quote\" title=\"" . $title . "\" /></a>";
}

//-------- Inserts a compose frame
function insert_compose_frame($id, $type = 'new')
{
	global $maxsubjectlength, $CURUSER;
	global $lang_forums;
	$hassubject = false;
	$subject = "";
	$body = "";
	print("<form id=\"compose\" method=\"post\" name=\"compose\" action=\"?action=post\">\n");
	switch ($type){
		case 'new':
		{
			$forumname = get_single_value("forums","name","WHERE id=".sqlesc($id));
			$title = $lang_forums['text_new_topic_in']." <a href=\"".htmlspecialchars("?action=viewforum&forumid=".$id)."\">".htmlspecialchars($forumname)."</a> ".$lang_forums['text_forum'];
			$hassubject = true;
			break;
		}
		case 'reply':
		{
			$topicname = get_single_value("topics","subject","WHERE id=".sqlesc($id));
			$title = $lang_forums['text_reply_to_topic']." <a href=\"".htmlspecialchars("?action=viewtopic&topicid=".$id)."\">".htmlspecialchars($topicname)."</a> ";
			break;
		}
		case 'quote':
		{
			$topicid=get_single_value("posts","topicid","WHERE id=".sqlesc($id));
			$topicname = get_single_value("topics","subject","WHERE id=".sqlesc($topicid));
			$title = $lang_forums['text_reply_to_topic']." <a href=\"".htmlspecialchars("?action=viewtopic&topicid=".$topicid)."\">".htmlspecialchars($topicname)."</a> ";
			$anonymousSelect = forum_posts_support_anonymous() ? ", posts.anonymous" : "";
			$res = sql_query("SELECT posts.body, posts.userid$anonymousSelect, users.username FROM posts LEFT JOIN users ON posts.userid = users.id WHERE posts.id=$id") or sqlerr(__FILE__, __LINE__);
			if (mysql_num_rows($res) != 1)
				stderr($lang_forums['std_error'], $lang_forums['std_no_post_id']);
			$arr = mysql_fetch_assoc($res);
			$quoteAuthor = forum_post_is_anonymous($arr) && !forum_can_view_anonymous_author($arr) ? '匿名用户' : $arr["username"];
			$body = "[quote=".htmlspecialchars($quoteAuthor)."]".htmlspecialchars(unesc($arr["body"]))."[/quote]";
			$postid = $id;
			$id = $topicid;
			$type = 'reply';
			print("<input type=\"hidden\" name=\"postid\" value=\"".$postid."\" />");
			break;
		}
		case 'edit':
		{
			$res = sql_query("SELECT * FROM posts WHERE id=".sqlesc($id)." LIMIT 1") or sqlerr(__FILE__, __LINE__);
			$row = mysql_fetch_array($res);
			$topicid=$row['topicid'];
			$firstpost = get_single_value("posts","MIN(id)", "WHERE topicid=".sqlesc($topicid));
			if ($firstpost == $id){
				$subject = get_single_value("topics","subject","WHERE id=".sqlesc($topicid));
				$hassubject = true;
			}
			$body = htmlspecialchars(unesc($row["body"]));
			$title = $lang_forums['text_edit_post'];
			$isAnonymous = forum_post_is_anonymous($row);
			break;
		}
		default:
		{
			die;
		}
	}
	print("<input type=\"hidden\" name=\"id\" value=\"".$id."\" />");
	print("<input type=\"hidden\" name=\"type\" value=\"".$type."\" />");
	begin_compose($title, $type, $body, $hassubject, $subject, $maxsubjectlength, $isAnonymous ?? false);
	end_compose();
	print("</form>");
}
// ------------- end: functions ------------------//
// ------------- start: Global variables ------------------//
$maxsubjectlength = 100;
$postsperpage = $CURUSER["postsperpage"];
if (!$postsperpage){
	if (is_numeric($forumpostsperpage))
		$postsperpage = $forumpostsperpage;//system-wide setting
	else $postsperpage = 10;
}
//get topics per page
$topicsperpage = $CURUSER["topicsperpage"];
if (!$topicsperpage){
	if (is_numeric($forumtopicsperpage_main))
		$topicsperpage = $forumtopicsperpage_main;//system-wide setting
	else $topicsperpage = 20;
}
$today_date = date("Y-m-d",TIMENOW);
// ------------- end: Global variables ------------------//

$action = htmlspecialchars(trim($_GET["action"] ?? ''));

// 手机端：套用与首页一致的手机外壳；?pc=1 强制电脑版。设置 f_mhead/f_mfoot 包装 stdhead/stdfoot。
$GLOBALS['F_MOBILE'] = empty($_GET['pc']) && preg_match('/Mobile|Android|iPhone|iPod|Windows Phone|BlackBerry|webOS|HarmonyOS/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
if ($GLOBALS['F_MOBILE']) { require_once ROOT_PATH . 'include/mobile_shell.php'; }
function f_mhead($title = '') {
    if (!empty($GLOBALS['F_MOBILE']) && function_exists('mobile_shell_page_head')) {
        mobile_shell_page_head(trim(strip_tags((string)$title)) ?: '论坛', 'forums', 'page-forums');
        echo '<link rel="stylesheet" type="text/css" href="styles/forums-mobile.css?v=20260701i">';
        echo '<script type="text/javascript" src="js/jquery-1.12.4.min.js"></script>';
        echo '<script>jQuery.noConflict();window.nexusLayerOptions={confirm:{btnAlign:"c",title:"Confirm",btn:["OK","Cancel"]},alert:{btnAlign:"c",title:"Info",btn:["OK","Cancel"]}};</script>';
        echo '<script type="text/javascript" src="vendor/layer-v3.5.1/layer/layer.js"></script>';
        echo '<script type="text/javascript" src="js/common.js"></script>';
        echo '<script type="text/javascript" src="js/ajaxbasic.js"></script>';
    } else {
        stdhead($title);
    }
}
function f_mfoot() {
    if (!empty($GLOBALS['F_MOBILE']) && function_exists('mobile_shell_page_foot')) {
        foreach (\Nexus\Nexus::getAppendFooters() as $v) { print($v); }
        mobile_shell_page_foot('forums');
    } else {
        stdfoot();
    }
}
// 手机端：返回带等级配色的用户名(<span>，非<a>，避免嵌套在卡片链接里把布局撑破)。
function f_author_html($uid, $anon, $post) {
    if ($anon) { return forum_strip_username_medals(forum_post_author_name($post, false)); }
    $arr = get_user_row((int)$uid);
    if (!$arr) { return '<span class="User_Name">' . htmlspecialchars((string)($post['username'] ?? '')) . '</span>'; }
    $cls = get_user_class_name($arr['class'], true, false, false) . '_Name';
    return '<span class="' . $cls . '"><b>' . htmlspecialchars($arr['username']) . '</b></span>';
}

//-------- Action: New topic
if ($action == "newtopic")
{
	$forumid = intval($_GET["forumid"] ?? 0);
	check_whether_exist($forumid, 'forum');
	f_mhead($lang_forums['head_new_topic']);
	begin_main_frame();
	insert_compose_frame($forumid,'new');
	end_main_frame();
	f_mfoot();
	die;
}
if ($action == "quotepost")
{
	$postid = intval($_GET["postid"] ?? 0);
	check_whether_exist($postid, 'post');
    if (!can_view_post($CURUSER['id'], $postid)) {
        permissiondenied();
    }
	f_mhead($lang_forums['head_post_reply']);
	begin_main_frame();
	insert_compose_frame($postid, 'quote');
	end_main_frame();
	f_mfoot();
	die;
}

//-------- Action: Reply

if ($action == "reply")
{
	$topicid = intval($_GET["topicid"] ?? 0);
	check_whether_exist($topicid, 'topic');
	f_mhead($lang_forums['head_post_reply']);
	begin_main_frame();
	insert_compose_frame($topicid, 'reply');
	end_main_frame();
	f_mfoot();
	die;
}

//-------- Action: Edit post

if ($action == "editpost")
{
	$postid = intval($_GET["postid"] ?? 0);
	check_whether_exist($postid, 'post');

	$res = sql_query("SELECT userid, topicid FROM posts WHERE id=".sqlesc($postid)) or sqlerr(__FILE__, __LINE__);
	$arr = mysql_fetch_assoc($res);

	$res2 = sql_query("SELECT locked FROM topics WHERE id = " . $arr["topicid"]) or sqlerr(__FILE__, __LINE__);
	$arr2 = mysql_fetch_assoc($res2);
	$locked = ($arr2["locked"] == 'yes');

	$ismod = is_forum_moderator($postid, 'post');
	if (($CURUSER["id"] != $arr["userid"] || $locked) && !user_can('postmanage') && !$ismod)
		permissiondenied();

	f_mhead($lang_forums['text_edit_post']);
	begin_main_frame();
	insert_compose_frame($postid, 'edit');
	end_main_frame();
	f_mfoot();
	die;
}

//-------- Action: Post
if ($action == "post")
{
	if ($CURUSER["forumpost"] == 'no')
	{
		stderr($lang_forums['std_sorry'], $lang_forums['std_unauthorized_to_post'],false);
		die;
	}
	$id = $_POST["id"];
	$type = $_POST["type"];
	$subject = $_POST["subject"] ?? '';
	$body = trim($_POST["body"]);
	$hassubject = false;
	switch ($type){
		case 'new':
		{
			check_whether_exist($id, 'forum');
			$forumid = $id;
			$hassubject = true;
			break;
		}
		case 'reply':
		{
			check_whether_exist($id, 'topic');
			$topicid = $id;
			$forumid = get_single_value("topics", "forumid", "WHERE id=".sqlesc($topicid));
			$quotepostid = intval($_POST["postid"] ?? 0);
			if ($quotepostid > 0) {
				$quoteTopicId = (int)get_single_value("posts", "topicid", "WHERE id=" . sqlesc($quotepostid));
				if ($quoteTopicId !== (int)$topicid) {
					$quotepostid = 0;
				}
			}
			break;
		}
		case 'edit':
		{
			check_whether_exist($id, 'post');
			$res = sql_query("SELECT topicid FROM posts WHERE id=".sqlesc($id)." LIMIT 1") or sqlerr(__FILE__, __LINE__);
			$row = mysql_fetch_array($res);
			$topicid=$row['topicid'];
			$forumid = get_single_value("topics", "forumid", "WHERE id=".sqlesc($topicid));
			$firstpost = get_single_value("posts","MIN(id)", "WHERE topicid=".sqlesc($topicid));
			if ($firstpost == $id){
				$hassubject = true;
			}
			break;
		}
		default:
		{
			die;
		}
	}

	if ($hassubject){
		$subject = trim($subject);
		if (!$subject)
			stderr($lang_forums['std_error'], $lang_forums['std_must_enter_subject']);
		if (strlen($subject) > $maxsubjectlength)
			stderr($lang_forums['std_error'], $lang_forums['std_subject_limited']);
	}

	//------ Make sure sure user has write access in forum
	$arr = get_forum_row($forumid) or die($lang_forums['std_bad_forum_id']);

	if (
	    get_user_class() < $arr["minclassread"]
        || get_user_class() < $arr["minclasswrite"]
        || ($type =='new' && get_user_class() < $arr["minclasscreate"])
    ) {
        permissiondenied();
    }
	if ($body == "")
		stderr($lang_forums['std_error'], $lang_forums['std_no_body_text']);

	$userid = intval($CURUSER["id"] ?? 0);
	$date = date("Y-m-d H:i:s");
	$anonymous = (($_POST['anonymous'] ?? '') === 'yes') ? 'yes' : 'no';

	if ($type != 'new'){
		//---- Make sure topic is unlocked

		$res = sql_query("SELECT locked FROM topics WHERE id=$topicid") or sqlerr(__FILE__, __LINE__);
		$arr = mysql_fetch_assoc($res) or die("Topic id n/a");
		if ($arr["locked"] == 'yes' && !user_can('postmanage') && !is_forum_moderator($topicid, 'topic'))
			stderr($lang_forums['std_error'], $lang_forums['std_topic_locked']);
	}

	$replyToPostId = 0;
	if ($type == 'edit')
	{
        $postid = $id;
        $topicInfo = \App\Models\Topic::query()->findOrFail($topicid);
        $postInfo = \App\Models\Post::query()->findOrFail($id);
        if ($postInfo->userid != $CURUSER['id'] && !is_forum_moderator($postid, 'post') && !user_can('postmanage')) {
            permissiondenied();
        }
		if ($hassubject){
			sql_query("UPDATE topics SET subject=".sqlesc($subject)." WHERE id=".sqlesc($topicid)) or sqlerr(__FILE__, __LINE__);
			$forum_last_replied_topic_row = $Cache->get_value('forum_'.$forumid.'_last_replied_topic_content');
			if ($forum_last_replied_topic_row && $forum_last_replied_topic_row['id'] == $topicid)
				$Cache->delete_value('forum_'.$forumid.'_last_replied_topic_content');
		}
		$anonymousUpdate = forum_posts_support_anonymous() ? ", anonymous=".sqlesc($anonymous) : "";
		sql_query("UPDATE posts SET body=".sqlesc($body).", editdate=".sqlesc($date).", editedby=".sqlesc($CURUSER['id']).$anonymousUpdate." WHERE id=".sqlesc($id)) or sqlerr(__FILE__, __LINE__);
		$Cache->delete_value('post_'.$postid.'_content');
        //send pm
        $postUrl = sprintf('[url=forums.php?action=viewtopic&topicid=%s&page=p%s#pid%s]%s[/url]', $topicid, $id, $id, $topicInfo->subject);
        if (!empty($postInfo->userid) && $postInfo->userid != $CURUSER['id']) {
            $receiver = $postInfo->user;
            if ($receiver) {
                $locale = $receiver->locale;
                $notify = [
                    'sender' => 0,
                    'receiver' => $receiver->id,
                    'subject' => nexus_trans('forum.post.edited_notify_subject', [], $locale),
                    'msg' => nexus_trans('forum.post.edited_notify_body', ['topic_subject' => $postUrl, 'editor' => $CURUSER['username']], $locale),
                    'added' => now(),
                ];
                \App\Models\Message::add($notify);
            }
        }
	}
	else
	{
		// Anti Flood Code
		// To ensure that posts are not entered within 10 seconds limiting posts
		// to a maximum of 360*6 per hour.
		if (!user_can('postmanage')) {
			if (strtotime($CURUSER['last_post']) > (TIMENOW - 10))
			{
				$secs = 10 - (TIMENOW - strtotime($CURUSER['last_post']));
				stderr($lang_forums['std_error'],$lang_forums['std_post_flooding'].$secs.$lang_forums['std_seconds_before_making'],false);
			}
		}
		if ($type == 'new'){ //new topic
			//add bonus
			KPS("+",$starttopic_bonus,$userid);

			//---- Create topic
			sql_query("INSERT INTO topics (userid, forumid, subject) VALUES($userid, $forumid, ".sqlesc($subject).")") or sqlerr(__FILE__, __LINE__);
			$topicid = mysql_insert_id() or stderr($lang_forums['std_error'],$lang_forums['std_no_topic_id_returned']);
			sql_query("UPDATE forums SET topiccount=topiccount+1, postcount=postcount+1 WHERE id=".sqlesc($forumid));
		}
		else // new post
		{
			//add bonus
			KPS("+",$makepost_bonus,$userid);
			sql_query("UPDATE forums SET postcount=postcount+1 WHERE id=".sqlesc($forumid));
		}

		$replyToPostId = ($type == 'reply' && !empty($quotepostid) && forum_posts_support_reply_to()) ? (int)$quotepostid : 0;
		if (forum_posts_support_anonymous() && forum_posts_support_reply_to()) {
			sql_query("INSERT INTO posts (topicid, userid, reply_to_post_id, added, body, ori_body, anonymous) VALUES ($topicid, $userid, $replyToPostId, ".sqlesc($date).", ".sqlesc($body).", ".sqlesc($body).", ".sqlesc($anonymous).")") or sqlerr(__FILE__, __LINE__);
		} elseif (forum_posts_support_anonymous()) {
			sql_query("INSERT INTO posts (topicid, userid, added, body, ori_body, anonymous) VALUES ($topicid, $userid, ".sqlesc($date).", ".sqlesc($body).", ".sqlesc($body).", ".sqlesc($anonymous).")") or sqlerr(__FILE__, __LINE__);
		} elseif (forum_posts_support_reply_to()) {
			sql_query("INSERT INTO posts (topicid, userid, reply_to_post_id, added, body, ori_body) VALUES ($topicid, $userid, $replyToPostId, ".sqlesc($date).", ".sqlesc($body).", ".sqlesc($body).")") or sqlerr(__FILE__, __LINE__);
		} else {
			sql_query("INSERT INTO posts (topicid, userid, added, body, ori_body) VALUES ($topicid, $userid, ".sqlesc($date).", ".sqlesc($body).", ".sqlesc($body).")") or sqlerr(__FILE__, __LINE__);
		}
		$postid = mysql_insert_id() or die($lang_forums['std_post_id_not_available']);
		//send pm
        $topicInfo = \App\Models\Topic::query()->findOrFail($topicid);
        $postUrl = sprintf('[url=forums.php?action=viewtopic&topicid=%s&page=p%s#pid%s]%s[/url]', $topicid, $postid, $postid, $topicInfo->subject);

		if ($type == 'reply') {
			/** @var \App\Models\User $receiver */
			if (!empty($topicInfo->userid) && $topicInfo->userid != $CURUSER['id'])
			{
				$receiver = $topicInfo->user;
				if ($receiver && $receiver->acceptNotification('topic_reply')) {
					$locale = $receiver->locale;
					$notify = [
						'sender' => 0,
						'receiver' => $receiver->id,
						'subject' => nexus_trans('forum.topic.replied_notify_subject', [], $locale),
						'msg' => nexus_trans('forum.topic.replied_notify_body', ['topic_subject' => $postUrl], $locale),
						'added' => now(),
					];
                    \App\Models\Message::add($notify);
				}
			}

            if (!empty($quotepostid)) {
                $quotePostInfo = \App\Models\Post::query()->find($quotepostid);
                if ($quotePostInfo && $quotePostInfo->userid != $CURUSER['id']) {
                    $receiver = $quotePostInfo->user;
                    if($receiver && $receiver->acceptNotification('topic_reply')) {
                        $locale = $receiver->locale;
                        $notify = [
                            'sender' => 0,
                            'receiver' => $receiver->id,
                            'subject' => nexus_trans('forum.reply.replied_notify_subject', [], $locale),
                            'msg' => nexus_trans('forum.reply.replied_notify_body', ['topic_subject' => $postUrl, 'replyer' => $CURUSER['username']], $locale),
                            'added' => now(),
                        ];
                        \App\Models\Message::add($notify);
                    }
                }
            }
        }

		$Cache->delete_value('forum_'.$forumid.'_post_'.$today_date.'_count');
		$Cache->delete_value('today_'.$today_date.'_posts_count');
		$Cache->delete_value('forum_'.$forumid.'_last_replied_topic_content');
		$Cache->delete_value('topic_'.$topicid.'_post_count');
		$Cache->delete_value('user_'.$userid.'_post_count');

		if ($type == 'new')
		{
			// update the first post of topic
			sql_query("UPDATE topics SET firstpost=$postid, lastpost=$postid WHERE id=".sqlesc($topicid)) or sqlerr(__FILE__, __LINE__);
		}
		else
		{
			sql_query("UPDATE topics SET lastpost=$postid WHERE id=".sqlesc($topicid)) or sqlerr(__FILE__, __LINE__);
		}
		sql_query("UPDATE users SET last_post=".sqlesc($date)." WHERE id=".sqlesc($CURUSER['id'])) or sqlerr(__FILE__, __LINE__);
	}

	//------ All done, redirect user to the post

	$headerstr = "Location: " . get_protocol_prefix() . "$BASEURL/forums.php?action=viewtopic&topicid=$topicid";

	if ($type == 'edit')
		header($headerstr."&page=p".$postid."#pid".$postid);
	elseif ($replyToPostId > 0)
		header($headerstr."&page=p".$postid."#pid".$postid);
	else
		header($headerstr."&page=last#pid$postid");
	die;
}

//-------- Action: View topic

if ($action == "viewtopic")
{
	$highlight = htmlspecialchars(trim($_GET["highlight"] ?? ''));

	$topicid = intval($_GET["topicid"] ?? 0);
	int_check($topicid,true);
	$page = $_GET["page"] ?? 0;
	$authorid = intval($_GET["authorid"] ?? 0);
	if ($authorid)
	{
		$where = "WHERE topicid=".sqlesc($topicid)." AND userid=".sqlesc($authorid);
		$addparam = "action=viewtopic&topicid=".$topicid."&authorid=".$authorid;
	}
	else
	{
		$where = "WHERE topicid=".sqlesc($topicid);
		$addparam = "action=viewtopic&topicid=".$topicid;
	}
	// Per-user remembered floor sort (default desc). An explicit ?psort in the URL
	// (clicking the toggle) overrides and is persisted back to the user's preference,
	// so a refresh or opening another topic keeps the chosen order.
	$qdSortSaved = \App\Models\UserMeta::query()
		->where('uid', $CURUSER['id'])
		->where('meta_key', 'FORUM_POST_SORT')
		->value('meta_value');
	$qdSortSaved = ($qdSortSaved === 'asc') ? 'asc' : 'desc';
	if (isset($_GET['psort'])) {
		$psort = ($_GET['psort'] === 'asc') ? 'asc' : 'desc';
		if ($psort !== $qdSortSaved) {
			\App\Models\UserMeta::query()->updateOrCreate(
				['uid' => $CURUSER['id'], 'meta_key' => 'FORUM_POST_SORT'],
				['meta_value' => $psort, 'status' => \App\Models\UserMeta::STATUS_NORMAL, 'deadline' => null]
			);
		}
	} else {
		$psort = $qdSortSaved;
	}
	$psortSql = $psort === 'asc' ? 'ASC' : 'DESC';
	$addparam .= '&psort=' . $psort;
	$firstPostId = (int)get_single_value("posts", "MIN(id)", "WHERE topicid=".sqlesc($topicid));
	$postOrderBy = ($psort === 'desc') ? "(id = $firstPostId) DESC, id DESC" : "id ASC";
	$userid = $CURUSER["id"];
	$threadedReplies = !$authorid && forum_posts_support_reply_to();
	$rootReplyWhere = "WHERE topicid=".sqlesc($topicid)." AND (reply_to_post_id = 0 OR reply_to_post_id IS NULL)";

	//------ Get topic info

	$res = sql_query("SELECT * FROM topics WHERE id=".sqlesc($topicid)." LIMIT 1") or sqlerr(__FILE__, __LINE__);
	$arr = mysql_fetch_assoc($res) or stderr($lang_forums['std_forum_error'], $lang_forums['std_topic_not_found']);

	$forumid = $arr['forumid'];
	$locked = $arr['locked'] == "yes";
	$orgsubject = $arr['subject'];
	$subject = htmlspecialchars($arr['subject']);
	if ($highlight){
		$subject = highlight($highlight,$orgsubject);
	}
	$sticky = $arr['sticky'] == "yes";
	$hlcolor = $arr['hlcolor'];
	$views = $arr['views'];
	$forumid = $arr["forumid"];
	$base_posterid = $arr['userid'];

	$row = get_forum_row($forumid);
	//------ Get forum name, moderators
	$forumname = $row['name'];
	$is_forummod = is_forum_moderator($forumid,'forum');

	if (get_user_class() < $row["minclassread"])
		stderr($lang_forums['std_error'], $lang_forums['std_unpermitted_viewing_topic']);
	if (((get_user_class() >= $row["minclasswrite"] && !$locked) || user_can('postmanage') || $is_forummod) && $CURUSER["forumpost"] == 'yes')
		$maypost = true;
	else $maypost = false;

	//------ Update hits column
	sql_query("UPDATE topics SET views = views + 1 WHERE id=$topicid") or sqlerr(__FILE__, __LINE__);

	//------ Get post count
	$postcount = get_row_count("posts", $threadedReplies ? $rootReplyWhere : $where);
	if (!$authorid)
		$Cache->cache_value('topic_'.$topicid.'_post_count', $postcount, 3600);

	//------ Make page menu

	$pagerarr = array();

	$perpage = $postsperpage;

	$pages = ceil($postcount / $perpage);

	if (isset($page[0]) && $page[0] == "p")
	{
		$findpost = substr($page, 1);
		if ($threadedReplies) {
			$findpost = forum_reply_root_post_id($findpost, $topicid);
		}
		$res = sql_query("SELECT id FROM posts " . ($threadedReplies ? $rootReplyWhere : $where) . " ORDER BY $postOrderBy") or sqlerr(__FILE__, __LINE__);
		$i = 0;
		while ($arr = mysql_fetch_row($res))
		{
			if ($arr[0] == $findpost)
			break;
			++$i;
		}
		$page = floor($i / $perpage);
	}
	if ($page === "last"){
	$page = $pages-1;
	}
	elseif(isset($page))
	{
		if($page < 0){
		$page = 0;
		}
		elseif ($page > $pages - 1){
		$page = $pages - 1;
		}
	}
	else {if ($psort === 'desc' || $CURUSER["clicktopic"] == "firstpage")
		$page = 0;
		else $page = $pages-1;
	}

	$offset = $page * $perpage;
	$dotted = 0;
	$dotspace = 3;
	$dotend = $pages - $dotspace;
	$curdotend = $page - $dotspace;
	$curdotstart = $page + $dotspace;
	for ($i = 0; $i < $pages; ++$i)
	{
		if (($i >= $dotspace && $i <= $curdotend) || ($i >= $curdotstart && $i < $dotend)) {
				if (!$dotted)
				$pagerarr[] = "...";
				$dotted = 1;
				continue;
		}
		$dotted = 0;
		if ($i != $page)
		$pagerarr[] .= "<a href=\"".htmlspecialchars("?".$addparam."&page=".$i)."\"><b>".($i+1)."</b></a>\n";
		else
		$pagerarr[] .= "<font class=\"gray\"><b>".($i+1)."</b></font>\n";
	}
	if ($page == 0)
	$pager = "<font class=\"gray\"><b>&lt;&lt;".$lang_forums['text_prev']."</b></font>";
	else
	$pager = "<a href=\"".htmlspecialchars("?".$addparam."&page=" . ($page - 1)) .
	"\"><b>&lt;&lt;".$lang_forums['text_prev']."</b></a>";
	$pager .= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
	if ($page == $pages-1)
	$pager .= "<font class=\"gray\"><b>".$lang_forums['text_next']." &gt;&gt;</b></font>\n";
	else
	$pager .= "<a href=\"".htmlspecialchars("?".$addparam."&page=" . ($page + 1)) .
	"\"><b>".$lang_forums['text_next']." &gt;&gt;</b></a>\n";

	$pagerstr = join(" | ", $pagerarr);
	$pagertop = "<p align=\"center\">".$pager."<br />".$pagerstr."</p>\n";
	$pagerbottom = "<p align=\"center\">".$pagerstr."<br />".$pager."</p>\n";
	//------ Get posts

	$res = sql_query("SELECT * FROM posts " . ($threadedReplies ? $rootReplyWhere : $where) . " ORDER BY $postOrderBy LIMIT $perpage offset $offset") or sqlerr(__FILE__, __LINE__);

	f_mhead($lang_forums['head_view_topic']." \"".$orgsubject."\"");

	// 手机端：主题帖用紧凑卡片(头像+作者+楼层/时间+正文)，类似触屏版，舍弃电脑版大用户栏
	if (!empty($GLOBALS['F_MOBILE'])) {
		$mPosts = []; $mUids = [];
		while ($p = mysql_fetch_assoc($res)) { $mPosts[] = $p; $mUids[(int)$p['userid']] = 1; }
		// 楼中楼回复：把嵌套子回复也平铺进来
		if ($threadedReplies && $mPosts) {
			$frontier = array_map(fn($x) => (int)$x['id'], $mPosts);
			$seen = array_fill_keys($frontier, true);
			for ($d = 0; $d < 20 && $frontier; $d++) {
				$pin = implode(',', array_map('intval', $frontier));
				$frontier = [];
				$cr = sql_query("SELECT * FROM posts WHERE topicid=".sqlesc($topicid)." AND reply_to_post_id IN ($pin) ORDER BY id") or sqlerr(__FILE__, __LINE__);
				while ($cp = mysql_fetch_assoc($cr)) {
					$cid = (int)$cp['id'];
					if (isset($seen[$cid])) continue;
					$seen[$cid] = true; $frontier[] = $cid;
					$mPosts[] = $cp; $mUids[(int)$cp['userid']] = 1;
				}
			}
			usort($mPosts, fn($a, $b) => ($psort === 'asc' ? (int)$a['id'] - (int)$b['id'] : (int)$b['id'] - (int)$a['id']));
		}
		$mUids = array_keys($mUids);
		$mUserInfo = $mUids ? \App\Models\User::query()->find($mUids, ['id','class','avatar','username','donor','title'])->keyBy('id') : collect();
		// 楼层号 = 该帖在主题“主楼层”(非楼中楼)中按发帖先后的序号；楼中楼不计楼层、不显示
		$floorMap = [];
		$fres = sql_query("SELECT id FROM posts WHERE topicid=" . sqlesc($topicid) . " AND (reply_to_post_id = 0 OR reply_to_post_id IS NULL) ORDER BY id ASC");
		$fi = 0;
		while ($fres && ($fr = mysql_fetch_row($fres))) { $floorMap[(int)$fr[0]] = ++$fi; }
		// 楼主(主题首帖)固定置顶为 1 楼
		$opPost = null; $restPosts = [];
		foreach ($mPosts as $p) { if ((int)$p['id'] === (int)$firstPostId) { $opPost = $p; } else { $restPosts[] = $p; } }
		$displayPosts = $opPost ? array_merge([$opPost], $restPosts) : $restPosts;
		echo '<div class="ft-head"><div class="ft-subject">' . ($sticky ? '<span class="f-tag sticky">置顶</span>' : '') . ($locked ? '<span class="f-tag lock">锁</span>' : '') . highlight_topic($subject, $hlcolor) . '</div>';
		echo '<div class="ft-hits">本主题共 ' . number_format((int)$views) . ' 次浏览' . ($maypost ? ' · <a href="' . htmlspecialchars("?action=reply&topicid=" . $topicid) . '">回复</a>' : '') . '</div></div>';
		$nextSort = $psort === 'desc' ? 'asc' : 'desc';
		$curSortLabel = $psort === 'desc' ? '倒序' : '正序';
		$sortUrl = "?action=viewtopic&topicid=" . $topicid . ($authorid ? "&authorid=" . $authorid : "") . "&psort=" . $nextSort;
		echo '<div class="ft-toolbar"><div class="ft-pager">' . ($pagerstr ?? '') . '</div><a class="ft-sort" href="' . htmlspecialchars($sortUrl) . '">楼层：' . $curSortLabel . ' ⇅</a></div>';
		echo '<div class="ft-posts">';
		foreach ($displayPosts as $p) {
			$puid = (int)$p['userid'];
			$anon = function_exists('forum_post_is_anonymous') && forum_post_is_anonymous($p);
			$u = $mUserInfo->get($puid);
			$pnameHtml = f_author_html($puid, $anon, $p);
			$pname = trim(strip_tags($pnameHtml));
			$pav = (!$anon && $u && !empty($u->avatar)) ? '<img src="' . htmlspecialchars($u->avatar) . '" alt="" onerror="this.style.display=\'none\'">' : '<b>' . htmlspecialchars(mb_substr($pname !== '' ? $pname : '?', 0, 1)) . '</b>';
			$pdate = gettime($p['added'], true, false);
			$isNested = !empty($p['reply_to_post_id']) && (int)$p['reply_to_post_id'] > 0;
			if ((int)$p['id'] === (int)$firstPostId) { $floorLabel = '楼主'; }
			elseif ($isNested) { $floorLabel = ''; }
			else { $floorLabel = (($floorMap[(int)$p['id']] ?? '') !== '' ? $floorMap[(int)$p['id']] . '楼' : ''); }
			$body = format_comment($p['body'], 0);
			echo '<div class="ft-post' . ($isNested ? ' ft-nested' : '') . '"><div class="ft-post-head"><span class="f-ava">' . $pav . '</span>'
				. '<span class="ft-pmeta"><span class="ft-pname">' . $pnameHtml . '</span><span class="ft-pdate">' . $pdate . '</span></span>'
				. ($floorLabel !== '' ? '<span class="ft-floor">' . $floorLabel . '</span>' : '') . '</div>'
				. '<div class="ft-body">' . $body . '</div></div>';
		}
		echo '</div>';
		if (trim((string)($pagerstr ?? '')) !== '') echo '<div class="ft-pager">' . $pagerstr . '</div>';
		if ($maypost) echo '<div class="ft-replybar"><a href="' . htmlspecialchars("?action=reply&topicid=" . $topicid) . '">我也要说两句…</a></div>';
		f_mfoot();
		die;
	}

	begin_main_frame("",true);

	print("<h1 align=\"center\"><a class=\"faqlink\" href=\"forums.php\">".$SITENAME."&nbsp;".$lang_forums['text_forums']."</a>--><a class=\"faqlink\" href=\"".htmlspecialchars("?action=viewforum&forumid=".$forumid)."\">".$forumname."</a><b>--></b><span id=\"top\">".$subject.($locked ? "&nbsp;&nbsp;<b>[<font class=\"striking\">".$lang_forums['text_locked']."</font>]</b>" : "")."</span></h1>\n");
	end_main_frame();
	print($pagertop);

	//------ Print table

	begin_main_frame();
	print("<table border=\"0\" class=\"main\" cellspacing=\"0\" cellpadding=\"5\" width=\"97%\"><tr>\n");
	print("<td class=\"embedded\" width=\"99%\">&nbsp;&nbsp;".$lang_forums['there_is']."<b>".$views."</b>".$lang_forums['hits_on_this_topic']);
	$qdSortNext = $psort === 'desc' ? 'asc' : 'desc';
	$qdSortCur = $psort === 'desc' ? '倒序' : '正序';
	$qdSortUrl = "?action=viewtopic&topicid=".$topicid.($authorid ? "&authorid=".$authorid : "")."&psort=".$qdSortNext;
	print("&nbsp;&nbsp;<a class=\"qd-postsort-btn\" href=\"".htmlspecialchars($qdSortUrl)."\" title=\"点击切换楼层正序/倒序\">楼层：".$qdSortCur." ⇅</a>");
	print("</td>\n");
	print("<td class=\"embedded nowrap\" width=\"1%\" align=\"right\">");
	if ($maypost)
	{
		print("<a href=\"".htmlspecialchars("?action=reply&topicid=".$topicid)."\"><img class=\"f_reply\" src=\"pic/trans.gif\" alt=\"Add Reply\" title=\"".$lang_forums['title_reply_directly']."\" /></a>&nbsp;&nbsp;");
	}
	print("</td>");
	print("</tr></table>\n");
	begin_frame();

	$pc = mysql_num_rows($res);
	$allPosts = $uidArr = [];
    while ($arr = mysql_fetch_assoc($res)) {
        $allPosts[] = $arr;
        $uidArr[$arr['userid']] = 1;
    }
	$nestedReplyChildren = [];
	$nestedReplyChildIds = [];
	if ($threadedReplies && $allPosts) {
		$frontierPostIds = [];
		foreach ($allPosts as $rootPost) {
			$frontierPostIds[] = (int)$rootPost['id'];
		}
		$seenReplyIds = array_fill_keys($frontierPostIds, true);
		for ($replyDepth = 0; $replyDepth < 20 && $frontierPostIds; $replyDepth++) {
			$parentIds = implode(',', array_map('intval', $frontierPostIds));
			$frontierPostIds = [];
			$childRes = sql_query("SELECT * FROM posts WHERE topicid=" . sqlesc($topicid) . " AND reply_to_post_id IN ($parentIds) ORDER BY id") or sqlerr(__FILE__, __LINE__);
			while ($childPost = mysql_fetch_assoc($childRes)) {
				$childPostId = (int)$childPost['id'];
				if (isset($seenReplyIds[$childPostId])) {
					continue;
				}
				$parentPostId = (int)$childPost['reply_to_post_id'];
				$nestedReplyChildren[$parentPostId][] = $childPost;
				$nestedReplyChildIds[$childPostId] = true;
				$seenReplyIds[$childPostId] = true;
				$frontierPostIds[] = $childPostId;
				$allPosts[] = $childPost;
				$uidArr[$childPost['userid']] = 1;
			}
		}
	}
    $uidArr = array_keys($uidArr);
    unset($arr);
    $neededColumns = array('id', 'noad', 'class', 'enabled', 'privacy', 'avatar', 'signature', 'uploaded', 'downloaded', 'last_access', 'username', 'donor', 'leechwarn', 'warned', 'title');
    $userInfoArr = \App\Models\User::query()->with('wearing_medals')->find($uidArr, $neededColumns)->keyBy('id');
	$lastVisiblePostId = 0;
	foreach ($allPosts as $visiblePost) {
		$lastVisiblePostId = max($lastVisiblePostId, (int)$visiblePost['id']);
	}
	$pn = 0;
	$lpr = get_last_read_post_id($topicid);
	if ($Advertisement->enable_ad())
		$forumpostad=$Advertisement->get_ad('forumpost');

	//check if privacy protection enabled in this forum
//	$protected_forums = Nexus\Database\NexusDB::remember("setting_protected_forum", 600, function () {
//		return \App\Models\Setting::getByName('misc.protected_forum');
//	});
//
//	if ($protected_forums and in_array(strval($forumid),explode(",",$protected_forums))){
//		$protected_enabled=true;
//	}else{
//		$protected_enabled=false;
//	}

	print("<script type=\"text/javascript\">
function forumToggleInlineReply(postId) {
	var box = document.getElementById('forum-inline-reply-' + postId);
	if (!box) {
		return true;
	}
	box.style.display = box.style.display === 'none' || box.style.display === '' ? 'block' : 'none';
	if (box.style.display === 'block') {
		var textarea = box.getElementsByTagName('textarea')[0];
		if (textarea) {
			textarea.focus();
		}
	}
	return false;
}
function forumCancelInlineReply(postId) {
	var box = document.getElementById('forum-inline-reply-' + postId);
	if (box) {
		box.style.display = 'none';
	}
	return false;
}
</script>\n");

	$renderNestedReplies = function ($parentPostId, $depth = 1) use (&$renderNestedReplies, &$nestedReplyChildren, $userInfoArr, $topicid, $highlight, $allPosts, $lang_forums, $maypost, $locked, $is_forummod, $CURUSER, $userid) {
		$parentPostId = (int)$parentPostId;
		if (empty($nestedReplyChildren[$parentPostId])) {
			return;
		}
		$depth = min(max((int)$depth, 1), 5);
		print("<div class=\"forum-nested-replies forum-nested-depth-" . $depth . "\">\n");
		foreach ($nestedReplyChildren[$parentPostId] as $replyPost) {
			$replyPostId = (int)$replyPost['id'];
			$replyPosterId = (int)$replyPost['userid'];
			$replyUserInfo = $userInfoArr->get($replyPosterId) ?: \App\Models\User::defaultUser();
			$replyUser = $replyUserInfo->toArray();
			$isAnonymousHidden = forum_post_is_anonymous($replyPost) && !forum_can_view_anonymous_author($replyPost);
			$replyAuthor = forum_post_author_name($replyPost, true);
			$replyAdded = gettime($replyPost["added"], true, false);
			$replyAvatar = ($CURUSER["avatars"] == "yes" ? htmlspecialchars($replyUser["avatar"]) : "");
			if (!$replyAvatar) {
				$replyAvatar = "pic/default_avatar.png";
			}
			$replyUserPanel = return_avatar_image($replyAvatar, $isAnonymousHidden ? 0 : $replyPosterId);
			if (!$isAnonymousHidden) {
				$replyPosts = get_row_count("posts", "WHERE userid=" . $replyPosterId);
				$replyUploaded = mksize($replyUser["uploaded"]);
				$replyDownloaded = mksize($replyUser["downloaded"]);
				$replyRatio = get_ratio($replyUser['id']);
				$replyClassImage = get_user_class_image($replyUser["class"]);
				$replyUserPanel .= "<br /><span class=\"forum-nested-username\">" . $replyAuthor . "</span>";
				$replyUserPanel .= "<br /><br /><img alt=\"" . get_user_class_name($replyUser["class"], false, false, true) . "\" title=\"" . get_user_class_name($replyUser["class"], false, false, true) . "\" src=\"" . $replyClassImage . "\" />";
				$replyUserPanel .= "<br />&nbsp;&nbsp;" . $lang_forums['text_posts'] . $replyPosts . "<br />&nbsp;&nbsp;" . $lang_forums['text_ul'] . $replyUploaded . "<br />&nbsp;&nbsp;" . $lang_forums['text_dl'] . $replyDownloaded . "<br />&nbsp;&nbsp;" . $lang_forums['text_ratio'] . $replyRatio;
				$replyUserPanel .= forum_post_is_anonymous($replyPost) ? "" : forum_render_user_medals($replyUserInfo);
			}
			$canViewProtected = can_view_post($userid, $replyPost);
			if ($canViewProtected) {
				$replyBodyContent = format_comment($replyPost["body"]);
			} else {
				$replyBodyContent = format_comment($lang_forums["text_post_protected"]);
			}
			if ($highlight) {
				$replyBodyContent = highlight($highlight, $replyBodyContent);
			}
			if (is_valid_id($replyPost['editedby'])) {
				$lastedittime = gettime($replyPost['editdate'], true, false);
				$replyBodyContent .= "<br /><p><font class=\"small\">" . $lang_forums['text_last_edited_by'] . get_username($replyPost['editedby']) . $lang_forums['text_last_edit_at'] . $lastedittime . "</font></p>\n";
			}
			$replyBodyContent = apply_filter('post_body', $replyBodyContent, $replyPost, $allPosts);
			$replyTools = "";
			ob_start();
			do_action('post_toolbox', $replyPost, $allPosts, $CURUSER['id']);
			$replyTools .= ob_get_clean();
			if ($maypost && $canViewProtected) {
				$replyTools .= forum_inline_reply_link($replyPostId, $lang_forums['title_reply_with_quote']);
			}
			if (user_can('postmanage') || $is_forummod) {
				$replyTools .= "<a href=\"" . htmlspecialchars("?action=deletepost&postid=" . $replyPostId) . "\"><img class=\"f_delete\" src=\"pic/trans.gif\" alt=\"Delete\" title=\"" . $lang_forums['title_delete_post'] . "\" /></a>";
			}
			if (($CURUSER["id"] == $replyPosterId && !$locked) || user_can('postmanage') || $is_forummod) {
				$replyTools .= "<a href=\"" . htmlspecialchars("?action=editpost&postid=" . $replyPostId) . "\"><img class=\"f_edit\" src=\"pic/trans.gif\" alt=\"Edit\" title=\"" . $lang_forums['title_edit_post'] . "\" /></a>";
			}
			$replyPosterTools = "";
			$dt = sqlesc(date("Y-m-d H:i:s", (TIMENOW - 900)));
			if (!$isAnonymousHidden) {
				$replyPosterTools .= ("'" . $replyUser['last_access'] . "'" > $dt)
					? "<img class=\"f_online\" src=\"pic/trans.gif\" alt=\"Online\" title=\"" . $lang_forums['title_online'] . "\" />"
					: "<img class=\"f_offline\" src=\"pic/trans.gif\" alt=\"Offline\" title=\"" . $lang_forums['title_offline'] . "\" />";
				$replyPosterTools .= "<a href=\"sendmessage.php?receiver=" . htmlspecialchars(trim($replyUser["id"])) . "\"><img class=\"f_pm\" src=\"pic/trans.gif\" alt=\"PM\" title=\"" . $lang_forums['title_send_message_to'] . htmlspecialchars($replyUser["username"]) . "\" /></a>";
			}
			$replyPosterTools .= "<a href=\"report.php?forumpost=" . $replyPostId . "\"><img class=\"f_report\" src=\"pic/trans.gif\" alt=\"Report\" title=\"" . $lang_forums['title_report_this_post'] . "\" /></a>";
			print("<div class=\"forum-nested-reply-wrap\">");
			print("<div class=\"forum-nested-reply-head\"><a id=\"pid" . $replyPostId . "\" href=\"" . htmlspecialchars("forums.php?action=viewtopic&topicid=" . $topicid . "&page=p" . $replyPostId . "#pid" . $replyPostId) . "\">#" . $replyPostId . "</a>&nbsp;&nbsp;<font color=\"gray\">" . $lang_forums['text_by'] . "</font>" . $replyAuthor . "&nbsp;&nbsp;<font color=\"gray\">" . $lang_forums['text_at'] . "</font>" . $replyAdded . "</div>");
			print("<table class=\"main forum-nested-reply\" width=\"100%\" border=\"1\" cellspacing=\"0\" cellpadding=\"5\">");
			print("<tr><td class=\"rowfollow forum-nested-user\" width=\"150\" valign=\"top\" align=\"left\">" . ($isAnonymousHidden ? return_avatar_image("pic/default_avatar.png") : $replyUserPanel) . "</td><td class=\"rowfollow\" valign=\"top\"><div id=\"pid" . $replyPostId . "body\" class=\"forum-nested-reply-body\">" . $replyBodyContent . "</div></td></tr>");
			print("<tr><td class=\"rowfollow\" align=\"center\" valign=\"middle\">" . $replyPosterTools . "</td><td class=\"toolbox forum-nested-reply-tools\" align=\"right\">" . $replyTools . "</td></tr>");
			print("</table>");
			if ($maypost && $canViewProtected) {
				print(forum_render_inline_reply_form($replyPost, $topicid));
			}
			print("</div>\n");
			$renderNestedReplies($replyPostId, $depth + 1);
		}
		print("</div>\n");
	};

	foreach ($allPosts as $arr)
	{
		if (isset($nestedReplyChildIds[(int)$arr['id']])) {
			continue;
		}
		if ($pn>=1)
		{
			if ($Advertisement->enable_ad()){
				if (!empty($forumpostad[$pn-1]))
				echo "<div align=\"center\" style=\"margin-top: 10px\" id=\"\">".$forumpostad[$pn-1]."</div>";
			}
		}
		++$pn;
        $realFloor = ($psort === 'desc') ? (($arr['id'] == $firstPostId) ? 1 : ($postcount - $offset - $pn + 2)) : ($pn + $offset);

		$postid = $arr["id"];
		$posterid = $arr["userid"];

		$added = gettime($arr["added"],true,false);

		//---- Get poster details

//		$arr2 = get_user_row($posterid);
		$userInfo = $userInfoArr->get($posterid) ?: \App\Models\User::defaultUser();

		$arr2 = $userInfo->toArray();

		$uploaded = mksize($arr2["uploaded"]);
		$downloaded = mksize($arr2["downloaded"]);
		$ratio = get_ratio($arr2['id']);

		if (!$forumposts = $Cache->get_value('user_'.$posterid.'_post_count')){
			$forumposts = get_row_count("posts","WHERE userid=".$posterid);
			$Cache->cache_value('user_'.$posterid.'_post_count', $forumposts, 3600);
		}

		$signature = ($CURUSER["signatures"] == "yes" ? $arr2["signature"] : "");
		$avatar = ($CURUSER["avatars"] == "yes" ? htmlspecialchars($arr2["avatar"]) : "");

		$uclass = get_user_class_image($arr2["class"]);
		$isAnonymousPost = forum_post_is_anonymous($arr);
		$isAnonymousHidden = forum_post_is_anonymous($arr) && !forum_can_view_anonymous_author($arr);
		$by = forum_post_author_name($arr, true);
		if ($isAnonymousHidden) {
			$signature = "";
			$avatar = "pic/default_avatar.png";
			$stats = "";
		}

		if (!$avatar)
			$avatar = "pic/default_avatar.png";

		if ($pn == $pc)
		{
			print("<span id=\"last\"></span>\n");
			if ($lastVisiblePostId > $lpr){
				if ($lpr == $CURUSER['last_catchup']) // There is no record of this topic
					sql_query("INSERT INTO readposts(userid, topicid, lastpostread) VALUES (".$userid.", ".$topicid.", ".$lastVisiblePostId.")") or sqlerr(__FILE__, __LINE__);
				elseif ($lpr > $CURUSER['last_catchup']) //There is record of this topic
					sql_query("UPDATE readposts SET lastpostread=$lastVisiblePostId WHERE userid=$userid AND topicid=$topicid") or sqlerr(__FILE__, __LINE__);
				$Cache->delete_value('user_'.$CURUSER['id'].'_last_read_post_list');
			}
		}

		print("<div style=\"margin-top: 8pt; margin-bottom: 8pt;\"><table id=\"pid".$postid."\" border=\"0\" cellspacing=\"0\" cellpadding=\"0\" width=\"100%\"><tr><td class=\"embedded\" width=\"99%\"><a href=\"".htmlspecialchars("forums.php?action=viewtopic&topicid=".$topicid."&page=p".$postid."#pid".$postid)."\">#".$postid."</a>&nbsp;&nbsp;<font color=\"gray\">".$lang_forums['text_by']."</font>".$by."&nbsp;&nbsp;<font color=\"gray\">".$lang_forums['text_at']."</font>".$added);
		if (is_valid_id($arr['editedby']))
			print("");
		print("&nbsp;&nbsp;<font color=\"gray\">|</font>&nbsp;&nbsp;");
		if ($authorid)
			print("<a href=\"?action=viewtopic&topicid=".$topicid."\">".$lang_forums['text_view_all_posts']."</a>");
		else if (!$isAnonymousHidden)
			print("<a href=\"".htmlspecialchars("?action=viewtopic&topicid=".$topicid."&authorid=".$posterid)."\">".$lang_forums['text_view_this_author_only']."</a>");
		print("</td><td class=\"embedded nowrap\" width=\"1%\"><font class=\"big\">".$lang_forums['text_number']."<b>".$realFloor."</b>".$lang_forums['text_lou']."&nbsp;&nbsp;</font><a href=\"#top\"><img class=\"top\" src=\"pic/trans.gif\" alt=\"Top\" title=\"".$lang_forums['text_back_to_top']."\" /></a>&nbsp;&nbsp;</td></tr>");

		print("</table></div>\n");

		print("<table class=\"main\" width=\"100%\" border=\"1\" cellspacing=\"0\" cellpadding=\"5\">\n");

		$body = "<div id=\"pid".$postid."body\" style=\"word-break: break-all;\">";
		//hidden content applied to second or higher floor post (for whose user class below Ad , not poster , not mods ,not reply's author)
//		if ($protected_enabled && $pn+$offset>1 && get_user_class()<UC_ADMINISTRATOR && $userid != $base_posterid && $posterid!=$userid && !$is_forummod){
		if ($realFloor>1 && !can_view_post($userid, $arr)){
			//enable content protection
			$bodyContent = format_comment($lang_forums["text_post_protected"]);
            $canViewProtected = false;
		}else{
			//display normal content
			$bodyContent = format_comment($arr["body"]);
            $canViewProtected = true;
		}
		if ($highlight){
            $bodyContent = highlight($highlight,$bodyContent);
		}

		if (is_valid_id($arr['editedby']))
		{
			$lastedittime = gettime($arr['editdate'],true,false);
            $bodyContent .= "<br /><p><font class=\"small\">".$lang_forums['text_last_edited_by'].get_username($arr['editedby']).$lang_forums['text_last_edit_at'].$lastedittime."</font></p>\n";
		}
		$bodyContent = apply_filter('post_body', $bodyContent, $arr, $allPosts);
		$body .= $bodyContent . "</div>";
		if ($signature)
		$body .= "<p style='vertical-align:bottom'><br />____________________<br />" . format_comment($signature,false,false,false,true,500,true,false, 1,200) . "</p>";

		if (!$isAnonymousHidden) {
			$stats = "<br />"."&nbsp;&nbsp;".$lang_forums['text_posts']."$forumposts<br />"."&nbsp;&nbsp;".$lang_forums['text_ul']."$uploaded <br />"."&nbsp;&nbsp;".$lang_forums['text_dl']."$downloaded<br />"."&nbsp;&nbsp;".$lang_forums['text_ratio']."$ratio";
		}
		$userMedals = $isAnonymousPost ? "" : forum_render_user_medals($userInfo);
		print("<tr><td class=\"rowfollow\" width=\"150\" valign=\"top\" align=\"left\" style='padding: 0px'>" .
		return_avatar_image($avatar, $isAnonymousHidden ? 0 : (int)$arr2['id']). ($isAnonymousHidden ? "" : "<br /><br /><br />&nbsp;&nbsp;<img alt=\"".get_user_class_name($arr2["class"],false,false,true)."\" title=\"".get_user_class_name($arr2["class"],false,false,true)."\" src=\"".$uclass."\" />".$stats.$userMedals)."</td><td class=\"rowfollow\" valign=\"top\"><br />".$body."</td></tr>\n");
		$secs = 900;
		$dt = sqlesc(date("Y-m-d H:i:s",(TIMENOW - $secs))); // calculate date.
		$posterTools = "";
		if (!$isAnonymousHidden) {
			$posterTools .= ("'".$arr2['last_access']."'">$dt)
				? "<img class=\"f_online\" src=\"pic/trans.gif\" alt=\"Online\" title=\"".$lang_forums['title_online']."\" />"
				: "<img class=\"f_offline\" src=\"pic/trans.gif\" alt=\"Offline\" title=\"".$lang_forums['title_offline']."\" />";
			$posterTools .= "<a href=\"sendmessage.php?receiver=".htmlspecialchars(trim($arr2["id"]))."\"><img class=\"f_pm\" src=\"pic/trans.gif\" alt=\"PM\" title=\"".$lang_forums['title_send_message_to'].htmlspecialchars($arr2["username"])."\" /></a>";
		}
		print("<tr><td class=\"rowfollow\" align=\"center\" valign=\"middle\">".$posterTools."<a href=\"report.php?forumpost=$postid\"><img class=\"f_report\" src=\"pic/trans.gif\" alt=\"Report\" title=\"".$lang_forums['title_report_this_post']."\" /></a></td>");
		print("<td class=\"toolbox\" align=\"right\">");

		do_action('post_toolbox', $arr, $allPosts, $CURUSER['id']);

		if ($maypost && $canViewProtected)
		print(forum_inline_reply_link($postid, $lang_forums['title_reply_with_quote']));

		if (user_can('postmanage') || $is_forummod)
		print("<a href=\"".htmlspecialchars("?action=deletepost&postid=".$postid)."\"><img class=\"f_delete\" src=\"pic/trans.gif\" alt=\"Delete\" title=\"".$lang_forums['title_delete_post']."\" /></a>");

		if (($CURUSER["id"] == $posterid && !$locked) || user_can('postmanage') || $is_forummod)
		print("<a href=\"".htmlspecialchars("?action=editpost&postid=".$postid)."\"><img class=\"f_edit\" src=\"pic/trans.gif\" alt=\"Edit\" title=\"".$lang_forums['title_edit_post']."\" /></a>");
		print("</td></tr></table>");
		if ($maypost && $canViewProtected) {
			print(forum_render_inline_reply_form($arr, $topicid));
		}
		$renderNestedReplies($postid);
	}

	//------ Mod options

	if (user_can('postmanage') || $is_forummod)
	{
		print("</td></tr><tr><td class=\"toolbox\" align=\"center\">\n");
		print("<table border=\"0\" cellspacing=\"0\" cellpadding=\"0\" align=\"left\">\n");
		print("<tr><td class=\"embedded\"><form method=\"post\" action=\"?action=setsticky\">\n");
		print("<input type=\"hidden\" name=\"topicid\" value=\"".$topicid."\" />\n");
		print("<input type=\"hidden\" name=\"returnto\" value=\"".htmlspecialchars($_SERVER['REQUEST_URI'])."\" />\n");
		print("<input type=\"hidden\" name=\"sticky\" value=\"".($sticky ? 'no' : 'yes')."\" /><input type=\"submit\" class=\"medium\" value=\"".($sticky ? $lang_forums['submit_unsticky'] : $lang_forums['submit_sticky'])."\" /></form></td>\n");
		print("<td class=\"embedded\"><form method=\"post\" action=\"?action=setlocked\">\n");
		print("<input type=\"hidden\" name=\"topicid\" value=\"".$topicid."\" />\n");
		print("<input type=\"hidden\" name=\"returnto\" value=\"".htmlspecialchars($_SERVER['REQUEST_URI'])."\" />\n");
		print("<input type=\"hidden\" name=\"locked\" value=\"".($locked ? 'no' : 'yes')."\" /><input type=\"submit\" class=\"medium\" value=\"".($locked ? $lang_forums['submit_unlock'] : $lang_forums['submit_lock'])."\" /></form></td>\n");
		print("<td class=\"embedded\"><form method=\"get\" action=\"?\">\n");
		print("<input type=\"hidden\" name=\"action\" value=\"deletetopic\" />\n");
		print("<input type=\"hidden\" name=\"topicid\" value=\"".$topicid."\" />\n");
		print("<input type=\"hidden\" name=\"forumid\" value=\"".$forumid."\" />\n");
		print("<input type=\"submit\" class=\"medium\" value=\"".$lang_forums['submit_delete_topic']."\" /></form></td>\n");
		print("<td class=\"embedded\"><form method=\"post\" action=\"".htmlspecialchars("?action=movetopic&topicid=".$topicid)."\">\n"."&nbsp;".$lang_forums['text_move_thread_to']."&nbsp;<select class=\"med\" name=\"forumid\">");
		$forums = get_forum_row();
		foreach ($forums as $arr){
			if ($arr["id"] != $forumid && get_user_class() >= $arr["minclasswrite"])
				print("<option value=\"" . $arr["id"] . "\">" . htmlspecialchars($arr["name"]) . "</option>\n");
		}
		print("</select> <input type=\"submit\" class=\"medium\" value=\"".$lang_forums['submit_move']."\" /></form></td>");
		print("<td class=\"embedded\"><form method=\"post\" action=\"".htmlspecialchars("?action=hltopic&topicid=".$topicid)."\">\n"."&nbsp;".$lang_forums['text_highlight_topic']."&nbsp;<select class=\"med\" name=\"color\">");
		print("<option value='0'>".$lang_forums['select_color']."</option>
<option style='background-color: black' value=\"1\">Black</option>
<option style='background-color: sienna' value=\"2\">Sienna</option>
<option style='background-color: darkolivegreen' value=\"3\">Dark Olive Green</option>
<option style='background-color: darkgreen' value=\"4\">Dark Green</option>
<option style='background-color: darkslateblue' value=\"5\">Dark Slate Blue</option>
<option style='background-color: navy' value=\"6\">Navy</option>
<option style='background-color: indigo' value=\"7\">Indigo</option>
<option style='background-color: darkslategray' value=\"8\">Dark Slate Gray</option>
<option style='background-color: darkred' value=\"9\">Dark Red</option>
<option style='background-color: darkorange' value=\"10\">Dark Orange</option>
<option style='background-color: olive' value=\"11\">Olive</option>
<option style='background-color: green' value=\"12\">Green</option>
<option style='background-color: teal' value=\"13\">Teal</option>
<option style='background-color: blue' value=\"14\">Blue</option>
<option style='background-color: slategray' value=\"15\">Slate Gray</option>
<option style='background-color: dimgray' value=\"16\">Dim Gray</option>
<option style='background-color: red' value=\"17\">Red</option>
<option style='background-color: sandybrown' value=\"18\">Sandy Brown</option>
<option style='background-color: yellowgreen' value=\"19\">Yellow Green</option>
<option style='background-color: seagreen' value=\"20\">Sea Green</option>
<option style='background-color: mediumturquoise' value=\"21\">Medium Turquoise</option>
<option style='background-color: royalblue' value=\"22\">Royal Blue</option>
<option style='background-color: purple' value=\"23\">Purple</option>
<option style='background-color: gray' value=\"24\">Gray</option>
<option style='background-color: magenta' value=\"25\">Magenta</option>
<option style='background-color: orange' value=\"26\">Orange</option>
<option style='background-color: yellow' value=\"27\">Yellow</option>
<option style='background-color: lime' value=\"28\">Lime</option>
<option style='background-color: cyan' value=\"29\">Cyan</option>
<option style='background-color: deepskyblue' value=\"30\">Deep Sky Blue</option>
<option style='background-color: darkorchid' value=\"31\">Dark Orchid</option>
<option style='background-color: silver' value=\"32\">Silver</option>
<option style='background-color: pink' value=\"33\">Pink</option>
<option style='background-color: wheat' value=\"34\">Wheat</option>
<option style='background-color: lemonchiffon' value=\"35\">Lemon Chiffon</option>
<option style='background-color: palegreen' value=\"36\">Pale Green</option>
<option style='background-color: paleturquoise' value=\"37\">Pale Turquoise</option>
<option style='background-color: lightblue' value=\"38\">Light Blue</option>
<option style='background-color: plum' value=\"39\">Plum</option>
<option style='background-color: white' value=\"40\">White</option>");
		print("</select>");
		print("<input type=\"hidden\" name=\"returnto\" value=\"".htmlspecialchars($_SERVER['REQUEST_URI'])."\" />\n");
		print("<input type=\"submit\" class=\"medium\" value=\"".$lang_forums['submit_change']."\" /></form></td>");
		print("</tr>\n");
		print("</table>\n");
	}

	end_frame();

	end_main_frame();

	print($pagerbottom);
	if ($maypost){
	print("<br /><table style='border:1px solid #000000;'><tr>".
"<td class=\"text\" align=\"center\"><b>".$lang_forums['text_quick_reply']."</b><br /><br />".
"<form id=\"compose\" name=\"compose\" method=\"post\" action=\"?action=post\" onsubmit=\"return postvalid(this);\">".
"<input type=\"hidden\" name=\"id\" value=\"".$topicid."\" /><input type=\"hidden\" name=\"type\" value=\"reply\" /><br />");
	quickreply('compose', 'body',$lang_forums['submit_add_reply']);
	print("</form></td></tr></table>");
	print("<p align=\"center\"><a class=\"index\" href=\"".htmlspecialchars("?action=reply&topicid=".$topicid)."\">".$lang_forums['text_add_reply']."</a></p>\n");
	}
	elseif ($locked)
		print($lang_forums['text_topic_locked_new_denied']);
	else print($lang_forums['text_unpermitted_posting_here']);

	print(key_shortcut($page,$pages-1));
	f_mfoot();
	die;
}

//-------- Action: Move topic

if ($action == "movetopic")
{
	$forumid = intval($_POST["forumid"] ?? 0);

	$topicid = intval($_GET["topicid"] ?? 0);
	$ismod = is_forum_moderator($topicid,'topic');
	if (!is_valid_id($forumid) || !is_valid_id($topicid) || (!user_can('postmanage') && !$ismod))
		permissiondenied();

	// Make sure topic and forum is valid

	$res = @sql_query("SELECT minclasswrite FROM forums WHERE id=$forumid") or sqlerr(__FILE__, __LINE__);

	if (mysql_num_rows($res) != 1)
	stderr($lang_forums['std_error'], $lang_forums['std_forum_not_found']);

	$arr = mysql_fetch_row($res);

	if (get_user_class() < $arr[0])
		permissiondenied();

	$res = @sql_query("SELECT forumid FROM topics WHERE id=$topicid") or sqlerr(__FILE__, __LINE__);
	if (mysql_num_rows($res) != 1)
		stderr($lang_forums['std_error'], $lang_forums['std_topic_not_found']);
	$arr = mysql_fetch_row($res);
	$old_forumid=$arr[0];

	// get posts count
	$res = sql_query("SELECT COUNT(id) AS nb_posts FROM posts WHERE topicid=$topicid") or sqlerr(__FILE__, __LINE__);
	if (mysql_num_rows($res) != 1)
	stderr($lang_forums['std_error'], $lang_forums['std_cannot_get_posts_count']);
	$arr = mysql_fetch_row($res);
	$nb_posts = $arr[0];

	// move topic
	if ($old_forumid != $forumid)
	{
		@sql_query("UPDATE topics SET forumid=$forumid WHERE id=$topicid") or sqlerr(__FILE__, __LINE__);
		// update counts
		@sql_query("UPDATE forums SET topiccount=topiccount-1, postcount=postcount-$nb_posts WHERE id=$old_forumid") or sqlerr(__FILE__, __LINE__);
		$Cache->delete_value('forum_'.$old_forumid.'_post_'.$today_date.'_count');
		$Cache->delete_value('forum_'.$old_forumid.'_last_replied_topic_content');
		@sql_query("UPDATE forums SET topiccount=topiccount+1, postcount=postcount+$nb_posts WHERE id=$forumid") or sqlerr(__FILE__, __LINE__);
		$Cache->delete_value('forum_'.$forumid.'_post_'.$today_date.'_count');
		$Cache->delete_value('forum_'.$forumid.'_last_replied_topic_content');
	}

	// Redirect to forum page

	header("Location: " . get_protocol_prefix() . "$BASEURL/forums.php?action=viewforum&forumid=$forumid");

	die;
}

//-------- Action: Delete topic

if ($action == "deletetopic")
{
	$topicid = intval($_GET["topicid"] ?? 0);
	$res1 = sql_query("SELECT forumid, userid FROM topics WHERE id=".sqlesc($topicid)." LIMIT 1") or sqlerr(__FILE__, __LINE__);
	$row1 = mysql_fetch_array($res1);
	if (!$row1){
		die;
	}
	else {
		$forumid = $row1['forumid'];
		$userid = $row1['userid'];
	}
	$ismod = is_forum_moderator($topicid,'topic');
	if (!is_valid_id($topicid) || (!user_can('postmanage') && !$ismod))
		permissiondenied();

	$sure = intval($_GET["sure"] ?? 0);
	if (!$sure)
	{
		stderr($lang_forums['std_delete_topic'], $lang_forums['std_delete_topic_note'] .
		"<a class=altlink href=?action=deletetopic&topicid=$topicid&sure=1>".$lang_forums['std_here_if_sure'],false);
	}

	$postcount = get_row_count("posts","WHERE topicid=".sqlesc($topicid));

	sql_query("DELETE FROM topics WHERE id=$topicid") or sqlerr(__FILE__, __LINE__);
	sql_query("DELETE FROM posts WHERE topicid=$topicid") or sqlerr(__FILE__, __LINE__);
	sql_query("DELETE FROM readposts WHERE topicid=$topicid") or sqlerr(__FILE__, __LINE__);
	@sql_query("UPDATE forums SET topiccount=topiccount-1, postcount=postcount-$postcount WHERE id=".sqlesc($forumid)) or sqlerr(__FILE__, __LINE__);
	$Cache->delete_value('forum_'.$forumid.'_post_'.$today_date.'_count');
	$forum_last_replied_topic_row = $Cache->get_value('forum_'.$forumid.'_last_replied_topic_content');
	if ($forum_last_replied_topic_row && $forum_last_replied_topic_row['id'] == $topicid)
		$Cache->delete_value('forum_'.$forumid.'_last_replied_topic_content');

	//===remove karma
	KPS("-",$starttopic_bonus,$userid);
	//===end

	header("Location: " . get_protocol_prefix() . "$BASEURL/forums.php?action=viewforum&forumid=$forumid");
	die;
}

//-------- Action: Delete post

if ($action == "deletepost")
{
	$postid = intval($_GET["postid"] ?? 0);
	$sure = intval($_GET["sure"] ?? 0);

	$ismod = is_forum_moderator($postid, 'post');
	if ((!user_can('postmanage') && !$ismod) || !is_valid_id($postid))
		permissiondenied();

	//------- Get topic id
	$res = sql_query("SELECT topicid, userid FROM posts WHERE id=$postid") or sqlerr(__FILE__, __LINE__);
	$arr = mysql_fetch_array($res) or stderr($lang_forums['std_error'], $lang_forums['std_post_not_found']);
	$topicid = $arr['topicid'];
	$userid = $arr['userid'];

	//------- Get the id of the last post before the one we're deleting
	$res = sql_query("SELECT id FROM posts WHERE topicid=$topicid AND id < $postid ORDER BY id DESC LIMIT 1") or sqlerr(__FILE__, __LINE__);
	if (mysql_num_rows($res) == 0) // This is the first post of a topic
		stderr($lang_forums['std_error'], $lang_forums['std_cannot_delete_post'] .
	"<a class=altlink href=?action=deletetopic&topicid=$topicid&sure=1>".$lang_forums['std_delete_topic_instead'],false);
	else
	{
		$arr = mysql_fetch_row($res);
		$redirtopost = "&page=p$arr[0]#pid$arr[0]";
	}

	//------- Make sure we know what we do :-)
	if (!$sure)
	{
		stderr($lang_forums['std_delete_post'], $lang_forums['std_delete_post_note'] .
		"<a class=altlink href=?action=deletepost&postid=$postid&sure=1>".$lang_forums['std_here_if_sure'],false);
	}

	//------- Delete post
	if (forum_posts_support_reply_to()) {
		$replyToPostId = (int)get_single_value("posts", "reply_to_post_id", "WHERE id=" . sqlesc($postid));
		sql_query("UPDATE posts SET reply_to_post_id=" . sqlesc($replyToPostId) . " WHERE reply_to_post_id=" . sqlesc($postid)) or sqlerr(__FILE__, __LINE__);
	}
	sql_query("DELETE FROM posts WHERE id=$postid") or sqlerr(__FILE__, __LINE__);
	$Cache->delete_value('user_'.$userid.'_post_count');
	$Cache->delete_value('topic_'.$topicid.'_post_count');
	// update forum
	$forumid = get_single_value("topics","forumid","WHERE id=".sqlesc($topicid));
	if (!$forumid)
		die();
	else{
		sql_query("UPDATE forums SET postcount=postcount-1 WHERE id=".sqlesc($forumid));
	}
	$forum_last_replied_topic_row = $Cache->get_value('forum_'.$forumid.'_last_replied_topic_content');
	if ($forum_last_replied_topic_row && $forum_last_replied_topic_row['lastpost'] == $postid)
		$Cache->delete_value('forum_'.$forumid.'_last_replied_topic_content');
	//------- Update topic
	update_topic_last_post($topicid);

	//===remove karma
	KPS("-",$makepost_bonus,$userid);

	header("Location: " . get_protocol_prefix() . "$BASEURL/forums.php?action=viewtopic&topicid=$topicid$redirtopost");
	die;
}

//-------- Action: Set locked on/off

if ($action == "setlocked")
{
	$topicid = intval($_POST["topicid"] ?? 0);
	$ismod = is_forum_moderator($topicid,'topic');
	if (!$topicid || (!user_can('postmanage') && !$ismod))
		permissiondenied();

	$locked = sqlesc($_POST["locked"]);
	sql_query("UPDATE topics SET locked=$locked WHERE id=$topicid") or sqlerr(__FILE__, __LINE__);

	header("Location: $_POST[returnto]");
	die;
}

if ($action == 'hltopic')
{
	$topicid = intval($_GET["topicid"] ?? 0);
	$ismod = is_forum_moderator($topicid,'topic');
	if (!$topicid || (!user_can('postmanage') && !$ismod))
		permissiondenied();
	$color = $_POST["color"];
	if ($color==0 || get_hl_color($color))
		sql_query("UPDATE topics SET hlcolor=".sqlesc($color)." WHERE id=".sqlesc($topicid)) or sqlerr(__FILE__, __LINE__);

	$forumid = get_single_value("topics","forumid","WHERE id=".sqlesc($topicid));
	$forum_last_replied_topic_row = $Cache->get_value('forum_'.$forumid.'_last_replied_topic_content');
	if ($forum_last_replied_topic_row && $forum_last_replied_topic_row['id'] == $topicid)
		$Cache->delete_value('forum_'.$forumid.'_last_replied_topic_content');
	header("Location: $_POST[returnto]");
	die;
}

//-------- Action: Set sticky on/off

if ($action == "setsticky")
{
	$topicid = intval($_POST["topicid"] ?? 0);
	$ismod = is_forum_moderator($topicid,'topic');
	if (!$topicid || (!user_can('postmanage') && !$ismod))
		permissiondenied();

	$sticky = sqlesc($_POST["sticky"]);
	sql_query("UPDATE topics SET sticky=$sticky WHERE id=$topicid") or sqlerr(__FILE__, __LINE__);

	header("Location: $_POST[returnto]");
	die;
}

//-------- Action: View forum

if ($action == "viewforum")
{
	$forumid = intval($_GET["forumid"] ?? 0);
	int_check($forumid,true);
	$userid = intval($CURUSER["id"] ?? 0);
	//------ Get forum name, moderators
	$row = get_forum_row($forumid);
	if (!$row){
		write_log("User " . $CURUSER["username"] . "," . $CURUSER["ip"] . " is trying to visit forum that doesn't exist", 'mod');
		stderr($lang_forums['std_forum_error'],$lang_forums['std_forum_not_found']);
	}
	if (get_user_class() < $row["minclassread"])
		permissiondenied();

	$forumname = $row['name'];
	$forummoderators = get_forum_moderators($forumid,false);
	$search = mysql_real_escape_string(trim($_GET["search"] ?? ''));
	if ($search){
		$wherea = " AND subject LIKE '%$search%'";
		$addparam .= "&search=".rawurlencode($search);
	}
	else{
		$wherea = "";
		$addparam = "";
	}
	$num = get_row_count("topics","WHERE forumid=".sqlesc($forumid).$wherea);

	list($pagertop, $pagerbottom, $limit) = pager($topicsperpage, $num, "?"."action=viewforum&forumid=".$forumid.$addparam."&");
	if (isset($_GET["sort"])){
		switch ($_GET["sort"]){
			case 'firstpostasc':
			{
				$orderby = "firstpost ASC";
				break;
			}
			case 'firstpostdesc':
			{
				$orderby = "firstpost DESC";
				break;
			}
			case 'lastpostasc':
			{
				$orderby = "lastpost ASC";
				break;
			}
			case 'lastpostdesc':
			{
				$orderby = "lastpost DESC";
				break;
			}
			default:
			{
				$orderby = "lastpost DESC";
			}
		}
	}
	else
	{
		$orderby = "lastpost DESC";
	}
	//------ Get topics data
	$topicsres = sql_query("SELECT * FROM topics WHERE forumid=".sqlesc($forumid).$wherea." ORDER BY sticky DESC,".$orderby." ".$limit) or sqlerr(__FILE__, __LINE__);
	$numtopics = mysql_num_rows($topicsres);
	f_mhead($lang_forums['head_forum']." ".$forumname);
	begin_main_frame("",true);
	print("<h1 align=\"center\"><a class=\"faqlink\" href=\"forums.php\">".$SITENAME."&nbsp;".$lang_forums['text_forums'] ."</a>--><a class=\"faqlink\" href=\"".htmlspecialchars("forums.php?action=viewforum&forumid=".$forumid)."\">".$forumname."</a></h1>\n");
	end_main_frame();
	print("<br />");
	$maypost = get_user_class() >= $row["minclasswrite"] && get_user_class() >= $row["minclasscreate"] && $CURUSER["forumpost"] == 'yes';

	if (!$maypost)
		print("<p><i>".$lang_forums['text_unpermitted_starting_new_topics']."</i></p>\n");

	// 手机端：主题列表用卡片(头像+作者/日期+浏览/回复+标题)，类似触屏版
	if (!empty($GLOBALS['F_MOBILE'])) {
		echo '<div class="f-vf-bar"><div class="f-vf-name">' . htmlspecialchars($forumname) . '<span class="f-vf-sub">主题 ' . number_format((int)$numtopics) . '</span></div>';
		if ($maypost) echo '<a class="f-vf-new" href="' . htmlspecialchars("?action=newtopic&forumid=" . $forumid) . '">发新主题</a>';
		echo '</div>';
		if ($numtopics > 0) {
			echo '<div class="f-topics">';
			while ($topicarr = mysql_fetch_assoc($topicsres)) {
				$topicid = (int)$topicarr["id"];
				$sticky = $topicarr["sticky"] == "yes";
				$locked = $topicarr["locked"] == "yes";
				$views = number_format((int)$topicarr["views"]);
				if (!$posts = $Cache->get_value('topic_'.$topicid.'_post_count')) {
					$posts = get_row_count("posts", "WHERE topicid=".sqlesc($topicid));
					$Cache->cache_value('topic_'.$topicid.'_post_count', $posts, 3600);
				}
				$replies = max(0, $posts - 1);
				$fp = get_post_row($topicarr['firstpost']);
				$fpuid = (int)($fp["userid"] ?? 0);
				$anon = function_exists('forum_post_is_anonymous') && forum_post_is_anonymous($fp);
				$fpnameHtml = f_author_html($fpuid, $anon, $fp);
				$fpname = trim(strip_tags($fpnameHtml));
				$fpdate = substr((string)($fp['added'] ?? ''), 0, 10);
				$fpavatar = '';
				if (!$anon && $fpuid) { $ur = get_user_row($fpuid); if ($ur && !empty($ur['avatar'])) $fpavatar = trim($ur['avatar']); }
				$av = $fpavatar !== '' ? '<img src="'.htmlspecialchars($fpavatar).'" alt="" onerror="this.style.display=\'none\'">' : '<b>'.htmlspecialchars(mb_substr($fpname !== '' ? $fpname : '?', 0, 1)).'</b>';
				$flag = ($sticky ? '<span class="f-tag sticky">置顶</span>' : '') . ($locked ? '<span class="f-tag lock">锁</span>' : '');
				echo '<a class="f-topic" href="' . htmlspecialchars("?action=viewtopic&forumid=".$forumid."&topicid=".$topicid) . '">'
					. '<div class="f-topic-top"><span class="f-ava">' . $av . '</span>'
					. '<span class="f-topic-meta"><span class="f-topic-author">' . $fpnameHtml . '</span><span class="f-topic-date">发布于 ' . htmlspecialchars($fpdate) . '</span></span>'
					. '<span class="f-topic-stats"><span class="st"><svg viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>' . $views . '</span><span class="st"><svg viewBox="0 0 24 24"><path d="M4 5h16v11H8l-4 4z"/></svg>' . $replies . '</span></span></div>'
					. '<div class="f-topic-title">' . $flag . highlight_topic(htmlspecialchars($topicarr["subject"]), $topicarr["hlcolor"]) . '</div></a>';
			}
			echo '</div>';
			echo $pagerbottom;
		} else {
			echo '<p class="f-empty">' . $lang_forums['text_no_topics_found'] . '</p>';
		}
		f_mfoot();
		die;
	}

	print("<table border=\"0\" class=\"main\" cellspacing=\"0\" cellpadding=\"5\" width=\"97%\"><tr>\n");
	print("<td class=\"embedded\" width=\"90%\">");
	print($forummoderators ? "&nbsp;&nbsp;<img class=\"forum_mod\" src=\"pic/trans.gif\" alt=\"Moderator\" title=\"".$lang_forums['col_moderator']."\">&nbsp;".$forummoderators : "");
	print("</td><td class=\"embedded nowrap\" width=\"1%\">");
	if ($maypost)
		print("<a href=\"".htmlspecialchars("?action=newtopic&forumid=".$forumid)."\"><img class=\"f_new\" src=\"pic/trans.gif\" alt=\"New Topic\" title=\"".$lang_forums['title_new_topic']."\" /></a>&nbsp;&nbsp;");
	print("</td>");
	print("</tr></table>\n");
	if ($numtopics > 0)
	{
		print("<table border=\"1\" cellspacing=\"0\" cellpadding=\"5\" width=\"97%\">");

		print("<tr><td class=\"colhead\" align=\"center\" width=\"99%\">".$lang_forums['col_topic']."</td><td class=\"colhead\" align=\"center\"><a href=\"".htmlspecialchars("?action=viewforum&forumid=".$forumid.$addparam."&sort=".(isset($_GET["sort"]) && $_GET["sort"] == 'firstpostdesc' ? "firstpostasc" : "firstpostdesc"))."\" title=\"".(isset($_GET["sort"]) && $_GET["sort"] == 'firstpostdesc' ?  $lang_forums['title_order_topic_asc'] : $lang_forums['title_order_topic_desc'])."\">".$lang_forums['col_author']."</a></td><td class=\"colhead\" align=\"center\">".$lang_forums['col_replies']."/".$lang_forums['col_views']."</td><td class=\"colhead\" align=\"center\"><a href=\"".htmlspecialchars("?action=viewforum&forumid=".$forumid.$addparam."&sort=".(isset($_GET["sort"]) && $_GET["sort"] == 'lastpostasc' ? "lastpostdesc" : "lastpostasc"))."\" title=\"".(isset($_GET["sort"]) && $_GET["sort"] == 'lastpostasc' ? $lang_forums['title_order_post_desc'] : $lang_forums['title_order_post_asc'])."\">".$lang_forums['col_last_post']."</a></td>\n");

		print("</tr>\n");
		$counter = 0;

		while ($topicarr = mysql_fetch_assoc($topicsres))
		{
			$topicid = $topicarr["id"];

			$topic_userid = $topicarr["userid"];

			$topic_views = $topicarr["views"];

			$views = number_format($topic_views);

			$locked = $topicarr["locked"] == "yes";

			$sticky = $topicarr["sticky"] == "yes";

			$hlcolor = $topicarr["hlcolor"];

			//---- Get reply count
			if (!$posts = $Cache->get_value('topic_'.$topicid.'_post_count')){
				$posts = get_row_count("posts","WHERE topicid=".sqlesc($topicid));
				$Cache->cache_value('topic_'.$topicid.'_post_count', $posts, 3600);
			}

			$replies = max(0, $posts - 1);

			$tpages = floor($posts / $postsperpage);

			if ($tpages * $postsperpage != $posts)
			++$tpages;

			if ($tpages > 1)
			{
				$topicpages = " [<img class=\"multipage\" src=\"pic/trans.gif\" alt=\"multi-page\" /> ";
				$dotted = 0;
				$dotspace = 4;
				$dotend = $tpages - $dotspace;
				for ($i = 1; $i <= $tpages; ++$i){
					if ($i > $dotspace && $i <= $dotend) {
						if (!$dotted)
						$topicpages .= " ... ";
						$dotted = 1;
						continue;
					}
				$topicpages .= " <a href=\"".htmlspecialchars("?action=viewtopic&topicid=".$topicid."&page=".($i-1))."\">$i</a>";
				}

				$topicpages .= " ]";
			}
			else
			$topicpages = "";

			//---- Get userID and date of last post

			$arr = get_post_row($topicarr['lastpost']);
			$lppostid = intval($arr["id"] ?? 0);
			$lpuserid = intval($arr["userid"] ?? 0);
			$lpusername = forum_post_author_name($arr, true);
			$lpadded = gettime($arr["added"],true,false);
			$onmouseover = "";
			if ($enabletooltip_tweak == 'yes' && $CURUSER['showlastpost'] != 'no'){
				if ($CURUSER['timetype'] != 'timealive')
					$lastposttime = $lang_forums['text_at_time'].$arr["added"];
				else
					$lastposttime = $lang_forums['text_blank'].gettime($arr["added"],true,false,true);
				$lptext = format_comment(mb_substr($arr['body'],0,100,"UTF-8") . (mb_strlen($arr['body'],"UTF-8") > 100 ? " ......" : "" ),true,false,false,true,600,false,false);
				$lastpost_tooltip[$counter]['id'] = "lastpost_" . $counter;
				$lastpost_tooltip[$counter]['content'] = $lang_forums['text_last_posted_by'].$lpusername.$lastposttime."<br />".$lptext;
				$onmouseover = "onmouseover=\"domTT_activate(this, event, 'content', document.getElementById('" . $lastpost_tooltip[$counter]['id'] . "'), 'trail', false,'lifetime', 5000,'styleClass','niceTitle','fadeMax', 87,'maxWidth', 400);\"";
			}

			$arr = get_post_row($topicarr['firstpost']);
			$fpuserid = intval($arr["userid"] ?? 0);
			$fpauthor = forum_post_author_name($arr, true);

			$subject = ($sticky ? "<img class=\"sticky\" src=\"pic/trans.gif\" alt=\"Sticky\" title=\"".$lang_forums['title_sticky']."\" />&nbsp;&nbsp;" : "") . "<a href=\"".htmlspecialchars("?action=viewtopic&forumid=".$forumid."&topicid=".$topicid)."\" ".$onmouseover.">" .highlight_topic(highlight($search,htmlspecialchars($topicarr["subject"])), $hlcolor) . "</a>".$topicpages;
			$lastpostread = get_last_read_post_id($topicid);

			if ($lastpostread >= $lppostid)
				$img = get_topic_image($locked ? "locked" : "read");
			else{
				$img = get_topic_image($locked ? "lockednew" : "unread");
				if ($lastpostread != $CURUSER['last_catchup'])
					$subject .= "&nbsp;&nbsp;<a href=\"".htmlspecialchars("?action=viewtopic&forumid=".$forumid."&topicid=".$topicid."&page=p".$lastpostread."#pid".$lastpostread)."\" title=\"".$lang_forums['title_jump_to_unread']."\"><font class=\"small new\"><b>".$lang_forums['text_new']."</b></font></a>";
			}


			$topictime = substr($arr['added'],0,10);
			if (strtotime($arr['added']) +  86400 > TIMENOW)
				$topictime = "<font class=\"new small\">".$topictime."</font>";
			else
				$topictime = "<font color=\"gray\" class=\"small\">".$topictime."</font>";

			print("<tr><td class=\"rowfollow\" align=\"left\"><table border=\"0\" cellspacing=\"0\" cellpadding=\"0\"><tr>" .
			"<td class=\"embedded\" style='padding-right: 10px'>".$img .
			"</td><td class=\"embedded\" align=\"left\">\n" .
			$subject."</td></tr></table></td><td class=\"rowfollow\" align=\"center\">".$fpauthor."<br />".$topictime."</td><td class=\"rowfollow\" align=\"center\">".$replies." / <font color=\"gray\">".$views."</font></td>\n" .
			"<td class=\"rowfollow nowrap\" align=\"center\">".$lpadded."<br />".$lpusername."</td>\n");

			print("</tr>\n");
			$counter++;

		} // while

		//print("</table>\n");
		//print("<table border=\"0\" cellspacing=\"0\" cellpadding=\"5\" width=\"97%\">");
		print("<tr><td align=\"left\">\n");
		print("<form method=\"get\" action=\"forums.php\"><b>".$lang_forums['text_fast_search']."</b><input type=\"hidden\" name=\"action\" value=\"viewforum\" /><input type=\"hidden\" name=\"forumid\" value=\"".$forumid."\" /><input type=\"text\" style=\"width: 180px\" name=\"search\" />&nbsp;<input type=\"submit\" value=\"".$lang_forums['text_go']."\" /></form>");
		print("</td>");
?>
<td align="left" colspan="3">
<span id="order" onclick="dropmenu(this);"><span style="cursor: pointer;"><b><?php echo $lang_forums['text_order']?></b></span>
<span id="orderlist" class="dropmenu" style="display: none"><ul>
<li><a href="?action=viewforum&amp;forumid=<?php echo $forumid.$addparam?>&amp;sort=firstpostdesc"><?php echo $lang_forums['text_topic_desc']?></a></li>
<li><a href="?action=viewforum&amp;forumid=<?php echo $forumid.$addparam?>&amp;sort=firstpostasc"><?php echo $lang_forums['text_topic_asc']?></a></li>
<li><a href="?action=viewforum&amp;forumid=<?php echo $forumid.$addparam?>&amp;sort=lastpostdesc"><?php echo $lang_forums['text_post_desc']?></a></li>
<li><a href="?action=viewforum&amp;forumid=<?php echo $forumid.$addparam?>&amp;sort=lastpostasc"><?php echo $lang_forums['text_post_asc']?></a></li>
</ul>
</span>
</span>
</td>
<?php
		print("</tr></table>");
		print($pagerbottom);
		if ($enabletooltip_tweak == 'yes' && $CURUSER['showlastpost'] != 'no')
			create_tooltip_container($lastpost_tooltip, 400);
	} // if
	else
		print("<p>".$lang_forums['text_no_topics_found']."</p>");
	f_mfoot();
	die;
}

//-------- Action: View unread posts

if ($action == "viewunread")
{
	$userid = $CURUSER['id'];

	$beforepostid = intval($_GET['beforepostid'] ?? 0);
	$maxresults = 25;
	$res = sql_query("SELECT id, forumid, subject, lastpost, hlcolor FROM topics WHERE lastpost > ".$CURUSER['last_catchup'].($beforepostid ? " AND lastpost < ".sqlesc($beforepostid) : "")." ORDER BY lastpost DESC LIMIT 100") or sqlerr(__FILE__, __LINE__);

	f_mhead($lang_forums['head_view_unread']);
	print("<h1 align=\"center\"><a class=\"faqlink\" href=\"forums.php\">".$SITENAME."&nbsp;".$lang_forums['text_forums']."</a>-->".$lang_forums['text_topics_with_unread_posts']."</h1>");

	$n = 0;
	$uc = get_user_class();

	while ($arr = mysql_fetch_assoc($res))
	{
		$topiclastpost = $arr['lastpost'];
		$topicid = $arr['id'];

		//---- Check if post is read
		$lastpostread = get_last_read_post_id($topicid);

		if ($lastpostread >= $topiclastpost)
			continue;

		$forumid = $arr['forumid'];
		//---- Check access & get forum name
		$a = get_forum_row($forumid);
		if ($uc < $a['minclassread'])
			continue;
		++$n;
		if ($n > $maxresults)
			break;

		$forumname = $a['name'];
		if ($n == 1)
		{
			print("<table border=\"1\" cellspacing=\"0\" cellpadding=\"5\">\n");
			print("<tr><td class=\"colhead\" align=\"left\">".$lang_forums['col_topic']."</td><td class=\"colhead\" align=\"left\">".$lang_forums['col_forum']."</td></tr>\n");
		}
		print("<tr><td class=\"rowfollow\" align=\"left\"><table border=\"0\" cellspacing=\"0\" cellpadding=\"0\"><tr><td class=\"embedded\" style='padding-right: 10px'>" .
		get_topic_image("unread")."</td><td class=\"embedded\">" .
		"<a href=\"".htmlspecialchars("?action=viewtopic&topicid=".$topicid.($lastpostread > 0 && $lastpostread != $CURUSER['last_catchup'] ? "&page=p".$lastpostread."#pid".$lastpostread : ""))."\">" . highlight_topic(htmlspecialchars($arr["subject"]), $arr["hlcolor"]).
		"</a></td></tr></table></td><td class=\"rowfollow\" align=\"left\"><a href=\"".htmlspecialchars("?action=viewforum&forumid=".$forumid)."\"><b>".$forumname."</b></a></td></tr>\n");
	}
	if ($n > 0)
	{
		print("</table>\n");
		print("<table border=\"0\" class=\"main\" cellspacing=\"0\" cellpadding=\"5\" width=\"1%\"><tr><td class=\"embedded\"><form method=\"get\" action=\"?\"><input type=\"hidden\" name=\"catchup\" value=\"1\" /><input type=\"submit\" value=\"".$lang_forums['text_catch_up']."\" class=\"btn\" /></form></td>");
		if ($n > $maxresults){
			print("<td class=\"embedded\"><form method=\"get\" action=\"?\"><input type=\"hidden\" name=\"action\" value=\"viewunread\" /><input type=\"hidden\" name=\"beforepostid\" value=\"".$topiclastpost."\" /><input type=\"submit\" value=\"".$lang_forums['submit_show_more']."\" class=\"btn\" /></form></td>");
		}
		print("</tr></table>");
	}
	else
		print("<p>".$lang_forums['text_nothing_found']."</p>");
	f_mfoot();
	die;
}

if ($action == "search")
{
	f_mhead($lang_forums['head_forum_search']);
	unset($error);
	$error = true;
	$found = "";
	$keywords = htmlspecialchars(trim($_GET["keywords"]));
	if ($keywords != "")
	{
		$extraSql 	= " LIKE '%".mysql_real_escape_string($keywords)."%'";

		$res = sql_query("SELECT COUNT(posts.id) FROM posts LEFT JOIN topics ON posts.topicid = topics.id LEFT JOIN forums ON topics.forumid = forums.id WHERE forums.minclassread <= ".sqlesc(get_user_class())." AND ((topics.subject $extraSql AND posts.id=topics.firstpost) OR posts.body $extraSql)") or sqlerr(__FILE__, __LINE__);
		$arr = mysql_fetch_row($res);
		$hits = intval($arr[0] ?? 0);
		if ($hits){
			$error = false;
			$found = "[<b><font class=\"striking\"> ".$lang_forums['text_found'].$hits.$lang_forums['text_num_posts']." </font></b>]";
		}
	}
?>
<style type="text/css">
.search{
	background-image:url(pic/search.gif);
	background-repeat:no-repeat;
	width:579px;
	height:95px;
	margin:5px 0 5px 0;
	text-align:left;
}
.search_title{
	color:#0062AE;
	background-color:#DAF3FB;
	font-size:12px;
	font-weight:bold;
	text-align:left;
	padding:7px 0 0 15px;
}

.search_table {
	border-collapse: collapse;
	border: none;
	background-color: #ffffff;
}

</style>
<div class="search">
	<div class="search_title"><?php echo $lang_forums['text_search_on_forum'] ?> <?php echo ($error && $keywords != "" ? "[<b><font color=striking> ".$lang_forums['text_nothing_found']."</font></b> ]" : $found)?></div>
	<div style="margin-left: 53px; margin-top: 13px;">
		<form method="get" action="forums.php" id="search_form" style="margin: 0pt; padding: 0pt; font-family: Tahoma,Arial,Helvetica,sans-serif; font-size: 11px;">
		<input type="hidden" name="action" value="search" />
		<table border="0" cellpadding="0" cellspacing="0" width="512" class="search_table">
		<tbody>
		<tr>
		<td style="padding-bottom: 3px; border: 0;" valign="top"><?php echo $lang_forums['text_by_keyword'] ?></td>
		</tr>
		<tr>
		<td style="padding-bottom: 3px; border: 0;" valign="top">
			<input name="keywords" type="text" value="<?php echo $keywords?>" style="width: 400px;" /></td>
			<td style="padding-bottom: 3px; border: 0;" valign="top"><input name="image" type="image" style="vertical-align: middle; padding-bottom: 0px; margin-left: 0px;" src="<?php echo get_forum_pic_folder()?>/search_button.gif" alt="Search" /></td>
		</tr>
		</tbody>
		</table>
		</form>
	</div>
</div>
<?php

	if (!$error)
	{
		$perpage = $topicsperpage;
		list($pagertop, $pagerbottom, $limit) = pager($perpage, $hits, "forums.php?action=search&keywords=".rawurlencode($keywords)."&");
		$anonymousSelect = forum_posts_support_anonymous() ? ", posts.anonymous" : "";
		$res = sql_query("SELECT posts.id, posts.topicid, posts.userid, posts.added$anonymousSelect, topics.subject, topics.hlcolor, forums.id AS forumid, forums.name AS forumname FROM posts LEFT JOIN topics ON posts.topicid = topics.id LEFT JOIN forums ON topics.forumid = forums.id WHERE forums.minclassread <= ".sqlesc(get_user_class())." AND ((topics.subject $extraSql AND posts.id=topics.firstpost) OR posts.body $extraSql) ORDER BY posts.id DESC $limit") or sqlerr(__FILE__, __LINE__);

		print($pagertop);
		print("<table border=\"1\" cellspacing=\"0\" cellpadding=\"5\" width=\"97%\">\n");
		print("<tr><td class=\"colhead\" align=\"center\">".$lang_forums['col_post']."</td><td class=\"colhead\" align=\"center\" width=\"70%\">".$lang_forums['col_topic']."</td><td class=\"colhead\" align=\"left\">".$lang_forums['col_forum']."</td><td class=\"colhead\" align=\"left\">".$lang_forums['col_posted_by']."</td></tr>\n");

		while ($post = mysql_fetch_array($res))
		{
			print("<tr><td class=\"rowfollow\" align=\"center\" width=\"1%\">".$post['id']."</td><td class=\"rowfollow\" align=\"left\"><a href=\"".htmlspecialchars("?action=viewtopic&topicid=".$post['topicid']."&highlight=".rawurlencode($keywords)."&page=p".$post['id']."#pid".$post['id'])."\">" . highlight_topic(highlight($keywords,htmlspecialchars($post['subject'])), $post['hlcolor']) . "</a></td><td class=\"rowfollow nowrap\" align=\"left\"><a href=\"".htmlspecialchars("?action=viewforum&forumid=".$post['forumid'])."\"><b>" . htmlspecialchars($post["forumname"]) . "</b></a></td><td class=\"rowfollow nowrap\" align=\"left\">" . gettime($post['added'],true,false) . "&nbsp;|&nbsp;". forum_post_author_name($post, true) ."</td></tr>\n");
		}

		print("</table>\n");
		print($pagerbottom);
	}
f_mfoot();
die;
}

if (isset($_GET["catchup"]) && $_GET["catchup"] == 1){
	catch_up();
}

//-------- Handle unknown action
if ($action != "")
	stderr($lang_forums['std_forum_error'], $lang_forums['std_unknown_action']);

//-------- Default action: View forums

//-------- Get forums
if ($CURUSER)
	$USERUPDATESET[] = "forum_access = ".sqlesc(date("Y-m-d H:i:s"));

f_mhead($lang_forums['head_forums']);
begin_main_frame();
print("<h1 align=\"center\">".$SITENAME."&nbsp;".$lang_forums['text_forums']."</h1>");
print("<p align=\"center\"><a href=\"?action=search\"><b>".$lang_forums['text_search']."</b></a> | <a href=\"?action=viewunread\"><b>".$lang_forums['text_view_unread']."</b></a> | <a href=\"?catchup=1\"><b>".$lang_forums['text_catch_up']."</b></a> ".(user_can('forummanage') ? "| <a href=\"forummanage.php\"><b>".$lang_forums['text_forum_manager']."</b></a>":"")."</p>");

// 手机端：版块首页用「版块名 + 主题/帖子数」的简洁列表(按分区分组)，不用电脑版多列表格。
if (!empty($GLOBALS['F_MOBILE'])) {
	if (!$overforums = $Cache->get_value('overforums_list')) {
		$overforums = array();
		$res = sql_query("SELECT * FROM overforums ORDER BY sort ASC") or sqlerr(__FILE__, __LINE__);
		while ($row = mysql_fetch_array($res)) $overforums[] = $row;
		$Cache->cache_value('overforums_list', $overforums, 86400);
	}
	echo '<div class="f-mb-index">';
	foreach ($overforums as $a) {
		if (get_user_class() < $a["minclassview"]) continue;
		$rows = '';
		foreach (get_forum_row() as $fa) {
			if ($fa['forid'] != $a['id'] || get_user_class() < $fa["minclassread"]) continue;
			$desc = trim((string)$fa['description']);
			$rows .= '<a class="f-forum" href="' . htmlspecialchars("?action=viewforum&forumid=" . $fa['id']) . '">'
				. '<span class="f-forum-main"><span class="f-forum-name">' . htmlspecialchars($fa['name']) . '</span>'
				. ($desc !== '' ? '<span class="f-forum-desc">' . htmlspecialchars($desc) . '</span>' : '')
				. '</span><span class="f-forum-count">' . number_format((int)$fa['topiccount']) . ' / ' . number_format((int)$fa['postcount']) . '</span></a>';
		}
		if ($rows === '') continue;
		echo '<div class="f-cat">' . htmlspecialchars($a["name"]) . '</div>';
		echo '<div class="f-group">' . $rows . '</div>';
	}
	echo '</div>';
	end_main_frame();
	f_mfoot();
	exit;
}

print("<table border=\"1\" cellspacing=\"0\" cellpadding=\"5\" width=\"100%\">\n");

if (!$overforums = $Cache->get_value('overforums_list')){
	$overforums = array();
	$res = sql_query("SELECT * FROM overforums ORDER BY sort ASC") or sqlerr(__FILE__, __LINE__);
	while ($row = mysql_fetch_array($res))
		$overforums[] = $row;
	$Cache->cache_value('overforums_list', $overforums, 86400);
}
$count=0;
if ($Advertisement->enable_ad())
	$interoverforumsad=$Advertisement->get_ad('interoverforums');

foreach ($overforums as $a)
{
	if (get_user_class() < $a["minclassview"])
		continue;
	if ($count>=1)
	if ($Advertisement->enable_ad()){
		if (!empty($interoverforumsad[$count-1]))
			echo "<tr><td colspan=\"5\" align=\"center\" id=\"\">".$interoverforumsad[$count-1]."</td></tr>";
	}
	$forid = $a["id"];
	$overforumname = $a["name"];

	print("<tr><td align=\"left\" class=\"colhead\" width=\"99%\">".htmlspecialchars($overforumname)."</td><td align=\"center\" class=\"colhead\">".$lang_forums['col_topics']."</td>" .
	"<td align=\"center\" class=\"colhead\">".$lang_forums['col_posts']."</td>" .
	"<td align=\"left\" class=\"colhead\">".$lang_forums['col_last_post']."</td><td class=\"colhead\" align=\"left\">".$lang_forums['col_moderator']."</td></tr>\n");

	$forums = get_forum_row();
	foreach ($forums as $forums_arr)
	{
		if ($forums_arr['forid'] != $forid)
			continue;
		if (get_user_class() < $forums_arr["minclassread"])
			continue;

		$forumid = $forums_arr["id"];
		$forumname = htmlspecialchars($forums_arr["name"]);
		$forumdescription = htmlspecialchars($forums_arr["description"]);

		$forummoderators = get_forum_moderators($forums_arr['id'],false);
		if (!$forummoderators)
			$forummoderators = "<a href=\"contactstaff.php\"><i>".$lang_forums['text_apply_now']."</i></a>";

		$topiccount = number_format($forums_arr["topiccount"]);
		$postcount = number_format($forums_arr["postcount"]);

		// Find last post ID
		//Returns the ID of the last post of a forum
		if (!$arr = $Cache->get_value('forum_'.$forumid.'_last_replied_topic_content')){
			$res = sql_query("SELECT * FROM topics WHERE forumid=".sqlesc($forumid)." ORDER BY lastpost DESC LIMIT 1") or sqlerr(__FILE__, __LINE__);
			$arr = mysql_fetch_array($res);
			$Cache->cache_value('forum_'.$forumid.'_last_replied_topic_content', $arr, 900);
		}

		if ($arr)
		{
			$lastpostid = $arr['lastpost'];
			// Get last post info
			$post_arr = get_post_row($lastpostid);
			$lastposterid = $post_arr["userid"];
			$lastpostdate = gettime($post_arr["added"],true,false);
			$lasttopicid = $arr['id'];
			$hlcolor = $arr['hlcolor'];
			$lasttopicdissubject = $lasttopicsubject = $arr['subject'];
			$max_length_of_topic_subject = 35;
			$count_dispname = mb_strlen($lasttopicdissubject,"UTF-8");
			if ($count_dispname > $max_length_of_topic_subject)
				$lasttopicdissubject = mb_substr($lasttopicdissubject, 0, $max_length_of_topic_subject-2,"UTF-8") . "..";
			$lasttopic = highlight_topic(htmlspecialchars($lasttopicdissubject), $hlcolor);

			$lastpost = "<a href=\"".htmlspecialchars("?action=viewtopic&topicid=".$lasttopicid."&page=last#last")."\" title=\"".htmlspecialchars($lasttopicsubject)."\">".$lasttopic."</a><br />". $lastpostdate."&nbsp;|&nbsp;".forum_post_author_name($post_arr, true);

			$lastreadpost = get_last_read_post_id($lasttopicid);

			if ($lastreadpost >= $lastpostid)
				$img = get_topic_image("read");
			else
				$img = get_topic_image("unread");
		}
		else
		{
			$lastpost = "N/A";
			$img = get_topic_image("read");
		}
		$posttodaycount = $Cache->get_value('forum_'.$forumid.'_post_'.$today_date.'_count');
		if ($posttodaycount == ""){
			$res3 = sql_query("SELECT COUNT(posts.id) FROM posts LEFT JOIN topics ON posts.topicid = topics.id WHERE posts.added > ".sqlesc(date("Y-m-d"))." AND topics.forumid=".sqlesc($forumid)) or sqlerr(__FILE__, __LINE__);
			$row3 = mysql_fetch_row($res3);
			$posttodaycount = $row3[0];
			$Cache->cache_value('forum_'.$forumid.'_post_'.$today_date.'_count', $posttodaycount, 1800);
		}
		if ($posttodaycount > 0)
			$posttoday = "&nbsp;&nbsp;(".$lang_forums['text_today']."<b><font class=\"new\">".$posttodaycount."</font></b>)";
		else $posttoday = "";
		print("<tr><td class=\"rowfollow\" align=\"left\"><table border=\"0\" cellspacing=\"0\" cellpadding=\"0\"><tr><td class=\"embedded\" style='padding-right: 10px'>".$img."</td><td class=\"embedded\"><a href=\"".htmlspecialchars("?action=viewforum&forumid=".$forumid)."\"><font class=\"big\"><b>".$forumname."</b></font></a>" .$posttoday.
		"<br />".$forumdescription."</td></tr></table></td><td class=\"rowfollow\" align=\"center\" width=\"1%\">".$topiccount."</td><td class=\"rowfollow\" align=\"center\" width=\"1%\">".$postcount."</td>" .
		"<td class=\"rowfollow nowrap\" align=\"left\">".$lastpost."</td><td class=\"rowfollow\" align=\"left\">".$forummoderators."</td></tr>\n");
	}
	$count++;
}
// End Table Mod
print("</table>");
if ($showforumstats_main == "yes")
	forum_stats();
end_main_frame();
f_mfoot();
?>
