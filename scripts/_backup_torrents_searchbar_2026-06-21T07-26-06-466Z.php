<?php
require_once("../include/bittorrent.php");
dbconn(true);
require_once(get_langfile_path('torrents.php'));
require_once(get_langfile_path('special.php'));
loggedinorreturn();
parked();

if (!function_exists('hdvideo_torrent_styles')) {
	function hdvideo_run_schema_sql($sql) {
		$res = @sql_query($sql);
		if (!$res) {
			do_log('[HDVIDEO_REGION_STYLE_SCHEMA_ERROR] ' . $sql . ' :: ' . mysql_error(), 'error');
			return false;
		}
		return true;
	}
	function hdvideo_ensure_region_style_schema() {
		static $done = false;
		if ($done) return;
		$done = true;
		hdvideo_run_schema_sql("CREATE TABLE IF NOT EXISTS torrent_regions (id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(64) NOT NULL, sort_index INT NOT NULL DEFAULT 0, enabled TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NULL DEFAULT NULL, updated_at TIMESTAMP NULL DEFAULT NULL, PRIMARY KEY (id), UNIQUE KEY torrent_regions_name_unique (name), KEY torrent_regions_sort_index_index (sort_index), KEY torrent_regions_enabled_index (enabled)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
		hdvideo_run_schema_sql("CREATE TABLE IF NOT EXISTS torrent_styles (id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(64) NOT NULL, sort_index INT NOT NULL DEFAULT 0, enabled TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NULL DEFAULT NULL, updated_at TIMESTAMP NULL DEFAULT NULL, PRIMARY KEY (id), UNIQUE KEY torrent_styles_name_unique (name), KEY torrent_styles_sort_index_index (sort_index), KEY torrent_styles_enabled_index (enabled)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
		$regionColumn = @sql_query("SHOW COLUMNS FROM torrents LIKE 'region'");
		if (!$regionColumn || mysql_num_rows($regionColumn) === 0) {
			hdvideo_run_schema_sql("ALTER TABLE torrents ADD COLUMN region SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER category, ADD KEY torrents_region_index (region)");
		}
		hdvideo_run_schema_sql("CREATE TABLE IF NOT EXISTS torrent_style_torrent (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, torrent_id MEDIUMINT UNSIGNED NOT NULL, style_id SMALLINT UNSIGNED NOT NULL, created_at TIMESTAMP NULL DEFAULT NULL, updated_at TIMESTAMP NULL DEFAULT NULL, PRIMARY KEY (id), UNIQUE KEY torrent_style_torrent_torrent_id_style_id_unique (torrent_id, style_id), KEY torrent_style_torrent_torrent_id_index (torrent_id), KEY torrent_style_torrent_style_id_index (style_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
		hdvideo_seed_filter_options('torrent_styles', hdvideo_region_style_option_names('styles'));
		hdvideo_seed_filter_options('torrent_regions', hdvideo_region_style_option_names('regions'));
	}
	function hdvideo_seed_filter_options($table, array $names) {
		$count = count($names);
		$now = sqlesc(date('Y-m-d H:i:s'));
		$listedNames = [];
		foreach ($names as $index => $name) {
			$name = trim((string)$name);
			if ($name === '') continue;
			$listedNames[] = sqlesc($name);
			$sortIndex = $count - $index;
			hdvideo_run_schema_sql("INSERT INTO `$table` (name, sort_index, enabled, created_at, updated_at) VALUES (" . sqlesc($name) . ", $sortIndex, 1, $now, $now) ON DUPLICATE KEY UPDATE sort_index = VALUES(sort_index), enabled = VALUES(enabled), updated_at = VALUES(updated_at)");
		}
		if ($listedNames) {
			hdvideo_run_schema_sql("UPDATE `$table` SET enabled = 0, updated_at = $now WHERE name NOT IN (" . implode(',', $listedNames) . ")");
		}
	}
	function hdvideo_region_style_default_options($type) {
		$defaults = [
			'styles' => ['短片', '喜剧', '动作', '科幻', '惊悚', '剧情', '爱情', '恐怖', '犯罪', '悬疑'],
			'regions' => ['中国大陆', '美国', '韩国', '英国', '泰国', '中国港台', '日本', '法国', '德国', '意大利'],
		];
		return $defaults[$type] ?? [];
	}
	function hdvideo_region_style_option_names($type) {
		$raw = get_setting("torrent_region_style.$type", '');
		if (is_array($raw)) $raw = implode("\n", $raw);
		$raw = trim((string)$raw);
		if ($raw === '') return hdvideo_region_style_default_options($type);
		$names = preg_split('/[\r\n,，、]+/u', $raw);
		$result = [];
		foreach ($names as $name) {
			$name = trim((string)$name);
			if ($name !== '') $result[$name] = $name;
		}
		return array_values($result ?: hdvideo_region_style_default_options($type));
	}
	function hdvideo_region_style_enabled() {
		return get_setting('torrent_region_style.enabled', 'yes') !== 'no';
	}
	function hdvideo_torrent_filter_items($table) {
		if (!hdvideo_region_style_enabled()) return [];
		hdvideo_ensure_region_style_schema();
		$items = [];
		$res = sql_query("SELECT id, name FROM `$table` WHERE enabled = 1 ORDER BY sort_index DESC, id ASC");
		while ($row = mysql_fetch_assoc($res)) $items[] = $row;
		return $items;
	}
	function hdvideo_torrent_styles() {
		return hdvideo_torrent_filter_items('torrent_styles');
	}
	function hdvideo_torrent_regions() {
		return hdvideo_torrent_filter_items('torrent_regions');
	}
	function hdvideo_filter_valid_ids($ids, $items) {
		$valid = [];
		foreach ($items as $item) $valid[(int)$item['id']] = true;
		$result = [];
		foreach ((array)$ids as $id) {
			$id = (int)$id;
			if ($id > 0 && isset($valid[$id])) $result[$id] = $id;
		}
		return array_values($result);
	}
}

//check searchbox
switch (nexus()->getScript()) {
    case 'torrents':
        $sectiontype = $browsecatmode;
        break;
    case 'special':
        if (get_setting('main.spsct') != 'yes') {
            httperr();
        }
        if (!user_can('view_special_torrent')) {
            stderr($lang_special['std_sorry'],$lang_special['std_permission_denied_only'].get_user_class_name(get_setting('authority.view_special_torrent'),false,true,true).sprintf($lang_special['std_or_above_can_view'], \App\Models\Setting::getSiteName()),false);
        }
        $sectiontype = $specialcatmode;
        break;
    default:
        $sectiontype = 0;
}
/**
 * tags
 */
$tagRep = new \App\Repositories\TagRepository();
$allTags = $tagRep->listAll($sectiontype);
$filterInputWidth = 62;
$searchParams = $_GET;
$searchParams['mode'] = $sectiontype;

$showsubcat = get_searchbox_value($sectiontype, 'showsubcat');//whether show subcategory (i.e. sources, codecs) or not
$showsource = get_searchbox_value($sectiontype, 'showsource'); //whether show sources or not
$showmedium = get_searchbox_value($sectiontype, 'showmedium'); //whether show media or not
$showcodec = get_searchbox_value($sectiontype, 'showcodec'); //whether show codecs or not
$showstandard = get_searchbox_value($sectiontype, 'showstandard'); //whether show standards or not
$showprocessing = get_searchbox_value($sectiontype, 'showprocessing'); //whether show processings or not
$showteam = get_searchbox_value($sectiontype, 'showteam'); //whether show teams or not
$showaudiocodec = get_searchbox_value($sectiontype, 'showaudiocodec'); //whether show audio codec or not
$catsperrow = get_searchbox_value($sectiontype, 'catsperrow'); //show how many cats per line in search box
$catpadding = get_searchbox_value($sectiontype, 'catpadding'); //padding space between categories in pixel

$cats = genrelist($sectiontype);
$torrentStyles = hdvideo_torrent_styles();
$torrentRegions = hdvideo_torrent_regions();
if ($showsubcat){
	if ($showsource) $sources = searchbox_item_list("sources", $sectiontype);
	if ($showmedium) $media = searchbox_item_list("media", $sectiontype);
	if ($showcodec) $codecs = searchbox_item_list("codecs", $sectiontype);
	if ($showstandard) $standards = searchbox_item_list("standards", $sectiontype);
	if ($showprocessing) $processings = searchbox_item_list("processings", $sectiontype);
	if ($showteam) $teams = searchbox_item_list("teams", $sectiontype);
	if ($showaudiocodec) $audiocodecs = searchbox_item_list("audiocodecs", $sectiontype);
}

$searchstr_ori = htmlspecialchars(trim($_GET["search"] ?? ''));
$searchstr = mysql_real_escape_string(trim($_GET["search"] ?? ''));
if (empty($searchstr)) {
    unset($searchstr);
}

$meilisearchEnabled = get_setting('meilisearch.enabled') == 'yes';
$shouldUseMeili = $meilisearchEnabled && !empty($searchstr);
do_log("[SHOULD_USE_MEILI]: $shouldUseMeili");
// sorting by MarkoStamcar
$column = '';
$ascdesc = '';
if (isset($_GET['sort']) && $_GET['sort'] && isset($_GET['type']) && $_GET['type']) {

	switch($_GET['sort']) {
		case '1': $column = "name"; break;
		case '2': $column = "numfiles"; break;
		case '3': $column = "comments"; break;
		case '4': $column = "added"; break;
		case '5': $column = "size"; break;
		case '6': $column = "times_completed"; break;
		case '7': $column = "seeders"; break;
		case '8': $column = "leechers"; break;
		case '9': $column = "owner"; break;
		default: $column = "id"; break;
	}

	switch($_GET['type']) {
		case 'asc': $ascdesc = "ASC"; $linkascdesc = "asc"; break;
		case 'desc': $ascdesc = "DESC"; $linkascdesc = "desc"; break;
		default: $ascdesc = "DESC"; $linkascdesc = "desc"; break;
	}

	if($column == "owner")
	{
		$orderby = "ORDER BY pos_state DESC, torrents.anonymous, users.username " . $ascdesc;
	}
	else
	{
		$orderby = "ORDER BY pos_state DESC, torrents." . $column . " " . $ascdesc;
	}

	$pagerlink = "sort=" . intval($_GET['sort']) . "&type=" . $linkascdesc . "&";

} else {

	$orderby = "ORDER BY pos_state DESC, torrents.id DESC";
	$pagerlink = "";

}

$allCategoryId = \App\Models\SearchBox::listCategoryId($sectiontype);
$addparam = "";
$wherea = array();
$wherecatina = array();
$wheresourceina = array();
$wheremediumina = array();
$wherecodecina = array();
$wherestandardina = array();
$whereprocessingina = array();
$whereteamina = array();
$whereaudiocodecina = array();
$whereothera = [];
$style_get = intval($_GET['style'] ?? 0);
$region_get = intval($_GET['region'] ?? 0);
//----------------- start whether show torrents from all sections---------------------//
if ($_GET)
	$allsec = intval($_GET["allsec"] ?? 0);
else $allsec = 0;
if ($allsec == 1)		//show torrents from all sections
{
	$addparam .= "allsec=1&";
}
// ----------------- end whether ignoring section ---------------------//
// ----------------- start bookmarked ---------------------//
$inclbookmarked = 0;
if ($_GET)
	$inclbookmarked = intval($_GET["inclbookmarked"] ?? 0);
elseif ($CURUSER['notifs']){
	if (strpos($CURUSER['notifs'], "[inclbookmarked=0]") !== false)
		$inclbookmarked = 0;
	elseif (strpos($CURUSER['notifs'], "[inclbookmarked=1]") !== false)
		$inclbookmarked = 1;
	elseif (strpos($CURUSER['notifs'], "[inclbookmarked=2]") !== false)
		$inclbookmarked = 2;
}

if (!in_array($inclbookmarked,array(0,1,2)))
{
	$inclbookmarked = 0;
	write_log("User " . $CURUSER["username"] . "," . $CURUSER["ip"] . " is hacking inclbookmarked field in" . $_SERVER['SCRIPT_NAME'], 'mod');
}
if ($inclbookmarked == 0)  //all(bookmarked,not)
{
	$addparam .= "inclbookmarked=0&";
}
elseif ($inclbookmarked == 1)		//bookmarked
{
	$addparam .= "inclbookmarked=1&";
	if(isset($CURUSER))
	$wherea[] = "torrents.id IN (SELECT torrentid FROM bookmarks WHERE userid=" . $CURUSER['id'] . ")";
}
elseif ($inclbookmarked == 2)		//not bookmarked
{
	$addparam .= "inclbookmarked=2&";
	if(isset($CURUSER))
	$wherea[] = "torrents.id NOT IN (SELECT torrentid FROM bookmarks WHERE userid=" . $CURUSER['id'] . ")";
}
// ----------------- end bookmarked ---------------------//

// ----------------- start include dead ---------------------//
if (isset($_GET["incldead"]))
	$include_dead = intval($_GET["incldead"] ?? 0);
elseif ($CURUSER['notifs']){
	if (strpos($CURUSER['notifs'], "[incldead=0]") !== false)
		$include_dead = 0;
	elseif (strpos($CURUSER['notifs'], "[incldead=1]") !== false)
		$include_dead = 1;
	elseif (strpos($CURUSER['notifs'], "[incldead=2]") !== false)
		$include_dead = 2;
	else $include_dead = 1;
}
else $include_dead = 1;

if (!in_array($include_dead,array(0,1,2)))
{
	$include_dead = 0;
	write_log("User " . $CURUSER["username"] . "," . $CURUSER["ip"] . " is hacking incldead field in" . $_SERVER['SCRIPT_NAME'], 'mod');
}
if ($include_dead == 0)  //all(active,dead)
{
	$addparam .= "incldead=0&";
}
elseif ($include_dead == 1)		//active
{
	$addparam .= "incldead=1&";
//	$wherea[] = "visible = 'yes'";
    $whereothera[] = "visible = 'yes'";
}
elseif ($include_dead == 2)		//dead
{
	$addparam .= "incldead=2&";
//	$wherea[] = "visible = 'no'";
    $whereothera[] = "visible = 'no'";
}

// In active/dead views, prioritize torrents tagged as official.
$officialTag = intval(get_setting('bonus.official_tag', 0));
if ($officialTag > 0 && in_array($include_dead, [1, 2], true)) {
	$officialOrder = "CASE WHEN EXISTS (SELECT 1 FROM torrent_tags tt WHERE tt.torrent_id = torrents.id AND tt.tag_id = {$officialTag}) THEN 1 ELSE 0 END DESC, ";
	$orderby = preg_replace('/^ORDER BY\s+/i', 'ORDER BY ' . $officialOrder, $orderby);
}
// ----------------- end include dead ---------------------//

if (!isset($CURUSER) || !user_can('seebanned')) {
//    $wherea[] = "banned = 'no'";
    $whereothera[] = "banned = 'no'";
    $searchParams["banned"] = 'no';
}

$special_state = 0;
if ($_GET)
	$special_state = intval($_GET["spstate"] ?? 0);
elseif ($CURUSER['notifs']){
	if (strpos($CURUSER['notifs'], "[spstate=0]") !== false)
		$special_state = 0;
	elseif (strpos($CURUSER['notifs'], "[spstate=1]") !== false)
		$special_state = 1;
	elseif (strpos($CURUSER['notifs'], "[spstate=2]") !== false)
		$special_state = 2;
	elseif (strpos($CURUSER['notifs'], "[spstate=3]") !== false)
		$special_state = 3;
	elseif (strpos($CURUSER['notifs'], "[spstate=4]") !== false)
		$special_state = 4;
	elseif (strpos($CURUSER['notifs'], "[spstate=5]") !== false)
		$special_state = 5;
	elseif (strpos($CURUSER['notifs'], "[spstate=6]") !== false)
		$special_state = 6;
	elseif (strpos($CURUSER['notifs'], "[spstate=7]") !== false)
		$special_state = 7;
}

if (!in_array($special_state,array(0,1,2,3,4,5,6,7)))
{
	$special_state = 0;
	write_log("User " . $CURUSER["username"] . "," . $CURUSER["ip"] . " is hacking spstate field in " . $_SERVER['SCRIPT_NAME'], 'mod');
}
if($special_state == 0)	//all
{
	$addparam .= "spstate=0&";
}
elseif ($special_state == 1)	//normal
{
	$addparam .= "spstate=1&";

	$wherea[] = "sp_state = 1";

	if(get_global_sp_state() == 1)
	{
		$wherea[] = "sp_state = 1";
	}
}
elseif ($special_state == 2)	//free
{
	$addparam .= "spstate=2&";

	if(get_global_sp_state() == 1)
	{
		$wherea[] = "sp_state = 2";
	}
	else if(get_global_sp_state() == 2)
	{
		;
	}
}
elseif ($special_state == 3)	//2x up
{
	$addparam .= "spstate=3&";
	if(get_global_sp_state() == 1)	//only sp state
	{
		$wherea[] = "sp_state = 3";
	}
	else if(get_global_sp_state() == 3)	//all
	{
		;
	}
}
elseif ($special_state == 4)	//2x up and free
{
	$addparam .= "spstate=4&";

	if(get_global_sp_state() == 1)	//only sp state
	{
		$wherea[] = "sp_state = 4";
	}
	else if(get_global_sp_state() == 4)	//all
	{
		;
	}
}
elseif ($special_state == 5)	//half down
{
	$addparam .= "spstate=5&";

	if(get_global_sp_state() == 1)	//only sp state
	{
		$wherea[] = "sp_state = 5";
	}
	else if(get_global_sp_state() == 5)	//all
	{
		;
	}
}
elseif ($special_state == 6)	//half down
{
	$addparam .= "spstate=6&";

	if(get_global_sp_state() == 1)	//only sp state
	{
		$wherea[] = "sp_state = 6";
	}
	else if(get_global_sp_state() == 6)	//all
	{
		;
	}
}
elseif ($special_state == 7)	//30% down
{
	$addparam .= "spstate=7&";

	if(get_global_sp_state() == 1)	//only sp state
	{
		$wherea[] = "sp_state = 7";
	}
	else if(get_global_sp_state() == 7)	//all
	{
		;
	}
}

$category_get = intval($_GET["cat"] ?? 0);
$source_get = $medium_get = $codec_get = $standard_get = $processing_get = $team_get = $audiocodec_get = 0;
if ($showsubcat){
if ($showsource) $source_get = intval($_GET["source"] ?? 0);
if ($showmedium) $medium_get = intval($_GET["medium"] ?? 0);
if ($showcodec) $codec_get = intval($_GET["codec"] ?? 0);
if ($showstandard) $standard_get = intval($_GET["standard"] ?? 0);
if ($showprocessing) $processing_get = intval($_GET["processing"] ?? 0);
if ($showteam) $team_get = intval($_GET["team"] ?? 0);
if ($showaudiocodec) $audiocodec_get = intval($_GET["audiocodec"] ?? 0);
}

$all = intval($_GET["all"] ?? 0);

if (!$all)
{
	if (!$_GET && $CURUSER['notifs'])
	{
		$all = true;
		foreach ($cats as $cat)
		{
			$all &= $cat['id'];
			$mystring = $CURUSER['notifs'];
			$findme  = '[cat'.$cat['id'].']';
			$search = strpos($mystring, $findme);
			if ($search === false)
			$catcheck = false;
			else
			$catcheck = true;

			if ($catcheck)
			{
				$wherecatina[] = $cat['id'];
				$addparam .= "cat$cat[id]=1&";
			}
		}
		if ($showsubcat){
		if ($showsource)
		foreach ($sources as $source)
		{
			$all &= $source['id'];
			$mystring = $CURUSER['notifs'];
			$findme  = '[sou'.$source['id'].']';
			$search = strpos($mystring, $findme);
			if ($search === false)
			$sourcecheck = false;
			else
			$sourcecheck = true;

			if ($sourcecheck)
			{
				$wheresourceina[] = $source['id'];
				$addparam .= "source{$source['id']}=1&";
			}
		}
		if ($showmedium)
		foreach ($media as $medium)
		{
			$all &= $medium['id'];
			$mystring = $CURUSER['notifs'];
			$findme  = '[med'.$medium['id'].']';
			$search = strpos($mystring, $findme);
			if ($search === false)
			$mediumcheck = false;
			else
			$mediumcheck = true;

			if ($mediumcheck)
			{
				$wheremediumina[] = $medium['id'];
				$addparam .= "medium{$medium['id']}=1&";
			}
		}
		if ($showcodec)
		foreach ($codecs as $codec)
		{
			$all &= $codec['id'];
			$mystring = $CURUSER['notifs'];
			$findme  = '[cod'.$codec['id'].']';
			$search = strpos($mystring, $findme);
			if ($search === false)
			$codeccheck = false;
			else
			$codeccheck = true;

			if ($codeccheck)
			{
				$wherecodecina[] = $codec['id'];
				$addparam .= "codec{$codec['id']}=1&";
			}
		}
		if ($showstandard)
		foreach ($standards as $standard)
		{
			$all &= $standard['id'];
			$mystring = $CURUSER['notifs'];
			$findme  = '[sta'.$standard['id'].']';
			$search = strpos($mystring, $findme);
			if ($search === false)
			$standardcheck = false;
			else
			$standardcheck = true;

			if ($standardcheck)
			{
				$wherestandardina[] = $standard['id'];
				$addparam .= "standard{$standard['id']}=1&";
			}
		}
		if ($showprocessing)
		foreach ($processings as $processing)
		{
			$all &= $processing['id'];
			$mystring = $CURUSER['notifs'];
			$findme  = '[pro'.$processing['id'].']';
			$search = strpos($mystring, $findme);
			if ($search === false)
			$processingcheck = false;
			else
			$processingcheck = true;

			if ($processingcheck)
			{
				$whereprocessingina[] = $processing['id'];
				$addparam .= "processing{$processing['id']}=1&";
			}
		}
		if ($showteam)
		foreach ($teams as $team)
		{
			$all &= $team['id'];
			$mystring = $CURUSER['notifs'];
			$findme  = '[tea'.$team['id'].']';
			$search = strpos($mystring, $findme);
			if ($search === false)
			$teamcheck = false;
			else
			$teamcheck = true;

			if ($teamcheck)
			{
				$whereteamina[] = $team['id'];
				$addparam .= "team{$team['id']}=1&";
			}
		}
		if ($showaudiocodec)
		foreach ($audiocodecs as $audiocodec)
		{
			$all &= $audiocodec['id'];
			$mystring = $CURUSER['notifs'];
			$findme  = '[aud'.$audiocodec['id'].']';
			$search = strpos($mystring, $findme);
			if ($search === false)
			$audiocodeccheck = false;
			else
			$audiocodeccheck = true;

			if ($audiocodeccheck)
			{
				$whereaudiocodecina[] = $audiocodec['id'];
				$addparam .= "audiocodec{$audiocodec['id']}=1&";
			}
		}
		}
	}
	// when one clicked the cat, source, etc. name/image
	elseif ($category_get)
	{
		int_check($category_get,true,true,true);
		$wherecatina[] = $category_get;
		$addparam .= "cat=$category_get&";
	}
	elseif ($medium_get)
	{
		int_check($medium_get,true,true,true);
		$wheremediumina[] = $medium_get;
		$addparam .= "medium=$medium_get&";
	}
	elseif ($source_get)
	{
		int_check($source_get,true,true,true);
		$wheresourceina[] = $source_get;
		$addparam .= "source=$source_get&";
	}
	elseif ($codec_get)
	{
		int_check($codec_get,true,true,true);
		$wherecodecina[] = $codec_get;
		$addparam .= "codec=$codec_get&";
	}
	elseif ($standard_get)
	{
		int_check($standard_get,true,true,true);
		$wherestandardina[] = $standard_get;
		$addparam .= "standard=$standard_get&";
	}
	elseif ($processing_get)
	{
		int_check($processing_get,true,true,true);
		$whereprocessingina[] = $processing_get;
		$addparam .= "processing=$processing_get&";
	}
	elseif ($team_get)
	{
		int_check($team_get,true,true,true);
		$whereteamina[] = $team_get;
		$addparam .= "team=$team_get&";
	}
	elseif ($audiocodec_get)
	{
		int_check($audiocodec_get,true,true,true);
		$whereaudiocodecina[] = $audiocodec_get;
		$addparam .= "audiocodec=$audiocodec_get&";
	}
	else	//select and go
	{
		$all = True;
		foreach ($cats as $cat)
		{
		    $__is = (isset($_GET["cat{$cat['id']}"]) && $_GET["cat{$cat['id']}"]);
			$all &= $__is;
			if ($__is)
			{
				$wherecatina[] = $cat['id'];
				$addparam .= "cat{$cat['id']}=1&";
			}
		}
		if ($showsubcat){
		if ($showsource)
		foreach ($sources as $source)
		{
            $__is = (isset($_GET["source{$source['id']}"]) && $_GET["source{$source['id']}"]);
            $all &= $__is;
			if ($__is)
			{
				$wheresourceina[] = $source['id'];
				$addparam .= "source{$source['id']}=1&";
			}
		}
		if ($showmedium)
		foreach ($media as $medium)
		{
            $__is = (isset($_GET["medium{$medium['id']}"]) && $_GET["medium{$medium['id']}"]);
            $all &= $__is;
            if ($__is)
			{
				$wheremediumina[] = $medium['id'];
				$addparam .= "medium{$medium['id']}=1&";
			}
		}
		if ($showcodec)
		foreach ($codecs as $codec)
		{
            $__is = (isset($_GET["codec{$codec['id']}"]) && $_GET["codec{$codec['id']}"]);
            $all &= $__is;
            if ($__is)
			{
				$wherecodecina[] = $codec['id'];
				$addparam .= "codec{$codec['id']}=1&";
			}
		}
		if ($showstandard)
		foreach ($standards as $standard)
		{
            $__is = (isset($_GET["standard{$standard['id']}"]) && $_GET["standard{$standard['id']}"]);
            $all &= $__is;
            if ($__is)
			{
				$wherestandardina[] = $standard['id'];
				$addparam .= "standard{$standard['id']}=1&";
			}
		}
		if ($showprocessing)
		foreach ($processings as $processing)
		{
            $__is = (isset($_GET["processing{$processing['id']}"]) && $_GET["processing{$processing['id']}"]);
            $all &= $__is;
            if ($__is)
			{
				$whereprocessingina[] = $processing['id'];
				$addparam .= "processing{$processing['id']}=1&";
			}
		}
		if ($showteam)
		foreach ($teams as $team)
		{
            $__is = (isset($_GET["team{$team['id']}"]) && $_GET["team{$team['id']}"]);
            $all &= $__is;
            if ($__is)
			{
				$whereteamina[] = $team['id'];
				$addparam .= "team{$team['id']}=1&";
			}
		}
		if ($showaudiocodec)
		foreach ($audiocodecs as $audiocodec)
		{
            $__is = (isset($_GET["audiocodec{$audiocodec['id']}"]) && $_GET["audiocodec{$audiocodec['id']}"]);
            $all &= $__is;
            if ($__is)
			{
				$whereaudiocodecina[] = $audiocodec['id'];
				$addparam .= "audiocodec{$audiocodec['id']}=1&";
			}
		}
		}
	}
}

if ($all)
{
	//stderr("in if all","");
	$wherecatina = array();
	if ($showsubcat){
	$wheresourceina = array();
	$wheremediumina = array();
	$wherecodecina = array();
	$wherestandardina = array();
	$whereprocessingina = array();
	$whereteamina = array();
	$whereaudiocodecina = array();}
	$addparam .= "";
}
//stderr("", count($wherecatina)."-". count($wheresourceina));
$wherecatin = $wheresourcein = $wheremediumin = $wherecodecin = $wherestandardin = $whereprocessingin = $whereteamin = $whereaudiocodecin = '';
if (empty($wherecatina) && !(in_array($inclbookmarked, [1, 2]) && $allsec == 1)) {
    //require limit in some category
    $wherecatina = $allCategoryId;
}
if (count($wherecatina) > 1)
$wherecatin = implode(",",$wherecatina);
elseif (count($wherecatina) == 1)
$wherea[] = "category = $wherecatina[0]";

if ($showsubcat){
if ($showsource){
if (count($wheresourceina) > 1)
$wheresourcein = implode(",",$wheresourceina);
elseif (count($wheresourceina) == 1)
$wherea[] = "source = $wheresourceina[0]";}

if ($showmedium){
if (count($wheremediumina) > 1)
$wheremediumin = implode(",",$wheremediumina);
elseif (count($wheremediumina) == 1)
$wherea[] = "medium = $wheremediumina[0]";}

if ($showcodec){
if (count($wherecodecina) > 1)
$wherecodecin = implode(",",$wherecodecina);
elseif (count($wherecodecina) == 1)
$wherea[] = "codec = $wherecodecina[0]";}

if ($showstandard){
if (count($wherestandardina) > 1)
$wherestandardin = implode(",",$wherestandardina);
elseif (count($wherestandardina) == 1)
$wherea[] = "standard = $wherestandardina[0]";}

if ($showprocessing){
if (count($whereprocessingina) > 1)
$whereprocessingin = implode(",",$whereprocessingina);
elseif (count($whereprocessingina) == 1)
$wherea[] = "processing = $whereprocessingina[0]";}
}
if ($showteam){
if (count($whereteamina) > 1)
$whereteamin = implode(",",$whereteamina);
elseif (count($whereteamina) == 1)
$wherea[] = "team = $whereteamina[0]";}

if ($showaudiocodec){
if (count($whereaudiocodecina) > 1)
$whereaudiocodecin = implode(",",$whereaudiocodecina);
elseif (count($whereaudiocodecina) == 1)
$wherea[] = "audiocodec = $whereaudiocodecina[0]";}

if ($region_get) {
	int_check($region_get, true, true, true);
	if (hdvideo_filter_valid_ids([$region_get], $torrentRegions)) {
		$wherea[] = "torrents.region = $region_get";
		$searchParams['region'] = $region_get;
		$addparam .= "region=$region_get&";
	}
}

if ($style_get) {
	int_check($style_get, true, true, true);
	if (hdvideo_filter_valid_ids([$style_get], $torrentStyles)) {
		$wherea[] = "EXISTS (SELECT 1 FROM torrent_style_torrent tst WHERE tst.torrent_id = torrents.id AND tst.style_id = $style_get)";
		$searchParams['style'] = $style_get;
		$addparam .= "style=$style_get&";
	}
}

if ($region_get || $style_get) {
	$shouldUseMeili = false;
}

$wherebase = $wherea;
$search_area = 0;
if (isset($searchstr))
{
	if (!isset($_GET['notnewword']) || !$_GET['notnewword']){
		$notnewword="";
	}
	else{
		$notnewword="notnewword=1&";
	}
	$search_mode = intval($_GET["search_mode"] ?? 0);
    /**
     * Deprecated search mode: 1(OR)
     * @since 1.8
     */
	if (!in_array($search_mode,array(0,2)))
	{
		$search_mode = 0;
		write_log("User " . $CURUSER["username"] . "," . $CURUSER["ip"] . " is hacking search_mode field in" . $_SERVER['SCRIPT_NAME'], 'mod');
	}

	$search_area = intval($_GET["search_area"] ?? 0) ;

	if ($search_area == 4) {
		$searchstr = (int)parse_imdb_id($searchstr);
	}
	$like_expression_array =array();
	unset($like_expression_array);

	switch ($search_mode)
	{
		case 0:	// AND, OR
		case 1	:
			{
				$searchstr = str_replace(".", " ", $searchstr);
				$searchstr_exploded = explode(" ", $searchstr);
				$searchstr_exploded_count= 0;
				foreach ($searchstr_exploded as $searchstr_element)
				{
					$searchstr_element = trim($searchstr_element);	// furthur trim to ensure that multi space seperated words still work
					$searchstr_exploded_count++;
					if ($searchstr_exploded_count > 3)	// maximum 3 keywords
					break;
					$like_expression_array[] = " LIKE '%" . $searchstr_element. "%'";
				}
				break;
			}
		case 2	:	// exact
		{
			$like_expression_array[] = " LIKE '%" . $searchstr. "%'";
			break;
		}
		/*case 3 :	// parsed
		{
		$like_expression_array[] = $searchstr;
		break;
		}*/
	}
	$ANDOR = ($search_mode == 0 ? " AND " : " OR ");	// only affects mode 0 and mode 1

	switch ($search_area)
	{
		case 0   :	// torrent name
		{
			foreach ($like_expression_array as &$like_expression_array_element)
			$like_expression_array_element = "(torrents.name" . $like_expression_array_element." OR torrents.small_descr". $like_expression_array_element.")";
			$wherea[] =  implode($ANDOR, $like_expression_array);
			break;
		}
		case 1	:	// torrent description
		{
			foreach ($like_expression_array as &$like_expression_array_element)
//			$like_expression_array_element = "torrents.descr". $like_expression_array_element;
			$like_expression_array_element = "torrent_extras.descr". $like_expression_array_element;
			$wherea[] =  implode($ANDOR,  $like_expression_array);
			break;
		}
		/*case 2	:	// torrent small description
		{
			foreach ($like_expression_array as &$like_expression_array_element)
			$like_expression_array_element =  "torrents.small_descr". $like_expression_array_element;
			$wherea[] =  implode($ANDOR, $like_expression_array);
			break;
		}*/
		case 3	:	// torrent uploader
		{
			foreach ($like_expression_array as &$like_expression_array_element)
			$like_expression_array_element =  "users.username". $like_expression_array_element;

			if(!isset($CURUSER))	// not registered user, only show not anonymous torrents
			{
				$wherea[] =  implode($ANDOR, $like_expression_array) . " AND torrents.anonymous = 'no'";
			}
			else
			{
				if(user_can('torrentmanage'))	// moderator or above, show all
				{
					$wherea[] =  implode($ANDOR, $like_expression_array);
				}
				else // only show normal torrents and anonymous torrents from hiself
				{
					$wherea[] =   "(" . implode($ANDOR, $like_expression_array) . " AND torrents.anonymous = 'no') OR (" . implode($ANDOR, $like_expression_array). " AND torrents.anonymous = 'yes' AND users.id=" . $CURUSER["id"] . ") ";
				}
			}
			break;
		}
		case 4  :  //imdb url
			foreach ($like_expression_array as &$like_expression_array_element)
			$like_expression_array_element = "torrents.url". $like_expression_array_element;
			$wherea[] =  implode($ANDOR,  $like_expression_array);
			break;
		default :	// unkonwn
		{
			$search_area = 0;
			$wherea[] =  "torrents.name LIKE '%" . $searchstr . "%'";
			write_log("User " . $CURUSER["username"] . "," . $CURUSER["ip"] . " is hacking search_area field in" . $_SERVER['SCRIPT_NAME'], 'mod');
			break;
		}
	}
	$addparam .= "search_area=" . $search_area . "&";
	$addparam .= "search=" . rawurlencode($searchstr) . "&".$notnewword;
	$addparam .= "search_mode=".$search_mode."&";
}

//approval status
$approvalStatusNoneVisible = get_setting('torrent.approval_status_none_visible');
$approvalStatusIconEnabled = get_setting('torrent.approval_status_icon_enabled');
$approvalStatus = null;
$showApprovalStatusFilter = false;
//when enable approval status icon, all user can use this filter, otherwise only staff member and approval none visible is 'no' can use
if ($approvalStatusIconEnabled == 'yes' || (user_can('torrent-approval') && $approvalStatusNoneVisible == 'no')) {
    $showApprovalStatusFilter = true;
}
//when user can use approval status filter, and pass `approval_status` parameter, will affect
//OR if [not approval can not be view] and not staff member, force to view  approval allowed
if ($showApprovalStatusFilter && isset($_REQUEST['approval_status']) && is_numeric($_REQUEST['approval_status'])) {
    $approvalStatus = intval($_REQUEST['approval_status']);
    $wherea[] = "torrents.approval_status = $approvalStatus";
    $searchParams['approval_status'] = $approvalStatus;
    $addparam .= "approval_status=$approvalStatus&";
} elseif ($approvalStatusNoneVisible == 'no' && !user_can('torrent-approval')) {
    $wherea[] = "torrents.approval_status = " . \App\Models\Torrent::APPROVAL_STATUS_ALLOW;
    $searchParams['approval_status'] = \App\Models\Torrent::APPROVAL_STATUS_ALLOW;
}

if (isset($_GET['size_begin']) && ctype_digit($_GET['size_begin'])) {
    $wherea[] = "torrents.size >= " . intval($_GET['size_begin']) * 1024 * 1024 * 1024;
    $addparam .= "size_begin=" . intval($_GET['size_begin']) . "&";
}
if (isset($_GET['size_end']) && ctype_digit($_GET['size_end'])) {
    $wherea[] = "torrents.size <= " . intval($_GET['size_end']) * 1024 * 1024 * 1024;
    $addparam .= "size_end=" . intval($_GET['size_end']) . "&";
}

if (isset($_GET['seeders_begin']) && ctype_digit($_GET['seeders_begin'])) {
    $wherea[] = "torrents.seeders >= " . (int)$_GET['seeders_begin'];
    $addparam .= "seeders_begin=" . intval($_GET['seeders_begin']) . "&";
}
if (isset($_GET['seeders_end']) && ctype_digit($_GET['seeders_end'])) {
    $wherea[] = "torrents.seeders <= " . (int)$_GET['seeders_end'];
    $addparam .= "seeders_end=" . intval($_GET['seeders_end']) . "&";
}

if (isset($_GET['leechers_begin']) && ctype_digit($_GET['leechers_begin'])) {
    $wherea[] = "torrents.leechers >= " . (int)$_GET['leechers_begin'];
    $addparam .= "leechers_begin=" . intval($_GET['leechers_begin']) . "&";
}
if (isset($_GET['leechers_end']) && ctype_digit($_GET['leechers_end'])) {
    $wherea[] = "torrents.leechers <= " . (int)$_GET['leechers_end'];
    $addparam .= "leechers_end=" . intval($_GET['leechers_end']) . "&";
}

if (isset($_GET['times_completed_begin']) && ctype_digit($_GET['times_completed_begin'])) {
    $wherea[] = "torrents.times_completed >= " . (int)$_GET['times_completed_begin'];
    $addparam .= "times_completed_begin=" . intval($_GET['times_completed_begin']) . "&";
}
if (isset($_GET['times_completed_end']) && ctype_digit($_GET['times_completed_end'])) {
    $wherea[] = "torrents.times_completed <= " . (int)$_GET['times_completed_end'];
    $addparam .= "times_completed_end=" . intval($_GET['times_completed_end']) . "&";
}

if (isset($_GET['added_begin']) && !empty($_GET['added_begin'])) {
    $wherea[] = "torrents.added >= " . sqlesc($_GET['added_begin']);
    $addparam .= "added_begin=" . $_GET['added_begin'] . "&";
}
if (isset($_GET['added_end']) && !empty($_GET['added_end'])) {
    $wherea[] = "torrents.added <= " . sqlesc(\Carbon\Carbon::parse($_GET['added_end'])->endOfDay()->toDateTimeString());
    $addparam .= "added_end=" . $_GET['added_end'] . "&";
}

$where = implode(" AND ", $wherea);

if ($wherecatin)
$where .= ($where ? " AND " : "") . "category IN(" . $wherecatin . ")";
if ($showsubcat){
if ($wheresourcein)
$where .= ($where ? " AND " : "") . "source IN(" . $wheresourcein . ")";
if ($wheremediumin)
$where .= ($where ? " AND " : "") . "medium IN(" . $wheremediumin . ")";
if ($wherecodecin)
$where .= ($where ? " AND " : "") . "codec IN(" . $wherecodecin . ")";
if ($wherestandardin)
$where .= ($where ? " AND " : "") . "standard IN(" . $wherestandardin . ")";
if ($whereprocessingin)
$where .= ($where ? " AND " : "") . "processing IN(" . $whereprocessingin . ")";
if ($whereteamin)
$where .= ($where ? " AND " : "") . "team IN(" . $whereteamin . ")";
if ($whereaudiocodecin)
$where .= ($where ? " AND " : "") . "audiocodec IN(" . $whereaudiocodecin . ")";
}
//last
if (!empty($whereothera)) {
    $where .= ($where ? " AND " : "") . implode(" AND ", $whereothera);
}

$tagFilter = "";
$tagId = intval($_REQUEST['tag_id'] ?? 0);
if ($tagId > 0) {
    $tagFilter = " inner join torrent_tags on torrents.id = torrent_tags.torrent_id and torrent_tags.tag_id = $tagId ";
    $addparam .= "tag_id={$tagId}&";
}
$torrentExtraFilter = "";
if ($search_area == 1) {
    $torrentExtraFilter = " inner join torrent_extras on torrents.id = torrent_extras.torrent_id ";
}
if ($allsec == 1 || $enablespecial != 'yes')
{
	if ($where != "")
		$where = "WHERE $where ";
	else $where = "";
	$sql = "SELECT COUNT(*) FROM torrents " . ($search_area == 3 || $column == "owner" ? "LEFT JOIN users ON torrents.owner = users.id " : "") . $tagFilter . $torrentExtraFilter . $where;
}
else
{
//	if ($where != "")
//		$where = "WHERE $where AND categories.mode = '$sectiontype'";
//	else $where = "WHERE categories.mode = '$sectiontype'";

    if ($where != "")
        $where = "WHERE $where";
    else $where = "";
//	$sql = "SELECT COUNT(*), categories.mode FROM torrents LEFT JOIN categories ON category = categories.id " . ($search_area == 3 || $column == "owner" ? "LEFT JOIN users ON torrents.owner = users.id " : "") . $tagFilter . $where . " GROUP BY categories.mode";
	$sql = "SELECT COUNT(*) FROM torrents " . ($search_area == 3 || $column == "owner" ? "LEFT JOIN users ON torrents.owner = users.id " : "") . $tagFilter . $torrentExtraFilter . $where;
}

if ($shouldUseMeili) {
    $searchRep = new \App\Repositories\MeiliSearchRepository();
    $resultFromSearchRep = $searchRep->search($searchParams, $CURUSER['id']);
    $count = $resultFromSearchRep['total'];
} else {
    do_log("[BEFORE_TORRENT_COUNT_SQL]", 'debug');
    $res = sql_query($sql);
    do_log("[AFTER_TORRENT_COUNT_SQL] $sql", 'debug');
    $count = 0;
    while($row = mysql_fetch_array($res)) {
        $count += $row[0];
    }
}
$maxPageSize = 100;
if (!empty($_GET['pageSize'])) {
    $torrentsperpage = $_GET['pageSize'];
} elseif ($CURUSER["torrentsperpage"]) {
    $torrentsperpage = (int)$CURUSER["torrentsperpage"];
} elseif ($torrentsperpage_main) {
    $torrentsperpage = $torrentsperpage_main;
} else {
    $torrentsperpage = $maxPageSize;
}
$torrentsperpage = min($maxPageSize, $torrentsperpage);

if ($count)
{
    if (isset($searchstr) && (!isset($_GET['notnewword']) || !$_GET['notnewword'])){
        insert_suggest($searchstr, $CURUSER['id']);
    }
	if ($addparam != "")
	{
		if ($pagerlink != "")
		{
			if ($addparam[strlen($addparam)-1] != ";")
			{ // & = &amp;
				$addparam = $addparam . "&" . $pagerlink;
			}
			else
			{
				$addparam = $addparam . $pagerlink;
			}
		}
	}
	else
	{
		//stderr("in else","");
		$addparam = $pagerlink;
	}
	//stderr("addparam",$addparam);
	//echo $addparam;

	list($pagertop, $pagerbottom, $limit, $offset, $size, $page) = pager($torrentsperpage, $count, "?" . $addparam);
	$fieldsStr = implode(', ', \App\Models\Torrent::getFieldsForList(true));
//    if ($allsec == 1 || $enablespecial != 'yes') {
//        $query = "SELECT $fieldsStr FROM torrents ".($search_area == 3 || $column == "owner" ? "LEFT JOIN users ON torrents.owner = users.id " : "")." $tagFilter $where $orderby $limit";
//    } else {
//        $query = "SELECT $fieldsStr, categories.mode as search_box_id FROM torrents ".($search_area == 3 || $column == "owner" ? "LEFT JOIN users ON torrents.owner = users.id " : "")." LEFT JOIN categories ON torrents.category=categories.id $tagFilter $where $orderby $limit";
        $query = "SELECT $fieldsStr, $sectiontype as search_box_id FROM torrents ".($search_area == 3 || $column == "owner" ? "LEFT JOIN users ON torrents.owner = users.id " : "")."$tagFilter $torrentExtraFilter $where $orderby $limit";
//    }

    if (!$shouldUseMeili) {
        do_log("[BEFORE_TORRENT_LIST_SQL]", 'debug');
        $res = sql_query($query);
        do_log("[AFTER_TORRENT_LIST_SQL] $query", 'debug');
    }
} else {
    unset($res);
}

if (isset($searchstr))
	stdhead($lang_torrents['head_search_results_for'].$searchstr_ori);
elseif ($sectiontype == $browsecatmode)
	stdhead($lang_torrents['head_torrents']);
else stdhead($lang_torrents['head_special']);

function torrent_quick_filter_url($field, $value = null) {
	$params = $_GET;
	unset($params['page']);
	foreach (['cat', 'source', 'medium', 'codec', 'standard', 'processing', 'team', 'audiocodec', 'style', 'region'] as $key) {
		if ($key === $field) {
			unset($params[$key]);
		}
		foreach (array_keys($params) as $paramKey) {
			if (preg_match('/^' . preg_quote($key, '/') . '\d+$/', (string)$paramKey)) {
				unset($params[$paramKey]);
			}
		}
	}
	if ($value !== null) {
		$params[$field] = (int)$value;
	}
	$query = http_build_query($params);
	return '?' . ($query ? $query : '');
}

function torrent_quick_filter_group($title, $field, $items, $activeValue, $limit = 8) {
	if (empty($items)) {
		return '';
	}
	$html = '<section class="torrent-quick-filter-group">';
	$cleanTitle = rtrim($title, ':：');
	$modalItems = [];
	foreach ($items as $item) {
		$id = (int)$item['id'];
		$name = trim((string)$item['name']);
		if ($id <= 0 || $name === '') {
			continue;
		}
		$modalItems[] = [
			'name' => $name,
			'url' => torrent_quick_filter_url($field, $id),
			'active' => (int)$activeValue === $id,
		];
	}
	array_unshift($modalItems, [
		'name' => '全部',
		'url' => torrent_quick_filter_url($field),
		'active' => (int)$activeValue === 0,
	]);
	$titleText = htmlspecialchars($cleanTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	if ($field === 'style') {
		$titleText = '&#39118;&#26684;';
	} elseif ($field === 'region') {
		$titleText = '&#22320;&#21306;';
	}
	$html .= '<div class="torrent-quick-filter-heading"><span>' . $titleText . '</span><a href="#" data-quick-filter-modal="1" data-filter-title="' . $titleText . '" data-filter-items="' . htmlspecialchars(json_encode($modalItems, JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" class="' . ((int)$activeValue === 0 ? 'is-active' : '') . '">全部 &gt;</a></div>';
	$html .= '<div class="torrent-quick-filter-links">';
	$count = 0;
	foreach ($items as $item) {
		if ($count >= $limit) {
			break;
		}
		$id = (int)$item['id'];
		$name = trim((string)$item['name']);
		if ($id <= 0 || $name === '') {
			continue;
		}
		$html .= '<a href="' . htmlspecialchars(torrent_quick_filter_url($field, $id)) . '" class="' . ((int)$activeValue === $id ? 'is-active' : '') . '">' . htmlspecialchars($name) . '</a>';
		$count++;
	}
	$html .= '</div></section>';
	return $html;
}

function render_torrent_quick_filters($lang_torrents, $cats, $category_get, $sources, $source_get, $media, $medium_get, $teams, $team_get, $torrentStyles, $style_get, $torrentRegions, $region_get, $showsource, $showmedium, $showteam) {
	$groups = [];
	$groups[] = torrent_quick_filter_group($lang_torrents['text_category'], 'cat', $cats, $category_get, 8);
	if ($showsource) {
		$groups[] = torrent_quick_filter_group($lang_torrents['text_source'], 'source', $sources, $source_get, 8);
	}
	if ($showmedium) {
		$groups[] = torrent_quick_filter_group($lang_torrents['text_medium'], 'medium', $media, $medium_get, 8);
	}
	if ($showteam) {
		$groups[] = torrent_quick_filter_group($lang_torrents['text_team'], 'team', $teams, $team_get, 8);
	}
	$groups[] = torrent_quick_filter_group('风格', 'style', $torrentStyles, $style_get, 10);
	$groups[] = torrent_quick_filter_group('地区', 'region', $torrentRegions, $region_get, 10);
	$groups = array_filter($groups);
	if (!$groups) {
		return;
	}
	print('<div class="torrent-quick-filters">' . implode('', $groups) . '</div>');
	print('<div class="torrent-quick-filter-modal" id="torrent-quick-filter-modal" aria-hidden="true"><div class="torrent-quick-filter-modal-backdrop" data-quick-filter-close="1"></div><div class="torrent-quick-filter-modal-dialog" role="dialog" aria-modal="true"><button type="button" class="torrent-quick-filter-modal-close" data-quick-filter-close="1">&times;</button><h2></h2><div class="torrent-quick-filter-modal-list"></div></div></div>');
}

print("<table width=\"97%\" class=\"main\" border=\"0\" cellspacing=\"0\" cellpadding=\"0\"><tr><td class=\"embedded\">");

displayHotAndClassic();
$searchBoxRightTdStyle = 'padding: 1px;padding-left: 10px;white-space: nowrap';
if ($allsec != 1 || $enablespecial != 'yes'){ //do not print searchbox if showing bookmarked torrents from all sections;
?>

<div id="torrent-advanced-search-modal" class="torrent-advanced-search-modal" aria-hidden="true">
	<div class="torrent-advanced-search-backdrop" data-advanced-search-close="1"></div>
	<div class="torrent-advanced-search-dialog" role="dialog" aria-modal="true" aria-label="<?php echo htmlspecialchars($lang_torrents['text_search_box']) ?>">
		<button type="button" class="torrent-advanced-search-close" data-advanced-search-close="1" aria-label="Close">&times;</button>
		<form method="get" name="searchbox" action="?">
	<table border="1" class="searchbox" cellspacing="0" cellpadding="5" width="100%">
		<tbody>
		<tr>
		<td class="colhead" align="center" colspan="2"><a href="javascript: klappe_news('searchboxmain')"><img class="plus" src="pic/trans.gif" id="picsearchboxmain" alt="Show/Hide" /><?php echo $lang_torrents['text_search_box'] ?></a></td>
		</tr></tbody>
		<tbody id="ksearchboxmain" style="display:none">
		<tr>
			<td class="rowfollow" align="left">
<!--				<table>-->
<!--					--><?php
//						function printcat($name, $listarray, $cbname, $wherelistina, $btname, $showimg = false)
//						{
//							global $catpadding,$catsperrow,$lang_torrents,$CURUSER,$CURLANGDIR,$catimgurl;
//
//							print("<tr><td class=\"embedded\" colspan=\"".$catsperrow."\" align=\"left\"><b>".$name."</b></td></tr><tr>");
//							$i = 0;
//							foreach($listarray as $list){
//								if ($i && $i % $catsperrow == 0){
//									print("</tr><tr>");
//								}
//								print("<td align=\"left\" class=\"bottom\" style=\"padding-bottom: 4px; padding-left: ".$catpadding."px;\"><input type=\"checkbox\" id=\"".$cbname.$list['id']."\" name=\"".$cbname.$list['id']."\"" . (in_array($list['id'],$wherelistina) ? " checked=\"checked\"" : "") . " value=\"1\" />".($showimg ? return_category_image($list['id'], "?") : "<a title=\"" .$list['name'] . "\" href=\"?".$cbname."=".$list['id']."\">".$list['name']."</a>")."</td>\n");
//								$i++;
//							}
//							$checker = "<input name=\"".$btname."\" value='" .  $lang_torrents['input_check_all'] . "' class=\"btn medium\" type=\"button\" onclick=\"javascript:SetChecked('".$cbname."','".$btname."','". $lang_torrents['input_check_all'] ."','" . $lang_torrents['input_uncheck_all'] . "',-1,10)\" />";
//							print("<td colspan=\"2\" class=\"bottom\" align=\"left\" style=\"padding-left: 15px\">".$checker."</td>\n");
//							print("</tr>");
//						}
//					printcat($lang_torrents['text_category'],$cats,"cat",$wherecatina,"cat_check",true);
//
//					if ($showsubcat){
//						if ($showsource)
//							printcat($lang_torrents['text_source'], $sources, "source", $wheresourceina, "source_check");
//						if ($showmedium)
//							printcat($lang_torrents['text_medium'], $media, "medium", $wheremediumina, "medium_check");
//						if ($showcodec)
//							printcat($lang_torrents['text_codec'], $codecs, "codec", $wherecodecina, "codec_check");
//						if ($showaudiocodec)
//							printcat($lang_torrents['text_audio_codec'], $audiocodecs, "audiocodec", $whereaudiocodecina, "audiocodec_check");
//						if ($showstandard)
//							printcat($lang_torrents['text_standard'], $standards, "standard", $wherestandardina, "standard_check");
//						if ($showprocessing)
//							printcat($lang_torrents['text_processing'], $processings, "processing", $whereprocessingina, "processing_check");
//						if ($showteam)
//							printcat($lang_torrents['text_team'], $teams, "team", $whereteamina, "team_check");
//					}
//					?>
<!--				</table>-->
                <?php echo build_search_box_category_table($sectiontype, '1', '?', '?', 0, $_SERVER['QUERY_STRING'], ['select_unselect' => true, 'user_notifs' => $CURUSER['notifs']])?>
			</td>

			<td class="rowfollow" valign="middle">
				<table>
					<tr>
						<td class="bottom" style="padding: 1px;padding-left: 10px">
							<font class="medium"><?php echo $lang_torrents['text_show_dead_active'] ?></font>
						</td>
				 	</tr>
					<tr>
						<td class="bottom" style="padding: 1px;padding-left: 10px">
							<select class="med" name="incldead" style="width: 100px;">
								<option value="0"><?php echo $lang_torrents['select_including_dead'] ?></option>
								<option value="1"<?php print($include_dead == 1 ? " selected=\"selected\"" : ""); ?>><?php echo $lang_torrents['select_active'] ?> </option>
								<option value="2"<?php print($include_dead == 2 ? " selected=\"selected\"" : ""); ?>><?php echo $lang_torrents['select_dead'] ?></option>
							</select>
						</td>
				 	</tr>
					<tr>
						<td class="bottom" style="padding: 1px;padding-left: 10px">
							<font class="medium"><?php echo $lang_torrents['text_show_special_torrents'] ?></font>
						</td>
				 	</tr>
				 	<tr>
						<td class="bottom" style="padding: 1px;padding-left: 10px">
							<select class="med" name="spstate" style="width: 100px;">
								<option value="0"><?php echo $lang_torrents['select_all'] ?></option>
<?php echo promotion_selection($special_state, 0)?>
							</select>
						</td>
					</tr>
					<tr>
						<td class="bottom" style="padding: 1px;padding-left: 10px">
							<font class="medium"><?php echo $lang_torrents['text_show_bookmarked'] ?></font>
						</td>
				 	</tr>
				 	<tr>
						<td class="bottom" style="padding: 1px;padding-left: 10px">
							<select class="med" name="inclbookmarked" style="width: 100px;">
								<option value="0"><?php echo $lang_torrents['select_all'] ?></option>
								<option value="1"<?php print($inclbookmarked == 1 ? " selected=\"selected\"" : ""); ?>><?php echo $lang_torrents['select_bookmarked'] ?></option>
								<option value="2"<?php print($inclbookmarked == 2 ? " selected=\"selected\"" : ""); ?>><?php echo $lang_torrents['select_bookmarked_exclude'] ?></option>
							</select>
						</td>
					</tr>
                    <?php if ($showApprovalStatusFilter) {?>
                    <tr>
                        <td class="bottom" style="padding: 1px;padding-left: 10px">
                            <font class="medium"><?php echo $lang_torrents['text_approval_status'] ?></font>
                        </td>
                    </tr>
                    <tr>
                        <td class="bottom" style="padding: 1px;padding-left: 10px">
                            <select class="med" name="approval_status" style="width: 100px;">
                                <option value=""><?php echo $lang_torrents['select_all'] ?></option>
                                <?php
                                foreach (\App\Models\Torrent::listApprovalStatus(true) as $key => $value) {
                                    printf('<option value="%s"%s>%s</option>', $key, isset($approvalStatus) && (string)$approvalStatus === (string)$key ? ' selected' : '', $value);
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <?php }?>
                    <tr>
                        <td class="bottom" style="<?php echo $searchBoxRightTdStyle ?>">
                            <font class="medium"><?php echo $lang_torrents['size_range'] ?></font>
                        </td>
                    </tr>
                    <tr>
                        <td class="bottom" style="<?php echo $searchBoxRightTdStyle ?>">
                            <input type="number" min="1" name="size_begin" style="width: <?php echo $filterInputWidth?>px" value="<?php echo htmlspecialchars($_GET['size_begin'] ?? '') ?>"/> ~ <input type="number" min="1" name="size_end" style="width: <?php echo $filterInputWidth?>px" value="<?php echo htmlspecialchars($_GET['size_end'] ?? '') ?>"/>
                        </td>
                    </tr>

                    <tr>
                        <td class="bottom" style="<?php echo $searchBoxRightTdStyle ?>">
                            <font class="medium"><?php echo $lang_torrents['seeders_range'] ?></font>
                        </td>
                    </tr>
                    <tr>
                        <td class="bottom" style="<?php echo $searchBoxRightTdStyle ?>">
                            <input type="number" min="1" name="seeders_begin" style="width: <?php echo $filterInputWidth?>px" value="<?php echo htmlspecialchars($_GET['seeders_begin'] ?? '') ?>"/> ~ <input type="number" min="1" name="seeders_end" style="width: <?php echo $filterInputWidth?>px" value="<?php echo htmlspecialchars($_GET['seeders_end'] ?? '') ?>"/>
                        </td>
                    </tr>

                    <tr>
                        <td class="bottom" style="<?php echo $searchBoxRightTdStyle ?>">
                            <font class="medium"><?php echo $lang_torrents['leechers_range'] ?></font>
                        </td>
                    </tr>
                    <tr>
                        <td class="bottom" style="<?php echo $searchBoxRightTdStyle ?>">
                            <input type="number" min="1" name="leechers_begin" style="width: <?php echo $filterInputWidth?>px" value="<?php echo htmlspecialchars($_GET['leechers_begin'] ?? '') ?>"/> ~ <input type="number" min="1" name="leechers_end" style="width: <?php echo $filterInputWidth?>px" value="<?php echo htmlspecialchars($_GET['leechers_end'] ?? '') ?>"/>
                        </td>
                    </tr>

                    <tr>
                        <td class="bottom" style="<?php echo $searchBoxRightTdStyle ?>">
                            <font class="medium"><?php echo $lang_torrents['times_completed_range'] ?></font>
                        </td>
                    </tr>
                    <tr>
                        <td class="bottom" style="<?php echo $searchBoxRightTdStyle ?>">
                            <input type="number" min="1" name="times_completed_begin" style="width: <?php echo $filterInputWidth?>px" value="<?php echo htmlspecialchars($_GET['times_completed_begin'] ?? '') ?>"/> ~ <input type="number" min="1" name="times_completed_end" style="width: <?php echo $filterInputWidth?>px" value="<?php echo htmlspecialchars($_GET['times_completed_end'] ?? '') ?>"/>
                        </td>
                    </tr>

                    <tr>
                        <td class="bottom" style="<?php echo $searchBoxRightTdStyle ?>">
                            <font class="medium"><?php echo $lang_torrents['added_range'] ?></font>
                        </td>
                    </tr>
                    <tr>
                        <td class="bottom" style="<?php echo $searchBoxRightTdStyle ?>">
                            <?php echo sprintf(
                                '%s ~ %s',
                                datetimepicker_input('added_begin', htmlspecialchars($_GET['added_begin'] ?? ''), '', ['require_files' => true, 'format' => 'Y-m-d', 'style' => 'width: '.$filterInputWidth.'px']),
                                datetimepicker_input('added_end', htmlspecialchars($_GET['added_end'] ?? ''), '', ['require_files' => false, 'format' => 'Y-m-d', 'style' => 'width: '.$filterInputWidth.'px']),
                            ) ?>
                        </td>
                    </tr>

				</table>
			</td>
		</tr>
		</tbody>
		<tbody>
		<tr>
			<td class="rowfollow" align="center">
				<table>
					<tr>
						<td class="embedded">
							<?php echo $lang_torrents['text_search'] ?>&nbsp;&nbsp;
						</td>
						<td class="embedded">
							<table>
								<tr>
									<td class="embedded">
										<input id="searchinput" name="search" type="text" value="<?php echo  $searchstr_ori ?>" autocomplete="off" style="width: 200px" ondblclick="suggest(event.keyCode,this.value);" onkeyup="suggest(event.keyCode,this.value);" onkeypress="return noenter(event.keyCode);"/>
										<script src="js/suggest.js" type="text/javascript"></script>
										<div id="suggcontainer" style="text-align: left; width:100px;  display: none;">
											<div id="suggestions" style="width:204px; border: 1px solid rgb(119, 119, 119); cursor: default; position: absolute; color: rgb(0,0,0); background-color: rgb(255, 255, 255);"></div>
										</div>
									</td>
								</tr>
							</table>
						</td>
						<td class="embedded">
							<?php echo "&nbsp;" . $lang_torrents['text_in'] ?>

							<select name="search_area">
								<option value="0"><?php echo $lang_torrents['select_title'] ?></option>
								<option value="1"<?php print(isset($_GET["search_area"]) && $_GET["search_area"] == 1 ? " selected=\"selected\"" : ""); ?>><?php echo $lang_torrents['select_description'] ?></option>
								<?php
								/*if ($smalldescription_main == 'yes'){
								?>
								<option value="2"<?php print($_GET["search_area"] == 2 ? " selected=\"selected\"" : ""); ?>><?php echo $lang_torrents['select_small_description'] ?></option>
								<?php
								}*/
								?>
								<option value="3"<?php print(isset($_GET["search_area"]) && $_GET["search_area"] == 3 ? " selected=\"selected\"" : ""); ?>><?php echo $lang_torrents['select_uploader'] ?></option>
								<option value="4"<?php print(isset($_GET["search_area"]) && $_GET["search_area"] == 4 ? " selected=\"selected\"" : ""); ?>><?php echo $lang_torrents['select_imdb_url'] ?></option>
							</select>

							<?php echo $lang_torrents['text_with'] ?>

							<select name="search_mode" style="width: 60px;">
                                <?php echo \App\Models\SearchBox::listSelectModeOptions($_GET["search_mode"] ?? "")?>
							</select>

							<?php echo $lang_torrents['text_mode'] ?>
						</td>
					</tr>
<?php
$Cache->new_page('hot_search', 3670, true);
if (!$Cache->get_page()){
	$secs = 3*24*60*60;
	$dt = sqlesc(date("Y-m-d H:i:s",(TIMENOW - $secs)));
	$dt2 = sqlesc(date("Y-m-d H:i:s",(TIMENOW - $secs*2)));
	sql_query("DELETE FROM suggest WHERE adddate <" . $dt2) or sqlerr();
	$searchres = sql_query("SELECT keywords, COUNT(DISTINCT userid) as count FROM suggest WHERE adddate >" . $dt . " GROUP BY keywords ORDER BY count DESC LIMIT 15") or sqlerr();
	$hotcount = 0;
	$hotsearch = "";
	while ($searchrow = mysql_fetch_assoc($searchres))
	{
		$hotsearch .= "<a href=\"".htmlspecialchars("?search=" . rawurlencode($searchrow["keywords"]) . "&notnewword=1")."\"><u>" . htmlspecialchars($searchrow["keywords"]) . "</u></a>&nbsp;&nbsp;";
		$hotcount += mb_strlen($searchrow["keywords"],"UTF-8");
		if ($hotcount > 60)
			break;
	}
	$Cache->add_whole_row();
	if ($hotsearch)
	print("<tr><td class=\"embedded\" colspan=\"3\">&nbsp;&nbsp;".$hotsearch."</td></tr>");
	$Cache->end_whole_row();
	$Cache->cache_page();
}
echo $Cache->next_row();

if ($allTags->isNotEmpty()) {
    echo '<tr><td colspan="3" class="embedded" style="padding-top: 4px">' . $tagRep->renderSpan($sectiontype, ['*'], true) . '</td></tr>';
}

?>

				</table>
			</td>
			<td class="rowfollow" align="center">
				<input type="submit" class="btn" value="<?php echo $lang_torrents['submit_go'] ?>" />
			</td>
		</tr>
		</tbody>
	</table>
		</form>
	</div>
</div>
<?php
}
	if ($Advertisement->enable_ad()){
        $belowsearchboxad = $Advertisement->get_ad('belowsearchbox');
        if (!empty($belowsearchboxad[0])) {
            echo "<div align=\"center\" style=\"margin-top: 10px\" id=\"\">".$belowsearchboxad[0]."</div>";
        }
	}
render_torrent_quick_filters(
	$lang_torrents,
	$cats,
	$category_get,
	$sources ?? [],
	$source_get,
	$media ?? [],
	$medium_get,
	$teams ?? [],
	$team_get,
	$torrentStyles,
	$style_get,
	$torrentRegions,
	$region_get,
	$showsource,
	$showmedium,
	$showteam
);
if($inclbookmarked == 1)
{
	print("<h1 align=\"center\">" . get_username($CURUSER['id']) . $lang_torrents['text_s_bookmarked_torrent'] . "</h1>");
}
elseif($inclbookmarked == 2)
{
	print("<h1 align=\"center\">" . get_username($CURUSER['id']) . $lang_torrents['text_s_not_bookmarked_torrent'] . "</h1>");
}

if ($count) {
    $rows = [];
    if ($shouldUseMeili) {
        $rows = $resultFromSearchRep['list'];
    } else {
        while ($row = mysql_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }
	$rows = apply_filter('torrent_list', $rows, $page, $sectiontype, $_GET['search'] ?? '');
	print('<div class="torrent-view-toolbar">'
	. '<div class="torrent-view-title">Torrent List</div>'
		. '<div class="torrent-view-pager">' . $pagertop . '</div>'
		. '<div class="torrent-view-switch" role="tablist" aria-label="Torrent View Switch">'
	. '<button type="button" class="torrent-view-btn is-active" data-view="list">List</button>'
	. '<button type="button" class="torrent-view-btn" data-view="card">Card</button>'
		. '</div>'
		. '</div>');
	if ($sectiontype == $browsecatmode)
		torrenttable($rows, "torrents", $sectiontype);
	elseif ($sectiontype == $specialcatmode)
		torrenttable($rows, "music", $sectiontype);
	else torrenttable($rows, "bookmarks", $sectiontype);
	print($pagerbottom);
}
else {
	if (isset($searchstr)) {
		print("<br />");
		stdmsg($lang_torrents['std_search_results_for'] . $searchstr_ori . "\"",$lang_torrents['std_try_again']);
	}
	else {
		stdmsg($lang_torrents['std_nothing_found'],$lang_torrents['std_no_active_torrents']);
	}
}
if ($CURUSER){
	if ($sectiontype == $browsecatmode)
		$USERUPDATESET[] = "last_browse = ".TIMENOW;
	else	$USERUPDATESET[] = "last_music = ".TIMENOW;
}
?>
<script>
(function () {
	var body = document.body;
	if (!body || !body.classList.contains('page-torrents')) {
		return;
	}

	var storageKey = 'nexus_torrents_view';
	var toolbar = document.querySelector('.torrent-view-toolbar');
	var table = document.querySelector('table.torrents');
	if (!toolbar || !table) {
		return;
	}

	var buttons = toolbar.querySelectorAll('.torrent-view-btn');
	var cardGrid = document.createElement('div');
	cardGrid.className = 'torrent-card-grid';
	table.parentNode.insertBefore(cardGrid, table.nextSibling);

	function cleanText(value) {
		return (value || '').replace(/\s+/g, ' ').trim();
	}

	function isValidPosterImage(src, img) {
		src = (src || '').replace(/&amp;/g, '&').trim();
		if (!src || src.indexOf('http') !== 0) {
			return false;
		}
		var tag = img && img.outerHTML ? img.outerHTML : '';
		var haystack = (src + ' ' + tag).toLowerCase();
		var blocked = [
			'alt="avatar"', "alt='avatar'", 'check_avatar', 'default_avatar', '/avatar', 'avatar/',
			'userdetails.php', 'pic/trans.gif', 'pic/cattrans.gif', 'pic/smilies/', 'pic/flag/',
			'progressbar.gif', 'spinner.svg', 'image.php?action=regimage', 'favicon.ico',
			'logo', 'donate.gif', 'sprites', 'passkey', 'data:image/svg'
		];
		for (var i = 0; i < blocked.length; i++) {
			if (haystack.indexOf(blocked[i]) !== -1) {
				return false;
			}
		}
		return true;
	}

	function getCardImage(nameCell, typeCell) {
		var source = nameCell.querySelector('.torrent-card-cover-source[data-cover]');
		if (source && isValidPosterImage(source.getAttribute('data-cover'))) {
			return source.getAttribute('data-cover');
		}
		var img = nameCell.querySelector('img[data-src], img[src]');
		if (!img && typeCell) {
			img = typeCell.querySelector('img[data-src], img[src]');
		}
		if (!img) {
			return '';
		}
		var src = img.getAttribute('data-src') || img.getAttribute('src') || '';
		if (src.indexOf('pic/misc/spinner.svg') !== -1) {
			src = img.getAttribute('data-src') || '';
		}
		return isValidPosterImage(src, img) ? src : '';
	}

	function getCardFallbackImage(nameCell) {
		var source = nameCell.querySelector('.torrent-card-cover-source[data-fallback-cover]');
		var fallback = source ? source.getAttribute('data-fallback-cover') : '';
		return isValidPosterImage(fallback) ? fallback : '';
	}

	function getCardSubtitle(nameCell, title, link) {
		var br = nameCell.querySelector('br');
		if (br) {
			var brParts = [];
			var brNode = br.nextSibling;
			while (brNode) {
				if (brNode.nodeType === 3) {
					brParts.push(brNode.nodeValue || '');
				} else if (brNode.nodeType === 1) {
					if (brNode.hidden || brNode.className.indexOf('torrent-card-') !== -1) {
						brNode = brNode.nextSibling;
						continue;
					}
					var brTag = (brNode.tagName || '').toLowerCase();
					if (brTag === 'script' || brTag === 'style' || brTag === 'img') {
						brNode = brNode.nextSibling;
						continue;
					}
					if (brNode.matches && brNode.matches('span[style]')) {
						brNode = brNode.nextSibling;
						continue;
					}
					brParts.push(' ' + cleanText(brNode.textContent));
				}
				brNode = brNode.nextSibling;
			}
			var brText = cleanText(brParts.join(' '));
			brText = cleanText(brText.split('【')[0].split('[')[0]);
			brText = cleanText(brText.replace(/^(?:副标题|别名)\s*[:：]\s*/i, ''));
			if (brText) {
				return brText.length > 34 ? brText.slice(0, 33) + '...' : brText;
			}
		}
		var parts = [];
		var node = link ? link.nextSibling : null;
		while (node) {
			if (node.nodeType === 3) {
				parts.push(node.nodeValue || '');
			} else if (node.nodeType === 1) {
				var tagName = (node.tagName || '').toLowerCase();
				if (tagName === 'br') {
					break;
				}
				var text = cleanText(node.textContent);
				if (text && /^[\/,，、\s]*(?:【|\[|剩余时间|奖励魔力|禁转|官方|原创|首发|中字|源码|豆瓣|IMDb|IMDB)/.test(text)) {
					break;
				}
				if (text && !node.hidden && node.className.indexOf('torrent-card-') === -1) {
					parts.push(' ' + text);
				}
				break;
			}
			node = node.nextSibling;
		}
		var text = cleanText(parts.join(' ')).replace(title, '');
		text = cleanText(text.replace(/^\[[^\]]+\]\s*/g, ''));
		text = cleanText(text.split('【')[0].split('[')[0]);
		text = cleanText(text.replace(/^(?:副标题|别名)\s*[:：]\s*/i, ''));
		return text.length > 34 ? text.slice(0, 33) + '...' : text;
	}

	function getCardRating(text) {
		var match = cleanText(text).match(/(?:豆瓣|IMDb|IMDB|评分)[^\d]*(\d(?:\.\d)?)/i);
		return match ? match[1] : '';
	}

	function normalizeCardRating(value) {
		value = cleanText(value);
		if (!value || /^N\/?A$/i.test(value)) {
			return '';
		}
		var match = value.match(/(\d(?:\.\d)?)/);
		return match ? match[1] : '';
	}

	function getCardRatingBySite(cells, site) {
		var siteLower = site.toLowerCase();
		var dataAttr = siteLower === 'douban' ? 'data-doubanid' : 'data-imdbid';
		for (var i = 0; i < cells.length; i++) {
			var cell = cells[i];
			var dataNode = cell.querySelector('span[' + dataAttr + ']');
			var rating = dataNode ? normalizeCardRating(dataNode.textContent) : '';
			if (rating) {
				return rating;
			}
			var imgs = cell.querySelectorAll('img[alt], img[title]');
			for (var j = 0; j < imgs.length; j++) {
				var img = imgs[j];
				var label = ((img.getAttribute('alt') || '') + ' ' + (img.getAttribute('title') || '') + ' ' + (img.getAttribute('src') || '')).toLowerCase();
				if (label.indexOf(siteLower) === -1) {
					continue;
				}
				var parent = img.parentNode;
				rating = normalizeCardRating(parent ? parent.textContent : '');
				if (rating) {
					return rating;
				}
			}
		}
		return '';
	}

	function getCardRatingFromRow(cells, nameCell) {
		var douban = getCardRatingBySite(cells, 'douban');
		if (douban) {
			return douban;
		}
		var imdb = getCardRatingBySite(cells, 'imdb');
		if (imdb) {
			return imdb;
		}
		var rowText = '';
		for (var i = 0; i < cells.length; i++) {
			rowText += ' ' + cleanText(cells[i].textContent);
		}
		var doubanMatch = rowText.match(/豆瓣(?:评分)?[^\d]*(\d(?:\.\d)?)/i);
		if (doubanMatch) {
			return doubanMatch[1];
		}
		var imdbMatch = rowText.match(/(?:IMDb|IMDB)(?:评分)?[^\d]*(\d(?:\.\d)?)/i);
		if (imdbMatch) {
			return imdbMatch[1];
		}
		return getCardRating(nameCell.textContent);
	}

	function getCardMeta(nameCell, rating) {
		var source = nameCell.querySelector('.torrent-card-meta-source');
		return {
			team: source ? cleanText(source.getAttribute('data-team')) : '',
			type: source ? cleanText(source.getAttribute('data-type')) : '',
			standard: source ? cleanText(source.getAttribute('data-standard')) : '',
			medium: source ? cleanText(source.getAttribute('data-medium')) : '',
			region: source ? cleanText(source.getAttribute('data-region')) : '',
			codec: source ? cleanText(source.getAttribute('data-codec')) : '',
			style: source ? cleanText(source.getAttribute('data-style')) : '',
			tags: getCardTags(nameCell),
			rating: source && source.getAttribute('data-rating') ? cleanText(source.getAttribute('data-rating')) : rating
		};
	}

	function appendBadge(wrap, type, label, value) {
		value = cleanText(value);
		if (!value) {
			return;
		}
		if (type === 'rating' && /^\d(?:\.\d)?$/.test(value)) {
			value = Number(value).toFixed(1);
		}
		var badge = document.createElement('span');
		badge.className = 'torrent-poster-badge is-' + type;
		badge.textContent = label ? label + ' ' + value : value;
		wrap.appendChild(badge);
	}

	function getCardTags(nameCell) {
		var seen = {};
		var tags = [];
		var nodes = nameCell.querySelectorAll('span[style]');
		for (var i = 0; i < nodes.length; i++) {
			var node = nodes[i];
			if (node.hidden || node.className.indexOf('torrent-card-') !== -1) {
				continue;
			}
			var text = cleanText(node.textContent);
			if (!text || seen[text]) {
				continue;
			}
			seen[text] = true;
			tags.push({
				text: text,
				style: node.getAttribute('style') || ''
			});
		}
		return tags;
	}

	function buildCardBadges(meta) {
		var wrap = document.createElement('div');
		wrap.className = 'torrent-poster-badges';
		appendBadge(wrap, 'team', '制作组', meta.team);
		appendBadge(wrap, 'type', '类型', meta.type);
		appendBadge(wrap, 'medium', '媒介', meta.medium);
		appendBadge(wrap, 'standard', '分辨率', meta.standard);
		appendBadge(wrap, 'region', '地区', meta.region);
		appendBadge(wrap, 'codec', '编码', meta.codec);
		appendBadge(wrap, 'style', '风格', meta.style);
		return wrap.children.length ? wrap : null;
	}

	function buildPosterTags(tags) {
		if (!tags || !tags.length) {
			return null;
		}
		var wrap = document.createElement('div');
		wrap.className = 'torrent-poster-tags';
		for (var i = 0; i < tags.length; i++) {
			var item = tags[i];
			var tag = document.createElement('span');
			tag.className = 'torrent-poster-tag';
			tag.textContent = item.text;
			if (item.style) {
				tag.style.cssText = item.style;
			}
			tag.style.margin = '0';
			tag.style.display = 'inline-flex';
			tag.style.alignItems = 'center';
			tag.style.lineHeight = '1.15';
			wrap.appendChild(tag);
		}
		return wrap.children.length ? wrap : null;
	}

	function buildPosterTextTags(tags) {
		var wrap = document.createElement('span');
		wrap.className = 'torrent-poster-tagline';
		if (!tags || !tags.length) {
			return wrap;
		}
		for (var i = 0; i < tags.length; i++) {
			var item = tags[i];
			var tag = document.createElement('span');
			tag.className = 'torrent-poster-text-tag';
			tag.textContent = item.text;
			if (item.style) {
				tag.style.cssText = item.style;
			}
			tag.style.margin = '0';
			wrap.appendChild(tag);
		}
		return wrap;
	}

	function appendPosterTags(poster, tags) {
		if (!tags || poster.querySelector('.torrent-poster-tags')) {
			return;
		}
		poster.appendChild(tags);
	}

	function findCardCellText(cells, patterns) {
		for (var i = 0; i < cells.length; i++) {
			var label = cleanText(cells[i].getAttribute('data-label')).toLowerCase();
			for (var p = 0; p < patterns.length; p++) {
				if (label.indexOf(patterns[p]) !== -1) {
					return cleanText(cells[i].textContent);
				}
			}
		}
		return '';
	}

	function getCardStats(cells) {
		var uploader = '';
		var uploaderNode = null;
		for (var i = 0; i < cells.length; i++) {
			var userLink = cells[i].querySelector('a[href*="userdetails.php"]');
			if (userLink) {
				uploader = cleanText(userLink.textContent);
				uploaderNode = userLink.cloneNode(true);
			}
		}
		return {
			seeders: findCardCellText(cells, ['seed', '做种', '上传']),
			leechers: findCardCellText(cells, ['leech', '下载']),
			uploader: uploader || findCardCellText(cells, ['uploader', '上传者', '发布者']),
			uploaderNode: uploaderNode
		};
	}

	function appendPosterStats(poster, stats) {
		if (!stats || poster.querySelector('.torrent-poster-stats')) {
			return;
		}
		if (!stats.seeders && !stats.leechers && !stats.uploader && !stats.uploaderNode) {
			return;
		}
		var wrap = document.createElement('div');
		wrap.className = 'torrent-poster-stats';
		if (stats.seeders || stats.leechers) {
			var uploadLine = document.createElement('span');
			uploadLine.className = 'torrent-poster-stat-line torrent-poster-stat-upload';
			uploadLine.textContent = '上传 ' + (stats.seeders || '0');
			wrap.appendChild(uploadLine);
			var downloadLine = document.createElement('span');
			downloadLine.className = 'torrent-poster-stat-line torrent-poster-stat-download';
			downloadLine.textContent = '下载 ' + (stats.leechers || '0');
			wrap.appendChild(downloadLine);
		}
		if (stats.uploader || stats.uploaderNode) {
			var line = document.createElement('span');
			line.className = 'torrent-poster-stat-line torrent-poster-stat-uploader';
			line.appendChild(document.createTextNode('上传者 '));
			if (stats.uploaderNode) {
				line.appendChild(stats.uploaderNode);
			} else {
				var anonymous = document.createElement('span');
				anonymous.className = 'torrent-uploader-anonymous';
				anonymous.textContent = stats.uploader;
				line.appendChild(anonymous);
			}
			wrap.appendChild(line);
		}
		poster.appendChild(wrap);
	}

	function applyPosterReferrerPolicy(img, src) {
		if (src && src.indexOf('doubanio.com') === -1) {
			img.referrerPolicy = 'no-referrer';
		} else {
			img.removeAttribute('referrerpolicy');
		}
	}

	function setPosterImage(poster, title, image, rating, fallbackImage) {
		poster.classList.remove('is-empty');
		poster.textContent = '';
		var posterImage = image;
		var img = document.createElement('img');
		img.alt = title;
		img.loading = 'lazy';
		applyPosterReferrerPolicy(img, posterImage);
		img.onerror = function () {
			var current = this.getAttribute('src') || '';
			var original = posterImage || current;
			if (fallbackImage && current !== fallbackImage) {
				this.removeAttribute('data-douban-fallback-index');
				applyPosterReferrerPolicy(this, fallbackImage);
				this.src = fallbackImage;
				return;
			}
			if (original.indexOf('doubanio.com') !== -1) {
				var domains = []; /* qd: no douban mirror-cycling (all mirrors hotlink-block the same -> console spam) */
				var index = Number(this.getAttribute('data-douban-fallback-index') || '0');
				while (index < domains.length) {
					var next = original.replace(/https:\/\/[a-zA-Z0-9.-]+\.doubanio\.com/, 'https://' + domains[index]);
					index++;
					if (next && next !== current) {
						this.setAttribute('data-douban-fallback-index', String(index));
						applyPosterReferrerPolicy(this, next);
						this.src = next;
						return;
					}
				}
			}
			poster.classList.add('is-empty');
			poster.textContent = title.slice(0, 2) || 'HD';
			appendPosterScore(poster, rating);
		};
		img.src = posterImage;
		poster.appendChild(img);
		appendPosterScore(poster, rating);
	}

	function appendPosterScore(poster, rating) {
		rating = cleanText(rating);
		if (!rating || poster.querySelector('.torrent-poster-score')) {
			return;
		}
		if (/^\d(?:\.\d)?$/.test(rating)) {
			rating = Number(rating).toFixed(1);
		}
		var score = document.createElement('span');
		score.className = 'torrent-poster-score';
		score.textContent = rating;
		poster.appendChild(score);
	}

	function hydratePosterFromDetails(poster, title, href, badges, rating, tags, stats) {
		if (!href || !window.fetch) {
			return;
		}
		fetch(href, { credentials: 'same-origin' })
			.then(function (response) {
				return response.ok ? response.text() : '';
			})
			.then(function (html) {
				if (!html) {
					return;
				}
				var image = '';
				var amazonMatch = html.match(/https?:\/\/[^"'\s<>]*m\.media-amazon\.com[^"'\s<>]*/i);
				if (amazonMatch && amazonMatch[0]) {
					image = amazonMatch[0];
				}
				if (!image) {
					var doc = new DOMParser().parseFromString(html, 'text/html');
					var metaPoster = doc.querySelector('meta[property="og:image"]');
					if (metaPoster && isValidPosterImage(metaPoster.content)) {
						image = metaPoster.content;
					}
					if (!image) {
						var posterImg = doc.querySelector('#posterimdb img');
						var posterSrc = posterImg ? (posterImg.getAttribute('src') || '') : '';
						if (isValidPosterImage(posterSrc, posterImg)) {
							image = posterSrc;
						}
					}
					if (!image) {
						var descrImgs = doc.querySelectorAll('#kdescr img');
						for (var i = 0; i < descrImgs.length; i++) {
							var src = descrImgs[i].getAttribute('data-src') || descrImgs[i].getAttribute('src') || '';
							if (isValidPosterImage(src, descrImgs[i])) {
								image = src;
								break;
							}
						}
					}
				}
				image = image.replace(/&amp;/g, '&');
				var currentImg = poster.querySelector('img');
				if (image && (!currentImg || currentImg.naturalWidth === 0 || poster.classList.contains('is-empty'))) {
					setPosterImage(poster, title, image, rating);
				}
				var ratingMatch = html.match(/(?:IMDb|IMDB)[\s\S]{0,160}?(\d(?:\.\d)?)/i);
				if (ratingMatch) {
					appendPosterScore(poster, ratingMatch[1]);
				}
			})
			.catch(function () {});
	}

	function getTorrentRows() {
		var bodyRows = table.tBodies && table.tBodies.length ? table.tBodies[0].rows : table.rows;
		return Array.prototype.slice.call(bodyRows || []);
	}

	function buildCardGrid() {
		var rows = getTorrentRows();
		cardGrid.innerHTML = '';
		for (var r = 1; r < rows.length; r++) {
			var cells = rows[r].children;
			if (cells.length < 2) {
				continue;
			}
			var typeCell = cells[0];
			var nameCell = cells[1];
			var link = nameCell.querySelector('a[href*="details.php"]');
			if (!link) {
				continue;
			}
			var title = cleanText(link.textContent);
			var subtitle = getCardSubtitle(nameCell, title, link);
			var image = getCardImage(nameCell, typeCell);
			var fallbackImage = getCardFallbackImage(nameCell);
			var ratingSource = nameCell.querySelector('.torrent-card-rating-source[data-rating]');
			var rating = ratingSource && ratingSource.getAttribute('data-rating') ? ratingSource.getAttribute('data-rating') : getCardRatingFromRow(cells, nameCell);
			var meta = getCardMeta(nameCell, rating);
			var posterRating = meta.rating || rating;
			var tagLine = buildPosterTextTags(meta.tags);
			var card = document.createElement('a');
			card.className = 'torrent-poster-card';
			card.href = link.href;
			var poster = document.createElement('div');
			poster.className = 'torrent-poster-media';
			if (image) {
				setPosterImage(poster, title, image, posterRating, fallbackImage);
			} else {
				poster.className += ' is-empty';
				poster.textContent = title.slice(0, 2) || 'HD';
				appendPosterScore(poster, posterRating);
			}
			var titleEl = document.createElement('strong');
			titleEl.className = 'torrent-poster-title';
			titleEl.textContent = title;
			var descEl = document.createElement('span');
			descEl.className = 'torrent-poster-desc';
			descEl.textContent = subtitle;
			card.appendChild(poster);
			card.appendChild(titleEl);
			card.appendChild(tagLine);
			card.appendChild(descEl);
			cardGrid.appendChild(card);
			if (!image || image.indexOf('doubanio.com') !== -1) {
				hydratePosterFromDetails(poster, title, link.href, [], posterRating, meta.tags, null);
			}
		}
	}

	function setView(view) {
		var finalView = view === 'card' ? 'card' : 'list';
		if (finalView === 'card' && !cardGrid.children.length) {
			buildCardGrid();
		}
		body.classList.remove('view-list', 'view-card');
		body.classList.add('view-' + finalView);
		for (var i = 0; i < buttons.length; i++) {
			var btn = buttons[i];
			var active = btn.getAttribute('data-view') === finalView;
			btn.classList.toggle('is-active', active);
			btn.setAttribute('aria-selected', active ? 'true' : 'false');
		}
		try {
			window.localStorage.setItem(storageKey, finalView);
		} catch (e) {}
	}

	var headers = table.querySelectorAll('tr:first-child > td.colhead');
	var labels = [];
	for (var h = 0; h < headers.length; h++) {
		var header = headers[h];
		var text = (header.textContent || '').replace(/\s+/g, ' ').trim();
		if (!text) {
			var icon = header.querySelector('img');
			text = icon ? (icon.getAttribute('title') || '') : '';
		}
		labels.push(text);
	}

	var rows = getTorrentRows();
	for (var r = 1; r < rows.length; r++) {
		var cells = rows[r].children;
		for (var c = 0; c < cells.length; c++) {
			if (labels[c]) {
				cells[c].setAttribute('data-label', labels[c]);
			}
		}
	}

	for (var i = 0; i < buttons.length; i++) {
		buttons[i].addEventListener('click', function () {
			setView(this.getAttribute('data-view'));
		});
	}

	var saved = 'list';
	try {
		saved = window.localStorage.getItem(storageKey) || 'list';
	} catch (e) {}
	setView(saved);
})();

(function () {
	var body = document.body;
	if (!body || !body.classList.contains('page-torrents')) {
		return;
	}

	var trigger = document.querySelector('.top-advanced-search-trigger');
	var modal = document.getElementById('torrent-advanced-search-modal');
	if (!trigger || !modal) {
		return;
	}

	var closeNodes = modal.querySelectorAll('[data-advanced-search-close]');
	var filterWrap = modal.querySelector('#ksearchboxmain');
	var filterIcon = modal.querySelector('#picsearchboxmain');
	var previousOverflow = '';

	function expandFilterPanel() {
		if (filterWrap && filterWrap.style.display === 'none') {
			filterWrap.style.display = '';
		}
		if (filterIcon) {
			filterIcon.classList.remove('plus');
			filterIcon.classList.add('minus');
		}
	}

	function openModal() {
		expandFilterPanel();
		modal.setAttribute('aria-hidden', 'false');
		body.classList.add('searchbox-modal-open');
		previousOverflow = body.style.overflow;
		body.style.overflow = 'hidden';
		var input = modal.querySelector('#searchinput') || modal.querySelector('input[name="search"]');
		if (input) {
			window.setTimeout(function () {
				input.focus();
			}, 60);
		}
	}

	function closeModal() {
		modal.setAttribute('aria-hidden', 'true');
		body.classList.remove('searchbox-modal-open');
		body.style.overflow = previousOverflow || '';
	}

	trigger.addEventListener('click', function (e) {
		e.preventDefault();
		openModal();
	});

	for (var i = 0; i < closeNodes.length; i++) {
		closeNodes[i].addEventListener('click', function (e) {
			e.preventDefault();
			closeModal();
		});
	}

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && body.classList.contains('searchbox-modal-open')) {
			closeModal();
		}
	});
})();

(function () {
	var modal = document.getElementById('torrent-quick-filter-modal');
	if (!modal) {
		return;
	}
	var title = modal.querySelector('h2');
	var list = modal.querySelector('.torrent-quick-filter-modal-list');
	var triggers = document.querySelectorAll('[data-quick-filter-modal]');
	var closeNodes = modal.querySelectorAll('[data-quick-filter-close]');

	function closeModal() {
		modal.setAttribute('aria-hidden', 'true');
	}

	function openModal(trigger) {
		var raw = trigger.getAttribute('data-filter-items') || '[]';
		var items = [];
		try {
			items = JSON.parse(raw);
		} catch (e) {}
		title.textContent = trigger.getAttribute('data-filter-title') || '';
		list.innerHTML = '';
		for (var i = 0; i < items.length; i++) {
			var item = items[i];
			var link = document.createElement('a');
			link.href = item.url || '#';
			link.textContent = item.name || '';
			if (item.active) {
				link.className = 'is-active';
			}
			list.appendChild(link);
		}
		modal.setAttribute('aria-hidden', 'false');
	}

	for (var i = 0; i < triggers.length; i++) {
		triggers[i].addEventListener('click', function (e) {
			e.preventDefault();
			openModal(this);
		});
	}
	for (var c = 0; c < closeNodes.length; c++) {
		closeNodes[c].addEventListener('click', function (e) {
			e.preventDefault();
			closeModal();
		});
	}
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && modal.getAttribute('aria-hidden') === 'false') {
			closeModal();
		}
	});
})();
</script>
<?php
print("</td></tr></table>");
stdfoot();
