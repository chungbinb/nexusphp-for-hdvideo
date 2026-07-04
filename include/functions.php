<?php

use App\Models\SearchBox;
use App\Models\TorrentExtra;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

function get_langfolder_cookie($transToLocale = false)
{
    $deflang = \App\Models\Setting::getDefaultLang();
	$lang = "";
	if (!isset($_COOKIE["c_lang_folder"])) {
		$lang = $deflang;
	} else {
		$langfolder_array = get_langfolder_list();
		$enabled = \App\Models\Language::listEnabled();
		foreach($langfolder_array as $lf)
		{
			if($lf == $_COOKIE["c_lang_folder"] && in_array($lf, $enabled)) {
                $lang = $_COOKIE["c_lang_folder"];
                break;
            }
		}
	}
	if (!$lang) {
	    $lang = $deflang;
    }
	if (!$transToLocale) {
	    return $lang;
    }
	return \App\Http\Middleware\Locale::$languageMaps[$lang] ?? 'en';
}

function get_user_lang($user_id)
{
	$lang = mysql_fetch_assoc(sql_query("SELECT site_lang_folder FROM language LEFT JOIN users ON language.id = users.lang WHERE language.site_lang=1 AND users.id= ". sqlesc($user_id) ." LIMIT 1"));
	return $lang['site_lang_folder'] ?: 'en';
}

function get_langfile_path($script_name ="", $target = false, $lang_folder = "")
{
	global $CURLANGDIR;
	$CURLANGDIR = get_langfolder_cookie();
	if($lang_folder == "")
	{
		$lang_folder = $CURLANGDIR;
	}
	$result = "lang/" . ($target == false ? $lang_folder : "_target") ."/lang_". ( $script_name == "" ? substr(strrchr($_SERVER['SCRIPT_NAME'],'/'),1) : $script_name);
    return $result;
}

function get_row_sum($table, $field, $suffix = "")
{
	$r = sql_query("SELECT SUM($field) FROM $table $suffix") or sqlerr(__FILE__, __LINE__);
	$a = mysql_fetch_row($r);
	return $a[0];
}

function get_single_value($table, $field, $suffix = ""){
	$r = sql_query("SELECT $field FROM $table $suffix LIMIT 1") or sqlerr(__FILE__, __LINE__);
	$a = mysql_fetch_row($r);
	if ($a) {
		return $a[0];
	} else {
		return false;
	}
}

function stdmsg($heading, $text, $htmlstrip = false)
{
	if ($htmlstrip) {
		$heading = htmlspecialchars(trim($heading));
		$text = htmlspecialchars(trim($text));
	}
	print("<table align=\"center\" class=\"main\" width=\"500\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\"><tr><td class=\"embedded\">\n");
	if ($heading)
	print("<h2>".$heading."</h2>\n");
	print("<table width=\"100%\" border=\"1\" cellspacing=\"0\" cellpadding=\"10\"><tr><td class=\"text\">");
	print($text . "</td></tr></table></td></tr></table>\n");
}

function stderr($heading, $text, $htmlstrip = true, $head = true, $foot = true, $die = true)
{
	if ($head) stdhead();
	stdmsg($heading, $text, $htmlstrip);
	if ($foot) stdfoot();
	if ($die) die;
}

function sqlerr($file = '', $line = '')
{
	print("<table border=\"0\" bgcolor=\"blue\" align=\"left\" cellspacing=\"0\" cellpadding=\"10\" style=\"background: blue;\">" .
	"<tr><td class=\"embedded\"><font color=\"white\"><h1>SQL Error</h1>\n" .
	"<b>" . mysql_error() . ($file != '' && $line != '' ? "<p>in $file, line $line</p>" : "") . "</b></font></td></tr></table>");
	die;
}

function format_quotes($s)
{
	preg_match_all('/\\[quote.*?\\]/i', $s, $result, PREG_PATTERN_ORDER);
	$openquotecount = count($openquote = $result[0]);
	preg_match_all('/\\[\/quote\\]/i', $s, $result, PREG_PATTERN_ORDER);
	$closequotecount = count($closequote = $result[0]);

	if ($openquotecount != $closequotecount) return $s; // quote mismatch. Return raw string...

	// Get position of opening quotes
	$openval = array();
	$pos = -1;

	foreach($openquote as $val)
	$openval[] = $pos = strpos($s,$val,$pos+1);

	// Get position of closing quotes
	$closeval = array();
	$pos = -1;

	foreach($closequote as $val)
	$closeval[] = $pos = strpos($s,$val,$pos+1);


	for ($i=0; $i < count($openval); $i++)
	if ($openval[$i] > $closeval[$i]) return $s; // Cannot close before opening. Return raw string...

    $textQuote = nexus_trans("label.text_quote");
	$s = preg_replace("/\\[quote\\]/i","<fieldset><legend> ".$textQuote." </legend><br />",$s);
	$s = preg_replace("/\\[quote=(.+?)\\]/i", "<fieldset><legend> ".$textQuote.": \\1 </legend><br />", $s);
	$s = preg_replace("/\\[\\/quote\\]/i","</fieldset><br />",$s);
	return $s;
}

function print_attachment($dlkey, $enableimage = true, $imageresizer = true)
{
	$httpdirectory_attachment = get_setting('attachment.httpdirectory');
	if (strlen($dlkey) == 32){
	if (!$row = \Nexus\Database\NexusDB::cache_get('attachment_'.$dlkey.'_content')){
		$res = sql_query("SELECT * FROM attachments WHERE dlkey=".sqlesc($dlkey)." LIMIT 1") or sqlerr(__FILE__,__LINE__);
		$row = mysql_fetch_array($res);
        \Nexus\Database\NexusDB::cache_put('attachment_'.$dlkey.'_content', $row, 86400);
	}
	}
	if (!$row)
	{
		return "<div style=\"text-decoration: line-through; font-size: 7pt\">".nexus_trans('attachment.text_key').$dlkey.nexus_trans('attachment.not_found')."</div>";
	}
	else{
	$id = $row['id'];
	if ($row['isimage'] == 1)
	{
		if ($enableimage){
            $driver = $row['driver'] ?? 'local';
            if ($driver == "local") {
                if ($row['thumb'] == 1){
                    $url = $httpdirectory_attachment."/".$row['location'].".thumb.jpg";
                } else {
                    $url = $httpdirectory_attachment."/".$row['location'];
                }
            } else {
                $url = \Nexus\Attachment\Storage::getDriver($driver)->getImageUrl($row['location']);
            }
            do_log(sprintf("driver: %s, location: %s, url: %s", $driver, $row['location'], $url));
			if($imageresizer == true)
				$onclick = " data-zoomable data-zoom-src=\"".$url."\"";
			else $onclick = "";
			$return = "<img id=\"attach".$id."\" style=\"max-width: 700px\" alt=\"".htmlspecialchars($row['filename'])."\" src=\"".$url."\"". $onclick .  " onmouseover=\"domTT_activate(this, event, 'content', '".htmlspecialchars("<strong>".nexus_trans('attachment.size')."</strong>: ".mksize($row['filesize'])."<br />".gettime($row['added']))."', 'styleClass', 'attach', 'x', findPosition(this)[0], 'y', findPosition(this)[1]-58);\" />";
		}
		else $return = "";
	}
	else
	{
		switch($row['filetype'])
		{
			case 'application/x-bittorrent': {
				$icon = "<img alt=\"torrent\" src=\"pic/attachicons/torrent.gif\" />";
				break;
			}
			case 'application/zip':{
				$icon = "<img alt=\"zip\" src=\"pic/attachicons/archive.gif\" />";
				break;
			}
			case 'application/rar':{
				$icon = "<img alt=\"rar\" src=\"pic/attachicons/archive.gif\" />";
				break;
			}
			case 'application/x-7z-compressed':{
				$icon = "<img alt=\"7z\" src=\"pic/attachicons/archive.gif\" />";
				break;
			}
			case 'application/x-gzip':{
				$icon = "<img alt=\"gzip\" src=\"pic/attachicons/archive.gif\" />";
				break;
			}
			case 'audio/mpeg':{
			}
			case 'audio/ogg':{
				$icon = "<img alt=\"audio\" src=\"pic/attachicons/audio.gif\" />";
				break;
			}
			case 'video/x-flv':{
				$icon = "<img alt=\"flv\" src=\"pic/attachicons/flv.gif\" />";
				break;
			}
			default: {
				$icon = "<img alt=\"other\" src=\"pic/attachicons/common.gif\" />";
			}
		}
		$return = "<div class=\"attach\">".$icon."&nbsp;&nbsp;<a href=\"".htmlspecialchars("getattachment.php?id=".$id."&dlkey=".$dlkey)."\" target=\"_blank\" id=\"attach".$id."\" onmouseover=\"domTT_activate(this, event, 'content', '".htmlspecialchars("<strong>".nexus_trans('attachment.downloads')."</strong>: ".number_format($row['downloads'])."<br />".gettime($row['added']))."', 'styleClass', 'attach', 'x', findPosition(this)[0], 'y', findPosition(this)[1]-58);\">".htmlspecialchars($row['filename'])."</a>&nbsp;&nbsp;<font class=\"size\">(".mksize($row['filesize']).")</font></div>";
	}
	return $return;
	}
}

function addTempCode($value) {
	global $tempCode, $tempCodeCount;
	$tempCode[$tempCodeCount] = $value;
	$return = "<tempCode_$tempCodeCount>";
	$tempCodeCount++;
	return $return;
}

function formatAdUrl($adid, $url, $content, $newWindow=true)
{
	return formatUrl("adredir.php?id=".$adid."&amp;url=".rawurlencode($url), $newWindow, $content);
}
function formatUrl($url, $newWindow = false, $text = '', $linkClass = '') {
	if (!$text) {
		$text = $url;
	}
	return addTempCode("<a".($linkClass ? " class=\"$linkClass\"" : '')." href=\"$url\"" . ($newWindow==true? " target=\"_blank\"" : "").">$text</a>");
}
function formatCode($text) {
    $textCode = nexus_trans("label.text_code");
	return addTempCode("<br /><div class=\"codetop\">".$textCode."</div><div class=\"codemain\"><pre><code>$text</code></pre></div><br />");
}

function formatImg($src, $enableImageResizer, $image_max_width, $image_max_height, $imgId = "") {
    $src = filter_src($src);
    if (empty($src)) {
        return "";
    }
    return addTempCode("<img style=\"max-width: 100%\" id=\"$imgId\" alt=\"image\" src=\"$src\"" .
        ($enableImageResizer ?
            " onload=\"Scale(this, $image_max_width, $image_max_height);\" data-zoomable " : "") .
        " onerror=\"handleImageError(this, '$src');\" />");
}

function formatFlash($src, $width, $height) {
    $src = filter_src($src);
    if (empty($src)) {
        return "";
    }
	if (!$width) {
		$width = 500;
	}
	if (!$height) {
		$height = 300;
	}
	return addTempCode("<object width=\"$width\" height=\"$height\"><param name=\"movie\" value=\"$src\" /><embed src=\"$src\" width=\"$width\" height=\"$height\" type=\"application/x-shockwave-flash\"></embed></object>");
}
function formatFlv($src, $width, $height) {
    $src = filter_src($src);
    if (empty($src)) {
        return "";
    }
	if (!$width) {
		$width = 320;
	}
	if (!$height) {
		$height = 240;
	}
	return addTempCode("<object width=\"$width\" height=\"$height\"><param name=\"movie\" value=\"flvplayer.swf?file=$src\" /><param name=\"allowFullScreen\" value=\"true\" /><embed src=\"flvplayer.swf?file=$src\" type=\"application/x-shockwave-flash\" allowfullscreen=\"true\" width=\"$width\" height=\"$height\"></embed></object>");
}
function formatYoutube($src, $width = '', $height = ''): string
{
    $src = filter_src($src);
    if (empty($src)) {
        return "";
    }
    if (!$width) {
        $width = 560;
    }
    if (!$height) {
        $height = 315;
    }
    $queryString = parse_url($src, PHP_URL_QUERY);
    parse_str($queryString, $parameters);
    if (empty($parameters['v'])) {
        $videoId = '';
    } else {
        $videoId = $parameters['v'];
    }
    return addTempCode(sprintf(
        '<iframe width="%s" height="%s" src="https://www.youtube.com/embed/%s" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>',
        $width, $height, $videoId
    ));
}

function formatVideo($src, $width, $height) {
    $src = filter_src($src);
    if (empty($src)) {
        return "";
    }
    if (!$width) {
        $width = 560;
    }
    if (!$height) {
        $height = 315;
    }
    return addTempCode("<video controls width=\"$width\" height=\"$height\"><source src=\"$src\" /><a href=\"$src\">$src</a></video>");
}

function formatAudio($src) {
    $src = filter_src($src);
    if (empty($src)) {
        return "";
    }
    return addTempCode("<audio controls><source src=\"$src\" /><a href=\"$src\">$src</a></audio>");
}

function formatSpoiler($content, $title = '', $defaultCollapsed = true): string
{
    global $lang_functions;
    if (!$title) {
        $title = $lang_functions['spoiler_default_title'];
    }
//    $content = str_replace(['<br>', '<br />'], '', $content);
    $contentClass = "";
    if (!$defaultCollapsed) {
        $contentClass .= " open";
    }
    $HTML = sprintf(
        '<details%s><summary>%s</summary>%s</details>',
        $contentClass, $title, $content
    );
    return addTempCode($HTML);
}

function formatHidden($content): string
{
    return addTempCode(sprintf('<span class="hidden-text">%s</span>', $content));
}

function formatTextAlign($text, $align): string
{
    return addTempCode(sprintf('<div style="text-align: %s">%s</div>', $align, $text));
}

function format_urls($text, $newWindow = false) {
//	return preg_replace("/((https?|ftp|gopher|news|telnet|mms|rtsp):\/\/[^()\[\]<>\s]+)/ei", "formatUrl('\\1', ".($newWindow==true ? 1 : 0).", '', 'faqlink')", $text);
	return preg_replace_callback("/((https?|ftp|gopher|news|telnet|mms|rtsp):\/\/[^()\[\]<>\s]+)/i", function ($matches) use ($newWindow) {
	    return formatUrl($matches[1], $newWindow, '', 'faqlink');
    }, $text);
}
function format_comment($text, $strip_html = true, $xssclean = false, $newtab = true, $imageresizer = true, $image_max_width = 700, $enableimage = true, $enableflash = true , $imagenum = -1, $image_max_height = 0, $adid = 0)
{
	global $lang_functions;
	global $CURUSER, $SITENAME, $BASEURL;
	global $tempCode, $tempCodeCount;
    if ($text == '') {
        return "";
    }
    $enableattach_attachment = get_setting('attachment.enableattach');
	$tempCode = array();
	$tempCodeCount = 0;
	$imageresizer = $imageresizer ? 1 : 0;
	$s = $text;

	if ($strip_html) {
		$s = htmlspecialchars($s);
	}

	if (strpos($s,"[code]") !== false && strpos($s,"[/code]") !== false) {
//		$s = preg_replace("/\[code\](.+?)\[\/code\]/eis","formatCode('\\1')", $s);
		$s = preg_replace_callback("/\[code\](.+?)\[\/code\]/is",function ($matches) {
		    return formatCode($matches[1]);
        }, $s);
	}

    if (strpos($s,"[raw]") !== false && strpos($s,"[/raw]") !== false) {
        $s = preg_replace_callback("/\[raw\](.+?)\[\/raw\]/is",function ($matches) {
            return addTempCode($matches[1]);
        }, $s);
    }

    // Linebreaks
    $s = nl2br($s);

	$originalBbTagArray = array('[siteurl]', '[site]','[*]', '[b]', '[/b]', '[i]', '[/i]', '[u]', '[/u]', '[s]', '[/s]', '[pre]', '[/pre]', '[/color]', '[/font]', '[/size]', '[hr]', "  ");
	$replaceXhtmlTagArray = array(get_protocol_prefix().get_setting('basic.BASEURL'), get_setting('basic.SITENAME'), '&#x2022; ', '<b>', '</b>', '<i>', '</i>', '<u>', '</u>', '<s>', '</s>', '<pre>', '</pre>', '</span>', '</font>', '</font>', '<hr>', ' &nbsp;');
	$s = str_replace($originalBbTagArray, $replaceXhtmlTagArray, $s);

	$originalBbTagArray = array("/\[font=([^\[\(&\\;]+?)\]/is", "/\[color=([#0-9a-z]{1,15})\]/is", "/\[color=([a-z]+)\]/is", "/\[size=([1-7])\]/is");
	$replaceXhtmlTagArray = array("<font face=\"\\1\">", "<span style=\"color: \\1;word-break: break-word\">", "<span style=\"color: \\1;word-break: break-word\">", "<font size=\"\\1\">");
	$s = preg_replace($originalBbTagArray, $replaceXhtmlTagArray, $s);


	if ($enableimage) {
//		$s = preg_replace("/\[img\]([^\<\r\n\"']+?)\[\/img\]/ei", "formatImg('\\1',".$imageresizer.",".$image_max_width.",".$image_max_height.")", $s, $imagenum, $imgReplaceCount);
		$s = preg_replace_callback("/\[img\]([^\<\r\n\"']+?)\[\/img\]/i", function ($matches) use ($imageresizer, $image_max_width, $image_max_height) {
		    return formatImg($matches[1],$imageresizer,$image_max_width,$image_max_height);
        }, $s, $imagenum, $imgReplaceCount);

//		$s = preg_replace("/\[img=([^\<\r\n\"']+?)\]/ei", "formatImg('\\1',".$imageresizer.",".$image_max_width.",".$image_max_height.")", $s, ($imagenum != -1 ? max($imagenum-$imgReplaceCount, 0) : -1));
		$s = preg_replace_callback("/\[img=([^\<\r\n\"']+?)\]/i", function ($matches) use ($imageresizer, $image_max_width, $image_max_height) {
		    return formatImg($matches[1],$imageresizer,$image_max_width,$image_max_height);
        }, $s, ($imagenum != -1 ? max($imagenum-$imgReplaceCount, 0) : -1));
	} else {
		$s = preg_replace("/\[img\]([^\<\r\n\"']+?)\[\/img\]/i", '', $s, -1);
		$s = preg_replace("/\[img=([^\<\r\n\"']+?)\]/i", '', $s, -1);
	}

    //[youtube,560,315]https://www.youtube.com/watch?v=DWDL3VTCcCg&ab_channel=ESPNMMA[/youtube]
	if (str_contains($s, '[youtube') && str_contains($s, 'v=')) {
        $s = preg_replace_callback("/\[youtube(\,([1-9][0-9]*)\,([1-9][0-9]*))?\]((http|https):\/\/[^\s'\"<>]+)\[\/youtube\]/i", function ($matches) {
            return formatYoutube($matches[4], $matches[2], $matches[3]);
        }, $s);
    }
    if (str_contains($s, "[video")) {
        $s = preg_replace_callback("/\[video(\,([1-9][0-9]*)\,([1-9][0-9]*))?\]((http|https):\/\/[^\s'\"<>]+)\[\/video\]/i", function ($matches) {
            return formatVideo($matches[4], $matches[2], $matches[3]);
        }, $s);
    }
    if (str_contains($s, "[audio")) {
        $s = preg_replace_callback("/\[audio\]((http|https):\/\/[^\s'\"<>]+)\[\/audio\]/i", function ($matches) {
            return formatAudio($matches[1]);
        }, $s);

    }

	// [url=http://www.example.com]Text[/url]
	if ($adid) {
//		$s = preg_replace("/\[url=([^\[\s]+?)\](.+?)\[\/url\]/ei", "formatAdUrl(".$adid." ,'\\1', '\\2', ".($newtab==true ? 1 : 0).", 'faqlink')", $s);
		$s = preg_replace_callback("/\[url=([^\[\s]+?)\](.+?)\[\/url\]/i", function ($matches) use ($adid, $newtab) {
		    return formatAdUrl($adid ,$matches[1], $matches[2], ".($newtab==true ? 1 : 0).", 'faqlink');
        }, $s);
	} else {
//		$s = preg_replace("/\[url=([^\[\s]+?)\](.+?)\[\/url\]/ei", "formatUrl('\\1', ".($newtab==true ? 1 : 0).", '\\2', 'faqlink')", $s);
		$s = preg_replace_callback("/\[url=([^\[\s]+?)\](.+?)\[\/url\]/i", function ($matches) use ($newtab) {
		    return formatUrl($matches[1], $newtab, $matches[2], 'faqlink');
        }, $s);
	}

	// [url]http://www.example.com[/url]
//	$s = preg_replace("/\[url\]([^\[\s]+?)\[\/url\]/ei", "formatUrl('\\1', ".($newtab==true ? 1 : 0).", '', 'faqlink')", $s);
	$s = preg_replace_callback("/\[url\]([^\[\s]+?)\[\/url\]/i", function ($matches) use ($newtab) {
	    return formatUrl($matches[1], $newtab, '', 'faqlink');
    }, $s);

    // [left]Left text[/left]
    $s = preg_replace_callback("/\[left\](.*)\[\/left\]/isU", function ($matches) {
        return formatTextAlign($matches[1], 'left');
    }, $s);

    // [center]Center text[/center]
    $s = preg_replace_callback("/\[center\](.*)\[\/center\]/isU", function ($matches) {
        return formatTextAlign($matches[1], 'center');
    }, $s);

    // [right]Right text[/right]
    $s = preg_replace_callback("/\[right\](.*)\[\/right\]/isU", function ($matches) {
        return formatTextAlign($matches[1], 'right');
    }, $s);

    // [hide]Hidden text[/hide]
    $s = preg_replace_callback("/\[hide\](.*)\[\/hide\]/isU", function ($matches) {
        return formatHidden($matches[1]);
    }, $s);


	$s = format_urls($s, $newtab);
	// Quotes
	if (strpos($s,"[quote") !== false && strpos($s,"[/quote]") !== false) { //format_quote is kind of slow. Better check if [quote] exists beforehand
		$s = format_quotes($s);
	}

//	$s = preg_replace("/\[em([1-9][0-9]*)\]/ie", "(\\1 < 192 ? '<img src=\"pic/smilies/\\1.gif\" alt=\"[em\\1]\" />' : '[em\\1]')", $s);
	$s = preg_replace_callback("/\[em([1-9][0-9]*)\]/i", function ($matches) {
	    $smile = get_smile($matches[1]);
	    return $smile ? '<img src="'.$smile.'" alt="[em' . $matches[1] . ']" />' : '[em' . $matches[1] . ']';
    }, $s);

    //[spoiler=What happens to the hero?]The hero dies at the end![/spoiler]
    if (str_contains($s, '[spoiler')) {
        $s = preg_replace_callback("/\[spoiler(=(.*))?\](.*)\[\/spoiler\]/isU", function ($matches) {
            return formatSpoiler($matches[3], $matches[2], nexus()->getScript() != 'preview');
        }, $s);
    }

    if ($enableattach_attachment == 'yes' && $imagenum != 1){
        $limit = 20;
//		$s = preg_replace("/\[attach\]([0-9a-zA-z][0-9a-zA-z]*)\[\/attach\]/ies", "print_attachment('\\1', ".($enableimage ? 1 : 0).", ".($imageresizer ? 1 : 0).")", $s, $limit);
        $s = preg_replace_callback("/\[attach\]([0-9a-zA-z][0-9a-zA-z]*)\[\/attach\]/is", function ($matches) use ($enableimage, $imageresizer) {
            return print_attachment($matches[1], ".($enableimage ? 1 : 0).", ".($imageresizer ? 1 : 0).");
        }, $s, $limit);
    }

	reset($tempCode);
	$j = $i = 0;
	while(count($tempCode) || $j > 5) {
		foreach($tempCode as $key=>$code) {
			$s = str_replace("<tempCode_$key>", $code, $s, $count);
			if ($count) {
				unset($tempCode[$key]);
				$i = $i+$count;
			}
		}
		$j++;
	}
    return str_replace('', '', $s);
}

function highlight($search,$subject,$hlstart='<b><font class="striking">',$hlend="</font></b>")
{

	$srchlen=strlen($search);    // lenght of searched string
	if ($srchlen==0) return $subject;
	$find = $subject;
	while ($find = stristr($find,$search)) {    // find $search text in $subject -case insensitiv
		$srchtxt = substr($find,0,$srchlen);    // get new search text
		$find=substr($find,$srchlen);
		$subject = str_replace($srchtxt,"$hlstart$srchtxt$hlend",$subject);    // highlight founded case insensitive search text
	}
	return $subject;
}


function get_user_class_name($class, $compact = false, $b_colored = false, $I18N = false, array $options = [])
{
    if (!IN_NEXUS) {
        return \App\Models\User::getClassName($class, $compact, $b_colored, $I18N);
    }
    global $SITENAME;
	static $en_lang_functions;
	static $current_user_lang_functions;
	static $settingAccount;
	if (!$en_lang_functions) {
		require(get_langfile_path("functions.php",false,"en"));
		$en_lang_functions = $lang_functions;
	}
	if (!$settingAccount) {
	    $settingAccount = get_setting('account');
    }

	if(!$I18N) {
		$this_lang_functions = $en_lang_functions;
	} else {
		if (!$current_user_lang_functions) {
			require(get_langfile_path("functions.php"));
			$current_user_lang_functions = $lang_functions;
		}
		$this_lang_functions = $current_user_lang_functions;
	}

	$class_name = "";
	switch ($class)
	{
		case UC_PEASANT: {$class_name = $this_lang_functions['text_peasant']; break;}
		case UC_USER: {$class_name = $this_lang_functions['text_user']; break;}
		case UC_POWER_USER: {$class_name = $this_lang_functions['text_power_user']; break;}
		case UC_ELITE_USER: {$class_name = $this_lang_functions['text_elite_user']; break;}
		case UC_CRAZY_USER: {$class_name = $this_lang_functions['text_crazy_user']; break;}
		case UC_INSANE_USER: {$class_name = $this_lang_functions['text_insane_user']; break;}
		case UC_VETERAN_USER: {$class_name = $this_lang_functions['text_veteran_user']; break;}
		case UC_EXTREME_USER: {$class_name = $this_lang_functions['text_extreme_user']; break;}
		case UC_ULTIMATE_USER: {$class_name = $this_lang_functions['text_ultimate_user']; break;}
		case UC_NEXUS_MASTER: {$class_name = $this_lang_functions['text_nexus_master']; break;}
		case UC_VIP: {$class_name = $this_lang_functions['text_vip']; break;}
		case UC_UPLOADER: {$class_name = $this_lang_functions['text_uploader']; break;}
		case UC_RETIREE: {$class_name = $this_lang_functions['text_retiree']; break;}
		case UC_MODERATOR: {$class_name = $this_lang_functions['text_moderators']; break;}
		case UC_ADMINISTRATOR: {$class_name = $this_lang_functions['text_administrators']; break;}
		case UC_SYSOP: {$class_name = $this_lang_functions['text_sysops']; break;}
		case UC_STAFFLEADER: {$class_name = $this_lang_functions['text_staff_leader']; break;}
	}
	if (isset($options['with_alias']) && $options['with_alias'] && $class < UC_VIP && isset($settingAccount["{$class}_alias"])) {
	    $alias = trim($settingAccount["{$class}_alias"]);
	    if (!empty($alias)) {
	        $class_name = sprintf('%s(%s)', $class_name, $alias);
        }
    }

	switch ($class)
	{
		case UC_PEASANT: {$class_name_color = $en_lang_functions['text_peasant']; break;}
		case UC_USER: {$class_name_color = $en_lang_functions['text_user']; break;}
		case UC_POWER_USER: {$class_name_color = $en_lang_functions['text_power_user']; break;}
		case UC_ELITE_USER: {$class_name_color = $en_lang_functions['text_elite_user']; break;}
		case UC_CRAZY_USER: {$class_name_color = $en_lang_functions['text_crazy_user']; break;}
		case UC_INSANE_USER: {$class_name_color = $en_lang_functions['text_insane_user']; break;}
		case UC_VETERAN_USER: {$class_name_color = $en_lang_functions['text_veteran_user']; break;}
		case UC_EXTREME_USER: {$class_name_color = $en_lang_functions['text_extreme_user']; break;}
		case UC_ULTIMATE_USER: {$class_name_color = $en_lang_functions['text_ultimate_user']; break;}
		case UC_NEXUS_MASTER: {$class_name_color = $en_lang_functions['text_nexus_master']; break;}
		case UC_VIP: {$class_name_color = $en_lang_functions['text_vip']; break;}
		case UC_UPLOADER: {$class_name_color = $en_lang_functions['text_uploader']; break;}
		case UC_RETIREE: {$class_name_color = $en_lang_functions['text_retiree']; break;}
		case UC_MODERATOR: {$class_name_color = $en_lang_functions['text_moderators']; break;}
		case UC_ADMINISTRATOR: {$class_name_color = $en_lang_functions['text_administrators']; break;}
		case UC_SYSOP: {$class_name_color = $en_lang_functions['text_sysops']; break;}
		case UC_STAFFLEADER: {$class_name_color = $en_lang_functions['text_staff_leader']; break;}
	}
	$class_name = ( $compact == true ? str_replace(" ", "",$class_name) : $class_name);
	if (isset($options['uid'], $options['with_role'])) {
        $class_name = implode('&nbsp;|&nbsp;', apply_filter('user_class_name', [$class_name], $options['uid']));
    }
	if ($class_name && $b_colored) {
        $class_name = "<b class='" . str_replace(" ", "",$class_name_color) . "_Name'>" . $class_name . "</b>";
    }
	return $class_name;
}

function is_valid_user_class($class)
{
	return is_numeric($class) && floor($class) == $class && $class >= UC_PEASANT && $class <= UC_STAFFLEADER;
}

function int_check($value,$stdhead = false, $stdfood = true, $die = true, $log = true) {
	global $lang_functions;
	global $CURUSER;
	if (is_array($value))
	{
		foreach ($value as $val) int_check ($val);
	}
	else
	{
		if (!is_valid_id($value)) {
			$msg = "Invalid ID Attempt: Username: ".$CURUSER["username"]." - UserID: ".$CURUSER["id"]." - UserIP : ".getip();
			if ($log) {
                write_log($msg,'mod');
            }
            do_log($msg, 'error');
			if ($stdhead)
				stderr($lang_functions['std_error'],$lang_functions['std_invalid_id']);
			else
			{
				print ("<h2>".$lang_functions['std_error']."</h2><table width=\"100%\" border=\"1\" cellspacing=\"0\" cellpadding=\"10\"><tr><td class=\"text\">");
				print ($lang_functions['std_invalid_id']."</td></tr></table>");
			}
			if ($stdfood)
				stdfoot();
			if ($die)
				die;
		}
		else
			return true;
	}
}

function is_valid_id($id)
{
	return is_numeric($id) && ($id > 0) && (floor($id) == $id);
}


//-------- Begins a main frame
function begin_main_frame($caption = "", $center = false, $width = 100)
{
	$tdextra = "";
	if ($caption)
	print("<h2>".$caption."</h2>");

	if ($center)
	$tdextra .= " align=\"center\"";

	if (!str_ends_with($width, '%')) {
        $width = CONTENT_WIDTH * $width / 100;
    }

	print("<table class=\"main\" width=\"".$width."\" border=\"0\" cellspacing=\"0\" cellpadding=\"0\">" .
	"<tr><td class=\"embedded\" $tdextra>");
}

function end_main_frame()
{
	print("</td></tr></table>\n");
}

function begin_frame($caption = "", $center = false, $padding = 10, $width="100%", $caption_center="left")
{
	$tdextra = "";

	if ($center)
	$tdextra .= " align=\"center\"";

	print(($caption ? "<h2 align=\"".$caption_center."\">".$caption."</h2>" : "") . "<table width=\"".$width."\" border=\"1\" cellspacing=\"0\" cellpadding=\"".$padding."\">" . "<tr><td class=\"text\" $tdextra>\n");

}

function end_frame()
{
	print("</td></tr></table>\n");
}

function begin_table($fullwidth = false, $padding = 5)
{
	$width = "";

	if ($fullwidth)
	$width .= " width=50%";
	print("<table class=\"main".$width."\" border=\"1\" cellspacing=\"0\" cellpadding=\"".$padding."\">");
}

function end_table()
{
	print("</table>\n");
}

//-------- Inserts a smilies frame
//         (move to globals)

function insert_smilies_frame()
{
	global $lang_functions;
	begin_frame($lang_functions['text_smilies'], true);
	begin_table(false, 5);
	print("<tr><td class=\"colhead\">".$lang_functions['col_type_something']."</td><td class=\"colhead\">".$lang_functions['col_to_make_a']."</td></tr>\n");
	for ($i=1; $i<192; $i++) {
		print("<tr><td>[em$i]</td><td><img src=\"pic/smilies/".$i.".gif\" alt=\"[em$i]\" /></td></tr>\n");
	}
	end_table();
	end_frame();
}

function get_ratio_color($ratio)
{
	if ($ratio < 0.1) return "#ff0000";
	if ($ratio < 0.2) return "#ee0000";
	if ($ratio < 0.3) return "#dd0000";
	if ($ratio < 0.4) return "#cc0000";
	if ($ratio < 0.5) return "#bb0000";
	if ($ratio < 0.6) return "#aa0000";
	if ($ratio < 0.7) return "#990000";
	if ($ratio < 0.8) return "#880000";
	if ($ratio < 0.9) return "#770000";
	if ($ratio < 1) return "#660000";
	return "";
}

function get_slr_color($ratio)
{
	if ($ratio < 0.025) return "#ff0000";
	if ($ratio < 0.05) return "#ee0000";
	if ($ratio < 0.075) return "#dd0000";
	if ($ratio < 0.1) return "#cc0000";
	if ($ratio < 0.125) return "#bb0000";
	if ($ratio < 0.15) return "#aa0000";
	if ($ratio < 0.175) return "#990000";
	if ($ratio < 0.2) return "#880000";
	if ($ratio < 0.225) return "#770000";
	if ($ratio < 0.25) return "#660000";
	if ($ratio < 0.275) return "#550000";
	if ($ratio < 0.3) return "#440000";
	if ($ratio < 0.325) return "#330000";
	if ($ratio < 0.35) return "#220000";
	if ($ratio < 0.375) return "#110000";
	return "";
}

function write_log($text, $security = "normal")
{
    \App\Models\SiteLog::query()->insert([
        'added' => now(),
        'txt' => $text,
        'security_level' => $security,
        'uid' => get_user_id(),
    ]);
}



function get_elapsed_time($ts,$shortunit = false)
{
	global $lang_functions;
	$mins = floor(abs(TIMENOW - $ts) / 60);
	$hours = floor($mins / 60);
	$mins -= $hours * 60;
	$days = floor($hours / 24);
	$hours -= $days * 24;
	$months = floor($days / 30);
	$days2 = $days - $months * 30;
	$years = floor($days / 365);
	$months -= $years * 12;
	$t = "";
	if ($years > 0)
	return $years.($shortunit ? $lang_functions['text_short_year'] : $lang_functions['text_year'] . add_s($years)) ."&nbsp;".$months.($shortunit ? $lang_functions['text_short_month'] : $lang_functions['text_month'] . add_s($months));
	if ($months > 0)
	return $months.($shortunit ?  $lang_functions['text_short_month'] : $lang_functions['text_month'] . add_s($months)) ."&nbsp;".$days2.($shortunit ? $lang_functions['text_short_day'] : $lang_functions['text_day'] . add_s($days2));
	if ($days > 0)
	return $days.($shortunit ? $lang_functions['text_short_day'] : $lang_functions['text_day'] . add_s($days))."&nbsp;".$hours.($shortunit ? $lang_functions['text_short_hour'] : $lang_functions['text_hour'] . add_s($hours));
	if ($hours > 0)
	return $hours.($shortunit ? $lang_functions['text_short_hour'] : $lang_functions['text_hour'] . add_s($hours))."&nbsp;".$mins.($shortunit ? $lang_functions['text_short_min'] : $lang_functions['text_min'] . add_s($mins));
	if ($mins > 0)
	return $mins.($shortunit ? $lang_functions['text_short_min'] : $lang_functions['text_min'] . add_s($mins));
	return "&lt; 1".($shortunit ? $lang_functions['text_short_min'] : $lang_functions['text_min']);
}

function textbbcode($form,$text,$content="",$hastitle=false, $col_num = 130, $withPreview = false)
{
	global $lang_functions;
	global $subject, $BASEURL, $CURUSER, $enableattach_attachment;
	$editTbodyId = "$form-$text-edit";
	$previewTbodyId = "$form-$text-preview";
	$btnEditId = "$form-$text-btn-edit";
    $btnPreviewId = "$form-$text-btn-preview";
?>
<script type="text/javascript">
    let textareaId = "<?php echo $text?>"
    let editTbodyId = "<?php echo $editTbodyId?>"
    let previewTbodyId = "<?php echo $previewTbodyId?>"
    let btnEditId = "<?php echo $btnEditId?>"
    let btnPreviewId = "<?php echo $btnPreviewId?>"
//<![CDATA[
var b_open = 0;
var i_open = 0;
var u_open = 0;
var color_open = 0;
var list_open = 0;
var quote_open = 0;
var html_open = 0;

var myAgent = navigator.userAgent.toLowerCase();
var myVersion = parseInt(navigator.appVersion);

var is_ie = ((myAgent.indexOf("msie") != -1) && (myAgent.indexOf("opera") == -1));
var is_nav = ((myAgent.indexOf('mozilla')!=-1) && (myAgent.indexOf('spoofer')==-1)
&& (myAgent.indexOf('compatible') == -1) && (myAgent.indexOf('opera')==-1)
&& (myAgent.indexOf('webtv') ==-1) && (myAgent.indexOf('hotjava')==-1));

var is_win = ((myAgent.indexOf("win")!=-1) || (myAgent.indexOf("16bit")!=-1));
var is_mac = (myAgent.indexOf("mac")!=-1);
var bbtags = new Array();
function cstat() {
	var c = stacksize(bbtags);
	if ( (c < 1) || (c == null) ) {c = 0;}
	if ( ! bbtags[0] ) {c = 0;}
	document.<?php echo $form?>.tagcount.value = "Close last, Open "+c;
}
function stacksize(thearray) {
	for (i = 0; i < thearray.length; i++ ) {
		if ( (thearray[i] == "") || (thearray[i] == null) || (thearray == 'undefined') ) {return i;}
	}
	return thearray.length;
}
function pushstack(thearray, newval) {
	arraysize = stacksize(thearray);
	thearray[arraysize] = newval;
}
function popstackd(thearray) {
	arraysize = stacksize(thearray);
	theval = thearray[arraysize - 1];
	return theval;
}
function popstack(thearray) {
	arraysize = stacksize(thearray);
	theval = thearray[arraysize - 1];
	delete thearray[arraysize - 1];
	return theval;
}
function closeall() {
	if (bbtags[0]) {
		while (bbtags[0]) {
			tagRemove = popstack(bbtags)
			if ( (tagRemove != 'color') ) {
				doInsert("[/"+tagRemove+"]", "", false);
				eval("document.<?php echo $form?>." + tagRemove + ".value = ' " + tagRemove.toUpperCase() + " '");
				eval(tagRemove + "_open = 0");
			} else {
				doInsert("[/"+tagRemove+"]", "", false);
			}
			cstat();
			return;
		}
	}
	document.<?php echo $form?>.tagcount.value = "Close last, Open 0";
	bbtags = new Array();
	document.<?php echo $form?>.<?php echo $text?>.focus();
}
function add_code(NewCode) {
	document.<?php echo $form?>.<?php echo $text?>.value += NewCode;
	document.<?php echo $form?>.<?php echo $text?>.focus();
}
function alterfont(theval, thetag) {
	if (theval == 0) return;
	if(doInsert("[" + thetag + "=" + theval + "]", "[/" + thetag + "]", true)) pushstack(bbtags, thetag);
	document.<?php echo $form?>.color.selectedIndex = 0;
	cstat();
}

function tag_url(PromptURL, PromptTitle, PromptError) {
	var FoundErrors = '';
	var enterURL = prompt(PromptURL, "http://");
	var enterTITLE = prompt(PromptTitle, "");
	if (!enterURL || enterURL=="") {FoundErrors += " " + PromptURL + ",";}
	if (!enterTITLE) {FoundErrors += " " + PromptTitle;}
	if (FoundErrors) {alert(PromptError+FoundErrors);return;}
	doInsert("[url="+enterURL+"]"+enterTITLE+"[/url]", "", false);
}

function tag_list(PromptEnterItem, PromptError) {
	var FoundErrors = '';
	var enterTITLE = prompt(PromptEnterItem, "");
	if (!enterTITLE) {FoundErrors += " " + PromptEnterItem;}
	if (FoundErrors) {alert(PromptError+FoundErrors);return;}
	doInsert("[*]"+enterTITLE+"", "", false);
}

function tag_image(PromptImageURL, PromptError) {
	var FoundErrors = '';
	var enterURL = prompt(PromptImageURL, "http://");
	if (!enterURL || enterURL=="http://") {
		alert(PromptError+PromptImageURL);
		return;
	}
	doInsert("[img]"+enterURL+"[/img]", "", false);
}

function nexusOpenAttachmentUpload(trigger) {
	var editor = trigger && trigger.closest ? trigger.closest('.nexus-bbcode-editor') : null;
	var frame = editor ? editor.querySelector('.nexus-bbcode-attachment iframe') : null;
	if (!frame) {
		alert('附件上传未启用');
		return;
	}
	try {
		var doc = frame.contentWindow.document;
		var input = doc.querySelector('input[type="file"]');
		if (!input || input.disabled) {
			alert('附件上传当前不可用');
			return;
		}
		if (!input.getAttribute('data-nexus-auto-submit')) {
			input.setAttribute('data-nexus-auto-submit', '1');
			input.addEventListener('change', function() {
				if (this.files && this.files.length && this.form) {
					this.form.submit();
				}
			});
		}
		input.click();
	} catch (e) {
		alert('无法打开附件上传，请刷新页面后重试');
	}
}

function nexusCloseEditorPopovers() {
	var popovers = document.querySelectorAll('.nexus-editor-popover');
	for (var i = 0; i < popovers.length; i++) {
		popovers[i].parentNode.removeChild(popovers[i]);
	}
}

function nexusChooseImageSource(trigger, PromptImageURL, PromptError) {
	nexusCloseEditorPopovers();
	var editor = trigger && trigger.closest ? trigger.closest('.nexus-bbcode-editor') : null;
	if (!editor) {
		tag_image(PromptImageURL, PromptError);
		return;
	}
	var popover = document.createElement('div');
	popover.className = 'nexus-editor-popover';
	popover.innerHTML = '<button type="button" data-action="upload">上传图片</button><button type="button" data-action="url">使用 URL</button>';
	editor.appendChild(popover);
	var editorRect = editor.getBoundingClientRect();
	var triggerRect = trigger.getBoundingClientRect();
	popover.style.left = Math.max(8, triggerRect.left - editorRect.left - 44) + 'px';
	popover.style.top = (triggerRect.bottom - editorRect.top + 6) + 'px';
	popover.querySelector('[data-action="upload"]').onclick = function() {
		nexusCloseEditorPopovers();
		nexusOpenAttachmentUpload(trigger);
	};
	popover.querySelector('[data-action="url"]').onclick = function() {
		nexusCloseEditorPopovers();
		tag_image(PromptImageURL, PromptError);
	};
	setTimeout(function() {
		document.addEventListener('click', function closePopover(event) {
			if (!popover.contains(event.target) && event.target !== trigger) {
				nexusCloseEditorPopovers();
				document.removeEventListener('click', closePopover);
			}
		});
	}, 0);
}

function tag_extimage(content) {
	doInsert(content, "", false);
}

function tag_email(PromptEmail, PromptError) {
	var emailAddress = prompt(PromptEmail, "");
	if (!emailAddress) {
		alert(PromptError+PromptEmail);
		return;
	}
	doInsert("[email]"+emailAddress+"[/email]", "", false);
}

function doInsert(ibTag, ibClsTag, isSingle)
{
	var isClose = false;
	var obj_ta = document.<?php echo $form?>.<?php echo $text?>;
	if ( (myVersion >= 4) && is_ie && is_win)
	{
		if(obj_ta.isTextEdit)
		{
			obj_ta.focus();
			var sel = document.selection;
			var rng = sel.createRange();
			rng.colapse;
			if((sel.type == "Text" || sel.type == "None") && rng != null)
			{
				if(ibClsTag != "" && rng.text.length > 0)
				ibTag += rng.text + ibClsTag;
				else if(isSingle) isClose = true;
				rng.text = ibTag;
			}
		}
		else
		{
			if(isSingle) isClose = true;
			obj_ta.value += ibTag;
		}
	}
	else if (obj_ta.selectionStart || obj_ta.selectionStart == '0')
	{
		var startPos = obj_ta.selectionStart;
		var endPos = obj_ta.selectionEnd;
		obj_ta.value = obj_ta.value.substring(0, startPos) + ibTag + obj_ta.value.substring(endPos, obj_ta.value.length);
		obj_ta.selectionEnd = startPos + ibTag.length;
		if(isSingle) isClose = true;
	}
	else
	{
		if(isSingle) isClose = true;
		obj_ta.value += ibTag;
	}
	obj_ta.focus();
	// obj_ta.value = obj_ta.value.replace(/ /, " ");
	return isClose;
}

function clearContent()
{
    document.<?php echo $form?>.<?php echo $text?>.value = '';
}

function winop()
{
	windop = window.open("moresmilies.php?form=<?php echo $form?>&text=<?php echo $text?>","mywin","height=500,width=500,resizable=no,scrollbars=yes");
}

function simpletag(thetag)
{
	var tagOpen = eval(thetag + "_open");
	if (tagOpen == 0) {
		if(doInsert("[" + thetag + "]", "[/" + thetag + "]", true))
		{
			eval(thetag + "_open = 1");
			eval("document.<?php echo $form?>." + thetag + ".value += '*'");
			pushstack(bbtags, thetag);
			cstat();
		}
	}
	else {
		lastindex = 0;
		for (i = 0; i < bbtags.length; i++ ) {
			if ( bbtags[i] == thetag ) {
				lastindex = i;
			}
		}

		while (bbtags[lastindex]) {
			tagRemove = popstack(bbtags);
			doInsert("[/" + tagRemove + "]", "", false)
			if ((tagRemove != 'COLOR') ){
				eval("document.<?php echo $form?>." + tagRemove + ".value = '" + tagRemove.toUpperCase() + "'");
				eval(tagRemove + "_open = 0");
			}
		}
		cstat();
	}
}

function textBBCodePreview() {
    let poststr = encodeURIComponent( document.getElementById(textareaId).value );
    let result=ajax.posts('preview.php','body='+poststr);
    jQuery('#' + editTbodyId).hide()
    jQuery('#' + previewTbodyId).html(result).show()
    jQuery('#' + btnPreviewId).hide()
    jQuery('#' + btnEditId).show()
}
function textBBCodeEdit() {
    jQuery('#' + editTbodyId).show()
    jQuery('#' + previewTbodyId).hide()
    jQuery('#' + btnPreviewId).show()
    jQuery('#' + btnEditId).hide()
}
//]]>
</script>
<table class="nexus-bbcode-table" width="100%" cellspacing="0" cellpadding="5" border="0">
    <tbody id="<?php echo $editTbodyId?>">
<tr><td align="left" colspan="2">
<div class="nexus-bbcode-editor">
<div class="nexus-bbcode-toolbar" role="toolbar" aria-label="BBCode editor toolbar">
<div class="nexus-editor-group nexus-editor-group-block">
<button class="nexus-editor-btn nexus-editor-btn-text" type="button" title="Paragraph">正文<span class="nexus-editor-caret">▾</span></button>
<button class="nexus-editor-btn nexus-editor-btn-quote-mark" type="button" name="quote" value="QUOTE" title="Quote" aria-label="Quote" onclick="javascript: simpletag('quote')">“</button>
</div>
<div class="nexus-editor-group nexus-editor-group-format">
<button class="nexus-editor-btn nexus-editor-btn-strong" type="button" name="b" value="B" title="Bold" aria-label="Bold" onclick="javascript: simpletag('b')">B</button>
<button class="nexus-editor-btn nexus-editor-btn-underline" type="button" name="u" value="U" title="Underline" aria-label="Underline" onclick="javascript: simpletag('u')">U</button>
<button class="nexus-editor-btn nexus-editor-btn-italic" type="button" name="i" value="I" title="Italic" aria-label="Italic" onclick="javascript: simpletag('i')">I</button>
<button class="nexus-editor-btn nexus-editor-btn-text" type="button" name="list" value="List" title="List item" aria-label="List item" onclick="tag_list('<?php echo addslashes($lang_functions['js_prompt_enter_item']) ?>','<?php echo $lang_functions['js_prompt_error'] ?>')">…<span class="nexus-editor-caret">▾</span></button>
</div>
<div class="nexus-editor-group nexus-editor-group-style">
<label class="nexus-editor-select-wrap nexus-editor-select-wrap-color">
<span class="nexus-editor-select-icon nexus-editor-select-icon-color" aria-hidden="true">A</span>
<select class="med codebuttons nexus-editor-select" name='color' aria-label="Text color" onchange="alterfont(this.options[this.selectedIndex].value, 'color')">
<option value='0'>A</option>
<option style="background-color: black" value="Black">Black</option>
<option style="background-color: sienna" value="Sienna">Sienna</option>
<option style="background-color: darkolivegreen" value="DarkOliveGreen">Dark Olive Green</option>
<option style="background-color: darkgreen" value="DarkGreen">Dark Green</option>
<option style="background-color: darkslateblue" value="DarkSlateBlue">Dark Slate Blue</option>
<option style="background-color: navy" value="Navy">Navy</option>
<option style="background-color: indigo" value="Indigo">Indigo</option>
<option style="background-color: darkslategray" value="DarkSlateGray">Dark Slate Gray</option>
<option style="background-color: darkred" value="DarkRed">Dark Red</option>
<option style="background-color: darkorange" value="DarkOrange">Dark Orange</option>
<option style="background-color: olive" value="Olive">Olive</option>
<option style="background-color: green" value="Green">Green</option>
<option style="background-color: teal" value="Teal">Teal</option>
<option style="background-color: blue" value="Blue">Blue</option>
<option style="background-color: slategray" value="SlateGray">Slate Gray</option>
<option style="background-color: dimgray" value="DimGray">Dim Gray</option>
<option style="background-color: red" value="Red">Red</option>
<option style="background-color: sandybrown" value="SandyBrown">Sandy Brown</option>
<option style="background-color: yellowgreen" value="YellowGreen">Yellow Green</option>
<option style="background-color: seagreen" value="SeaGreen">Sea Green</option>
<option style="background-color: mediumturquoise" value="MediumTurquoise">Medium Turquoise</option>
<option style="background-color: royalblue" value="RoyalBlue">Royal Blue</option>
<option style="background-color: purple" value="Purple">Purple</option>
<option style="background-color: gray" value="Gray">Gray</option>
<option style="background-color: magenta" value="Magenta">Magenta</option>
<option style="background-color: orange" value="Orange">Orange</option>
<option style="background-color: yellow" value="Yellow">Yellow</option>
<option style="background-color: lime" value="Lime">Lime</option>
<option style="background-color: cyan" value="Cyan">Cyan</option>
<option style="background-color: deepskyblue" value="DeepSkyBlue">Deep Sky Blue</option>
<option style="background-color: darkorchid" value="DarkOrchid">Dark Orchid</option>
<option style="background-color: silver" value="Silver">Silver</option>
<option style="background-color: pink" value="Pink">Pink</option>
<option style="background-color: wheat" value="Wheat">Wheat</option>
<option style="background-color: lemonchiffon" value="LemonChiffon">Lemon Chiffon</option>
<option style="background-color: palegreen" value="PaleGreen">Pale Green</option>
<option style="background-color: paleturquoise" value="PaleTurquoise">Pale Turquoise</option>
<option style="background-color: lightblue" value="LightBlue">Light Blue</option>
<option style="background-color: plum" value="Plum">Plum</option>
<option style="background-color: white" value="White">White</option>
</select>
</label>
<button class="nexus-editor-btn nexus-editor-btn-text" type="button" title="Highlight">A<span class="nexus-editor-caret">▾</span></button>
</div>
<div class="nexus-editor-group nexus-editor-group-selects">
<label class="nexus-editor-select-wrap nexus-editor-select-wrap-size">
<select class="med codebuttons nexus-editor-select nexus-editor-select-small" name='size' aria-label="Font size" onchange="alterfont(this.options[this.selectedIndex].value, 'size')">
<option value="0">默认字号</option>
<option value="1">1</option>
<option value="2">2</option>
<option value="3">3</option>
<option value="4">4</option>
<option value="5">5</option>
<option value="6">6</option>
<option value="7">7</option>
</select>
</label>
<label class="nexus-editor-select-wrap nexus-editor-select-wrap-font">
<select class="med codebuttons nexus-editor-select" name='font' aria-label="Font" onchange="alterfont(this.options[this.selectedIndex].value, 'font')">
<option value="0">默认字体</option>
<option value="Arial">Arial</option>
<option value="Arial Black">Arial Black</option>
<option value="Arial Narrow">Arial Narrow</option>
<option value="Book Antiqua">Book Antiqua</option>
<option value="Century Gothic">Century Gothic</option>
<option value="Comic Sans MS">Comic Sans MS</option>
<option value="Courier New">Courier New</option>
<option value="Fixedsys">Fixedsys</option>
<option value="Garamond">Garamond</option>
<option value="Georgia">Georgia</option>
<option value="Impact">Impact</option>
<option value="Lucida Console">Lucida Console</option>
<option value="Lucida Sans Unicode">Lucida Sans Unicode</option>
<option value="Microsoft Sans Serif">Microsoft Sans Serif</option>
<option value="Palatino Linotype">Palatino Linotype</option>
<option value="System">System</option>
<option value="Tahoma">Tahoma</option>
<option value="Times New Roman">Times New Roman</option>
<option value="Trebuchet MS">Trebuchet MS</option>
<option value="Verdana">Verdana</option>
</select>
</label>
<button class="nexus-editor-btn nexus-editor-btn-text nexus-editor-btn-lineheight" type="button" title="Line height">默认行高<span class="nexus-editor-caret">▾</span></button>
</div>
<div class="nexus-editor-group nexus-editor-group-list">
<button class="nexus-editor-btn" type="button" title="Bulleted list" onclick="javascript: doInsert('[*]', '', false)">☷</button>
<button class="nexus-editor-btn" type="button" title="Numbered list" onclick="javascript: doInsert('[list=1]\n[*]\n[/list]', '', false)">☰</button>
<button class="nexus-editor-btn" type="button" title="Task item" onclick="javascript: doInsert('[*] [ ] ', '', false)">☑</button>
<button class="nexus-editor-btn" type="button" title="Align">≡<span class="nexus-editor-caret">▾</span></button>
<button class="nexus-editor-btn" type="button" title="Indent">▸<span class="nexus-editor-caret">▾</span></button>
</div>
<div class="nexus-editor-group nexus-editor-group-insert">
<?php
print("<button class=\"nexus-editor-btn\" type=\"button\" title=\"Smilies\" onclick=\"javascript:winop();\">☺<span class=\"nexus-editor-caret\">▾</span></button>");
print("<button class=\"nexus-editor-btn\" type=\"button\" name='url' value='URL' title=\"Link\" aria-label=\"Link\" onclick=\"javascript:tag_url('" . $lang_functions['js_prompt_enter_url'] . "','" . $lang_functions['js_prompt_enter_title'] . "','" . $lang_functions['js_prompt_error'] . "')\">🔗</button>");
if ($enableattach_attachment == 'yes') {
	print("<button class=\"nexus-editor-btn\" type=\"button\" name=\"IMG\" value=\"IMG\" title=\"Image\" aria-label=\"Image\" onclick=\"javascript:nexusChooseImageSource(this, '" . $lang_functions['js_prompt_enter_image_url'] . "','" . $lang_functions['js_prompt_error'] . "')\">▣<span class=\"nexus-editor-caret\">▾</span></button>");
	print("<button class=\"nexus-editor-btn\" type=\"button\" title=\"Upload attachment\" aria-label=\"Upload attachment\" onclick=\"javascript:nexusOpenAttachmentUpload(this)\">📎</button>");
} else {
	print("<button class=\"nexus-editor-btn\" type=\"button\" name=\"IMG\" value=\"IMG\" title=\"Image\" aria-label=\"Image\" onclick=\"javascript:tag_image('" . $lang_functions['js_prompt_enter_image_url'] . "','" . $lang_functions['js_prompt_error'] . "')\">▣<span class=\"nexus-editor-caret\">▾</span></button>");
}

?>
<button class="nexus-editor-btn" type="button" title="Video" onclick="javascript: doInsert('[video]', '[/video]', true)">▶<span class="nexus-editor-caret">▾</span></button>
<button class="nexus-editor-btn" type="button" title="Table">▦<span class="nexus-editor-caret">▾</span></button>
<button class="nexus-editor-btn" type="button" title="Code" onclick="javascript: doInsert('[code]', '[/code]', true)">‹/›</button>
<button class="nexus-editor-btn" type="button" onclick='javascript:closeall();' name='tagcount' value="Close all tags" title="Close all tags">☰</button>
</div>
<div class="nexus-editor-group nexus-editor-group-actions">
<button class="nexus-editor-btn" type="button" title="Undo" onclick="javascript: document.getElementById('<?php echo $text ?>').focus(); document.execCommand('undo')">↶</button>
<button class="nexus-editor-btn" type="button" title="Redo" onclick="javascript: document.getElementById('<?php echo $text ?>').focus(); document.execCommand('redo')">↷</button>
<button class="nexus-editor-btn" type="button" title="Fullscreen" onclick="javascript: this.closest('.nexus-bbcode-editor').classList.toggle('is-fullscreen')">⛶</button>
</div>
</div>
<?php
if ($enableattach_attachment == 'yes'){
?>
<div class="nexus-bbcode-attachment">
<iframe src="<?php echo getSchemeAndHttpHost()?>/attachment.php" width="100%" height="24" frameborder="0" scrolling="no" marginheight="0" marginwidth="0"></iframe>
</div>
<?php
}
print("<div class=\"nexus-bbcode-body\">");
print("<textarea class=\"bbcode nexus-bbcode-textarea\" cols=\"100\" name=\"".$text."\" id=\"".$text."\" rows=\"20\" placeholder=\"Type here...\" spellcheck=\"false\" onkeydown=\"ctrlenter(event,'compose','qr')\">".$content."</textarea>");
?>
<div class="nexus-bbcode-smilies">
<div class="nexus-bbcode-smilies-grid">
<?php
$i = 0;
$quickSmilies = array(1, 2, 3, 5, 6, 7, 8, 9, 10, 11, 13, 16, 17, 19, 20, 21, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 39, 40, 41);
foreach ($quickSmilies as $smily) {
	print("<span class=\"nexus-bbcode-smily\">".getSmileIt($form, $text, $smily)."</span>");
	$i++;
}
?>
</div>
<a class="nexus-bbcode-more-smilies" href="javascript:winop();"><?php echo $lang_functions['text_more_smilies'] ?></a>
</div>
</div>
</div>
</td></tr></tbody>
    <?php if($withPreview) {?>
    <tbody id="<?php echo $previewTbodyId?>"></tbody>
    <tbody>
        <tr><td colspan="2" style="text-align: center;border: none">
            <input id="<?php echo $btnPreviewId ?>" type="button" class="btn" value="<?php echo $lang_functions['submit_preview']?>" onclick="javascript:textBBCodePreview()">
            <input id="<?php echo $btnEditId ?>" type="button" class="btn" style="display: none" value="<?php echo $lang_functions['submit_edit']?>" onclick="javascript:textBBCodeEdit()">
        </td></tr>
    </tbody>
    <?php }?>
</table>
<?php
}

function begin_compose($title = "",$type="new", $body="", $hassubject=true, $subject="", $maxsubjectlength=100, $anonymous = false){
	global $lang_functions;
	if ($title)
		print("<h1 align=\"center\">".$title."</h1>");
	switch ($type){
		case 'new':
		{
			$framename = $lang_functions['text_new'];
			break;
		}
		case 'reply':
		{
			$framename = $lang_functions['text_reply'];
			break;
		}
		case 'quote':
		{
			$framename = $lang_functions['text_quote'];
			break;
		}
		case 'edit':
		{
			$framename = $lang_functions['text_edit'];
			break;
		}
		default:
		{
			$framename = $lang_functions['text_new'];
			break;
		}
	}
	begin_frame($framename, true);
	print("<table class=\"main\" width=\"100%\" border=\"1\" cellspacing=\"0\" cellpadding=\"5\">\n");
	if ($hassubject)
		print("<tr><td class=\"rowhead\">".$lang_functions['row_subject']."</td>" .
"<td class=\"rowfollow\" align=\"left\"><input type=\"text\" style=\"display: block; width: 100%; box-sizing: border-box;\" name=\"subject\" maxlength=\"".$maxsubjectlength."\" value=\"".htmlspecialchars($subject)."\" /></td></tr>\n");
	$anonymousChecked = $anonymous ? " checked=\"checked\"" : "";
	print("<tr><td class=\"rowhead\">匿名</td><td class=\"rowfollow\" align=\"left\"><label class=\"forum-anonymous-option\"><input type=\"checkbox\" name=\"anonymous\" value=\"yes\"".$anonymousChecked." /> 匿名发表</label></td></tr>\n");
	print("<tr><td class=\"rowhead\" valign=\"top\">".$lang_functions['row_body']."</td><td class=\"rowfollow\" align=\"left\"><span style=\"display: none;\" id=\"previewouter\"></span><div id=\"editorouter\">");
	textbbcode("compose","body", $body, false);
	print("</div></td></tr>");
}

function end_compose(){
	global $lang_functions;
	print("<tr><td colspan=\"2\" align=\"center\"><table><tr><td class=\"embedded\"><input id=\"qr\" type=\"submit\" class=\"btn\" value=\"".$lang_functions['submit_submit']."\" /></td><td class=\"embedded\">");
	print("<input type=\"button\" class=\"btn2\" name=\"previewbutton\" id=\"previewbutton\" value=\"".$lang_functions['submit_preview']."\" onclick=\"javascript:preview(this.parentNode);\" />");
	print("<input type=\"button\" class=\"btn2\" style=\"display: none;\" name=\"unpreviewbutton\" id=\"unpreviewbutton\" value=\"".$lang_functions['submit_edit']."\" onclick=\"javascript:unpreview(this.parentNode);\" />");
	print("</td></tr></table>");
	print("</td></tr>");
	print("</table>\n");
	end_frame();
	print("<p align=\"center\"><a href=\"tags.php\" target=\"_blank\">".$lang_functions['text_tags']."</a> | <a href=\"smilies.php\" target=\"_blank\">".$lang_functions['text_smilies']."</a></p>\n");
}

function insert_suggest($keyword, $userid, $pre_escaped = true)
{
	if(mb_strlen($keyword,"UTF-8") >= 2)
	{
		$userid = intval($userid ?? 0);
		if($userid)
		sql_query("INSERT INTO suggest(keywords, userid, adddate) VALUES (" . ($pre_escaped == true ? "'" . $keyword . "'" : sqlesc($keyword)) . "," . sqlesc($userid) . ", NOW())") or sqlerr(__FILE__,__LINE__);
	}
}

function get_external_tr($imdb_url = "")
{
	global $lang_functions;
	global $showextinfo;
	if ($showextinfo['imdb'] != 'yes') {
	    return '';
    }
	$imdbNumber = parse_imdb_id($imdb_url);
	$imdbValue = $imdbNumber ? build_imdb_url($imdbNumber) : "";
	$imdbInput = "<input type=\"text\" style=\"width: 99%;\" name=\"url\" value=\"" . htmlspecialchars($imdbValue) . "\" /><br /><font class=\"medium\">" . $lang_functions['text_imdb_url_note'] . "</font>";
	$doubanInput = "<input type=\"text\" style=\"width: 99%;\" name=\"douban_url\" value=\"\" /><br /><font class=\"medium\">豆瓣条目链接，如 https://movie.douban.com/subject/1292052/</font>";
    return tr($lang_functions['row_imdb_url'], $imdbInput, 1) . tr("Douban URL", $doubanInput, 1);

//	($showextinfo['imdb'] == 'yes' ? tr($lang_functions['row_imdb_url'],  "<input type=\"text\" style=\"width: 99%;\" name=\"url\" value=\"".($imdbNumber ? "https://www.imdb.com/title/tt".parse_imdb_id($imdb_url) : "")."\" /><br /><font class=\"medium\">".$lang_functions['text_imdb_url_note']."</font>", 1) : "");
}

function get_torrent_extinfo_identifier($torrentid)
{
	$torrentid = intval($torrentid ?? 0);

	$result = array('imdb_id');
	unset($result);

	if($torrentid)
	{
		$res = sql_query("SELECT url FROM torrents WHERE id=" . $torrentid) or sqlerr(__FILE__,__LINE__);
		if(mysql_num_rows($res) == 1)
		{
			$arr = mysql_fetch_array($res) or sqlerr(__FILE__,__LINE__);

			$imdb_id = parse_imdb_id($arr["url"]);
			$result['imdb_id'] = $imdb_id;
		}
	}
	return $result;
}

function parse_imdb_id($url)
{
    if ($url && is_numeric($url) && strlen($url) < 7) {
        $url = str_pad($url, 7, '0', STR_PAD_LEFT);
    }
	if ($url != "" && preg_match("/[0-9]+/i", $url, $matches)) {
		return intval($matches[0]);
	}
	return null;
}

function build_imdb_url($imdb_id)
{
	$imdb_id = parse_imdb_id($imdb_id);
	return $imdb_id ? "https://www.imdb.com/title/tt" . str_pad((string)$imdb_id, 7, '0', STR_PAD_LEFT) . "/" : "";
}

// it's a stub implemetation here, we need more acurate regression analysis to complete our algorithm
function get_torrent_2_user_value($user_snatched_arr)
{
	// check if it's current user's torrent
	$torrent_2_user_value = 1.0;

	$torrent_res = sql_query("SELECT * FROM torrents WHERE id = " . $user_snatched_arr['torrentid']) or sqlerr(__FILE__, __LINE__);
	if(mysql_num_rows($torrent_res) == 1)	// torrent still exists
	{
		$torrent_arr = mysql_fetch_array($torrent_res) or sqlerr(__FILE__, __LINE__);
		if($torrent_arr['owner'] == $user_snatched_arr['userid'])	// owner's torrent
		{
			$torrent_2_user_value *= 0.7;	// owner's torrent
			$torrent_2_user_value += ($user_snatched_arr['uploaded'] / $torrent_arr['size'] ) -1 > 0 ? 0.2 - exp(-(($user_snatched_arr['uploaded'] / $torrent_arr['size'] ) -1)) : ($user_snatched_arr['uploaded'] / $torrent_arr['size'] ) -1;
			$torrent_2_user_value += min(0.1 , ($user_snatched_arr['seedtime'] / 37*60*60 ) * 0.1);
		}
		else
		{
			if($user_snatched_arr['finished'] == 'yes')
			{
				$torrent_2_user_value *= 0.5;
				$torrent_2_user_value += ($user_snatched_arr['uploaded'] / $torrent_arr['size'] ) -1 > 0 ? 0.4 - exp(-(($user_snatched_arr['uploaded'] / $torrent_arr['size'] ) -1)) : ($user_snatched_arr['uploaded'] / $torrent_arr['size'] ) -1;
				$torrent_2_user_value += min(0.1, ($user_snatched_arr['seedtime'] / 22*60*60 ) * 0.1);
			}
			else
			{
				$torrent_2_user_value *= 0.2;
				$torrent_2_user_value += min(0.05, ($user_snatched_arr['leechtime'] / 24*60*60 ) * 0.1);	// usually leechtime could not explain much
			}
		}
	}
	else	// torrent already deleted, half blind guess, be conservative
	{

		if($user_snatched_arr['finished'] == 'no' && $user_snatched_arr['uploaded'] > 0 && $user_snatched_arr['downloaded'] == 0)	// possibly owner
		{
			$torrent_2_user_value *= 0.55;	//conservative
			$torrent_2_user_value += min(0.05, ($user_snatched_arr['leechtime'] / 31*60*60 ) * 0.1);
			$torrent_2_user_value += min(0.1, ($user_snatched_arr['seedtime'] / 31*60*60 ) * 0.1);
		}
		else if($user_snatched_arr['downloaded'] > 0)	// possibly leecher
		{
			$torrent_2_user_value *= 0.38;	//conservative
			$torrent_2_user_value *= min(0.22, 0.1 * $user_snatched_arr['uploaded'] / $user_snatched_arr['downloaded']);	// 0.3 for conservative
			$torrent_2_user_value += min(0.05, ($user_snatched_arr['leechtime'] / 22*60*60 ) * 0.1);
			$torrent_2_user_value += min(0.12, ($user_snatched_arr['seedtime'] / 22*60*60 ) * 0.1);
		}
		else
			$torrent_2_user_value *= 0.0;
	}
	return $torrent_2_user_value;
}

function cur_user_check () {
	global $lang_functions;
	global $CURUSER;
	if ($CURUSER)
	{
		sql_query("UPDATE users SET lang=" . get_langid_from_langcookie() . " WHERE id = ". $CURUSER['id']);
		stderr ($lang_functions['std_permission_denied'], $lang_functions['std_already_logged_in']);
	}
}

function KPS($type = "+", $point = "1.0", $id = "") {
	global $bonus_tweak;
	if ($point != 0){
		$point = sqlesc($point);
		if ($bonus_tweak == "enable" || $bonus_tweak == "disablesave"){
			sql_query("UPDATE users SET seedbonus = seedbonus$type$point WHERE id = ".sqlesc($id)) or sqlerr(__FILE__, __LINE__);
		}
	}
	else return;
}

function get_agent($peer_id, $agent)
{
	return substr($agent, 0, (strpos($agent, ";") == false ? strlen($agent) : strpos($agent, ";")));
}

function EmailBanned($newEmail)
{
	$newEmail = trim(strtolower($newEmail));
	$sql = sql_query("SELECT * FROM bannedemails") or sqlerr(__FILE__, __LINE__);
	$list = mysql_fetch_array($sql);
	$addresses = explode(' ', preg_replace("/[[:space:]]+/", " ", trim($list['value'])) );

	if(count($addresses) > 0)
	{
		foreach ( $addresses as $email )
		{
			$email = trim(strtolower(preg_replace('/\./', '\\.', $email)));
			if(strstr($email, "@"))
			{
				if(preg_match('/^@/', $email))
				{// Any user @host?
					// Expand the match expression to catch hosts and
					// sub-domains
					$email = preg_replace('/^@/', '[@\\.]', $email);
					if(preg_match("/".$email."$/", $newEmail))
					return true;
				}
			}
			elseif(preg_match('/@$/', $email))
			{    // User at any host?
				if(preg_match("/^".$email."/", $newEmail))
				return true;
			}
			else
			{                // User@host
				if(strtolower($email) == $newEmail)
				return true;
			}
		}
	}

	return false;
}

function EmailAllowed($newEmail)
{
global $restrictemaildomain;
if ($restrictemaildomain == 'yes'){
	$newEmail = trim(strtolower($newEmail));
	$sql = sql_query("SELECT * FROM allowedemails") or sqlerr(__FILE__, __LINE__);
	$list = mysql_fetch_array($sql);
	$addresses = explode(' ', preg_replace("/[[:space:]]+/", " ", trim($list['value'])) );

	if(count($addresses) > 0)
	{
		foreach ( $addresses as $email )
		{
			$email = trim(strtolower(preg_replace('/\./', '\\.', $email)));
			if(strstr($email, "@"))
			{
				if(preg_match('/^@/', $email))
				{// Any user @host?
					// Expand the match expression to catch hosts and
					// sub-domains
					$email = preg_replace('/^@/', '[@\\.]', $email);
					if(preg_match('/'.$email.'$/', $newEmail))
					return true;
				}
			}
			elseif(preg_match('/@$/', $email))
			{    // User at any host?
				if(preg_match("/^".$email."/", $newEmail))
				return true;
			}
			else
			{                // User@host
				if(strtolower($email) == $newEmail)
				return true;
			}
		}
	}
	return false;
}
else return true;
}

function allowedemails()
{
	$sql = sql_query("SELECT * FROM allowedemails") or sqlerr(__FILE__, __LINE__);
	$list = mysql_fetch_array($sql);
	return $list['value'];
}

function nexus_redirect($url)
{
    if (substr($url, 0, 4) != 'http') {
        $url = getSchemeAndHttpHost() . '/' . trim($url, '/');
    }
	if(!headers_sent()){
	    header("Location: $url", true, 302);
	} else {
        echo "<script type=\"text/javascript\">window.location.href = '$url';</script>";
    }
	exit;
}

function set_cachetimestamp($id, $field = "cache_stamp")
{
	sql_query("UPDATE torrents SET $field = " . time() . " WHERE id = " . sqlesc($id)) or sqlerr(__FILE__, __LINE__);
}
function reset_cachetimestamp($id, $field = "cache_stamp")
{
	sql_query("UPDATE torrents SET $field = 0 WHERE id = " . sqlesc($id)) or sqlerr(__FILE__, __LINE__);
}

function cache_check ($file = 'cachefile',$endpage = true, $cachetime = 600) {
	global $lang_functions;
	global $rootpath,$cache,$CURLANGDIR;
	$cachefile = $rootpath.$cache ."/" . $CURLANGDIR .'/'.$file.'.html';
	// Serve from the cache if it is younger than $cachetime
	if (file_exists($cachefile) && (time() - $cachetime < filemtime($cachefile)))
	{
		include($cachefile);
		if ($endpage)
		{
			print("<p align=\"center\"><font class=\"small\">".$lang_functions['text_page_last_updated'].date('Y-m-d H:i:s', filemtime($cachefile))."</font></p>");
			end_main_frame();
			stdfoot();
			exit;
		}
		return false;
	}
  	ob_start();
	return true;
}

function cache_save  ($file = 'cachefile') {
	global $rootpath,$cache;
	global $CURLANGDIR;
	$cachefile = $rootpath.$cache ."/" . $CURLANGDIR . '/'.$file.'.html';
	$fp = fopen($cachefile, 'w');
	// save the contents of output buffer to the file
	fwrite($fp, ob_get_contents());
	// close the file
	fclose($fp);
	// Send the output to the browser
	ob_end_flush();
}

function get_email_encode($lang)
{
	if($lang == 'chs' || $lang == 'cht')
	return "gbk";
	else
	return "utf-8";
}

function change_email_encode($lang, $content)
{
	return iconv("utf-8", get_email_encode($lang) . "//IGNORE", $content);
}

function safe_email($email) {
	$email = str_replace("<","",$email);
	$email = str_replace(">","",$email);
	$email = str_replace("\'","",$email);
	$email = str_replace('\"',"",$email);
	$email = str_replace("\\\\","",$email);

	return $email;
}

function check_email ($email) {
	if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.+\-]*@[A-Za-z0-9][A-Za-z0-9_+\-]*(\.[A-Za-z0-9][A-Za-z0-9_+\-]*)+$/', $email)) {
        return false;
    }
    $bannedEmails = \Nexus\Database\NexusDB::select('select * from bannedemails');
    $bannedEmailsArr = array_filter(preg_split('/[\s]+/', $bannedEmails[0]['value'] ?? ''));
    if (empty($bannedEmailsArr)) {
        return true;
    }
    foreach ($bannedEmailsArr as $ban) {
        if (str_ends_with($email, $ban)) {
            do_log("[BANNED_EMAIL] email: $email is banned by record: $ban");
            return false;
        }
    }
	return true;
}

function sent_mail($to,$fromname,$fromemail,$subject,$body,$type = "confirmation",$showmsg=true,$multiple=false,$multiplemail='',$hdr_encoding = 'UTF-8', $specialcase = '') {
    do_log("to: $to, fromname: $fromname, fromemail: $fromemail, subject: $subject, body: $body. type: $type");
	global $lang_functions;
	global $rootpath,$SITENAME,$SITEEMAIL,$smtptype,$smtp,$smtp_host,$smtp_port,$smtp_from,$smtpaddress,$smtpport,$accountname,$accountpassword;
	# Is the OS Windows or Mac or Linux?
	if (strtoupper(substr(PHP_OS,0,3)=='WIN')) {
		$eol="\r\n";
		$windows = true;
	}
	elseif (strtoupper(substr(PHP_OS,0,3)=='MAC'))
		$eol="\r";
	else
		$eol="\n";
	if ($smtptype == 'none')
		return false;
	if ($smtptype == 'default') {
		@mail($to, "=?".$hdr_encoding."?B?".base64_encode($subject)."?=", $body, "From: ".$SITEEMAIL.$eol."Content-type: text/html; charset=".$hdr_encoding.$eol, "-f$SITEEMAIL") or stderr($lang_functions['std_error'], $lang_functions['text_unable_to_send_mail']);
	}
	elseif ($smtptype == 'advanced') {
		$mid = md5(getip() . $fromname);
		$name = $_SERVER["SERVER_NAME"];
        $headers = '';
		$headers .= "From: $fromname <$fromemail>".$eol;
		$headers .= "Reply-To: $fromname <$fromemail>".$eol;
		$headers .= "Return-Path: $fromname <$fromemail>".$eol;
		$headers .= "Message-ID: <$mid thesystem@$name>".$eol;
		$headers .= "X-Mailer: PHP v".phpversion().$eol;
		$headers .= "MIME-Version: 1.0".$eol;
		$headers .= "Content-type: text/html; charset=".$hdr_encoding.$eol;
		$headers .= "X-Sender: PHP".$eol;
		if ($multiple)
		{
			$bcc_multiplemail = "";
			foreach ($multiplemail as $toemail)
			$bcc_multiplemail = $bcc_multiplemail . ( $bcc_multiplemail != "" ? "," : "") . $toemail;

			$headers .= "Bcc: $multiplemail.$eol";
		}
		if ($smtp == "yes") {
			ini_set('SMTP', $smtp_host);
			ini_set('smtp_port', $smtp_port);
			if ($windows)
			ini_set('sendmail_from', $smtp_from);
		}

		@mail($to,"=?".$hdr_encoding."?B?".base64_encode($subject)."?=",$body,$headers) or stderr($lang_functions['std_error'], $lang_functions['text_unable_to_send_mail']);

		ini_restore('SMTP');
		ini_restore('smtp_port');
		if ($windows)
		ini_restore('sendmail_from');
	}
	elseif ($smtptype == 'external') {
	    /*
		require_once ($rootpath . 'include/smtp/smtp.lib.php');
		$mail = new smtp($hdr_encoding,'eYou');
		$mail->debug(true);
		$mail->open($smtpaddress, $smtpport);
		$mail->auth($accountname, $accountpassword);
		//	$mail->bcc($multiplemail);
		$mail->from($SITEEMAIL);
		if ($multiple)
		{
			$mail->multi_to_head($to);
			foreach ($multiplemail as $toemail)
			$mail->multi_to($toemail);
		}
		else
		$mail->to($to);
		$mail->mime_content_transfer_encoding();
		$mail->mime_charset('text/html', $hdr_encoding);
		$mail->subject($subject);
		$mail->body($body);
		$mail->send() or stderr($lang_functions['std_error'], $lang_functions['text_unable_to_send_mail']);
		$mail->close();
	    */

        /**
         * use Symfony Mailer instead
         *
         * @since 1.7
         * @author xiaomlove<1939737565@qq.com>
         */

        $toolRep = new \App\Repositories\ToolRepository();
        $sendResult = $toolRep->sendMail($to, $subject, $body);
        if ($sendResult === false) {
            stderr($lang_functions['std_error'], $lang_functions['text_unable_to_send_mail']);
        }
	}
	if ($showmsg) {
		if ($type == "confirmation")
		stderr($lang_functions['std_success'], $lang_functions['std_confirmation_email_sent']."<b>". htmlspecialchars($to) ."</b>.\n" .
		$lang_functions['std_please_wait'],false);
		elseif ($type == "details")
		stderr($lang_functions['std_success'], $lang_functions['std_account_details_sent']."<b>". htmlspecialchars($to) ."</b>.\n" .
		$lang_functions['std_please_wait'],false);
	}else
	return true;
}

function failedloginscheck ($type = 'Login') {
	global $lang_functions;
	global $maxloginattempts;
	$total = 0;
	$ip = sqlesc(getip());
	$Query = sql_query("SELECT SUM(attempts) FROM loginattempts WHERE ip=$ip") or sqlerr(__FILE__, __LINE__);
	list($total) = mysql_fetch_array($Query);
	if ($total >= $maxloginattempts) {
		sql_query("UPDATE loginattempts SET banned = 'yes' WHERE ip=$ip") or sqlerr(__FILE__, __LINE__);
		stderr($type.$lang_functions['std_locked'].$maxloginattempts.$lang_functions['std_attempts_reached'], $lang_functions['std_your_ip_banned'], true, true);
	}
}
function failedlogins ($type = 'login', $recover = false, $head = true)
{
	global $lang_functions;
	$ip = sqlesc(getip());
	$added = sqlesc(date("Y-m-d H:i:s"));
	$a = (@mysql_fetch_row(@sql_query("select count(*) from loginattempts where ip=$ip"))) or sqlerr(__FILE__, __LINE__);
	if ($a[0] == 0)
	sql_query("INSERT INTO loginattempts (ip, added, attempts) VALUES ($ip, $added, 1)") or sqlerr(__FILE__, __LINE__);
	else
	sql_query("UPDATE loginattempts SET attempts = attempts + 1 where ip=$ip") or sqlerr(__FILE__, __LINE__);
	if ($recover)
	sql_query("UPDATE loginattempts SET type = 'recover' WHERE ip = $ip") or sqlerr(__FILE__, __LINE__);
	if ($type == 'silent')
	return;
	elseif ($type == 'login')
	{
		stderr($lang_functions['std_login_failed'],$lang_functions['std_login_failed_note'],false);
	}
	else
	stderr($lang_functions['std_failed'],$type,false, $head);

}

function login_failedlogins($type = 'login', $recover = false, $head = true)
{
	global $lang_functions;
	$ip = sqlesc(getip());
	$added = sqlesc(date("Y-m-d H:i:s"));
	$a = (@mysql_fetch_row(@sql_query("select count(*) from loginattempts where ip=$ip"))) or sqlerr(__FILE__, __LINE__);
	if ($a[0] == 0)
	sql_query("INSERT INTO loginattempts (ip, added, attempts) VALUES ($ip, $added, 1)") or sqlerr(__FILE__, __LINE__);
	else
	sql_query("UPDATE loginattempts SET attempts = attempts + 1 where ip=$ip") or sqlerr(__FILE__, __LINE__);
	if ($recover)
	sql_query("UPDATE loginattempts SET type = 'recover' WHERE ip = $ip") or sqlerr(__FILE__, __LINE__);
	if ($type == 'silent')
	return;
	elseif ($type == 'login')
	{
		stderr($lang_functions['std_login_failed'],$lang_functions['std_login_failed_note'],false);
	}
	else
	stderr($lang_functions['std_recover_failed'],$type,false, $head);
}

function remaining ($type = 'login') {
	global $maxloginattempts;
	$total = 0;
	$ip = sqlesc(getip());
	$Query = sql_query("SELECT SUM(attempts) FROM loginattempts WHERE ip=$ip") or sqlerr(__FILE__, __LINE__);
	list($total) = mysql_fetch_array($Query);
	$remaining = $maxloginattempts - $total;
	if ($remaining <= 2 )
	$remaining = "<font color=\"red\" size=\"2\">[".$remaining."]</font>";
	else
	$remaining = "<font color=\"green\" size=\"2\">[".$remaining."]</font>";

	return $remaining;
}

function registration_check($type = "invitesystem", $maxuserscheck = true, $ipcheck = true) {
	global $lang_functions;
	global $invitesystem, $registration, $maxusers, $SITENAME, $maxip;
	if ($type == "invitesystem") {
		if ($invitesystem == "no") {
			stderr($lang_functions['std_oops'], $lang_functions['std_invite_system_disabled'], 0, true);
		}
	}

	if ($type == "normal") {
		if ($registration == "no") {
			stderr($lang_functions['std_sorry'], $lang_functions['std_open_registration_disabled'], 0, true);
		}
	}

	if ($maxuserscheck) {
		$res = sql_query("SELECT COUNT(*) FROM users") or sqlerr(__FILE__, __LINE__);
		$arr = mysql_fetch_row($res);
		if ($arr[0] >= $maxusers)
		stderr($lang_functions['std_sorry'], $lang_functions['std_account_limit_reached'], 0, true);
	}

	if ($ipcheck) {
		$ip = getip () ;
		$a = (@mysql_fetch_row(@sql_query("select count(*) from users where ip='" . mysql_real_escape_string($ip) . "'"))) or sqlerr(__FILE__, __LINE__);
		if ($a[0] > $maxip)
		stderr($lang_functions['std_sorry'], $lang_functions['std_the_ip']."<b>" . htmlspecialchars($ip) ."</b>". sprintf($lang_functions['std_used_many_times'], \App\Models\Setting::getSiteName()),false, true);
	}
	return true;
}

function random_str($length="6")
{
	$set = array("A","B","C","D","E","F","G","H","P","R","M","N","1","2","3","4","5","6","7","8","9");
	$str = '';
	for($i=1;$i<=$length;$i++)
	{
		$ch = rand(0, count($set)-1);
		$str .= $set[$ch];
	}
	return $str;
}
function captcha_manager(): \App\Services\Captcha\CaptchaManager
{
    static $manager;

    if (!$manager) {
        $manager = new \App\Services\Captcha\CaptchaManager();
    }

    return $manager;
}

function image_code () {
    $driver = captcha_manager()->driver('image');

    if (!method_exists($driver, 'issue')) {
        throw new \RuntimeException('Image captcha driver is unavailable.');
    }

    return $driver->issue();
}

function check_code ($imagehash, $imagestring, $where = 'signup.php', $maxattemptlog = false, $head = true) {
    global $lang_functions;
    global $iv;

    if ($iv !== 'yes') {
        return true;
    }

    $manager = captcha_manager();

    if (!$manager->isEnabled()) {
        return true;
    }

    $payload = [
        'imagehash' => $imagehash,
        'imagestring' => $imagestring,
        'request' => array_merge($_POST ?? [], $_GET ?? []),
    ];

    $context = [
        'where' => $where,
        'maxattemptlog' => $maxattemptlog,
        'head' => $head,
        'ip' => getip(),
    ];

    try {
        if ($manager->verify($payload, $context)) {
            return true;
        }
    } catch (\App\Services\Captcha\Exceptions\CaptchaValidationException $exception) {
        $message = $exception->getMessage();

        $defaultMessage = $lang_functions['std_invalid_image_code'] . "<a href=\"" . htmlspecialchars($where) . "\">" . $lang_functions['std_here_to_request_new'];

        if ($message === '' || $message === 'Invalid captcha response.' || $message === 'Missing captcha parameters.') {
            $message = $defaultMessage;
        }

        if (!$maxattemptlog) {
            stderr('Error', $message, false);
        } else {
            failedlogins($message, true, $head);
        }
    }

    return false;
}

function show_image_code () {
    global $lang_functions;
    global $iv;

    if ($iv !== 'yes') {
        return;
    }

    $manager = captcha_manager();
    $driver = $manager->driver();

    if (!$driver->isEnabled()) {
        return;
    }

    $labelKey = $driver instanceof \App\Services\Captcha\Drivers\ImageCaptchaDriver
        ? 'row_security_image'
        : 'row_security_challenge';

    $labels = [
        'image' => $lang_functions[$labelKey] ?? $lang_functions['row_security_image'],
        'code' => $lang_functions['row_security_code'],
    ];

    $markup = $driver->render([
        'labels' => $labels,
        'secret' => $_GET['secret'] ?? '',
    ]);

    if ($markup !== '') {
        echo $markup;
    }
}

function get_ip_location($ip)
{
	global $lang_functions;
	global $Cache;

	static $locations;
	if (isset($locations[$ip])) {
	    return $locations[$ip];
    }
    /**
     * @since 1.7.4
     */
	$arr = get_ip_location_from_geoip($ip);
	$result = [];
	if ($arr) {
	    $result[] = $arr['name'];
    } else {
	    $result[] = $lang_functions['text_unknown'];
    }
	$result[] = $lang_functions['text_user_ip'] . ":&nbsp;" . trim($ip, ',');
	return $locations[$ip] = $result;

	$cacheKey = "location_$ip";
	if (!$ret = $Cache->get_value($cacheKey)){
		$ret = array();

//		$res = sql_query("SELECT * FROM locations") or sqlerr(__FILE__, __LINE__);
//		while ($row = mysql_fetch_array($res))
//			$ret[] = $row;

        //get from geoip2
        $row = get_ip_location_from_geoip($ip);
        if ($row) {
            $ret[] = $row;
        }
		$Cache->cache_value($cacheKey, $ret, 152800);
	}
	$location = array($lang_functions['text_unknown'],"");

	foreach($ret AS $arr)
	{
        $location = array($arr["name"], $lang_functions['text_user_ip'] . ":&nbsp;" . $ip);
        break;
//		if(in_ip_range(false, $ip, $arr["start_ip"], $arr["end_ip"]))
//		{
//			$location = array($arr["name"], $lang_functions['text_user_ip'].":&nbsp;" . $ip . ($arr["location_main"] != "" ? "&nbsp;".$lang_functions['text_location_main'].":&nbsp;" . $arr["location_main"] : ""). ($arr["location_sub"] != "" ? "&nbsp;".$lang_functions['text_location_sub'].":&nbsp;" . $arr["location_sub"] : "") . "&nbsp;".$lang_functions['text_ip_range'].":&nbsp;" . $arr["start_ip"] . "&nbsp;~&nbsp;". $arr["end_ip"]);
//			break;
//		}
	}
	return $location;
}

function in_ip_range($long, $targetip, $ip_one, $ip_two=false)
{
	// if only one ip, check if is this ip
	if($ip_two===false){
		if(($long ? (long2ip($ip_one) == $targetip) : ( $ip_one == $targetip))){
			$ip=true;
		}
		else{
			$ip=false;
		}
	}
	else{
		if($long ? ($ip_one<=ip2long($targetip) && $ip_two>=ip2long($targetip)) : (ip2long($ip_one)<=ip2long($targetip) && ip2long($ip_two)>=ip2long($targetip))){
			$ip=true;
		}
		else{
			$ip=false;
		}
	}
	return $ip;
}


function validip_format($ip)
{
	$ipPattern =
	'/\b(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.' .
	'(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.' .
	'(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.' .
	'(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\b/';

	return preg_match($ipPattern, $ip);
}

function maxslots () {
	global $lang_functions;
	global $CURUSER, $maxdlsystem;
	$gigs = $CURUSER["uploaded"] / (1024*1024*1024);
	$ratio = (($CURUSER["downloaded"] > 0) ? ($CURUSER["uploaded"] / $CURUSER["downloaded"]) : 1);
	if ($ratio < 0.5 || $gigs < 5) $max = 1;
	elseif ($ratio < 0.65 || $gigs < 6.5) $max = 2;
	elseif ($ratio < 0.8 || $gigs < 8) $max = 3;
	elseif ($ratio < 0.95 || $gigs < 9.5) $max = 4;
	else $max = 0;
	if ($maxdlsystem == "yes") {
		if (get_user_class() < UC_VIP) {
			if ($max > 0)
			print ("<font class='color_slots'>".$lang_functions['text_slots']."</font><a href=\"faq.php#id215\">$max</a>");
			else
			print ("<font class='color_slots'>".$lang_functions['text_slots']."</font>".$lang_functions['text_unlimited']);
		}else
		print ("<font class='color_slots'>".$lang_functions['text_slots']."</font>".$lang_functions['text_unlimited']);
	}else
	print ("<font class='color_slots'>".$lang_functions['text_slots']."</font>".$lang_functions['text_unlimited']);
}

function WriteConfig ($configname = NULL, $config = NULL) {
	global $lang_functions, $CONFIGURATIONS;

	if (file_exists('config/allconfig.php')) {
		require('config/allconfig.php');
	}
	if ($configname) {
		$$configname=$config;
	}
	$path = './config/allconfig.php';
	if (!file_exists($path) || !is_writable ($path)) {
		stdmsg($lang_functions['std_error'], $lang_functions['std_cannot_read_file']."[<b>".htmlspecialchars($path)."</b>]".$lang_functions['std_access_permission_note']);
	}
	$data = "<?php\n";
	foreach ($CONFIGURATIONS as $CONFIGURATION) {
		$data .= "\$$CONFIGURATION=".getExportedValue($$CONFIGURATION).";\n";
	}
	$fp = @fopen ($path, 'w');
	if (!$fp) {
		stdmsg($lang_functions['std_error'], $lang_functions['std_cannot_open_file']."[<b>".htmlspecialchars($path)."</b>]".$lang_functions['std_to_save_info'].$lang_functions['std_access_permission_note']);
	}
	$Res = @fwrite($fp, $data);
	if (empty($Res)) {
		stdmsg($lang_functions['std_error'], $lang_functions['text_cannot_save_info_in']."[<b>".htmlspecialchars($path)."</b>]".$lang_functions['std_access_permission_note']);
	}
	fclose($fp);
	return true;
}

function getExportedValue($input,$t = null) {
	switch (gettype($input)) {
		case 'string':
			return "'".str_replace(array("\\","'"),array("\\\\","\'"),$input)."'";
		case 'array':
			$output = "array(\r";
			foreach ($input as $key => $value) {
				$output .= $t."\t".getExportedValue($key,$t."\t").' => '.getExportedValue($value,$t."\t");
				$output .= ",\n";
			}
			$output .= $t.')';
			return $output;
		case 'boolean':
			return $input ? 'true' : 'false';
		case 'NULL':
			return 'NULL';
		case 'integer':
		case 'double':
		case 'float':
			return "'".(string)$input."'";
	 }
	 return 'NULL';
}

function dbconn($autoclean = false, $doLogin = true)
{
    global $useCronTriggerCleanUp;
    \Nexus\Database\NexusDB::getInstance()->autoConnect();
	if ($doLogin) {
        userlogin();
    }
	if (!$useCronTriggerCleanUp && $autoclean) {
		register_shutdown_function("autoclean");
	}
}

function userlogin() {
    static $loginResult;
    if (!is_null($loginResult)) {
        return $loginResult;
    }
	global $lang_functions;
	global $Cache;
	global $SITE_ONLINE, $oldip;
	global $enablesqldebug_tweak, $sqldebug_tweak;
	unset($GLOBALS["CURUSER"]);

	$ip = getip();
	$nip = ip2long($ip);
	if ($nip) //$nip would be false for IPv6 address
	{
		$res = sql_query("SELECT * FROM bans WHERE first <= $nip AND last >= $nip") or sqlerr(__FILE__, __LINE__);
        if (mysql_num_rows($res) > 0)
		{
			header("HTTP/1.1 403 Forbidden");
			print("<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\"></head><body>".$lang_functions['text_unauthorized_ip']."</body></html>\n");
			die;
		}
	}

	$row = get_user_from_cookie($_COOKIE);
    if (empty($row)) {
        return $loginResult = false;
    }
	if (!$row["passkey"]){
		$passkey = md5($row['username'].date("Y-m-d H:i:s").$row['passhash']);
		sql_query("UPDATE users SET passkey = ".sqlesc($passkey)." WHERE id=" . sqlesc($row["id"]));
	}

	$oldip = $row['ip'];
	$row['ip'] = $ip;
    $row['seedbonus'] = floatval($row['seedbonus']);
	$GLOBALS["CURUSER"] = $row;
	if (isset($_GET['clearcache']) && $_GET['clearcache'] && get_user_class() >= UC_MODERATOR) {
	    $Cache->setClearCache(1);
	}
    /**
     * no need any more, already set in core.php
     * @since v1.6
     */
//	if ($enablesqldebug_tweak == 'yes' && get_user_class() >= $sqldebug_tweak) {
//		error_reporting(E_ALL & ~E_NOTICE);
//		error_reporting(-1);
//	}
    return $loginResult = true;
}

function autoclean($printProgress = false) {
	global $autoclean_interval_one, $rootpath;
	$now = TIMENOW;
	$res = sql_query("SELECT value_u FROM avps WHERE arg = 'lastcleantime'");
	$row = mysql_fetch_array($res);
	if (!$row) {
	    do_log("SELECT value_u FROM avps WHERE arg = 'lastcleantime', empty");
		sql_query("INSERT INTO avps (arg, value_u) VALUES ('lastcleantime',$now)") or sqlerr(__FILE__, __LINE__);
		return false;
	}
	$ts = $row[0];
	if ($ts + $autoclean_interval_one > $now) {
	    do_log("ts: {$ts} + autoclean_interval_one: $autoclean_interval_one > now: $now");
		return false;
	}
	sql_query("UPDATE avps SET value_u=$now WHERE arg='lastcleantime' AND value_u = $ts") or sqlerr(__FILE__, __LINE__);
	if (!mysql_affected_rows()) {
	    do_log("UPDATE avps SET value_u=$now WHERE arg='lastcleantime' AND value_u = $ts, affectedRows = 0");
		return false;
	}
	require_once($rootpath . 'include/cleanup.php');
	return docleanup(0, $printProgress);
}

function unesc($x) {
	return $x;
}


function getsize_int($amount, $unit = "G")
{
	if ($unit == "B")
	return floor($amount);
	elseif ($unit == "K")
	return floor($amount * 1024);
	elseif ($unit == "M")
	return floor($amount * 1048576);
	elseif ($unit == "G")
	return floor($amount * 1073741824);
	elseif($unit == "T")
	return floor($amount * 1099511627776);
	elseif($unit == "P")
	return floor($amount * 1125899906842624);
}

function mksize_compact($bytes)
{
	if ($bytes < 1000 * 1024)
	return number_format($bytes / 1024, 2) . "<br />KB";
	elseif ($bytes < 1000 * 1048576)
	return number_format($bytes / 1048576, 2) . "<br />MB";
	elseif ($bytes < 1000 * 1073741824)
	return number_format($bytes / 1073741824, 2) . "<br />GB";
	elseif ($bytes < 1000 * 1099511627776)
	return number_format($bytes / 1099511627776, 3) . "<br />TB";
	else
	return number_format($bytes / 1125899906842624, 3) . "<br />PB";
}

function mksize_loose($bytes)
{
	if ($bytes < 1000 * 1024)
	return number_format($bytes / 1024, 2) . "&nbsp;KB";
	elseif ($bytes < 1000 * 1048576)
	return number_format($bytes / 1048576, 2) . "&nbsp;MB";
	elseif ($bytes < 1000 * 1073741824)
	return number_format($bytes / 1073741824, 2) . "&nbsp;GB";
	elseif ($bytes < 1000 * 1099511627776)
	return number_format($bytes / 1099511627776, 3) . "&nbsp;TB";
	else
	return number_format($bytes / 1125899906842624, 3) . "&nbsp;PB";
}

function mksize($bytes)
{
	if ($bytes < 1000 * 1024)
	return number_format($bytes / 1024, 2) . " KB";
	elseif ($bytes < 1000 * 1048576)
	return number_format($bytes / 1048576, 2) . " MB";
	elseif ($bytes < 1000 * 1073741824)
	return number_format($bytes / 1073741824, 2) . " GB";
	elseif ($bytes < 1000 * 1099511627776)
	return number_format($bytes / 1099511627776, 3) . " TB";
	else
	return number_format($bytes / 1125899906842624, 3) . " PB";
}


function mksizeint($bytes)
{
	$bytes = max(0, $bytes);
	if ($bytes < 1000)
	return floor($bytes) . " B";
	elseif ($bytes < 1000 * 1024)
	return floor($bytes / 1024) . " kB";
	elseif ($bytes < 1000 * 1048576)
	return floor($bytes / 1048576) . " MB";
	elseif ($bytes < 1000 * 1073741824)
	return floor($bytes / 1073741824) . " GB";
	elseif ($bytes < 1000 * 1099511627776)
	return floor($bytes / 1099511627776) . " TB";
	else
	return floor($bytes / 1125899906842624) . " PB";
}

function deadtime() {
    $anninterthree = (int)get_setting("main.anninterthree");
	return time() - floor($anninterthree * 1.3);
}

function mkprettytime($s) {
	global $lang_functions;
	if ($s < 0)
	$s = 0;
	$t = array();
    $s = round($s);
	foreach (array("60:sec","60:min","24:hour","0:day") as $x) {
		$y = explode(":", $x);
		if ($y[0] > 1) {
			$v = $s % $y[0];
			$s = floor($s / $y[0]);
		}
		else
		$v = $s;
		$t[$y[1]] = $v;
	}

	if ($t["day"])
	return $t["day"] . ($lang_functions['text_day'] ?? 'day(s)') . sprintf("%02d:%02d:%02d", $t["hour"], $t["min"], $t["sec"]);
	if ($t["hour"])
	return sprintf("%d:%02d:%02d", $t["hour"], $t["min"], $t["sec"]);
	//    if ($t["min"])
	return sprintf("%d:%02d", $t["min"], $t["sec"]);
	//    return $t["sec"] . " secs";
}

function mkglobal($vars) {
	if (!is_array($vars))
	$vars = explode(":", $vars);
	foreach ($vars as $v) {
		if (isset($_GET[$v]))
		$GLOBALS[$v] = unesc($_GET[$v]);
		elseif (isset($_POST[$v]))
		$GLOBALS[$v] = unesc($_POST[$v]);
		else
		return 0;
	}
	return 1;
}

function tr($x,$y,$noesc=0,$relation='', $return = false) {
	if ($noesc)
	$a = $y;
	else {
		$a = htmlspecialchars($y);
		$a = str_replace("\n", "<br />\n", $a);
	}
//	$result = ("<tr".( $relation ? " relation = \"$relation\"" : "")."><td class=\"rowhead nowrap\" valign=\"top\" align=\"right\">$x</td><td class=\"rowfollow\" valign=\"top\" align=\"left\">".$a."</td></tr>\n");
	$result = sprintf(
	        '<tr%s><td class="rowhead nowrap" valign="top" align="right">%s</td><td class="rowfollow" valign="top" align="left">%s</td></tr>',
            $relation ? sprintf(' relation="%s" class="%s"', $relation, $relation) : '',
            $x, $a
    );
	if ($return) {
	    return $result;
    }
	print $result;
}

function tr_small($x,$y,$noesc=0,$relation='',$return = false) {
	if ($noesc)
	$a = $y;
	else {
		$a = htmlspecialchars($y);
		//$a = str_replace("\n", "<br />\n", $a);
	}
	$result = "<tr".( $relation ? " relation = \"$relation\"" : "")."><td width=\"1%\" class=\"rowhead nowrap\" valign=\"top\" align=\"right\">".$x."</td><td width=\"99%\" class=\"rowfollow\" valign=\"top\" align=\"left\">".$a."</td></tr>";
	if ($return) {
	    return $result;
    }
	print($result);
}

function twotd($x,$y,$nosec=0){
	if ($nosec)
	$a = $y;
	else {
		$a = htmlspecialchars($y);
		$a = str_replace("\n", "<br />\n", $a);
	}
	print("<td class=\"rowhead\">".$x."</td><td class=\"rowfollow\">".$y."</td>");
}

function validfilename($name) {
	return preg_match('/^[^\0-\x1f:\\\\\/?*\xff#<>|]+$/si', $name);
}

function validemail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validlang($langid) {
	global $deflang;
	$langid = intval($langid ?? 0);
	$res = sql_query("SELECT * FROM language WHERE site_lang = 1 AND id = " . sqlesc($langid)) or sqlerr(__FILE__, __LINE__);
	if(mysql_num_rows($res) == 1)
	{
		$arr = mysql_fetch_array($res)  or sqlerr(__FILE__, __LINE__);
		return $arr['site_lang_folder'];
	}
	else return $deflang;
}

function get_if_restricted_is_open()
{
	// it's sunday
	if(\App\Models\Setting::getIsUploadOpenAtWeekend() && (date("w",time()) == '0' || (date("w",time()) == 6) && (date("G",time()) >=12 && date("G",time()) <=23)))
	{
		return true;
	}
	else
	return false;
}

function menu ($selected = "home") {
	global $lang_functions;
	global $BASEURL,$CURUSER;
	global $enableoffer, $enablespecial, $enableextforum, $extforumurl, $where_tweak;
	global $USERUPDATESET;
	global $SITENAME, $logo_main;
	//no this option in config.php
    $enablerequest = 'yes';
	$script_name = $_SERVER["SCRIPT_NAME"];
	if (preg_match("/index/i", $script_name)) {
		$selected = "home";
	}elseif (preg_match("/forums/i", $script_name)) {
		$selected = "forums";
	}elseif (preg_match("/torrents/i", $script_name)) {
		$selected = "torrents";
	}elseif (preg_match("/special/i", $script_name)) {
		$selected = "special";
	}elseif (preg_match("/offers/i", $script_name) OR preg_match("/offcomment/i", $script_name)) {
		$selected = "offers";
    }elseif (preg_match("/requests/i", $script_name)) {
        $selected = "requests";
	}elseif (preg_match("/upload/i", $script_name)) {
		$selected = "upload";
	}elseif (preg_match("/subtitles/i", $script_name)) {
		$selected = "subtitles";
	}elseif (preg_match("/usercp/i", $script_name)) {
		$selected = "usercp";
	}elseif (preg_match("/topten/i", $script_name)) {
		$selected = "topten";
	}elseif (preg_match("/log/i", $script_name)) {
		$selected = "log";
	}elseif (preg_match("/rules/i", $script_name)) {
		$selected = "rules";
	}elseif (preg_match("/faq/i", $script_name)) {
		$selected = "faq";
    }elseif (preg_match("/contactstaff/i", $script_name)) {
        $selected = "contactstaff";
    }elseif (preg_match("/staff/i", $script_name)) {
        $selected = "staff";
	}else
	$selected = "";
	$menu = apply_filter('nexus_menu');
	print ("<div id=\"nav\">");
	if ($menu) {
	    print $menu;
    } else {
	    $lang = get_langfolder_cookie();
		$normalizeMenuText = static function ($text) {
			$text = str_replace('&nbsp;', ' ', (string)$text);
			$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			return trim(preg_replace('/\s+/u', ' ', $text));
		};
        $normalSectionName = get_searchbox_value(get_setting('main.browsecat'), 'section_name');
        $specialSectionName = get_searchbox_value(get_setting('main.specialcat'), 'section_name');
        print ("<ul id=\"mainmenu\" class=\"menu\">");
		$brandTitle = htmlspecialchars((string)$SITENAME);
		$brandLogo = trim((string)$logo_main);
		$brandLogoHtml = '';
		if ($brandLogo !== '') {
			$brandLogoHtml = '<img src="'.htmlspecialchars($brandLogo).'" alt="'.$brandTitle.'" loading="lazy" decoding="async" />';
		}
		$brandInner = is_file(ROOT_PATH . 'public/pic/logo.png') ? '<img class="nav-brand-logo" src="/pic/logo.png?v='.@filemtime(ROOT_PATH . 'public/pic/logo.png').'" alt="'.$brandTitle.'" loading="lazy" decoding="async" />' : '<span class="nav-brand-text">'.$brandTitle.'</span>'; print ('<li class="nav-brand"><a href="index.php" title="'.$brandTitle.'">'.$brandInner.'</a></li>');
		$homeClass = $selected == "home" ? " class=\"nav-home selected\"" : " class=\"nav-home\"";
		print ("<li" . $homeClass . "><a href=\"index.php\">" . $normalizeMenuText($lang_functions['text_home']) . "</a></li>");
        if ($enableextforum != 'yes')
			print ("<li" . ($selected == "forums" ? " class=\"selected\"" : "") . "><a href=\"forums.php\">".$normalizeMenuText($lang_functions['text_forums'])."</a></li>");
        else
			print ("<li" . ($selected == "forums" ? " class=\"selected\"" : "") . "><a href=\"" . $extforumurl."\" target=\"_blank\">".$normalizeMenuText($lang_functions['text_forums'])."</a></li>");
		$qdIsRequireSeed = !empty($_GET['requireseed']);
		$qdTorrentSelected = ($selected == "torrents") && !$qdIsRequireSeed;
		print ("<li class=\"nav-torrents has-submenu" . ($qdTorrentSelected ? " selected" : "") . "\"><a href=\"torrents.php\">".$normalizeMenuText($normalSectionName[$lang] ?? $lang_functions['text_torrents'])."</a><ul class=\"nav-submenu nav-torrents-submenu\">");
		foreach (genrelist(get_setting('main.browsecat')) as $qdCat) {
			if (empty($qdCat['id'])) { continue; }
			print ("<li><a href=\"torrents.php?cat=".(int)$qdCat['id']."\">".htmlspecialchars((string)$qdCat['name'])."</a></li>");
		}
		print ("</ul></li>");
		print ("<li class=\"nav-requireseed" . ($selected == "torrents" && $qdIsRequireSeed ? " selected" : "") . "\"><a href=\"torrents.php?requireseed=1\">保种区</a></li>");
        if ($enablespecial == 'yes' && user_can('view_special_torrent'))
			print ("<li" . ($selected == "special" ? " class=\"selected\"" : "") . "><a href=\"special.php\">".$normalizeMenuText($specialSectionName[$lang] ?? $lang_functions['text_special'])."</a></li>");
        if ($enableoffer == 'yes')
			print ("<li" . ($selected == "offers" ? " class=\"selected\"" : "") . "><a href=\"offers.php\">".$normalizeMenuText($lang_functions['text_offers'])."</a></li>");
        if ($enablerequest == 'yes')
			print ("<li" . ($selected == "requests" ? " class=\"selected\"" : "") . "><a href=\"viewrequests.php\">".$normalizeMenuText($lang_functions['text_request'])."</a></li>");
		print ("<li" . ($selected == "upload" ? " class=\"selected\"" : "") . "><a href=\"upload.php\">".$normalizeMenuText($lang_functions['text_upload'])."</a></li>");
		print ("<li" . ($selected == "subtitles" ? " class=\"selected\"" : "") . "><a href=\"subtitles.php\">".$normalizeMenuText($lang_functions['text_subtitles'])."</a></li>");
        //	print ("<li" . ($selected == "usercp" ? " class=\"selected\"" : "") . "><a href=\"usercp.php\">".$lang_functions['text_user_cp']."</a></li>");
        if (user_can('topten')) {
			print ("<li" . ($selected == "topten" ? " class=\"selected\"" : "") . "><a href=\"topten.php\">".$normalizeMenuText($lang_functions['text_top_ten'])."</a></li>");
        }
		$rulesMenuSelected = in_array($selected, ["rules", "log", "faq", "contactstaff"], true);
		print ("<li class=\"nav-rules has-submenu" . ($rulesMenuSelected ? " selected" : "") . "\"><a href=\"javascript:void(0)\" onclick=\"return false;\">帮助</a><ul class=\"nav-submenu nav-rules-submenu\">");
				print ("<li" . ($selected == "rules" ? " class=\"selected\"" : "") . "><a href=\"rules.php\">".$normalizeMenuText($lang_functions['text_rules'])."</a></li>");
		print ("<li" . ($selected == "faq" ? " class=\"selected\"" : "") . "><a href=\"faq.php\">".$normalizeMenuText($lang_functions['text_faq'])."</a></li>");
		if (user_can('log')) {
			print ("<li" . ($selected == "log" ? " class=\"selected\"" : "") . "><a href=\"log.php\">".$normalizeMenuText($lang_functions['text_log'])."</a></li>");
		}
		print ("<li><a href=\"user-ban-log.php\">封禁记录</a></li>");
		if (user_can('staffmem')) {
			print ("<li class=\"has-submenu has-subsubmenu\"><a href=\"staff.php\">管理组</a><ul class=\"nav-submenu nav-subsubmenu\">");
			print ("<li><a href=\"staffbox.php\">管理组信箱</a></li>");
			print ("<li><a href=\"reports.php\">举报信箱</a></li>");
			print ("<li><a href=\"cheaterbox.php\">作弊者</a></li>");
			print ("<li><a href=\"complains.php?action=list\">申诉处理</a></li>");
			print ("</ul></li>");
		}
		print ("<li" . ($selected == "contactstaff" ? " class=\"selected\"" : "") . "><a href=\"contactstaff.php\">".$normalizeMenuText($lang_functions['text_contactstaff'])."</a></li>");
		print ("</ul></li>");
        print ("</ul>");
    }
	print ("</div>");
	if ($CURUSER){
		if ($where_tweak == 'yes')
			$USERUPDATESET[] = "page = ".sqlesc($selected);
	}
}
function get_css_row() {
	global $CURUSER, $defcss, $Cache;
	static $rows;
	$cssid = $CURUSER ? $CURUSER["stylesheet"] : $defcss;
	if (!$rows && !$rows = $Cache->get_value('stylesheet_content')){
		$rows = array();
		$res = sql_query("SELECT * FROM stylesheets ORDER BY id ASC");
		while($row = mysql_fetch_array($res)) {
			$rows[$row['id']] = $row;
		}
		$Cache->cache_value('stylesheet_content', $rows, 95400);
	}
	return $rows[$cssid] ?? $rows[$defcss];
}
function get_css_uri($file = "")
{
    global $defcss;
	$cssRow = get_css_row();
	$ss_uri = $cssRow['uri'];
	if (!$ss_uri)
		$ss_uri = get_single_value("stylesheets","uri","WHERE id=".sqlesc($defcss));
	if ($file == "")
		return $ss_uri;
	else return $ss_uri.$file;
}

function get_font_css_uri(){
	global $CURUSER;
    $file = 'mediumfont.css';
    if ($CURUSER && isset($CURUSER['fontsize'])) {
        if ($CURUSER['fontsize'] == 'large')
            $file = 'largefont.css';
        elseif ($CURUSER['fontsize'] == 'small')
            $file = 'smallfont.css';
    }
	return "styles/".$file;
}

function get_style_addicode()
{
	$cssRow = get_css_row();
	return $cssRow['addicode'];
}

function get_cat_folder($cat = 101)
{
	static $catPath = array();
	if (!isset($catPath[$cat])) {
		global $CURUSER, $CURLANGDIR;
        $catrow = get_category_row($cat);
		$catmode = $catrow['catmodename'];
//		$caticonrow = get_category_icon_row($CURUSER['caticon']);
        /**
         * @since v1.6
         * use setting, not user's caticon, that field make no sense!
         */
		$caticonrow = get_category_icon_row($catrow['icon_id'] ?: 1);
		$path = sprintf('category/%s/%s', trim($catmode, '/'), trim($caticonrow['folder'], '/'));
		if ($caticonrow['multilang'] == 'yes') {
		    $path .= '/' . trim($CURLANGDIR, '/');
        }
		do_log("cat: $cat, path: $path", 'debug');
        $catPath[$cat] = $path;
	}
	return $catPath[$cat] ?? '';
}

function get_style_highlight()
{
	global $CURUSER;
	if ($CURUSER)
	{
		$ss_a = @mysql_fetch_array(@sql_query("select hltr from stylesheets where id=" . $CURUSER["stylesheet"]));
		if ($ss_a) $hltr = $ss_a["hltr"];
	}
	if (!$hltr)
	{
		$r = sql_query("SELECT hltr FROM stylesheets WHERE id=5");
		$a = mysql_fetch_array($r);
		$hltr = $a["hltr"];
	}
	return $hltr;
}

function qd_mobile_std_auto_page_class(): string
{
    $path = strtolower((string)(parse_url((string)($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH) ?: ''));
    $path = trim($path, '/');
    if ($path === '') {
        $path = strtolower((string)(nexus()->getScript() ?: 'std'));
    }
    if (str_starts_with($path, 'public/')) {
        $path = substr($path, 7);
    }
    $path = preg_replace('/\.php$/', '', $path);
    $path = preg_replace('/[^a-z0-9]+/', '-', $path);
    $path = trim((string)$path, '-');
    return 'page-std page-' . ($path !== '' ? $path : 'std');
}

function qd_mobile_std_auto_head(string $title = ''): bool
{
    global $CURUSER;
    if (!defined('ROOT_PATH') || !empty($GLOBALS['QD_STD_MOBILE_AUTO_DISABLED']) || !empty($_GET['inframe']) || empty($CURUSER['id'])) {
        return false;
    }
    require_once ROOT_PATH . 'include/mobile_shell.php';
    if (!function_exists('mobile_is') || !mobile_is() || !function_exists('mobile_shell_page_head')) {
        return false;
    }

    $GLOBALS['QD_STD_MOBILE_AUTO'] = true;
    $active = function_exists('mobile_std_active') ? mobile_std_active() : '';
    mobile_shell_page_head(trim(strip_tags($title)), $active, qd_mobile_std_auto_page_class());
    echo '<link rel="stylesheet" type="text/css" href="/styles/mobile-content.css?v=20260704ai">';
    do_action('nexus_header');
    if (class_exists('\\Nexus\\Nexus')) {
        foreach (\Nexus\Nexus::getAppendHeaders() as $value) {
            print($value);
        }
    }
    echo '<script type="text/javascript" src="js/jquery-1.12.4.min.js"></script>';
    echo '<script>jQuery.noConflict();window.nexusLayerOptions={confirm:{btnAlign:"c",title:"Confirm",btn:["OK","Cancel"]},alert:{btnAlign:"c",title:"Info",btn:["OK","Cancel"]}};</script>';
    echo '<script type="text/javascript" src="vendor/layer-v3.5.1/layer/layer.js"></script>';
    echo '<script type="text/javascript" src="js/common.js"></script>';
    echo '<script type="text/javascript" src="js/ajaxbasic.js"></script>';
    return true;
}

function qd_mobile_std_auto_foot(): bool
{
    global $CURUSER, $USERUPDATESET;
    if (empty($GLOBALS['QD_STD_MOBILE_AUTO'])) {
        return false;
    }
    if ($CURUSER && count($USERUPDATESET)) {
        sql_query("UPDATE users SET " . join(",", $USERUPDATESET) . " WHERE id = ".$CURUSER['id']);
        $USERUPDATESET = [];
    }
    if (class_exists('\\Nexus\\Nexus')) {
        foreach (\Nexus\Nexus::getAppendFooters() as $value) {
            print($value);
        }
    }
    require_once ROOT_PATH . 'include/mobile_shell.php';
    if (function_exists('mobile_shell_page_foot')) {
        mobile_shell_page_foot(function_exists('mobile_std_active') ? mobile_std_active() : '');
        $GLOBALS['QD_STD_MOBILE_AUTO'] = false;
        return true;
    }
    return false;
}

function stdhead($title = "", $msgalert = true, $script = "", $place = "")
{
	global $lang_functions;
	global $CURUSER, $CURLANGDIR, $USERUPDATESET, $iplog1, $oldip, $SITE_ONLINE, $FUNDS, $SITENAME, $SLOGAN, $logo_main, $BASEURL, $offlinemsg,$enabledonation, $staffmem_class, $titlekeywords_tweak, $metakeywords_tweak, $metadescription_tweak, $cssdate_tweak, $deletenotransfertwo_account, $neverdelete_account, $iniupload_main;
	global $tstart;
	global $Cache;
	global $Advertisement;
	$qdMobileTitle = trim(strip_tags((string)$title));

	$Cache->setLanguage($CURLANGDIR);

	$Advertisement = new ADVERTISEMENT($CURUSER['id'] ?? 0);
	$cssupdatedate = $cssdate_tweak;
	// Variable for Start Time
	$tstart = getmicrotime(); // Start time
	//Insert old ip into iplog
	if ($CURUSER){
//		if ($iplog1 == "yes") {
//			if (($oldip != $CURUSER["ip"]) && $CURUSER["ip"])
//			sql_query("INSERT INTO iplog (ip, userid, access) VALUES (" . sqlesc($CURUSER['ip']) . ", " . $CURUSER['id'] . ", '" . $CURUSER['last_access'] . "')");
//		}
        //record always
        \App\Repositories\IpLogRepository::saveToCache($CURUSER['id']);
		$USERUPDATESET[] = "last_access = ".sqlesc(date("Y-m-d H:i:s"));
		$USERUPDATESET[] = "ip = ".sqlesc($CURUSER['ip']);
	}
	header("Content-Type: text/html; charset=utf-8; Cache-control:private");
	//header("Pragma: No-cache");
	if ($title == "")
	$title = $SITENAME;
	else
	$title = $SITENAME." :: " . htmlspecialchars($title);
	if ($titlekeywords_tweak)
		$title .= " ".htmlspecialchars($titlekeywords_tweak);
	$title .= " - Powered by ".PROJECTNAME;
	if ($SITE_ONLINE == "no") {
		if (get_user_class() < UC_ADMINISTRATOR) {
			die($lang_functions['std_site_down_for_maintenance']);
		}
		else
		{
			$offlinemsg = true;
		}
	}
    if (qd_mobile_std_auto_head($qdMobileTitle)) {
        return;
    }
?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<?php
// 手机端适配：仅“已适配”的页面输出 viewport，其余未适配页面不受影响（避免缩成桌面版）。
// 已适配：游戏板块(/games/...)、登录页(login.php)、首页(index.php)。响应式样式已在
// modern-refresh.css / login-modern.css 中就绪，输出 viewport 后即生效。
$qdScriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$qdRequestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
$qdPageBase = strtolower(basename((string)(parse_url($qdScriptName, PHP_URL_PATH) ?: $qdScriptName)));
$qdMobileAdapted = strpos($qdScriptName, '/games/') !== false
    || strpos($qdRequestUri, '/games/') !== false
    || in_array($qdPageBase, ['login.php', 'index.php', 'usercp.php'], true);
if ($qdMobileAdapted){
?>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<?php
}
if ($metakeywords_tweak){
?>
<meta name="keywords" content="<?php echo htmlspecialchars($metakeywords_tweak)?>" />
<?php
}
if ($metadescription_tweak){
?>
<meta name="description" content="<?php echo htmlspecialchars($metadescription_tweak)?>" />
<?php
}
?>
<meta name="generator" content="<?php echo PROJECTNAME?>" />
<?php
print(get_style_addicode());
$css_uri = get_css_uri();
$cssupdatedate=($cssupdatedate ? "?".htmlspecialchars($cssupdatedate) : "");
?>
<title><?php echo $title?></title>
<?php if (!empty($GLOBALS['nexus_base_href'])) { ?><base href="<?php echo htmlspecialchars($GLOBALS['nexus_base_href']) ?>" /><?php } ?>
<link rel="shortcut icon" href="favicon.ico?v=2" type="image/x-icon" />
<link rel="search" type="application/opensearchdescription+xml" title="<?php echo $SITENAME?> Torrents" href="opensearch.php" />
<link rel="stylesheet" href="<?php echo get_font_css_uri().$cssupdatedate?>" type="text/css" />
<link rel="stylesheet" href="styles/sprites.css<?php echo $cssupdatedate?>" type="text/css" />
<link rel="stylesheet" href="<?php echo get_forum_pic_folder()."/forumsprites.css".$cssupdatedate?>" type="text/css" />
<link rel="stylesheet" href="<?php echo $css_uri."theme.css".$cssupdatedate?>" type="text/css" />
<link rel="stylesheet" href="<?php echo $css_uri."DomTT.css".$cssupdatedate?>" type="text/css" />
<link rel="stylesheet" href="styles/nexus.css<?php echo $cssupdatedate?>" type="text/css" />
<?php $modernRefreshVersion = @filemtime(ROOT_PATH . 'public/styles/modern-refresh.css') ?: time(); ?>
<link rel="stylesheet" href="styles/modern-refresh.css?v=<?php echo intval($modernRefreshVersion) ?>" type="text/css" />
<?php
$qdPV = ''; $qdPJ = 'null';
if (!empty($GLOBALS['CURUSER']['id'])) {
    $qdUid = (int)$GLOBALS['CURUSER']['id'];
    try {
        $qdM = \Nexus\Database\NexusDB::remember("qd_personalize_$qdUid", 3600, function () use ($qdUid) {
            return (string)(\App\Models\UserMeta::query()->where('uid', $qdUid)->where('meta_key', 'PERSONALIZE')->where('status', 0)->value('meta_value') ?: '');
        });
        if ($qdM !== '') {
            $qdArr = json_decode($qdM, true);
            if (is_array($qdArr)) {
                $qdAllow = ['--bili-primary', '--bili-accent', '--bili-bg', '--bili-surface', '--bili-text'];
                $qdClean = [];
                foreach ($qdAllow as $qdK) {
                    if (isset($qdArr[$qdK]) && preg_match('/^#[0-9a-fA-F]{6}$/', $qdArr[$qdK])) {
                        $qdClean[$qdK] = $qdArr[$qdK];
                        $qdPV .= $qdK . ':' . $qdArr[$qdK] . ';';
                    }
                }
                $qdPJ = json_encode($qdClean);
            }
        }
    } catch (\Throwable $qdE) {}
}
echo '<style id="qd-personalize-vars">' . ($qdPV !== '' ? (':root[data-site-theme="day"],html:not([data-site-theme]){' . $qdPV . '}') : '') . '</style>';
echo '<script>window.__QD_P__=' . $qdPJ . ';</script>';
$qdLW = 90; $qdLN = 1200;
if (!empty($GLOBALS['CURUSER']['id'])) {
    $qdUidW = (int)$GLOBALS['CURUSER']['id'];
    try {
        $qdWM = \Nexus\Database\NexusDB::remember("qd_layout_width_$qdUidW", 3600, function () use ($qdUidW) {
            return (string)(\App\Models\UserMeta::query()->where('uid', $qdUidW)->where('meta_key', 'QD_LAYOUT_WIDTH')->where('status', 0)->value('meta_value') ?: '');
        });
        if (strpos($qdWM, '|') !== false) {
            $qdWNa = explode('|', $qdWM, 2);
            $qdWp = (int)$qdWNa[0]; $qdNp = (int)$qdWNa[1];
            if ($qdWp >= 30 && $qdWp <= 100) { $qdLW = $qdWp; }
            if ($qdNp >= 600 && $qdNp <= 3840) { $qdLN = $qdNp; }
        }
    } catch (\Throwable $qdWE) {}
}
echo '<style id="qd-layout-width">@media (min-width:1100px){body.layout-wide:not(.inframe){padding-left:0!important;padding-right:0!important;}}body.layout-wide:not(.inframe) #outer.outer,body.layout-wide:not(.inframe) table.mainouter{width:' . $qdLW . '%!important;max-width:' . $qdLW . '%!important;margin-left:auto!important;margin-right:auto!important;}body.layout-narrow:not(.inframe) #outer.outer,body.layout-narrow:not(.inframe) table.mainouter,body.layout-narrow.page-torrents:not(.inframe) #outer.outer{width:min(' . $qdLN . 'px,100vw)!important;max-width:' . $qdLN . 'px!important;margin-left:auto!important;margin-right:auto!important;}</style>';
?>

<?php
if ($CURUSER){
//	$caticonrow = get_category_icon_row($CURUSER['caticon']);
//	if($caticonrow['cssfile']){
    $requireSearchBoxIdAr = list_require_search_box_id();
    if (!empty($requireSearchBoxIdAr)) {
        $icons = (new \App\Repositories\SearchBoxRepository())->listIcon($requireSearchBoxIdAr);
        foreach ($icons as $icon) {

?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(trim($icon['cssfile'] ?? '', '/')).$cssupdatedate?>" type="text/css" />
<?php
	}}
}
?>
<link rel="alternate" type="application/rss+xml" title="Latest Torrents" href="torrentrss.php" />
<script type="text/javascript" src="js/curtain_imageresizer.js<?php echo $cssupdatedate?>"></script>
<script type="text/javascript" src="js/ajaxbasic.js<?php echo $cssupdatedate?>"></script>
<script type="text/javascript" src="js/common.js<?php echo $cssupdatedate?>"></script>
<script type="text/javascript" src="js/domLib.js<?php echo $cssupdatedate?>"></script>
<script type="text/javascript" src="js/domTT.js<?php echo $cssupdatedate?>"></script>
<script type="text/javascript" src="js/domTT_drag.js<?php echo $cssupdatedate?>"></script>
<script type="text/javascript" src="js/fadomatic.js<?php echo $cssupdatedate?>"></script>
<?php
do_action('nexus_header');
foreach (\Nexus\Nexus::getAppendHeaders() as $value) {
    print($value);
}
?>
<script type="text/javascript" src="js/jquery-1.12.4.min.js<?php echo $cssupdatedate?>"></script>
<script type="text/javascript">
    jQuery.noConflict();
    window.nexusLayerOptions = {
        confirm: {btnAlign: 'c', title: 'Confirm', btn: ['OK', 'Cancel']},
        alert: {btnAlign: 'c', title: 'Info', btn: ['OK', 'Cancel']}
    }
</script>
<script type="text/javascript" src="vendor/layer-v3.5.1/layer/layer.js<?php echo $cssupdatedate?>"></script>
</head>
<?php
$pageClass = preg_replace('/[^a-z0-9_-]+/i', '-', nexus()->getScript());
$isInframePage = !empty($_GET['inframe']);
$qdCarouselOff = !$isInframePage
    && !in_array(nexus()->getScript(), ['upload', 'details'], true)
    && !should_show_top_carousel($GLOBALS['CURUSER'] ?? null);
$bodyClass = trim('page-' . ($pageClass ?: 'index') . ($isInframePage ? ' inframe' : '') . ($qdCarouselOff ? ' carousel-off' : ''));
?>
<body class="<?php echo htmlspecialchars($bodyClass) ?>">
<?php if ($isInframePage) { ?>
<table class="mainouter" width="100%" cellspacing="0" cellpadding="5" align="center">
<tr><td id="outer" align="center" class="outer" style="padding: 12px 14px">
<?php return; } ?>
<table class="head" cellspacing="0" cellpadding="0" align="center" style="width: <?php echo isset($GLOBALS['CURUSER']) ? CONTENT_WIDTH + 28.66 : CONTENT_WIDTH ?>px">
	<tr>
		<td class="clear">
<?php
if ($logo_main == "")
{
?>
			<div class="logo"><?php echo htmlspecialchars($SITENAME)?></div>
			<div class="slogan"><?php echo htmlspecialchars($SLOGAN)?></div>
<?php
}
else
{
?>
			<div class="logo_img"><img src="<?php echo $logo_main?>" alt="<?php echo htmlspecialchars($SITENAME)?>" title="<?php echo htmlspecialchars($SITENAME)?> - <?php echo htmlspecialchars($SLOGAN)?>" /></div>
<?php
}
?>
		</td>
		<td class="clear nowrap" align="right" valign="middle">
<?php if ($Advertisement->enable_ad()){
		$headerad=$Advertisement->get_ad('header');
		if ($headerad){
			echo "<span>".$headerad[0]."</span>";
		}
}
if ($enabledonation == 'yes'){?>
			<a href="donate.php"><img src="<?php echo get_forum_pic_folder()?>/donate.gif" alt="Make a donation" style="margin-left: 5px; margin-top: 50px;" /></a>
<?php
}
?>
		</td>
	</tr>
</table>

<table class="mainouter" width="<?php echo CONTENT_WIDTH ?>" cellspacing="0" cellpadding="5" align="center">
	<tr><td id="nav_block" class="text" align="center">
<?php if (!$CURUSER) { ?>
			<a href="login.php"><font class="big"><b><?php echo $lang_functions['text_login'] ?></b></font></a> / <a href="signup.php"><font class="big"><b><?php echo $lang_functions['text_signup'] ?></b></font></a>
<?php }
else {
	begin_main_frame();
	menu ();
	end_main_frame();

	$topSearchValue = htmlspecialchars((string)($_GET['search'] ?? ''));
	$topSearchPlaceholder = htmlspecialchars(nexus_trans('search.search_keyword') ?: 'Search torrents');
?>

<div id="top-nav-search">
	<form action="torrents.php" method="get" autocomplete="off">
		<input type="text" name="search" value="<?php echo $topSearchValue ?>" placeholder="<?php echo $topSearchPlaceholder ?>" />
		<input type="hidden" name="search_area" value="0" />
		<input type="hidden" name="search_mode" value="and" />
		<button type="submit" class="top-nav-search-submit" aria-label="Search">Search</button>
		<?php if (nexus()->getScript() == 'torrents') { ?>
		<button type="button" class="top-advanced-search-trigger" aria-haspopup="dialog" aria-controls="torrent-advanced-search-modal">&#39640;&#32423;&#25628;&#32034;</button>
		<?php } ?>
	</form>
</div>

<script>
(function () {
	function initTopbarScrollState() {
		if (!document.body || document.body.classList.contains('inframe')) {
			return;
		}

		var body = document.body;
		var threshold = 120;

		function recalcThreshold() {
			var hero = document.querySelector('.global-top-banner');
			if (hero) {
				threshold = Math.max(80, Math.round(hero.getBoundingClientRect().bottom + window.scrollY - 72));
				return;
			}

			var bannerAnchor = document.querySelector('.searchbox') || document.querySelector('#outer');
			if (!bannerAnchor) {
				threshold = 120;
				return;
			}
			threshold = Math.max(80, Math.round(bannerAnchor.getBoundingClientRect().top + window.scrollY - 120));
		}

		function updateNavState() {
			if (window.scrollY >= threshold) {
				body.classList.add('nav-scrolled');
			} else {
				body.classList.remove('nav-scrolled');
			}
		}

		recalcThreshold();
		updateNavState();
		window.addEventListener('scroll', updateNavState, { passive: true });
		window.addEventListener('resize', function () {
			recalcThreshold();
			updateNavState();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initTopbarScrollState);
	} else {
		initTopbarScrollState();
	}
})();
</script>

<?php

	$datum = getdate();
	$datum["hours"] = sprintf("%02.0f", $datum["hours"]);
	$datum["minutes"] = sprintf("%02.0f", $datum["minutes"]);
	$ratio = get_ratio($CURUSER['id']);

	//// check every 15 minutes //////////////////
	$messages = $Cache->get_value('user_'.$CURUSER["id"].'_inbox_count');
	if ($messages == ""){
		$messages = get_row_count("messages", "WHERE receiver=" . sqlesc($CURUSER["id"]) . " AND location<>0");
		$Cache->cache_value('user_'.$CURUSER["id"].'_inbox_count', $messages, 900);
	}
	$outmessages = $Cache->get_value('user_'.$CURUSER["id"].'_outbox_count');
	if ($outmessages == ""){
		$outmessages = get_row_count("messages","WHERE sender=" . sqlesc($CURUSER["id"]) . " AND saved='yes'");
		$Cache->cache_value('user_'.$CURUSER["id"].'_outbox_count', $outmessages, 900);
	}
	if (!$connect = $Cache->get_value('user_'.$CURUSER["id"].'_connect')){
		$res3 = sql_query("SELECT connectable FROM peers WHERE userid=" . sqlesc($CURUSER["id"]) . " order by id desc LIMIT 1");
		if($row = mysql_fetch_row($res3))
			$connect = $row[0];
		else $connect = 'unknown';
		$Cache->cache_value('user_'.$CURUSER["id"].'_connect', $connect, 900);
	}

	if($connect == "yes")
		$connectable = "<b><font color=\"green\">".$lang_functions['text_yes']."</font></b>";
	elseif ($connect == 'no')
		$connectable = "<a href=\"faq.php#id21\"><b><font color=\"red\">".$lang_functions['text_no']."</font></b></a>";
	else
		$connectable = $lang_functions['text_unknown'];

	//// check every 60 seconds //////////////////
	$activeseed = $Cache->get_value('user_'.$CURUSER["id"].'_active_seed_count');
	if ($activeseed == ""){
		$activeseed = get_row_count("peers","WHERE userid=" . sqlesc($CURUSER["id"]) . " AND seeder='yes'");
		$Cache->cache_value('user_'.$CURUSER["id"].'_active_seed_count', $activeseed, 60);
	}
	$activeleech = $Cache->get_value('user_'.$CURUSER["id"].'_active_leech_count');
	if ($activeleech == ""){
		$activeleech = get_row_count("peers","WHERE userid=" . sqlesc($CURUSER["id"]) . " AND seeder='no'");
		$Cache->cache_value('user_'.$CURUSER["id"].'_active_leech_count', $activeleech, 60);
	}
	$unread = $Cache->get_value('user_'.$CURUSER["id"].'_unread_message_count');
	if ($unread == ""){
		$unread = get_row_count("messages","WHERE receiver=" . sqlesc($CURUSER["id"]) . " AND unread='yes'");
		$Cache->cache_value('user_'.$CURUSER["id"].'_unread_message_count', $unread, 60);
	}

	$inboxpic = "<img class=\"".($unread ? "inboxnew" : "inbox")."\" src=\"pic/trans.gif\" alt=\"inbox\" title=\"".($unread ? $lang_functions['title_inbox_new_messages'] : $lang_functions['title_inbox_no_new_messages'])."\" />";
//    $attend_desk = new Attendance($CURUSER['id']);
//    $attendance = $attend_desk->check();
    $attendanceRep = new \App\Repositories\AttendanceRepository();
	$attendance = $attendanceRep->getAttendance($CURUSER['id'], date('Ymd'));
	$showTopAttendancePrompt = !$attendance
		&& ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
		&& nexus()->getScript() !== 'attendance';

	$topAvatar = strtoupper(substr((string)$CURUSER['username'], 0, 1));
	$topAvatarUrl = trim((string)($CURUSER['avatar'] ?? ''));
	if ($topAvatarUrl !== '' && preg_match('/^[a-z][a-z0-9+.-]*:/i', $topAvatarUrl) && !preg_match('/^https?:/i', $topAvatarUrl)) {
		$topAvatarUrl = '';
	}
	$topUserClassColor = get_user_class_name($CURUSER['class'], true, false, false) . '_Name';
	$topUserTone = 'user';
	if ($CURUSER['class'] >= UC_SYSOP) {
		$topUserTone = 'sysop';
	} elseif ($CURUSER['class'] >= UC_ADMINISTRATOR) {
		$topUserTone = 'admin';
	} elseif ($CURUSER['class'] >= UC_MODERATOR) {
		$topUserTone = 'mod';
	} elseif ($CURUSER['class'] >= UC_VIP) {
		$topUserTone = 'vip';
	}
	$topConnectableText = trim(strip_tags((string)$connectable));
	$topInviteCount = (int)($CURUSER['invites'] ?? 0);
	$topHrCount = (int)\App\Models\HitAndRun::query()
		->where('uid', $CURUSER['id'])
		->where('status', \App\Models\HitAndRun::STATUS_INSPECTING)
		->count();
	$topClaimCount = (int)\App\Models\Claim::query()
		->where('uid', $CURUSER['id'])
		->count();
	$normalizeTopLabel = static function ($value, $fallback = '') {
		$text = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = str_replace("\xC2\xA0", ' ', $text);
		$text = str_replace(array('&nbsp;', '&#160;'), ' ', $text);
		$text = preg_replace('/[\s\x{00A0}]+/u', '', (string)$text);
		$text = trim((string)$text);
		if ($text === '') {
			return (string)$fallback;
		}
		return $text;
	};
	$showTopUpload = user_can_upload('torrents');
	$showTopStaffPanel = get_user_class() >= UC_MODERATOR;
	$showTopStaff = user_can('viewstaff');
	$showTopContactStaff = user_can('viewstafflist');
	$showTopSiteSettings = get_user_class() >= UC_SYSOP;
	$showTopManagementSystem = get_user_class() >= \App\Models\User::getAccessAdminClassMin();
	$topUploadLabel = $normalizeTopLabel($lang_functions['text_upload'] ?? '', 'Upload');
	$topStaffPanelLabel = $normalizeTopLabel($lang_functions['text_staff_panel'] ?? '', 'Staff Panel');
	$topStaffLabel = $normalizeTopLabel($lang_functions['text_staff'] ?? '', 'Staff');
	$topContactStaffLabel = $normalizeTopLabel($lang_functions['text_contactstaff'] ?? '', 'Contact');
	$topSiteSettingsLabel = $normalizeTopLabel($lang_functions['text_site_settings'] ?? '', 'Site Settings');
	$topManagementSystemLabel = $normalizeTopLabel($lang_functions['text_management_system'] ?? '', 'Management');
	$topManagementSystemUrl = nexus_env('FILAMENT_PATH', 'nexusphp');
	$topAccountMenuIcon = function ($type) {
		$icons = [
			'user' => '<path d="M10 10.5a3.25 3.25 0 1 0 0-6.5 3.25 3.25 0 0 0 0 6.5Z"></path><path d="M4.2 17a5.9 5.9 0 0 1 11.6 0"></path>',
			'friends' => '<path d="M7.2 9.5a2.7 2.7 0 1 0 0-5.4 2.7 2.7 0 0 0 0 5.4Z"></path><path d="M13.2 10a2.25 2.25 0 1 0 0-4.5"></path><path d="M3.2 16.2a4.2 4.2 0 0 1 8 0"></path><path d="M12.2 13.2a3.55 3.55 0 0 1 4.6 3"></path>',
			'medal' => '<path d="M7.2 3.5h5.6l1.4 3.6-4.2 4.4-4.2-4.4 1.4-3.6Z"></path><path d="M6.1 7.1h7.8"></path><path d="M10 11.5v2.1"></path><path d="M7.2 16.5a2.8 2.8 0 1 1 5.6 0"></path><path d="M8.3 16.5h3.4"></path>',
			'task' => '<path d="M6 3.5h8A1.5 1.5 0 0 1 15.5 5v10A1.5 1.5 0 0 1 14 16.5H6A1.5 1.5 0 0 1 4.5 15V5A1.5 1.5 0 0 1 6 3.5Z"></path><path d="M7.2 7.2h5.6"></path><path d="M7.2 10h5.6"></path><path d="M7.2 12.8h3.2"></path><path d="M13.2 12.3l.8.8 1.6-1.8"></path>',
			'staff' => '<path d="M6 3.5h6.8L16 6.7V16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5.5a2 2 0 0 1 2-2Z"></path><path d="M12.5 3.8v3.4h3.3"></path><path d="M8.2 12.2h3.2"></path><path d="M8.2 15h4.8"></path>',
			'panel' => '<path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h9A1.5 1.5 0 0 1 16 5.5v9a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 4 14.5v-9Z"></path><path d="M7 7.5h6"></path><path d="M7 10h6"></path><path d="M7 12.5h3.5"></path>',
			'management' => '<path d="M4 5.2A1.7 1.7 0 0 1 5.7 3.5h8.6A1.7 1.7 0 0 1 16 5.2v5.9a1.7 1.7 0 0 1-1.7 1.7H5.7A1.7 1.7 0 0 1 4 11.1V5.2Z"></path><path d="M8.2 16.5h3.6"></path><path d="M10 12.8v3.7"></path><path d="M6.8 6.5h6.4"></path><path d="M6.8 9h3.8"></path>',
			'settings' => '<path d="M10 3.5 12 5l2.4-.5 1.1 2.1-1.6 1.9.1 1 1.7 1.8-1.1 2.2-2.5-.4-.9.6-.9 2.3H7.8l-.9-2.3-.9-.6-2.5.4-1.1-2.2 1.7-1.8.1-1-1.6-1.9 1.1-2.1L6 5l2-1.5h2Z"></path><path d="M10 12.4a2.4 2.4 0 1 0 0-4.8 2.4 2.4 0 0 0 0 4.8Z"></path>',
			'logout' => '<path d="M8.2 5H5.5A1.5 1.5 0 0 0 4 6.5v7A1.5 1.5 0 0 0 5.5 15h2.7"></path><path d="M11 7l3 3-3 3"></path><path d="M14 10H7.5"></path>',
		];
		return '<span class="top-account-link-icon" aria-hidden="true"><svg viewBox="0 0 20 20" focusable="false">' . ($icons[$type] ?? $icons['user']) . '</svg></span>';
	};
	$topMessagesLabel = '&#28040;&#24687;';
	$topInboxLabel = '收件箱';
	$topOutboxLabel = trim(html_entity_decode((string)($lang_functions['title_sentbox'] ?? 'Outbox'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	$topInboxTitle = trim(html_entity_decode((string)($lang_functions['title_inbox_no_new_messages'] ?? $topInboxLabel), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	$topUnreadCount = (int)$unread;
	$topCheaterCount = 0;
	if (user_can('cheatmanage')) {
		$topCheaterCount = (int)get_row_count("cheaters");
	}
	$topReportCount = 0;
	if (user_can('staffmem')) {
		$topReportCount = (int)get_row_count("reports");
	}
	$topStaffMessageCount = 0;
	if (user_can('staffmem')) {
		$topStaffMessageCount = \App\Repositories\MessageRepository::getStaffMessageCountCache($CURUSER['id'], 'total');
		if ($topStaffMessageCount === false) {
			$topStaffMessageCount = (int)\App\Repositories\MessageRepository::countStaffMessage($CURUSER['id']);
		} else {
			$topStaffMessageCount = (int)$topStaffMessageCount;
		}
	}
?>

<div id="top-account-widget">
	<div id="top-stats-bar" class="top-stats-bar">
		<span class="top-stat"><i><?php echo $lang_functions['text_ratio'] ?? '分享率' ?></i><b><?php echo $ratio ?></b></span>
		<a class="top-stat" href="mybonus.php"><i><?php echo $lang_functions['text_bonus'] ?? '电影票' ?></i><b><?php echo number_format($CURUSER['seedbonus'], 1) ?></b></a>
		<a class="top-stat" href="invite.php?id=<?php echo (int)$CURUSER['id'] ?>"><i>邀请</i><b><?php echo (int)($topInviteCount ?? 0) ?></b></a>
		<a class="top-stat" href="claim.php?uid=<?php echo (int)$CURUSER['id'] ?>"><i>认领</i><b><?php echo (int)($topClaimCount ?? 0) ?></b></a>
		<span class="top-stat"><i><?php echo $lang_functions['text_active_torrents'] ?? '活动' ?></i><b><em class="up"><?php echo (int)($activeseed ?? 0) ?></em>/<em class="dn"><?php echo (int)($activeleech ?? 0) ?></em></b></span>
		<a class="top-stat" href="userdetails.php?id=<?php echo (int)$CURUSER['id'] ?>"><i class="up"><?php echo $lang_functions['text_uploaded'] ?? '上传' ?></i><b><?php echo mksize($CURUSER['uploaded']) ?></b></a>
		<a class="top-stat" href="userdetails.php?id=<?php echo (int)$CURUSER['id'] ?>"><i class="dn"><?php echo $lang_functions['text_downloaded'] ?? '下载' ?></i><b><?php echo mksize($CURUSER['downloaded']) ?></b></a>
		<a class="top-stat" href="myhr.php"><i>H&amp;R</i><b><?php echo (int)($topHrCount ?? 0) ?></b></a>
	</div>
	<div class="top-account-entry">
		<a class="top-account-trigger" href="userdetails.php?id=<?php echo (int)$CURUSER['id'] ?>" title="<?php echo htmlspecialchars($lang_functions['text_user_cp']) ?>">
			<span class="top-account-avatar<?php echo $topAvatarUrl !== '' ? ' top-account-avatar--image' : '' ?>"><?php if ($topAvatarUrl !== '') { ?><img src="<?php echo htmlspecialchars($topAvatarUrl) ?>" alt="" loading="lazy" onerror="this.remove();this.parentNode.classList.remove('top-account-avatar--image');this.parentNode.textContent='<?php echo htmlspecialchars($topAvatar, ENT_QUOTES) ?>';" /><?php } else { echo htmlspecialchars($topAvatar); } ?></span>
		</a>
		<div class="top-account-dropdown">
			<div class="top-account-header">
				<a class="top-account-name top-account-name--<?php echo htmlspecialchars($topUserTone) ?> <?php echo htmlspecialchars($topUserClassColor) ?>" href="userdetails.php?id=<?php echo (int)$CURUSER['id'] ?>" style="text-decoration:none" title="<?php echo htmlspecialchars($lang_functions['text_user_cp']) ?>"><?php echo htmlspecialchars($CURUSER['username']) ?></a>
				<div class="top-theme-switch" role="group" aria-label="Theme switch">
					<button type="button" class="top-theme-btn" data-theme-toggle data-theme="night" aria-label="Switch theme" title="Switch theme">&#9790;</button>
				</div>
			</div>
			<div class="top-account-stats">
				<div class="top-account-stat top-account-stat-ratio"><span><?php echo $lang_functions['text_ratio'] ?></span><b><?php echo $ratio ?></b></div>
				<div class="top-account-stat top-account-stat-uploaded"><span style="color: #008000 !important;"><?php echo $lang_functions['text_uploaded'] ?></span><b style="color: #008000 !important;"><?php echo mksize($CURUSER['uploaded']) ?></b></div>
				<div class="top-account-stat top-account-stat-downloaded"><span style="color: #8b0000 !important;"><?php echo $lang_functions['text_downloaded'] ?></span><b style="color: #8b0000 !important;"><?php echo mksize($CURUSER['downloaded']) ?></b></div>
				<div class="top-account-stat top-account-stat-active"><span><?php echo $lang_functions['text_active_torrents'] ?></span><b><img class="arrowup" alt="Torrents seeding" title="<?php echo $lang_functions['title_torrents_seeding'] ?>" src="pic/trans.gif" /><em class="top-account-active-up"><?php echo $activeseed ?></em><i>/</i><img class="arrowdown" alt="Torrents leeching" title="<?php echo $lang_functions['title_torrents_leeching'] ?>" src="pic/trans.gif" /><em class="top-account-active-down"><?php echo $activeleech ?></em></b></div>
				<div class="top-account-stat top-account-stat-connectable"><span><?php echo $lang_functions['text_connectable'] ?></span><b><?php echo htmlspecialchars($topConnectableText) ?></b></div>
				<a class="top-account-stat top-account-stat-link top-account-stat-bonus" href="mybonus.php"><span><?php echo $lang_functions['text_bonus'] ?></span><b><?php echo number_format($CURUSER['seedbonus'], 1) ?></b></a>
				<a class="top-account-stat top-account-stat-link top-account-stat-invite" href="invite.php?id=<?php echo (int)$CURUSER['id'] ?>"><span>邀请</span><b><?php echo $topInviteCount ?></b></a>
				<a class="top-account-stat top-account-stat-link top-account-stat-hr" href="myhr.php"><span>H&amp;R</span><b><?php echo $topHrCount ?></b></a>
				<a class="top-account-stat top-account-stat-link top-account-stat-claim" href="claim.php?uid=<?php echo (int)$CURUSER['id'] ?>"><span>认领</span><b><?php echo $topClaimCount ?></b></a>
			</div>
			<?php if ($enabledonation == 'yes') { ?>
			<a class="top-account-donate-card" href="donate.php">
				<span class="top-account-donate-copy">
					<strong>捐赠权益活动</strong>
					<em>点我看看</em>
				</span>
				<span class="top-account-donate-action">去捐赠</span>
			</a>
			<?php } ?>
			<div class="top-account-links">
				<a class="top-account-menu-item top-account-menu-user" href="usercp.php"><?php echo $topAccountMenuIcon('user') ?><span><?php echo htmlspecialchars($lang_functions['text_user_cp']) ?></span></a>
				<a class="top-account-menu-item top-account-menu-friends" href="friends.php"><?php echo $topAccountMenuIcon('friends') ?><span>好友社交</span></a>
				<a class="top-account-menu-item top-account-menu-medal" href="medal.php"><?php echo $topAccountMenuIcon('medal') ?><span><?php echo htmlspecialchars(nexus_trans('medal.label') ?: '勋章') ?></span></a>
				<a class="top-account-menu-item top-account-menu-task" href="task.php"><?php echo $topAccountMenuIcon('task') ?><span><?php echo htmlspecialchars(nexus_trans('exam.type_task') ?: '任务') ?></span></a>
				<?php if ($showTopStaffPanel) { ?><a class="top-account-menu-item top-account-menu-panel" href="staffpanel.php"><?php echo $topAccountMenuIcon('panel') ?><span><?php echo htmlspecialchars($topStaffPanelLabel) ?></span></a><?php } ?>
				<?php if ($showTopStaff) { ?><a class="top-account-menu-item top-account-menu-staff" href="staff.php"><?php echo $topAccountMenuIcon('staff') ?><span><?php echo htmlspecialchars($topStaffLabel) ?></span></a><?php } ?>
				<?php if ($showTopSiteSettings) { ?><a class="top-account-menu-item top-account-menu-settings" href="settings.php"><?php echo $topAccountMenuIcon('settings') ?><span><?php echo htmlspecialchars($topSiteSettingsLabel) ?></span></a><?php } ?>
				<?php if ($showTopManagementSystem) { ?><a class="top-account-menu-item top-account-menu-management" href="<?php echo htmlspecialchars($topManagementSystemUrl) ?>" target="_blank"><?php echo $topAccountMenuIcon('management') ?><span><?php echo htmlspecialchars($topManagementSystemLabel) ?></span></a><?php } ?>
				<a class="top-account-menu-item top-account-menu-logout" href="logout.php"><?php echo $topAccountMenuIcon('logout') ?><span>退出登录</span></a>
			</div>
		</div>
	</div>
	<div class="top-right-tools">
		<div id="top-message-widget" class="top-message-widget">
			<a class="top-message-trigger" href="messages.php" title="<?php echo htmlspecialchars($topInboxTitle) ?>">
				<span class="top-message-icon" aria-hidden="true">
					<svg viewBox="0 0 20 20" class="top-message-icon-svg" focusable="false">
						<path d="M15.435 17.7717H4.567C2.60143 17.7717 1 16.1723 1 14.2047V5.76702C1 3.80144 2.59942 2.20001 4.567 2.20001H15.433C17.3986 2.20001 19 3.79943 19 5.76702V14.2047C19.002 16.1703 17.4006 17.7717 15.435 17.7717ZM4.567 4.00062C3.59327 4.00062 2.8006 4.79328 2.8006 5.76702V14.2047C2.8006 15.1784 3.59327 15.9711 4.567 15.9711H15.433C16.4067 15.9711 17.1994 15.1784 17.1994 14.2047V5.76702C17.1994 4.79328 16.4067 4.00062 15.433 4.00062H4.567Z" fill="currentColor"></path>
						<path d="M9.99943 11.2C9.51188 11.2 9.02238 11.0667 8.59748 10.8019L8.5407 10.7635L4.3329 7.65675C3.95304 7.37731 3.88842 6.86226 4.18996 6.50976C4.48954 6.15544 5.0417 6.09699 5.4196 6.37643L9.59412 9.45943C9.84279 9.60189 10.1561 9.60189 10.4067 9.45943L14.5812 6.37643C14.9591 6.09699 15.5113 6.15544 15.8109 6.50976C16.1104 6.86409 16.0478 7.37731 15.6679 7.65675L11.4014 10.8019C10.9765 11.0667 10.487 11.2 9.99943 11.2Z" fill="currentColor"></path>
					</svg>
				</span>
				<span class="top-message-text"><?php echo $topMessagesLabel ?></span>
				<?php if ($topUnreadCount > 0) { ?><span class="top-message-badge"><?php echo $topUnreadCount > 99 ? '99+' : $topUnreadCount ?></span><?php } ?>
			</a>
			<div class="top-message-dropdown">
				<a href="messages.php"><span><?php echo htmlspecialchars($topInboxLabel) ?></span><b><?php echo (int)$messages ?></b></a>
				<a href="messages.php?action=viewmailbox&amp;box=-1"><span><?php echo htmlspecialchars($topOutboxLabel) ?></span><b><?php echo (int)$outmessages ?></b></a>
				<?php if (user_can('cheatmanage')) { ?><a href="cheaterbox.php"><span><?php echo htmlspecialchars($lang_functions['title_cheaterbox'] ?? '作弊者') ?></span><b><?php echo (int)$topCheaterCount ?></b></a><?php } ?>
				<?php if (user_can('staffmem')) { ?><a href="reports.php"><span><?php echo htmlspecialchars($lang_functions['title_reportbox'] ?? '举报信箱') ?></span><b><?php echo (int)$topReportCount ?></b></a><?php } ?>
				<?php if ($topStaffMessageCount > 0 || user_can('staffmem')) { ?><a href="staffbox.php"><span><?php echo htmlspecialchars($lang_functions['title_staffbox'] ?? 'Staff Box') ?></span><b><?php echo (int)$topStaffMessageCount ?></b></a><?php } ?>
				<?php if ($showTopContactStaff) { ?><a href="contactstaff.php" class="top-message-link-full"><span><?php echo htmlspecialchars($topContactStaffLabel) ?></span></a><?php } ?>
				<a href="getrss.php" class="top-message-link-full"><span><?php echo htmlspecialchars($lang_functions['title_get_rss'] ?? 'RSS订阅') ?></span></a>
			</div>
		</div>
		<a class="top-shortcut-link top-link-bookmarks" href="torrents.php?inclbookmarked=1&amp;allsec=1&amp;incldead=0">
			<span class="top-link-bookmarks-icon" aria-hidden="true">
				<svg viewBox="0 0 20 20" class="top-link-bookmarks-icon-svg" focusable="false">
					<path d="M10 2.25L12.35 7.01L17.6 7.77L13.8 11.48L14.7 16.7L10 14.23L5.3 16.7L6.2 11.48L2.4 7.77L7.65 7.01L10 2.25Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"></path>
				</svg>
			</span>
			<span class="top-link-bookmarks-text"><?php echo $lang_functions['text_bookmarks'] ?></span>
		</a>
		<button type="button" class="top-shortcut-link top-link-layout" data-layout-toggle aria-label="切换宽屏/窄屏" aria-pressed="true">
			<span class="top-link-layout-icon" aria-hidden="true">
				<svg viewBox="0 0 20 20" class="top-link-layout-icon-svg" focusable="false">
					<path d="M3.5 7.2V3.5H7.2M16.5 7.2V3.5H12.8M7.2 16.5H3.5V12.8M12.8 16.5H16.5V12.8" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round"></path>
					<path d="M7.1 7.1L3.9 3.9M12.9 7.1L16.1 3.9M7.1 12.9L3.9 16.1M12.9 12.9L16.1 16.1" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round"></path>
				</svg>
			</span>
			<span class="top-link-layout-text">宽屏</span>
		</button>
		<?php if ($showTopUpload) { ?>
		<a class="top-shortcut-link top-link-upload" href="upload.php" aria-label="发布">
			<span class="top-link-upload-icon" aria-hidden="true">
				<svg viewBox="0 0 18 18" class="top-link-upload-icon-svg" focusable="false">
					<path d="M12.0824 10H14.1412C15.0508 10 15.7882 10.7374 15.7882 11.6471V12.8824C15.7882 13.792 15.0508 14.5294 14.1412 14.5294H3.84707C2.93743 14.5294 2.20001 13.792 2.20001 12.8824V11.6471C2.20001 10.7374 2.93743 10 3.84707 10H5.90589" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path>
					<path d="M8.99413 11.2353L8.99413 3.82353" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path>
					<path d="M12.0823 6.29413L8.9941 3.20589L5.90587 6.29413" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path>
				</svg>
			</span>
			<span class="top-link-upload-text">&#21457;&#24067;</span>
		</a>
		<?php } ?>
	</div>
</div>

<!-- QD floating side tools: 返回旧版 / 个性化 (injected site-wide via stdhead) -->
<style>
.qd-side-tools{position:fixed;right:14px;top:50%;transform:translateY(-50%);z-index:9990;display:flex;flex-direction:column;gap:0;border-radius:14px;overflow:hidden;box-shadow:var(--bili-shadow-md,0 8px 24px rgba(24,25,28,.14));}
.qd-side-btn{width:58px;height:58px;border:none;border-radius:0;background:var(--bili-surface,#fff);color:var(--bili-primary,#00aeec);box-shadow:none;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;text-decoration:none;line-height:1.05;transition:background .15s ease,color .15s ease;padding:0;}
.qd-side-btn + .qd-side-btn{border-top:1px solid var(--bili-border,#e6e9ef);}
.qd-side-btn:hover{background:var(--bili-surface-soft,#f2f3f5);color:var(--bili-primary-hover,#38bff2);}
.qd-side-btn svg{width:21px;height:21px;margin-bottom:3px;}
.qd-side-btn .qd-side-text{font-size:10px;white-space:nowrap;}
@media (max-width:768px){.qd-side-tools{right:8px;}.qd-side-btn{width:50px;height:50px;}.qd-side-btn svg{width:18px;height:18px;}}
.qd-modal{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;}
.qd-modal[hidden]{display:none;}
.qd-modal-mask{position:absolute;inset:0;background:rgba(0,0,0,.45);}
.qd-modal-card{position:relative;width:340px;max-width:92vw;background:var(--bili-surface,#fff);color:var(--bili-text,#18191c);border-radius:var(--bili-radius-lg,16px);box-shadow:0 20px 60px rgba(0,0,0,.3);padding:20px 22px;}
.qd-modal-card h3{margin:0 0 6px;font-size:17px;}
.qd-modal-card .qd-modal-sub{margin:0 0 14px;font-size:12px;color:var(--bili-text-muted,#9499a0);}
.qd-color-row{display:flex;align-items:center;justify-content:space-between;margin:11px 0;}
.qd-color-row label{font-size:13px;color:var(--bili-text-secondary,#61666d);}
.qd-color-row input[type=color]{width:44px;height:28px;border:1px solid var(--bili-border,#e6e9ef);border-radius:6px;background:none;cursor:pointer;padding:0;}
.qd-presets{display:flex;gap:9px;margin:16px 0 4px;flex-wrap:wrap;align-items:center;}
.qd-presets .qd-presets-label{font-size:12px;color:var(--bili-text-secondary,#61666d);margin-right:2px;}
.qd-preset{width:26px;height:26px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1px var(--bili-border,#e6e9ef);cursor:pointer;}
.qd-modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px;}
.qd-modal-actions button{border:none;border-radius:8px;padding:8px 16px;font-size:13px;cursor:pointer;}
.qd-btn-reset{background:var(--bili-surface-soft,#f2f3f5);color:var(--bili-text-secondary,#61666d);}
.qd-btn-apply{background:var(--bili-primary,#00aeec);color:#fff;}
</style>
<div class="qd-side-tools" id="qd-side-tools">
	<a class="qd-side-btn" href="/old/" title="返回旧版">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"></path><path d="M3 3v5h5"></path></svg>
		<span class="qd-side-text">返回旧版</span>
	</a>
	<button type="button" class="qd-side-btn" data-layout-toggle aria-label="切换宽屏/窄屏" title="切换宽屏/窄屏">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="M9 5v14"></path><path d="M15 5v14"></path></svg>
		<span class="qd-side-text top-link-layout-text">宽屏</span>
	</button>
	<a class="qd-side-btn" href="/attendance.php" title="签到">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4.5" width="18" height="16" rx="2.5"></rect><path d="M3 9.5h18"></path><path d="M8 2.5v4"></path><path d="M16 2.5v4"></path><path d="M8.4 14.6l2.3 2.3 4.9-4.9"></path></svg>
		<span class="qd-side-text">签到</span>
	</a>
	<div class="qd-side-msg-wrap">
	<a class="qd-side-btn" href="messages.php" title="消息">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2.5"></rect><path d="M3.5 6.5l8.5 6 8.5-6"></path></svg>
		<span class="qd-side-text">消息</span>
<?php if (isset($topUnreadCount) && $topUnreadCount > 0) { ?>		<span class="qd-side-badge"><?php echo $topUnreadCount > 99 ? '99+' : $topUnreadCount ?></span>
<?php } ?>	</a>
		<div class="qd-side-submenu">
			<a href="messages.php"><span><?php echo htmlspecialchars($topInboxLabel ?? '收件箱') ?></span><b><?php echo (int)($messages ?? 0) ?></b></a>
			<a href="messages.php?action=viewmailbox&amp;box=-1"><span><?php echo htmlspecialchars($topOutboxLabel ?? '发件箱') ?></span><b><?php echo (int)($outmessages ?? 0) ?></b></a>
<?php if (function_exists('user_can') && user_can('cheatmanage')) { ?>			<a href="cheaterbox.php"><span><?php echo htmlspecialchars($lang_functions['title_cheaterbox'] ?? '作弊者') ?></span><b><?php echo (int)($topCheaterCount ?? 0) ?></b></a>
<?php } if (function_exists('user_can') && user_can('staffmem')) { ?>			<a href="reports.php"><span><?php echo htmlspecialchars($lang_functions['title_reportbox'] ?? '举报信箱') ?></span><b><?php echo (int)($topReportCount ?? 0) ?></b></a>
<?php } if ((isset($topStaffMessageCount) && $topStaffMessageCount > 0) || (function_exists('user_can') && user_can('staffmem'))) { ?>			<a href="staffbox.php"><span><?php echo htmlspecialchars($lang_functions['title_staffbox'] ?? 'Staff Box') ?></span><b><?php echo (int)($topStaffMessageCount ?? 0) ?></b></a>
<?php } if (!empty($showTopContactStaff)) { ?>			<a href="contactstaff.php"><span><?php echo htmlspecialchars($topContactStaffLabel ?? '联系管理组') ?></span></a>
<?php } ?>			<a href="getrss.php"><span><?php echo htmlspecialchars($lang_functions['title_get_rss'] ?? 'RSS订阅') ?></span></a>
		</div>
	</div>
	<a class="qd-side-btn" href="torrents.php?inclbookmarked=1&amp;allsec=1&amp;incldead=0" title="收藏">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5l2.6 5.3 5.9.86-4.25 4.14 1 5.86L12 17.1l-5.25 2.76 1-5.86L3.5 9.66l5.9-.86L12 3.5z"></path></svg>
		<span class="qd-side-text">收藏</span>
	</a>
	<button type="button" class="qd-side-btn" id="qd-bank-btn" title="高清银行">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10l9-6 9 6"></path><path d="M4 10v9h16v-9"></path><path d="M8 19v-6M12 19v-6M16 19v-6"></path><path d="M3 21h18"></path></svg>
		<span class="qd-side-text">高清银行</span>
	</button>
	<button type="button" class="qd-side-btn" id="qd-personalize-btn" title="个性化配色">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r="1.3"></circle><circle cx="17.5" cy="10.5" r="1.3"></circle><circle cx="8.5" cy="7.5" r="1.3"></circle><circle cx="6.5" cy="12.5" r="1.3"></circle><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10c.93 0 1.5-.75 1.5-1.5 0-.4-.18-.74-.42-1-.24-.27-.42-.6-.42-1 0-.83.67-1.5 1.5-1.5H16c3.31 0 6-2.69 6-6 0-4.42-4.48-8-10-8z"></path></svg>
		<span class="qd-side-text">个性化</span>
	</button>
	<a class="qd-side-btn" href="games/" title="游戏大厅">
		<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6.35 7.9H4.75M5.55 7.1V8.7M13.05 7.9H13.06M15.25 9.55H15.26"></path><path d="M6.05 5.1H13.95C15.3 5.1 16.45 6.08 16.67 7.41L17.25 10.9C17.55 12.69 16.17 14.32 14.35 14.32C13.47 14.32 12.64 13.93 12.08 13.25L11.53 12.58H8.47L7.92 13.25C7.36 13.93 6.53 14.32 5.65 14.32C3.83 14.32 2.45 12.69 2.75 10.9L3.33 7.41C3.55 6.08 4.7 5.1 6.05 5.1Z"></path></svg>
		<span class="qd-side-text">游戏大厅</span>
	</a>
</div>
<style>
.qd-bank-bal{display:flex;gap:10px;margin:6px 0 12px;}
.qd-bank-bal div{flex:1;text-align:center;background:var(--bili-surface-soft,#f2f3f5);border-radius:10px;padding:9px 6px;}
.qd-bank-bal .v{font-size:16px;font-weight:800;}
.qd-bank-bal .k{font-size:11px;color:var(--bili-text-muted,#9499a0);margin-top:2px;}
.qd-bank-rate{font-size:12px;color:var(--bili-text-secondary,#61666d);margin:0 0 12px;}
.qd-bank-amt{width:100%;box-sizing:border-box;border:1px solid var(--bili-border,#e6e9ef);border-radius:8px;padding:9px 10px;font-size:15px;background:var(--bili-surface,#fff);color:var(--bili-text,#18191c);}
.qd-bank-acts{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px;}
.qd-bank-acts button{border:none;border-radius:8px;padding:9px 0;font-size:13px;font-weight:700;cursor:pointer;color:#fff;}
.qd-bank-deposit{background:#2e8b57;}.qd-bank-withdraw{background:#3a6ea5;}
.qd-bank-borrow{background:#c0883a;}.qd-bank-repay{background:#c0392b;}
.qd-bank-acts button:disabled{opacity:.5;cursor:not-allowed;}
.qd-bank-msg{margin-top:10px;font-size:13px;font-weight:700;min-height:18px;text-align:center;}
.qd-bank-sec{border-top:1px solid var(--bili-border,#e6e9ef);margin-top:12px;padding-top:12px;}
.qd-bank-sec h4{margin:0 0 8px;font-size:13px;display:flex;justify-content:space-between;align-items:baseline;gap:8px;}
.qd-bank-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.qd-bank-row .qd-bank-amt{flex:1;min-width:110px;}
.qd-bank-sel{border:1px solid var(--bili-border,#e6e9ef);border-radius:8px;padding:8px;background:var(--bili-surface,#fff);color:var(--bili-text,#18191c);cursor:pointer;}
.qd-bank-info{font-size:11px;font-weight:400;color:var(--bili-text-secondary,#61666d);}
.qd-bank-info b{color:var(--bili-text,#18191c);}
.qd-bank-info .warn{color:#c0392b;font-weight:700;}
.qd-bank-detail{font-size:12px;color:var(--bili-text-secondary,#61666d);margin-bottom:8px;line-height:1.6;}
.qd-b1{padding:9px 14px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;color:#fff;}
.qd-bank-bal{flex-wrap:wrap;}
.qd-bank-bal>div{min-width:64px;}
#qd-bank-modal .qd-modal-card{width:460px;}
@media (max-width:520px){#qd-bank-modal .qd-modal-card{width:92vw;}}
html[data-site-theme="night"] #qd-bank-modal .qd-modal-card{background:#0e1728;color:#d9e2f4;}
html[data-site-theme="night"] #qd-bank-modal h3,
html[data-site-theme="night"] #qd-bank-modal h4{color:#eaf1ff;}
html[data-site-theme="night"] #qd-bank-modal .qd-modal-sub,
html[data-site-theme="night"] #qd-bank-modal .qd-bank-info,
html[data-site-theme="night"] #qd-bank-modal .qd-bank-detail,
html[data-site-theme="night"] #qd-bank-modal .qd-bank-bal .k{color:#9fb0c8;}
html[data-site-theme="night"] #qd-bank-modal .qd-bank-info b,
html[data-site-theme="night"] #qd-bank-modal .qd-bank-detail b{color:#eaf1ff;}
html[data-site-theme="night"] #qd-bank-modal .qd-bank-bal div,
html[data-site-theme="night"] #qd-bank-modal .qd-bank-amt,
html[data-site-theme="night"] #qd-bank-modal .qd-bank-sel{background:#16223a;color:#d9e2f4;border-color:rgba(116,145,196,.4);}
html[data-site-theme="night"] #qd-bank-modal .qd-bank-sec{border-top-color:rgba(116,145,196,.25);}
html[data-site-theme="night"] #qd-bank-modal .qd-btn-reset{background:#16223a;color:#cdd9ee;}
</style>
<div class="qd-modal" id="qd-bank-modal" hidden>
	<div class="qd-modal-mask" data-qd-close></div>
	<div class="qd-modal-card">
		<h3>🏦 高清银行</h3>
		<p class="qd-modal-sub">活期随存随取，定期到期高息，急用可借（逾期每天加收费用）。</p>
		<div class="qd-bank-bal">
			<div><div class="v" id="qd-bank-wallet">-</div><div class="k">钱包</div></div>
			<div><div class="v" id="qd-bank-cur" style="color:#2e8b57">-</div><div class="k">活期</div></div>
			<div><div class="v" id="qd-bank-fix" style="color:#8e44ad">-</div><div class="k">定期</div></div>
			<div><div class="v" id="qd-bank-loanbal" style="color:#c0392b">-</div><div class="k">欠款</div></div>
		</div>
		<div class="qd-bank-detail" id="qd-bank-credit" style="text-align:center;margin:2px 0 4px"></div>
		<div class="qd-bank-detail" id="qd-bank-pool" style="text-align:center;margin:0 0 4px;font-size:11px"></div>

		<div class="qd-bank-sec">
			<h4>活期存款 <span class="qd-bank-info" id="qd-bank-curr"></span></h4>
			<div class="qd-bank-row">
				<input type="number" class="qd-bank-amt" id="qd-amt-cur" min="1" placeholder="金额">
				<button type="button" class="qd-b1 qd-bank-deposit" data-bank="deposit" data-amt="qd-amt-cur">存入</button>
				<button type="button" class="qd-b1 qd-bank-withdraw" data-bank="withdraw" data-amt="qd-amt-cur">取出</button>
			</div>
		</div>

		<div class="qd-bank-sec" id="qd-bank-transfer-sec">
			<h4>转账给用户 <span class="qd-bank-info">从钱包电影票转出，对方即时到账</span></h4>
			<div class="qd-bank-row">
				<input type="text" class="qd-bank-amt" id="qd-tx-to" placeholder="收款人用户名">
				<input type="number" class="qd-bank-amt" id="qd-amt-tx" min="1" placeholder="金额">
				<button type="button" class="qd-b1 qd-bank-withdraw" data-bank="transfer" data-amt="qd-amt-tx" data-to="qd-tx-to">转账</button>
			</div>
		</div>

		<div class="qd-bank-sec">
			<h4>定期存款 <span class="qd-bank-info" id="qd-bank-fixr"></span></h4>
			<div id="qd-bank-fix-none">
				<div class="qd-bank-row">
					<select class="qd-bank-sel" id="qd-fix-term"></select>
					<input type="number" class="qd-bank-amt" id="qd-amt-fix" min="1" placeholder="金额">
					<button type="button" class="qd-b1 qd-bank-borrow" data-bank="deposit_fix" data-amt="qd-amt-fix" data-term="qd-fix-term">存定期</button>
				</div>
			</div>
			<div id="qd-bank-fix-has" style="display:none">
				<div class="qd-bank-detail" id="qd-bank-fix-detail"></div>
				<button type="button" class="qd-b1 qd-bank-withdraw" data-bank="withdraw_fix">取出定期</button>
			</div>
		</div>

		<div class="qd-bank-sec">
			<h4>借款 <span class="qd-bank-info" id="qd-bank-loanr"></span></h4>
			<div id="qd-bank-loan-none">
				<div class="qd-bank-row">
					<select class="qd-bank-sel" id="qd-loan-term"></select>
					<input type="number" class="qd-bank-amt" id="qd-amt-borrow" min="1" placeholder="金额">
					<button type="button" class="qd-b1 qd-bank-borrow" data-bank="borrow" data-amt="qd-amt-borrow" data-term="qd-loan-term" data-guar="qd-loan-guar">借款</button>
				</div>
				<input type="text" class="qd-bank-amt" id="qd-loan-guar" placeholder="担保人用户名（≥100万需担保，逗号分隔）" style="margin-top:6px">
			</div>
			<div id="qd-bank-app" style="display:none">
				<div class="qd-bank-detail" id="qd-bank-app-detail"></div>
				<button type="button" class="qd-b1 qd-bank-repay" data-bank="cancel_app">取消申请</button>
			</div>
			<div id="qd-bank-loan-has" style="display:none">
				<div class="qd-bank-detail" id="qd-bank-loan-detail"></div>
				<div class="qd-bank-row">
					<input type="number" class="qd-bank-amt" id="qd-amt-repay" min="1" placeholder="还款金额">
					<button type="button" class="qd-b1 qd-bank-repay" data-bank="repay" data-amt="qd-amt-repay">还款</button>
				</div>
			</div>
		</div>

		<div class="qd-bank-sec" id="qd-bank-special" style="display:none">
			<h4>特殊业务 <span class="qd-bank-info" id="qd-bank-special-info"></span></h4>
			<div class="qd-bank-row" id="qd-bank-special-btns"></div>
			<div class="qd-bank-detail" id="qd-bank-myreq" style="margin-top:6px"></div>
		</div>

		<div class="qd-bank-sec" id="qd-bank-guarantee-sec" style="display:none">
			<h4>担保请求</h4>
			<div id="qd-bank-guarantee-list"></div>
		</div>

		<div class="qd-bank-msg" id="qd-bank-msg"></div>
		<div class="qd-modal-actions"><button type="button" class="qd-btn-reset" data-qd-close>关闭</button></div>
	</div>
</div>
<script>
(function () {
	var modal = document.getElementById('qd-bank-modal'), btn = document.getElementById('qd-bank-btn');
	if (!modal || !btn) return;
	var msg = document.getElementById('qd-bank-msg');
	var busy = false, termsSet = false;
	function $(id) { return document.getElementById(id); }
	function fmt(n) { return Number(Math.round((n || 0) * 100) / 100).toLocaleString('en-US', { maximumFractionDigits: 2 }); }
	function inputAmount(n) { return String(Math.round((Number(n) || 0) * 100) / 100); }
	function dateStr(ts) { if (!ts) return '-'; var d = new Date(ts * 1000); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
	function setTerms(d) {
		if (termsSet) return; termsSet = true;
		var fs = $('qd-fix-term');
		if (fs) fs.innerHTML = (d.fix_tiers || []).map(function (t) { return '<option value="' + t.days + '">' + t.days + '天 · 年化' + t.annual + '%</option>'; }).join('');
		var ls = $('qd-loan-term');
		if (ls) ls.innerHTML = (d.loan_tiers || []).map(function (t) { return '<option value="' + t.periods + '">' + t.periods + '期(' + (t.periods * 30) + '天) · 月息' + t.rate_m + '%</option>'; }).join('');
	}
	function show(id, on) { var e = $(id); if (e) e.style.display = on ? '' : 'none'; }
	function paint(d) {
		setTerms(d);
		$('qd-bank-wallet').textContent = fmt(d.wallet);
		$('qd-bank-cur').textContent = fmt(d.cur_deposit);
		$('qd-bank-fix').textContent = fmt(d.fix ? d.fix.value_now : 0);
		$('qd-bank-loanbal').textContent = fmt(d.loan ? d.loan.owed : 0);
		$('qd-bank-credit').innerHTML = '信用等级 <b style="color:#8e44ad">' + d.credit.grade + '</b> · 评分 ' + d.credit.score + ' · 分享率 ' + d.credit.ratio + ' · 可借上限 <b>' + fmt(d.credit.max_loan) + '</b>';
		if (d.pool) { $('qd-bank-pool').innerHTML = '🏛 资金池存款 ' + fmt(d.pool.deposits) + ' · 风险准备金 ' + fmt(d.pool.risk_reserve) + ' · 待分红 ' + fmt(d.pool.dividend_pool) + '（每季度按存款占比派发）'; }
		show('qd-bank-transfer-sec', d.transfer_enabled !== false);
		$('qd-bank-curr').innerHTML = '年化 <b>' + d.cur_annual + '%</b> · 满24h起息';
		$('qd-bank-fixr').innerHTML = '到期得全额利息，提前取只退本金';
		$('qd-bank-loanr').innerHTML = d.can_borrow ? '按信用等级定额度，分期月息见下' : '<span class="warn">' + d.borrow_block + '</span>';
		if (d.fix) {
			show('qd-bank-fix-none', false); show('qd-bank-fix-has', true);
			$('qd-bank-fix-detail').innerHTML = '本金 <b>' + fmt(d.fix.principal) + '</b> · 年化 ' + d.fix.annual + '% · ' + d.fix.term + '天 · 到期 ' + dateStr(d.fix.due_ts) + '（' + (d.fix.matured ? '<b style="color:#2e8b57">已到期</b>' : '未到期') + '）<br>到期可得 <b>' + fmt(d.fix.mature_value) + '</b>，现在取出 <b>' + fmt(d.fix.value_now) + '</b>（提前取利息失效）';
		} else { show('qd-bank-fix-none', true); show('qd-bank-fix-has', false); }
		if (d.my_app) {
			show('qd-bank-app', true); show('qd-bank-loan-none', false); show('qd-bank-loan-has', false); show('qd-bank-special', false);
			var gl = (d.my_app.guarantors || []).map(function (g) {
				var t = g.status === 'agreed' ? '<b style="color:#2e8b57">已同意</b>' : (g.status === 'rejected' ? '<span class="warn">已拒绝</span>' : '待确认');
				return g.name + '：' + t;
			}).join('，');
			$('qd-bank-app-detail').innerHTML = '⏳ 贷款申请审核中：' + fmt(d.my_app.amount) + ' · ' + d.my_app.periods + '期 · 保证金' + d.my_app.margin_pct + '%<br>担保人 ' + gl;
		} else if (d.loan) {
			show('qd-bank-app', false); show('qd-bank-loan-none', false); show('qd-bank-loan-has', true);
			var od = d.loan.overdue ? ('<span class="warn">已逾期 ' + d.loan.overdue_days + ' 天</span>') : ('到期 ' + dateStr(d.loan.due_ts));
			var mg = d.loan.margin > 0 ? ('（含冻结保证金 ' + fmt(d.loan.margin) + '）') : '';
			var fz = d.loan.frozen ? ' · <b style="color:#8e44ad">已暂停计息</b>' : '';
			var ins = d.loan.insured ? ' · <b style="color:#2e8b57">已投保</b>' : '';
			$('qd-bank-loan-detail').innerHTML = '当前欠款 <b style="color:#c0392b">' + fmt(d.loan.owed) + '</b>（本金 ' + fmt(d.loan.principal) + '）· ' + d.loan.periods + '期 · 月息 ' + d.loan.rate_m + '% · ' + od + mg + fz + ins + (d.restricted ? '<br><span class="warn">⚠ 逾期已暂停娱乐功能，并启用每日自动还款/担保代偿</span>' : '');
			var repayInput = $('qd-amt-repay'); if (repayInput) repayInput.value = inputAmount(d.loan.owed);
			// 特殊业务
			show('qd-bank-special', true);
			var sb = '';
			if (!d.loan.insured) sb += '<button type="button" class="qd-b1 qd-bank-withdraw" data-bank="buy_insurance">购买保险(' + d.insurance_pct + '%)</button>';
			else sb += '<button type="button" class="qd-b1 qd-bank-deposit" data-bank="apply_request" data-type="insurance_claim">申请保险理赔</button>';
			sb += '<button type="button" class="qd-b1 qd-bank-borrow" data-bank="apply_request" data-type="bankruptcy">申请破产保护</button>';
			sb += '<button type="button" class="qd-b1 qd-bank-borrow" data-bank="apply_request" data-type="restructure">申请债务重组</button>';
			$('qd-bank-special-btns').innerHTML = sb;
			var mr = (d.my_requests || []).map(function (r) { return r.label; }).join('，');
			$('qd-bank-myreq').innerHTML = mr ? ('待审核申请：' + mr) : '';
		} else {
			show('qd-bank-app', false); show('qd-bank-loan-none', true); show('qd-bank-loan-has', false); show('qd-bank-special', false);
			var bb = modal.querySelector('[data-bank="borrow"]'); if (bb) bb.disabled = !d.can_borrow;
		}
		var reqs = d.guarantee_requests || [];
		show('qd-bank-guarantee-sec', reqs.length > 0);
		if (reqs.length > 0) {
			$('qd-bank-guarantee-list').innerHTML = reqs.map(function (r) {
				return '<div class="qd-bank-detail" style="border-top:1px dashed var(--bili-border,#e6e9ef);padding-top:8px">' + r.borrower + ' 申请借款 <b>' + fmt(r.amount) + '</b>（' + r.periods + '期），邀请你担保。<div class="qd-bank-row" style="margin-top:6px"><button type="button" class="qd-b1 qd-bank-deposit" data-bank="guarantee_agree" data-app="' + r.app_id + '">同意担保</button><button type="button" class="qd-b1 qd-bank-repay" data-bank="guarantee_reject" data-app="' + r.app_id + '">拒绝</button></div></div>';
			}).join('');
		}
	}
	function load() { fetch('/bank.php?action=status', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) { if (d.ok) paint(d); }).catch(function () {}); }
	var noAmount = { withdraw_fix: 1, cancel_app: 1, guarantee_agree: 1, guarantee_reject: 1, buy_insurance: 1, apply_request: 1 };
	function act(b) {
		if (busy) return;
		var action = b.getAttribute('data-bank');
		var amtId = b.getAttribute('data-amt'), termId = b.getAttribute('data-term'), guarId = b.getAttribute('data-guar'), appId = b.getAttribute('data-app'), rtype = b.getAttribute('data-type'), toId = b.getAttribute('data-to');
		var amount = amtId && $(amtId) ? parseFloat($(amtId).value) : 0;
		if (!noAmount[action] && !(amount > 0)) { msg.style.color = '#c0392b'; msg.textContent = '请输入金额'; if (amtId && $(amtId)) $(amtId).focus(); return; }
		var reason = null;
		if (action === 'apply_request') { reason = window.prompt('请简述申请理由（供管理员审核）：', ''); if (reason === null) return; }
		if (action === 'buy_insurance' && !window.confirm('购买保险将按贷款额收取 1% 保费，确定？')) return;
		busy = true; msg.style.color = '#61666d'; msg.textContent = '处理中…';
		var body = 'action=' + action + '&amount=' + encodeURIComponent(amount || 0);
		if (termId && $(termId)) body += '&term=' + encodeURIComponent($(termId).value);
		if (guarId && $(guarId)) body += '&guarantors=' + encodeURIComponent($(guarId).value);
		if (toId && $(toId)) body += '&to=' + encodeURIComponent($(toId).value);
		if (appId) body += '&app_id=' + encodeURIComponent(appId);
		if (rtype) body += '&type=' + encodeURIComponent(rtype);
		if (reason !== null) body += '&reason=' + encodeURIComponent(reason);
		fetch('/bank.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
			.then(function (r) { return r.json(); }).then(function (d) {
				if (!d.ok) { msg.style.color = '#c0392b'; msg.textContent = d.error || '出错了'; return; }
				paint(d); if (amtId && amtId !== 'qd-amt-repay' && $(amtId)) $(amtId).value = '';
				msg.style.color = '#16a34a'; msg.textContent = '操作成功';
			}).catch(function () { msg.style.color = '#c0392b'; msg.textContent = '网络错误'; })
			.finally(function () { busy = false; });
	}
	window.hdvideoOpenBank = function () { modal.hidden = false; msg.textContent = ''; load(); };
	btn.addEventListener('click', window.hdvideoOpenBank);
	modal.addEventListener('click', function (e) {
		var c = e.target.closest ? e.target.closest('[data-qd-close]') : null;
		if (c && modal.contains(c)) { modal.hidden = true; return; }
		var b = e.target.closest ? e.target.closest('[data-bank]') : null;
		if (b && modal.contains(b)) act(b);
	});
})();
</script>
<style>
.qd-swatch{width:48px;height:28px;border:1px solid var(--bili-border,#e6e9ef);border-radius:6px;cursor:pointer;padding:0;box-shadow:inset 0 0 0 1px rgba(255,255,255,.5);}
.qd-picker{position:absolute;z-index:10002;width:212px;background:var(--bili-surface,#fff);border:1px solid var(--bili-border,#e6e9ef);border-radius:12px;box-shadow:0 14px 44px rgba(0,0,0,.3);padding:12px;}
.qd-picker[hidden]{display:none;}
.qd-pk-sv{position:relative;width:100%;height:128px;border-radius:8px;cursor:crosshair;overflow:hidden;}
.qd-pk-sv-thumb{position:absolute;width:12px;height:12px;border:2px solid #fff;border-radius:50%;box-shadow:0 0 0 1px rgba(0,0,0,.45);transform:translate(-50%,-50%);pointer-events:none;}
.qd-pk-hue{position:relative;width:100%;height:14px;margin:12px 0 10px;border-radius:7px;cursor:pointer;background:linear-gradient(to right,#f00 0%,#ff0 17%,#0f0 33%,#0ff 50%,#00f 67%,#f0f 83%,#f00 100%);}
.qd-pk-hue-thumb{position:absolute;top:50%;width:16px;height:16px;border:2px solid #fff;border-radius:50%;box-shadow:0 0 0 1px rgba(0,0,0,.45);transform:translate(-50%,-50%);pointer-events:none;}
.qd-pk-foot{display:flex;gap:8px;align-items:center;}
.qd-pk-hex{flex:1;min-width:0;border:1px solid var(--bili-border,#e6e9ef);border-radius:6px;padding:6px 8px;font-size:13px;text-transform:uppercase;background:var(--bili-surface,#fff);color:var(--bili-text,#18191c);}
.qd-pk-ok{background:var(--bili-primary,#00aeec);color:#fff;border:none;border-radius:6px;padding:7px 14px;font-size:13px;cursor:pointer;white-space:nowrap;}
</style>
<div class="qd-modal" id="qd-personalize-modal" hidden>
	<div class="qd-modal-mask" data-qd-close></div>
	<div class="qd-modal-card">
		<h3>个性化配色</h3>
		<p class="qd-modal-sub">点色块选颜色，实时预览；应用后保存到账号，换设备登录也生效。</p>
		<div class="qd-color-row"><label>预设配色</label><select id="qd-preset-select" style="flex:0 0 auto;max-width:140px;border:1px solid var(--bili-border,#e6e9ef);border-radius:6px;padding:6px 8px;font-size:13px;background:var(--bili-surface,#fff);color:var(--bili-text,#18191c);cursor:pointer;"><option value="">— 选择预设 —</option><option value="0">默认</option><option value="1">樱花粉</option><option value="2">抹茶绿</option><option value="3">海洋蓝</option><option value="4">暮光紫</option><option value="5">落日橙</option><option value="6">性感紫</option><option value="7">妖娆紫</option><option value="8">魅惑紫</option><option value="9">清纯粉</option></select></div>
		<div class="qd-color-row"><label>主色调</label><button type="button" class="qd-swatch" data-var="--bili-primary"></button></div>
		<div class="qd-color-row"><label>强调色</label><button type="button" class="qd-swatch" data-var="--bili-accent"></button></div>
		<div class="qd-color-row"><label>页面背景</label><button type="button" class="qd-swatch" data-var="--bili-bg"></button></div>
		<div class="qd-color-row"><label>卡片背景</label><button type="button" class="qd-swatch" data-var="--bili-surface"></button></div>
		<div class="qd-color-row"><label>文字颜色</label><button type="button" class="qd-swatch" data-var="--bili-text"></button></div>
		<div class="qd-modal-actions">
			<button type="button" class="qd-btn-reset" id="qd-color-reset">恢复默认</button>
			<button type="button" class="qd-btn-apply" id="qd-color-apply">应用</button>
		</div>
		<div class="qd-picker" id="qd-picker" hidden>
			<div class="qd-pk-sv" id="qd-pk-sv"><div class="qd-pk-sv-thumb" id="qd-pk-sv-thumb"></div></div>
			<div class="qd-pk-hue" id="qd-pk-hue"><div class="qd-pk-hue-thumb" id="qd-pk-hue-thumb"></div></div>
			<div class="qd-pk-foot"><input type="text" class="qd-pk-hex" id="qd-pk-hex" maxlength="7" spellcheck="false"><button type="button" class="qd-pk-ok" id="qd-pk-ok">确定</button></div>
		</div>
	</div>
</div>
<script>
(function(){
	var KEY='qd_custom_colors';
	var VARS=['--bili-primary','--bili-accent','--bili-bg','--bili-surface','--bili-text'];
	var root=document.documentElement;
	function getSaved(){if(window.__QD_P__&&typeof window.__QD_P__==='object'){return window.__QD_P__;}try{return JSON.parse(localStorage.getItem(KEY)||'{}')||{};}catch(e){return {};}}
	function isNight(){return root.getAttribute('data-site-theme')==='night'||(document.body&&document.body.classList.contains('theme-night'));}function applySaved(){var c=getSaved();for(var i=0;i<VARS.length;i++){if(isNight()||!c||!c[VARS[i]]){root.style.removeProperty(VARS[i]);}else{root.style.setProperty(VARS[i],c[VARS[i]]);}}}try{var qdMO=new MutationObserver(function(){applySaved();});qdMO.observe(root,{attributes:true,attributeFilter:['data-site-theme','class']});if(document.body){qdMO.observe(document.body,{attributes:true,attributeFilter:['class']});}}catch(e){}
	applySaved();
	function curVal(v){var s=getComputedStyle(root).getPropertyValue(v).trim();return s||'#000000';}
	function toHex(c){
		if(/^#([0-9a-f]{6})$/i.test(c)){return c.toLowerCase();}
		if(/^#([0-9a-f]{3})$/i.test(c)){return ('#'+c[1]+c[1]+c[2]+c[2]+c[3]+c[3]).toLowerCase();}
		var m=c.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
		if(m){var h='#';for(var i=1;i<=3;i++){h+=('0'+parseInt(m[i],10).toString(16)).slice(-2);}return h;}
		return '#00aeec';
	}
	function hex2rgb(h){h=h.replace('#','');return [parseInt(h.slice(0,2),16),parseInt(h.slice(2,4),16),parseInt(h.slice(4,6),16)];}
	function rgb2hex(r,g,b){return '#'+[r,g,b].map(function(x){return ('0'+Math.round(Math.max(0,Math.min(255,x))).toString(16)).slice(-2);}).join('');}
	function rgb2hsv(r,g,b){r/=255;g/=255;b/=255;var mx=Math.max(r,g,b),mn=Math.min(r,g,b),d=mx-mn,h=0;if(d){if(mx===r)h=(((g-b)/d)%6+6)%6;else if(mx===g)h=(b-r)/d+2;else h=(r-g)/d+4;h*=60;}return [h,mx?d/mx:0,mx];}
	function hsv2rgb(h,s,v){var c=v*s,x=c*(1-Math.abs((h/60)%2-1)),m=v-c,r=0,g=0,b=0;if(h<60){r=c;g=x;}else if(h<120){r=x;g=c;}else if(h<180){g=c;b=x;}else if(h<240){g=x;b=c;}else if(h<300){r=x;b=c;}else{r=c;b=x;}return [(r+m)*255,(g+m)*255,(b+m)*255];}

	var modal=document.getElementById('qd-personalize-modal');
	var openBtn=document.getElementById('qd-personalize-btn');
	function swatches(){return modal.querySelectorAll('.qd-swatch');}
	function setSwatch(el,hex){el.style.setProperty('background',hex,'important');el.setAttribute('data-color',hex);}
	function openModal(){var c=getSaved();var sw=swatches();for(var i=0;i<sw.length;i++){var v=sw[i].getAttribute('data-var');setSwatch(sw[i],toHex((c&&c[v])||curVal(v)));}modal.hidden=false;}
	function closeModal(){modal.hidden=true;picker.hidden=true;}
	if(openBtn){openBtn.addEventListener('click',openModal);}

	var picker=document.getElementById('qd-picker');
	var svEl=document.getElementById('qd-pk-sv'),svThumb=document.getElementById('qd-pk-sv-thumb');
	var hueEl=document.getElementById('qd-pk-hue'),hueThumb=document.getElementById('qd-pk-hue-thumb');
	var hexInput=document.getElementById('qd-pk-hex'),okBtn=document.getElementById('qd-pk-ok');
	var curSwatch=null,H=0,S=1,V=1;
	function paint(live){
		svEl.style.background='linear-gradient(to top,#000,rgba(0,0,0,0)),linear-gradient(to right,#fff,rgba(255,255,255,0)),'+rgb2hex.apply(null,hsv2rgb(H,1,1));
		svThumb.style.left=(S*100)+'%';svThumb.style.top=((1-V)*100)+'%';
		hueThumb.style.left=(H/360*100)+'%';
		var hex=rgb2hex.apply(null,hsv2rgb(H,S,V));
		hexInput.value=hex.toUpperCase();
		if(curSwatch){setSwatch(curSwatch,hex);if(live!==false){root.style.setProperty(curSwatch.getAttribute('data-var'),hex);}}
		return hex;
	}
	function openPicker(sw){
		curSwatch=sw;
		var hsv=rgb2hsv.apply(null,hex2rgb(toHex(sw.getAttribute('data-color')||curVal(sw.getAttribute('data-var')))));
		H=hsv[0];S=hsv[1];V=hsv[2];
		picker.hidden=false;
		var card=sw.closest('.qd-modal-card'),cr=card.getBoundingClientRect(),sr=sw.getBoundingClientRect();
		picker.style.left=Math.min(card.clientWidth-224,sr.left-cr.left)+'px';
		picker.style.top=(sr.bottom-cr.top+6)+'px';
		paint(false);
	}
	modal.addEventListener('click',function(e){if(e.target&&e.target.hasAttribute&&e.target.hasAttribute('data-qd-close')){applySaved();closeModal();return;}var sw=e.target.closest?e.target.closest('.qd-swatch'):null;if(sw){e.stopPropagation();openPicker(sw);}else if(!e.target.closest('.qd-picker')){picker.hidden=true;}});
	function dragSV(e){var r=svEl.getBoundingClientRect();var x=((e.touches?e.touches[0].clientX:e.clientX)-r.left)/r.width;var y=((e.touches?e.touches[0].clientY:e.clientY)-r.top)/r.height;S=Math.max(0,Math.min(1,x));V=Math.max(0,Math.min(1,1-y));paint();}
	function dragHue(e){var r=hueEl.getBoundingClientRect();var x=((e.touches?e.touches[0].clientX:e.clientX)-r.left)/r.width;H=Math.max(0,Math.min(359.99,x*360));paint();}
	function bindDrag(el,fn){el.addEventListener('mousedown',function(e){e.preventDefault();fn(e);function mv(ev){fn(ev);}function up(){document.removeEventListener('mousemove',mv);document.removeEventListener('mouseup',up);}document.addEventListener('mousemove',mv);document.addEventListener('mouseup',up);});el.addEventListener('touchstart',function(e){fn(e);function mv(ev){ev.preventDefault();fn(ev);}function up(){document.removeEventListener('touchmove',mv);document.removeEventListener('touchend',up);}document.addEventListener('touchmove',mv,{passive:false});document.addEventListener('touchend',up);},{passive:false});}
	bindDrag(svEl,dragSV);bindDrag(hueEl,dragHue);
	hexInput.addEventListener('input',function(){var val=hexInput.value.trim().replace('#','');if(/^[0-9a-fA-F]{6}$/.test(val)){var hsv=rgb2hsv.apply(null,hex2rgb('#'+val));H=hsv[0];S=hsv[1];V=hsv[2];paint();}});
	okBtn.addEventListener('click',function(){picker.hidden=true;});

	var PRESETS=[['#00aeec','#fb7299','#f6f7fb','#ffffff','#18191c'],['#fb7299','#f8a5c2','#fcdfe9','#fbcfdf','#5a3a44'],['#689f38','#9ccc65','#d3e8bb','#c6e0a8','#2e3d22'],['#1976d2','#4fc3f7','#cfe5f6','#bfddf2','#13314a'],['#7c4dff','#b388ff','#ddd0f5','#cfbef0','#2a2340'],['#f4511e','#ff8a65','#ffdcc9','#ffceb6','#4a2818'],['#9c27b0','#ff4081','#ecd6f4','#ddbbeb','#3b1d49'],['#ba68c8','#f06292','#f2e2f7','#e6cdf0','#45284f'],['#6a1b9a','#c2185b','#e3cbee','#cfaae2','#2f1340'],['#f48fb1','#f8bbd0','#fdf0f5','#fcdde9','#5a3a48']];
	var psel=document.getElementById('qd-preset-select');
	if(psel){psel.addEventListener('change',function(){var idx=parseInt(psel.value,10);if(isNaN(idx)||!PRESETS[idx]){return;}var preset=PRESETS[idx];var sw=swatches();for(var i=0;i<sw.length;i++){setSwatch(sw[i],preset[i]);root.style.setProperty(VARS[i],preset[i]);}picker.hidden=true;});}

	document.getElementById('qd-color-apply').addEventListener('click',function(){var c={},sw=swatches();for(var i=0;i<sw.length;i++){var v=sw[i].getAttribute('data-var'),hex=sw[i].getAttribute('data-color');c[v]=hex;root.style.setProperty(v,hex);}try{localStorage.setItem(KEY,JSON.stringify(c));}catch(e){}window.__QD_P__=c;try{fetch('ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=savePersonalize&params%5Bdata%5D='+encodeURIComponent(JSON.stringify(c))});}catch(e){}closeModal();});
	document.getElementById('qd-color-reset').addEventListener('click',function(){try{localStorage.removeItem(KEY);}catch(e){}for(var i=0;i<VARS.length;i++){root.style.removeProperty(VARS[i]);}window.__QD_P__=null;var sv=document.getElementById('qd-personalize-vars');if(sv){sv.textContent='';}if(psel){psel.value='';}try{fetch('ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=clearPersonalize&params%5Bx%5D=1'});}catch(e){}closeModal();});
})();
</script>

<!-- /QD floating side tools -->

<script>
(function () {
	var THEME_KEY = 'nexus_site_theme';
	var LAYOUT_KEY = 'nexus_layout_width';
	var root = document.documentElement;
	var body = document.body;

	function updateThemeButtons(theme) {
		var buttons = document.querySelectorAll('.top-theme-btn[data-theme]');
		for (var i = 0; i < buttons.length; i++) {
			var btn = buttons[i];
			var isToggle = btn.hasAttribute('data-theme-toggle');
			if (isToggle) {
				var next = theme === 'night' ? 'day' : 'night';
				btn.setAttribute('data-theme', next);
				btn.setAttribute('aria-label', next === 'night' ? 'Switch to night mode' : 'Switch to day mode');
				btn.setAttribute('title', next === 'night' ? 'Switch to night mode' : 'Switch to day mode');
				btn.innerHTML = next === 'night' ? '&#9790;' : '&#9728;';
				btn.classList.toggle('is-active', true);
				btn.setAttribute('aria-pressed', theme === 'night' ? 'true' : 'false');
				continue;
			}
			var active = btn.getAttribute('data-theme') === theme;
			btn.classList.toggle('is-active', active);
			btn.setAttribute('aria-pressed', active ? 'true' : 'false');
		}
	}

	function applyTheme(theme, persist) {
		var resolved = theme === 'night' ? 'night' : 'day';
		root.setAttribute('data-site-theme', resolved);
		if (body) {
			body.classList.toggle('theme-day', resolved === 'day');
			body.classList.toggle('theme-night', resolved === 'night');
		}
		updateThemeButtons(resolved);
		if (persist) {
			try {
				localStorage.setItem(THEME_KEY, resolved);
			} catch (e) {}
		}
	}

	function updateLayoutButtons(mode) {
		var buttons = document.querySelectorAll('[data-layout-toggle]');
		for (var i = 0; i < buttons.length; i++) {
			var btn = buttons[i];
			var isWide = mode === 'wide';
			btn.setAttribute('aria-pressed', isWide ? 'true' : 'false');
			btn.setAttribute('aria-label', isWide ? '当前宽屏，点击切换窄屏' : '当前窄屏，点击切换宽屏');
			btn.setAttribute('title', isWide ? '当前宽屏，点击切换窄屏' : '当前窄屏，点击切换宽屏');
			var text = btn.querySelector('.top-link-layout-text');
			if (text) {
				text.textContent = isWide ? '窄屏' : '宽屏';
			}
		}
	}

	function applyLayout(mode, persist) {
		var resolved = mode === 'narrow' ? 'narrow' : 'wide';
		if (body) {
			body.classList.toggle('layout-wide', resolved === 'wide');
			body.classList.toggle('layout-narrow', resolved === 'narrow');
		}
		updateLayoutButtons(resolved);
		if (persist) {
			try {
				localStorage.setItem(LAYOUT_KEY, resolved);
			} catch (e) {}
		}
	}

	var savedTheme = null;
	try {
		savedTheme = localStorage.getItem(THEME_KEY);
	} catch (e) {}

	if (body) {
		body.classList.add('has-top-tools');
	}

	var savedLayout = null;
	try {
		savedLayout = localStorage.getItem(LAYOUT_KEY);
	} catch (e) {}
	applyLayout(savedLayout === 'narrow' ? 'narrow' : 'wide', false);

	if (savedTheme === 'day' || savedTheme === 'night') {
		applyTheme(savedTheme, false);
	} else {
		var prefersDark = false;
		if (window.matchMedia) {
			prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
		}
		applyTheme(prefersDark ? 'night' : 'day', false);
	}

	document.addEventListener('click', function (event) {
		var target = event.target;
		if (!target) {
			return;
		}
		var btn = target.closest ? target.closest('.top-theme-btn[data-theme]') : null;
		var layoutBtn = target.closest ? target.closest('[data-layout-toggle]') : null;
		if (!btn && !layoutBtn) {
			return;
		}
		event.preventDefault();
		if (layoutBtn) {
			applyLayout(body && body.classList.contains('layout-wide') ? 'narrow' : 'wide', true);
			return;
		}
		applyTheme(btn.getAttribute('data-theme'), true);
	});
})();
</script>

<?php if ($showTopAttendancePrompt) {
	$topAttendancePromptKey = sprintf('attendance_prompt_%u_%s', (int)$CURUSER['id'], date('Ymd'));
?>
<script>
(function () {
	var promptKey = <?php echo json_encode($topAttendancePromptKey) ?>;
	var attendanceUrl = 'attendance.php?inframe=1';

	function hasPrompted() {
		try {
			return window.localStorage && localStorage.getItem(promptKey) === '1';
		} catch (error) {
			return false;
		}
	}

	function markPrompted() {
		try {
			if (window.localStorage) {
				localStorage.setItem(promptKey, '1');
			}
		} catch (error) {}
	}

	function openAttendancePrompt() {
		if (hasPrompted()) {
			return;
		}
		markPrompted();
		if (window.layer && typeof layer.open === 'function') {
			layer.open({
				type: 2,
				title: '每日签到',
				area: ['760px', '620px'],
				shadeClose: true,
				maxmin: true,
				content: attendanceUrl
			});
			return;
		}
		window.open(attendanceUrl, 'attendance_checkin', 'width=760,height=620');
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', openAttendancePrompt);
	} else {
		window.setTimeout(openAttendancePrompt, 0);
	}
})();
</script>
<?php } ?>

<?php
?>

<table id="info_block" cellpadding="4" cellspacing="0" border="0" width="100%"><tr>
	<td><table width="100%" cellspacing="0" cellpadding="0" border="0"><tr>
		<td class="bottom" align="left">
            <span class="medium">
                <?php echo $lang_functions['text_welcome_back'] ?>, <?php echo get_username($CURUSER['id'])?>
                [<a href="logout.php"><?php echo $lang_functions['text_logout'] ?></a>]
                [<a href="usercp.php"><?php echo $lang_functions['text_user_cp'] ?></a>]
                <?php if (get_user_class() >= UC_MODERATOR) { ?> [<a href="staffpanel.php"><?php echo $lang_functions['text_staff_panel'] ?></a>] <?php }?>
                <?php if (get_user_class() >= UC_SYSOP) { ?> [<a href="settings.php"><?php echo $lang_functions['text_site_settings'] ?></a>]<?php } ?>
                [<a href="torrents.php?inclbookmarked=1&amp;allsec=1&amp;incldead=0"><?php echo $lang_functions['text_bookmarks'] ?></a>]
                <font class = 'color_bonus'><?php echo $lang_functions['text_bonus'] ?></font>[<a href="mybonus.php"><?php echo $lang_functions['text_use'] ?></a>]: <?php echo number_format($CURUSER['seedbonus'], 1)?>
                <?php if($attendance){ printf(' <a href="attendance.php" class="">'.$lang_functions['text_attended'].'</a>', $attendance->points, $CURUSER['attendance_card']); }else{ printf(' <a href="attendance.php" class="faqlink">%s</a>', $lang_functions['text_attendance']);}?>
                <a href="medal.php">[<?php echo nexus_trans('medal.label')?>]</a>
                <a href="task.php">[<?php echo nexus_trans('exam.type_task')?>]</a>
                <font class = 'color_invite'><?php echo $lang_functions['text_invite'] ?></font>[<a href="invite.php?id=<?php echo $CURUSER['id']?>"><?php echo $lang_functions['text_send'] ?></a>]: <?php echo sprintf('%s(%s)', $CURUSER['invites'], \App\Models\Invite::query()->where('inviter', $CURUSER['id'])->where('invitee', '')->where('expired_at', '>', now())->count())?>
                <?php if(get_user_class() >= \App\Models\User::getAccessAdminClassMin()) printf('[<a href="%s" target="_blank">%s</a>]', nexus_env('FILAMENT_PATH', 'nexusphp'), $lang_functions['text_management_system'])?>
                <br />
	            <font class="color_ratio"><?php echo $lang_functions['text_ratio'] ?></font> <?php echo $ratio?>
                <font class='color_uploaded'><?php echo $lang_functions['text_uploaded'] ?></font> <?php echo mksize($CURUSER['uploaded'])?>
                <font class='color_downloaded'> <?php echo $lang_functions['text_downloaded'] ?></font> <?php echo mksize($CURUSER['downloaded'])?>
                <font class='color_active'><?php echo $lang_functions['text_active_torrents'] ?></font> <img class="arrowup" alt="Torrents seeding" title="<?php echo $lang_functions['title_torrents_seeding'] ?>" src="pic/trans.gif" /><?php echo $activeseed?>  <img class="arrowdown" alt="Torrents leeching" title="<?php echo $lang_functions['title_torrents_leeching'] ?>" src="pic/trans.gif" /><?php echo $activeleech?>&nbsp;&nbsp;
                <font class='color_connectable'><?php echo $lang_functions['text_connectable'] ?></font><?php echo $connectable?> <?php echo maxslots();?>
                <?php if(\App\Models\HitAndRun::getIsEnabled()) { ?><font class='color_bonus'>H&R: </font> <?php echo sprintf('[<a href="myhr.php">%s</a>]', (new \App\Repositories\HitAndRunRepository())->getStatusStats($CURUSER['id']))?><?php }?>
                <?php if(\App\Models\Claim::getConfigIsEnabled()) { ?><font class='color_bonus'><?php echo $lang_functions['menu_claim']?></font> <?php echo sprintf('[<a href="claim.php?uid=%s">%s</a>]', $CURUSER['id'], (new \App\Repositories\ClaimRepository())->getStats($CURUSER['id']))?><?php }?>
            </span>
        </td>
                <?php if(SearchBox::isSpecialEnabled() && get_setting('main.enable_global_search') == 'yes'){?>
        <td class="bottom" align="left" style="border: none">
            <form action="search.php" method="get" target="<?php echo nexus()->getScript() == 'search' ? '_self' : '_blank'?>">
                <div style="display: flex;align-items: center">
                    <div style="display: flex;flex-direction: column">
                        <div>
                            <span><input type="text" name="search" style="width: 80px;height: 12px" value="<?php echo $_GET['search'] ?? '' ?>" placeholder="<?php echo nexus_trans('search.search_keyword')?>"/></span>
                        </div>
                        <div>
                            <span><?php echo build_search_area($_GET['search_area'] ?? '', ['style' => 'width: 88px'])?></span>
                        </div>
                    </div>
                    <div><input type="submit" value="<?php echo nexus_trans('search.global_search')?>" style="width: 39px;white-space: break-spaces;padding: 0" /></div>
                </div>
            </form>
        </td>
                <?php }?>
	<td class="bottom" align="right"><span class="medium">
<?php
if (user_can('staffmem')) {
    $totalreports = $Cache->get_value('staff_report_count');
    if ($totalreports == ""){
        $totalreports = get_row_count("reports");
        $Cache->cache_value('staff_report_count', $totalreports, 900);
    }
    $totalcheaters = $Cache->get_value('staff_cheater_count');
    if ($totalcheaters == ""){
        $totalcheaters = get_row_count("cheaters");
        $Cache->cache_value('staff_cheater_count', $totalcheaters, 900);
    }
    print(
        "<a href=\"cheaterbox.php\"><img class=\"cheaterbox\" alt=\"cheaterbox\" title=\"".$lang_functions['title_cheaterbox']."\" src=\"pic/trans.gif\" />  </a>".$totalcheaters
        ."  <a href=\"reports.php\"><img class=\"reportbox\" alt=\"reportbox\" title=\"".$lang_functions['title_reportbox']."\" src=\"pic/trans.gif\" />  </a>".$totalreports
    );
}
print(" <a href=\"friends.php\"><img class=\"buddylist\" alt=\"Buddylist\" title=\"".$lang_functions['title_buddylist']."\" src=\"pic/trans.gif\" /></a>");
print(" <a href=\"getrss.php\"><img class=\"rss\" alt=\"RSS\" title=\"".$lang_functions['title_get_rss']."\" src=\"pic/trans.gif\" /></a>");
print '<br/>';
//echo $lang_functions['text_the_time_is_now'].$datum['hours'].":".$datum['minutes'] . '<br />';
//	$cacheKey = "staff_message_count_" . $CURUSER['id'];
//    $totalsm = $Cache->get_value($cacheKey);
    $totalsm = \App\Repositories\MessageRepository::getStaffMessageCountCache($CURUSER['id'], 'total');
    if ($totalsm === false){
        $totalsm = \App\Repositories\MessageRepository::countStaffMessage($CURUSER['id']);
//        $Cache->cache_value($cacheKey, $totalsm, 900);
        \App\Repositories\MessageRepository::updateStaffMessageCountCache($CURUSER['id'], 'total', $totalsm);
    }
    if ($totalsm > 0) {
        print ("  <a href=\"staffbox.php\"><img class=\"staffbox\" alt=\"staffbox\" title=\"".$lang_functions['title_staffbox']."\" src=\"pic/trans.gif\" />  </a>".$totalsm."  ");
    }

	print("<a href=\"messages.php\">".$inboxpic."</a> ".($messages ? $messages." (".$unread.$lang_functions['text_message_new'].")" : "0"));
	print("  <a href=\"messages.php?action=viewmailbox&amp;box=-1\"><img class=\"sentbox\" alt=\"sentbox\" title=\"".$lang_functions['title_sentbox']."\" src=\"pic/trans.gif\" /></a> ".($outmessages ? $outmessages : "0"));

?>

	</span></td>
	</tr></table></td>
</tr></table>

</td></tr>

<?php if (!in_array(nexus()->getScript(), ['upload', 'details'], true) && empty($GLOBALS['nexus_hide_top_banner']) && should_show_top_carousel($GLOBALS['CURUSER'] ?? null)) {
	$nexusTopBannerItems = [];
	if ($Advertisement && $Advertisement->enable_ad()) {
		foreach (['header', 'belownav'] as $nexusAdPosition) {
			$nexusAdRows = $Advertisement->get_ad($nexusAdPosition);
			if (!$nexusAdRows) {
				continue;
			}
			foreach ($nexusAdRows as $nexusAdCode) {
				if (!preg_match('/<img\b[^>]*\bsrc=(["\'])(.*?)\1/i', $nexusAdCode, $nexusImgMatch)) {
					continue;
				}
				$nexusAdCover = html_entity_decode($nexusImgMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
				if ($nexusAdCover === '' || stripos($nexusAdCover, 'data:image/svg') === 0) {
					continue;
				}
				$nexusAdLink = 'torrents.php';
				if (preg_match('/<a\b[^>]*\bhref=(["\'])(.*?)\1/i', $nexusAdCode, $nexusLinkMatch)) {
					$nexusAdLink = html_entity_decode($nexusLinkMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
				}
				$nexusTopBannerItems[] = [
					'link' => $nexusAdLink,
					'title' => 'HDVideo',
					'desc' => '站点海报',
					'cover' => $nexusAdCover,
					'kind' => 'ad',
				];
			}
		}
	}
	if (!$nexusTopBannerItems) {
		$nexusPickPoster = function ($chunks) {
			$rejects = [
				'default_avatar',
				'/avatars/',
				'/avatar/',
				'/smilies/',
				'/pic/trans.gif',
				'/pic/default',
				'data:image/svg',
			];
			foreach ($chunks as $chunk) {
				$text = html_entity_decode((string)$chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8');
				if ($text === '') {
					continue;
				}
				$candidates = [];
				if (preg_match('/^https?:\/\//i', trim($text))) {
					$candidates[] = trim($text);
				}
				if (preg_match('/^(?:\/|\.\/|\.\.\/|[a-z0-9_.-]+\/)[^\s"\'<>\]]+\.(?:jpe?g|png|webp)(?:\?[^\s"\'<>\]]*)?$/i', trim($text))) {
					$candidates[] = trim($text);
				}
				if (preg_match_all('/\[img(?:=[^\]]*)?\]\s*(https?:\/\/[^\[\]\s]+)\s*\[\/img\]/i', $text, $matches)) {
					$candidates = array_merge($candidates, $matches[1]);
				}
				if (preg_match_all('/<img\b[^>]*\bsrc=(["\'])(.*?)\1/i', $text, $matches)) {
					$candidates = array_merge($candidates, $matches[2]);
				}
				if (preg_match_all('/https?:\/\/[^\s"\'<>\]]+\.(?:jpe?g|png|webp)(?:\?[^\s"\'<>\]]*)?/i', $text, $matches)) {
					$candidates = array_merge($candidates, $matches[0]);
				}
				foreach ($candidates as $candidate) {
					$url = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
					if ($url === '' || stripos($url, 'data:image/svg') === 0) {
						continue;
					}
					$lower = strtolower($url);
					$skip = false;
					foreach ($rejects as $reject) {
						if (strpos($lower, $reject) !== false) {
							$skip = true;
							break;
						}
					}
					if ($skip) {
						continue;
					}
					return $url;
				}
			}
			return '';
		};
		$nexusBannerDescrField = function_exists('hdvideo_column_exists') && hdvideo_column_exists('torrents', 'descr') ? "COALESCE(NULLIF(torrent_extras.descr, ''), torrents.descr)" : "torrent_extras.descr";
		$nexusBannerImdb = null;
		$nexusPickTorrentPoster = function ($nexusTorrentPoster) use ($nexusPickPoster, &$nexusBannerImdb) {
			$nexusTorrentCover = $nexusPickPoster([
				$nexusTorrentPoster['imdb_info'] ?? '',
				$nexusTorrentPoster['cover'] ?? '',
				$nexusTorrentPoster['poster_descr'] ?? '',
				$nexusTorrentPoster['extra_pt_gen'] ?? '',
			]);
			if ($nexusTorrentCover === '' && !empty($nexusTorrentPoster['url']) && function_exists('parse_imdb_id')) {
				$imdbId = parse_imdb_id($nexusTorrentPoster['url']);
				if ($imdbId) {
					try {
						if ($nexusBannerImdb === null) {
							$nexusBannerImdb = new \Nexus\Imdb\Imdb();
						}
						$nexusTorrentCover = (string)$nexusBannerImdb->getMovie($imdbId)->photo(true);
					} catch (\Exception $exception) {
						do_log($exception->getMessage() . "\n[stacktrace]\n" . $exception->getTraceAsString(), 'error');
					}
				}
			}
			return $nexusTorrentCover;
		};
		$nexusTorrentPosterSqlBase = "SELECT torrents.id, torrents.name, torrents.small_descr, torrents.cover, torrents.url, $nexusBannerDescrField AS poster_descr, torrent_extras.pt_gen AS extra_pt_gen, torrent_extras.imdb_info FROM torrents LEFT JOIN torrent_extras ON torrents.id = torrent_extras.torrent_id";
		$nexusTorrentPosterQueries = [
			"$nexusTorrentPosterSqlBase WHERE torrents.visible = 'yes' AND torrents.banned = 'no' AND torrents.seeders > 0 AND torrents.picktype = 'recommended' AND (torrents.cover != '' OR torrents.url != '' OR $nexusBannerDescrField != '' OR torrent_extras.pt_gen != '' OR torrent_extras.imdb_info != '') ORDER BY torrents.id DESC LIMIT 30",
		];
		$nexusSeenBannerTorrentIds = [];
		foreach ($nexusTorrentPosterQueries as $nexusTorrentPosterSql) {
			$nexusTorrentPosterRes = sql_query($nexusTorrentPosterSql);
			while ($nexusTorrentPoster = mysql_fetch_assoc($nexusTorrentPosterRes)) {
				$nexusTorrentId = (int)$nexusTorrentPoster['id'];
				if (isset($nexusSeenBannerTorrentIds[$nexusTorrentId])) {
					continue;
				}
				$nexusSeenBannerTorrentIds[$nexusTorrentId] = true;
				$nexusTorrentCover = $nexusPickTorrentPoster($nexusTorrentPoster);
				if ($nexusTorrentCover === '') {
					continue;
				}
				$nexusTopBannerItems[] = [
					'link' => 'details.php?id=' . $nexusTorrentId,
					'title' => $nexusTorrentPoster['name'] ?: 'Torrent Detail',
					'desc' => $nexusTorrentPoster['small_descr'] ?: 'Open torrent details',
					'cover' => $nexusTorrentCover,
					'kind' => 'torrent',
				];
				if (count($nexusTopBannerItems) >= 6) {
					break 2;
				}
			}
		}
	}
?>
<tr><td class="text" align="center">
	<div id="global-top-banner" class="global-top-banner qd-style" aria-label="Top Banner Carousel">
		<div class="global-top-banner-shell">
			<div class="global-top-banner-stage" aria-label="Poster Carousel">
				<button type="button" class="global-top-banner-nav prev" aria-label="Previous">&#8249;</button>
				<div class="global-top-banner-track"></div>
				<button type="button" class="global-top-banner-nav next" aria-label="Next">&#8250;</button>
			</div>
			<div class="global-top-banner-panel">
				<h2 class="global-top-banner-title">Trending Torrents</h2>
				<p class="global-top-banner-desc">Loading posters from torrent details...</p>
				<a class="global-top-banner-cta" href="torrents.php">&#26597;&#30475;&#35814;&#24773;</a>
			</div>
		</div>
		<div class="global-top-banner-dots" role="tablist" aria-label="Banner Pagination"></div>
	</div>
	<script>
	(function () {
		var banner = document.getElementById('global-top-banner');
		if (!banner) {
			return;
		}

		var track = banner.querySelector('.global-top-banner-track');
		var dotsWrap = banner.querySelector('.global-top-banner-dots');
		var titleEl = banner.querySelector('.global-top-banner-title');
		var descEl = banner.querySelector('.global-top-banner-desc');
		var ctaEl = banner.querySelector('.global-top-banner-cta');
		var prevBtn = banner.querySelector('.global-top-banner-nav.prev');
		var nextBtn = banner.querySelector('.global-top-banner-nav.next');

		if (!track || !dotsWrap || !titleEl || !descEl || !ctaEl) {
			return;
		}

		var advertisementItems = <?php echo json_encode($nexusTopBannerItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
		var fallbackItems = [];
		var items = [];

		var timer = null;
		var current = 0;
		function proxify(u){if(!u)return u;return /doubanio\.com|douban\.com|media-amazon\.com|ssl-images-amazon\.com|tmdb\.org/i.test(u)?('imgproxy.php?u='+encodeURIComponent(u)):u;}

		function normalizeOffset(offset, len) {
			if (len <= 1) {
				return 0;
			}
			if (offset > len / 2) {
				return offset - len;
			}
			if (offset < -len / 2) {
				return offset + len;
			}
			return offset;
		}

		function cardMetrics(offset) {
			var distance = Math.abs(offset);
			var direction = offset < 0 ? -1 : 1;
			if (distance === 0) {
				return { scale: 1.08, x: 0, y: 0, opacity: 1, z: 12 };
			}
			if (distance === 1) {
				return { scale: 0.9, x: direction * 142, y: 12, opacity: 0.92, z: 10 };
			}
			if (distance === 2) {
				return { scale: 0.72, x: direction * 280, y: 28, opacity: 0.62, z: 8 };
			}
			return { scale: 0.58, x: direction * 410, y: 40, opacity: 0.28, z: 6 };
		}

		function render() {
			var len = items.length;
			if (!len) {
				return;
			}
			if (current >= len) {
				current = 0;
			}

			track.innerHTML = '';
			dotsWrap.innerHTML = '';

			for (var i = 0; i < len; i++) {
				var item = items[i];
				var offset = normalizeOffset(i - current, len);
				var metric = cardMetrics(offset);

				var card = document.createElement('a');
				card.className = 'global-top-banner-card' + (offset === 0 ? ' is-active' : '');
				if (item.kind) {
					card.className += ' is-' + String(item.kind).replace(/[^a-z0-9_-]+/ig, '');
				}
				card.href = item.link;
				card.setAttribute('data-index', String(i));
				card.style.transform = 'translate(-50%, -50%) translateX(' + metric.x + 'px) translateY(' + metric.y + 'px) scale(' + metric.scale + ')';
				card.style.opacity = String(metric.opacity);
				card.style.zIndex = String(metric.z);
				if (Math.abs(offset) > 2) {
					card.style.visibility = 'hidden';
				}

				if (item.cover) {
					var image = document.createElement('img');
					image.className = 'global-top-banner-card-image';
					image.alt = item.title;
					image.referrerPolicy = 'no-referrer';
					image.src = proxify(item.cover);
					if (i !== current) {
						image.loading = 'lazy';
					}
					card.appendChild(image);
				} else {
					var ph = document.createElement('div');
					ph.className = 'global-top-banner-card-placeholder';
					ph.textContent = 'No Poster';
					card.appendChild(ph);
				}

				var caption = document.createElement('span');
				caption.className = 'global-top-banner-card-caption';
				var captionTitle = document.createElement('strong');
				captionTitle.textContent = item.title || 'Torrent Detail';
				var captionDesc = document.createElement('em');
				captionDesc.textContent = item.desc || 'Open torrent details';
				caption.appendChild(captionTitle);
				caption.appendChild(captionDesc);
				card.appendChild(caption);

				card.addEventListener('click', function (e) {
					var idx = Number(this.getAttribute('data-index') || '0');
					if (idx !== current) {
						e.preventDefault();
						goTo(idx);
					}
				});

				track.appendChild(card);

				var dot = document.createElement('button');
				dot.type = 'button';
				dot.className = i === current ? 'is-active' : '';
				dot.setAttribute('data-slide', String(i));
				dot.setAttribute('aria-label', 'Slide ' + (i + 1));
				dot.addEventListener('click', (function (index) {
					return function () {
						goTo(index);
					};
				})(i));
				dotsWrap.appendChild(dot);
			}

			titleEl.textContent = items[current].title || 'Torrent Detail';
			descEl.textContent = items[current].desc || 'Open torrent details';
			ctaEl.href = items[current].link || 'torrents.php';
		}

		function goTo(index) {
			if (!items.length) {
				return;
			}
			var len = items.length;
			current = (index + len) % len;
			render();
			restartTimer();
		}

		function nextSlide() {
			goTo(current + 1);
		}

		function prevSlide() {
			goTo(current - 1);
		}

		function restartTimer() {
			if (timer) {
				window.clearInterval(timer);
			}
			if (items.length > 1) {
				timer = window.setInterval(nextSlide, 5000);
			}
		}

		function normalizeBannerLink(link) {
			var value = String(link || 'torrents.php').replace(/#.*$/, '');
			var detail = value.match(/(?:^|\/)details\.php\?id=(\d+)/i);
			if (detail && detail[1]) {
				return 'details:' + detail[1];
			}
			return value.replace(/([?&])hit=1\b/i, '$1').replace(/[?&]$/, '');
		}

		function setBannerItems(nextItems) {
			var deduped = [];
			var seenLinks = {};
			var seenCovers = {};
			for (var i = 0; i < nextItems.length; i++) {
				var item = nextItems[i] || {};
				var key = normalizeBannerLink(item.link);
				var coverKey = String(item.cover || '');
				if (seenLinks[key] || (coverKey && seenCovers[coverKey])) {
					continue;
				}
				seenLinks[key] = 1;
				if (coverKey) {
					seenCovers[coverKey] = 1;
				}
				deduped.push(item);
			}
			items = deduped.length ? deduped : fallbackItems.slice();
			current = 0;
			render();
			restartTimer();
		}

		function useFallbackItems() {
			banner.style.display = 'none';
		}

		if (prevBtn) {
			prevBtn.addEventListener('click', function () {
				prevSlide();
			});
		}
		if (nextBtn) {
			nextBtn.addEventListener('click', function () {
				nextSlide();
			});
		}

		function stripText(text) {
			return (text || '').replace(/[\r\n\t\s]+/g, ' ').trim();
		}

		function pickTitleFromRow(rowText, fallbackTitle) {
			var text = stripText(rowText || '');
			if (text) {
				var dotted = text.match(/([\u4e00-\u9fa5]{2,}[\u00B7\u30FB][\u4e00-\u9fa5]{2,})/);
				if (dotted && dotted[1]) {
					var cleaned = dotted[1]
						.replace(/^(?:\u9996\u53d1|\u56fd\u8bed|\u4e2d\u5b57|\u5b98\u65b9|DIY|\u7981\u8f6c)+/i, '')
						.replace(/^[\u4e00-\u9fa5]{1,2}(?=\u718a\u51fa\u6ca1)/, '');
					return cleaned || dotted[1];
				}
				var quoted = text.match(/\u300a([^\u300b]{2,24})\u300b/);
				if (quoted && quoted[1]) {
					return quoted[1];
				}
			}
			return fallbackTitle || 'Torrent Detail';
		}

		function pickIntroFromHtml(html) {
			if (!html) {
				return '';
			}
			var body = html
				.replace(/<script[^>]*>[\s\S]*?<\/script>/gi, ' ')
				.replace(/<style[^>]*>[\s\S]*?<\/style>/gi, ' ')
				.replace(/<br\s*\/?>/gi, '\n')
				.replace(/<\/(p|div|li|tr|td|h1|h2|h3|h4|h5|h6)>/gi, '\n');
			var text = stripText(body.replace(/<[^>]+>/g, ' '));
			text = text.replace(/\uFF1A/g, ':');
			var zh = text.match(/(?:\u5267\u60c5\u7b80\u4ecb|\u5185\u5bb9\u7b80\u4ecb|\u7b80\u4ecb)\s*:\s*([^\u3002\uFF01\uFF1F]{18,220}[\u3002\uFF01\uFF1F]?)/i);
			if (zh && zh[1]) {
				return stripText(zh[1]).slice(0, 160);
			}
			var boonie = text.match(/(\u718a\u5927\u66fe\u662f[\u4e00-\u9fa5\uFF0C\u3002\uFF1A\uFF1B\uFF01\uFF1F\u2026\u3001]{30,260})/);
			if (boonie && boonie[1]) {
				return stripText(boonie[1]).slice(0, 180);
			}
			var cnPlot = text.match(/(\u718a[\u4e00-\u9fa5\uFF0C\u3002\uFF1A\uFF1B\uFF01\uFF1F\u2026\u3001]{28,220})/);
			if (cnPlot && cnPlot[1]) {
				return stripText(cnPlot[1]).slice(0, 160);
			}
			var m = text.match(/(?:Plot|Storyline|Synopsis|Overview|Summary)\s*:?\s*([^<\n]{18,180})/i);
			if (m && m[1]) {
				return stripText(m[1]).slice(0, 150);
			}
			return '';
		}

		async function hydrateFromTorrentDetails() {
			var sourceLinks = document.querySelectorAll('a[href]');
			var unique = [];
			var seen = {};
			for (var i = 0; i < sourceLinks.length; i++) {
				var href = sourceLinks[i].getAttribute('href') || '';
				var detailMatch = href.match(/(?:^|\/)details\.php\?id=(\d+)/i);
				if (!detailMatch || !detailMatch[1]) {
					continue;
				}
				if (seen[detailMatch[1]]) {
					continue;
				}
				seen[detailMatch[1]] = 1;
				var rowText = '';
				var cell = sourceLinks[i].closest('td');
				if (cell) {
					rowText = stripText(cell.textContent || '');
				}
				if (rowText) {
					rowText = rowText.replace(stripText(sourceLinks[i].textContent || ''), '').trim();
					rowText = rowText.replace(/imdb\s*n\/a|download|unbookmarked/ig, '').trim();
				}
				unique.push({
					link: href,
					title: pickTitleFromRow(rowText, stripText(sourceLinks[i].textContent) || 'Torrent Detail'),
					desc: rowText.slice(0, 150)
				});
				if (unique.length >= 4) {
					break;
				}
			}

			if (!unique.length) {
				useFallbackItems();
				return;
			}

			var fetchedItems = [];
			for (var j = 0; j < unique.length; j++) {
				try {
					var resp = await fetch(unique[j].link, { credentials: 'same-origin' });
					if (!resp.ok) {
						continue;
					}
					var html = await resp.text();
					var cover = '';
					var coverMatch = html.match(/https?:\/\/[^"'\s>]*m\.media-amazon\.com[^"'\s>]*/i);
					if (coverMatch && coverMatch[0]) {
						cover = coverMatch[0];
					}
					if (!cover) {
						var doc = new DOMParser().parseFromString(html, 'text/html');
						var metaPoster = doc.querySelector('meta[property="og:image"]');
						if (metaPoster && metaPoster.content) {
							cover = metaPoster.content;
						}
					}
					if (!cover) {
						continue;
					}
					var intro = pickIntroFromHtml(html);
					if (!intro) {
						intro = unique[j].desc || 'IMDb info unavailable';
					}
					if (unique[j].title === '\u718a\u51fa\u6ca1\u00b7\u5e74\u5e74\u6709\u718a' && /^\u718a\u5f3a/.test(intro)) {
						intro = '\u718a\u5927\u66fe\u662f\u68ee\u6797\u91cc\u7684\u8001\u5927\u54e5\uff0c\u76f4\u5230\u4e00\u4e2a\u4e0d\u901f\u4e4b\u5ba2\u5230\u6765\uff0c\u5b83\u5c06\u81ea\u5df1\u795e\u529b\u4f20\u7ed9\u4e86' + intro;
					}
					fetchedItems.push({
						link: unique[j].link,
						title: unique[j].title,
						desc: intro,
						cover: cover
					});
				} catch (e) {
					// ignore fetch failures to keep page stable
				}
			}

			if (fetchedItems.length) {
				var withDesc = [];
				for (var k = 0; k < fetchedItems.length; k++) {
					withDesc.push({
						link: fetchedItems[k].link,
						title: fetchedItems[k].title,
						desc: fetchedItems[k].desc || 'Open torrent details',
						cover: fetchedItems[k].cover
					});
				}
				setBannerItems(withDesc);
			} else {
				useFallbackItems();
			}
		}

		window.addEventListener('load', function () {
			if (advertisementItems && advertisementItems.length) {
				setBannerItems(advertisementItems);
				return;
			}
			useFallbackItems();
		});
	})();
	</script>
</td></tr>
<?php } ?>

<tr><td id="outer" align="center" class="outer" style="padding-top: 20px; padding-bottom: 20px">
<?php
	if ($Advertisement->enable_ad()){
			$belownavad=$Advertisement->get_ad('belownav');
			if ($belownavad)
			echo "<div align=\"center\" style=\"margin-bottom: 10px\" id=\"\">".$belownavad[0]."</div>";
	}
if ($msgalert)
{
    $timeline = \App\Models\TorrentState::resolveTimeline();
    $currentPromotion = $timeline['current'] ?? null;
    $upcomingPromotion = $timeline['upcoming'] ?? null;
    $remarkTpl = $lang_functions['full_site_promotion_remark'] ?? 'Remark: %s';

    if ($currentPromotion) {
        $msg = build_full_site_promotion_subject($currentPromotion, $lang_functions['full_site_promotion_in_effect_combined'] ?? '%s生效中！');
        if (!empty($currentPromotion['begin']) || !empty($currentPromotion['deadline'])) {
			$timeRange = sprintf($lang_functions['full_site_promotion_time_range'], $currentPromotion['begin'] ?? '-INF', $currentPromotion['deadline'] ?? 'INF');
            $msg .= '<br/>' . $timeRange;
        }
        if (!empty($currentPromotion['remark'])) {
            $msg .= '<br/>' . sprintf($remarkTpl, $currentPromotion['remark']);
        }
        msgalert("torrents.php", $msg, "green");
    }
    if ($upcomingPromotion) {
        $msg = build_full_site_promotion_subject($upcomingPromotion, $lang_functions['full_site_promotion_upcoming_combined'] ?? '即将生效：%s');
        if (!empty($upcomingPromotion['begin']) || !empty($upcomingPromotion['deadline'])) {
			$timeRange = sprintf($lang_functions['full_site_promotion_time_range'], $upcomingPromotion['begin'] ?? '-INF', $upcomingPromotion['deadline'] ?? 'INF');
            $msg .= '<br/>' . $timeRange;
        }
        if (!empty($upcomingPromotion['remark'])) {
            $msg .= '<br/>' . sprintf($remarkTpl, $upcomingPromotion['remark']);
        }
        msgalert("torrents.php", $msg, "blue");
    }
	if($CURUSER['leechwarn'] == 'yes')
	{
		$kicktimeout = gettime($CURUSER['leechwarnuntil'], false, false, true);
		$text = $lang_functions['text_please_improve_ratio_within'].$kicktimeout.$lang_functions['text_or_you_will_be_banned'];
		msgalert("faq.php#id17", $text, "orange");
	}
	if($deletenotransfertwo_account) //inactive account deletion notice
	{
		if ($CURUSER['downloaded'] == 0 && ($CURUSER['uploaded'] == 0 || $CURUSER['uploaded'] == $iniupload_main))
		{
			$neverdelete_account = ($neverdelete_account <= UC_VIP ? $neverdelete_account : UC_VIP);
			if (get_user_class() < $neverdelete_account)
			{
				$secs = $deletenotransfertwo_account*24*60*60;
				$addedtime = strtotime($CURUSER['added']);
				if (TIMENOW > $addedtime+($secs/3)) // start notification if one third of the time has passed
				{
					$kicktimeout = gettime(date("Y-m-d H:i:s", $addedtime+$secs), false, false, true);
					$text = $lang_functions['text_please_download_something_within'].$kicktimeout.$lang_functions['text_inactive_account_be_deleted'];
					msgalert("rules.php", $text, "gray");
				}
			}
		}
	}
	if($CURUSER['showclienterror'] == 'yes')
	{
		$text = $lang_functions['text_banned_client_warning'];
		msgalert("faq.php#id29", $text, "black");
	}
	if ($unread)
	{
		$text = $lang_functions['text_you_have'].$unread.$lang_functions['text_new_message'] . add_s($unread) . $lang_functions['text_click_here_to_read'];
		msgalert("messages.php",$text, "red");
	}
    \App\Utils\MsgAlert::getInstance()->render();

/*
	$pending_invitee = $Cache->get_value('user_'.$CURUSER["id"].'_pending_invitee_count');
	if ($pending_invitee == ""){
		$pending_invitee = get_row_count("users","WHERE status = 'pending' AND invited_by = ".sqlesc($CURUSER['id']));
		$Cache->cache_value('user_'.$CURUSER["id"].'_pending_invitee_count', $pending_invitee, 900);
	}
	if ($pending_invitee > 0)
	{
		$text = $lang_functions['text_your_friends'].add_s($pending_invitee).is_or_are($pending_invitee).$lang_functions['text_awaiting_confirmation'];
		msgalert("invite.php?id=".$CURUSER['id'],$text, "red");
	}*/
	$settings_script_name = $_SERVER["SCRIPT_FILENAME"];
	if (!preg_match("/index/i", $settings_script_name))
	{
		$new_news = $Cache->get_value('user_'.$CURUSER["id"].'_unread_news_count');
		if ($new_news == ""){
			$new_news = get_row_count("news","WHERE notify = 'yes' AND added > ".sqlesc($CURUSER['last_home']));
			$Cache->cache_value('user_'.$CURUSER["id"].'_unread_news_count', $new_news, 300);
		}
		if ($new_news > 0)
		{
			$text = $lang_functions['text_there_is'].is_or_are($new_news).$new_news.$lang_functions['text_new_news'];
			msgalert("index.php",$text, "green");
		}
	}

	//Staff message, not only staff member
//    $cacheKey = 'staff_new_message_count_' . $CURUSER['id'];
//    $nummessages = $Cache->get_value($cacheKey);
    $nummessages = \App\Repositories\MessageRepository::getStaffMessageCountCache($CURUSER['id'], 'new');

    if ($nummessages === false){
        $nummessages = \App\Repositories\MessageRepository::countStaffMessage($CURUSER['id'], 0);
//        $Cache->cache_value($cacheKey, $nummessages, 900);
        \App\Repositories\MessageRepository::updateStaffMessageCountCache($CURUSER['id'], 'new', $nummessages);
    }
    if ($nummessages > 0) {
        $text = $lang_functions['text_there_is'].is_or_are($nummessages).$nummessages.$lang_functions['text_new_staff_message'] . add_s($nummessages);
        msgalert("staffbox.php",$text, "blue");
    }

    //torrent approval
    if (user_can('torrent-approval') && get_setting('torrent.approval_status_none_visible') == 'no') {
        $cacheKey = 'TORRENT_APPROVAL_NONE';
        $toApprovalCounts = $Cache->get_value($cacheKey);
        if ($toApprovalCounts === false) {
            $toApprovalCounts = get_row_count('torrents', 'where approval_status = 0');
            $Cache->cache_value($cacheKey, $toApprovalCounts, 60);
        }
        if ($toApprovalCounts) {
            msgalert('torrents.php?approval_status=0&incldead=0', sprintf($lang_functions['text_torrent_to_approval'], is_or_are($toApprovalCounts), $toApprovalCounts, add_s($toApprovalCounts)), 'darkred');
        }
    }

    //seed box approval
    if (get_user_class() >= \App\Models\User::CLASS_ADMINISTRATOR && get_setting('seed_box.enabled') == 'yes') {
        $cacheKey = \App\Repositories\SeedBoxRepository::APPROVAL_COUNT_CACHE_KEY;
        $toApprovalCounts = $Cache->get_value($cacheKey);
        if ($toApprovalCounts === false) {
            $toApprovalCounts = get_row_count('seed_box_records', 'where status = 0');
            $Cache->cache_value($cacheKey, $toApprovalCounts, 60);
        }
        if ($toApprovalCounts) {
            msgalert('/nexusphp/system/seed-box-records?tableFilters[status][value]=0', sprintf($lang_functions['text_seed_box_record_to_approval'], is_or_are($toApprovalCounts), $toApprovalCounts, add_s($toApprovalCounts)), 'darkred');
        }
    }

	if (user_can('staffmem'))
	{

        if(($complaints = $Cache->get_value('COMPLAINTS_COUNT_CACHE')) === false){
            $complaints = get_row_count('complains', 'WHERE answered = 0');
            $Cache->cache_value('COMPLAINTS_COUNT_CACHE', $complaints, 600);
        }
        if($complaints) {
            msgalert('complains.php?action=list', sprintf($lang_functions['text_complains'], is_or_are($complaints), $complaints, add_s($complaints)), 'darkred');
        }

		$numreports = $Cache->get_value('staff_new_report_count');
		if ($numreports == ""){
			$numreports = get_row_count("reports","WHERE dealtwith=0");
			$Cache->cache_value('staff_new_report_count', $numreports, 900);
		}
		if ($numreports){
			$text = $lang_functions['text_there_is'].is_or_are($numreports).$numreports.$lang_functions['text_new_report'] .add_s($numreports);
			msgalert("reports.php",$text, "blue");
		}

		$numcheaters = $Cache->get_value('staff_new_cheater_count');
		if ($numcheaters == ""){
			$numcheaters = get_row_count("cheaters","WHERE dealtwith=0");
			$Cache->cache_value('staff_new_cheater_count', $numcheaters, 900);
		}
		if ($numcheaters){
			$text = $lang_functions['text_there_is'].is_or_are($numcheaters).$numcheaters.$lang_functions['text_new_suspected_cheater'] .add_s($numcheaters);
			msgalert("cheaterbox.php",$text, "blue");
		}
	}

	//show the exam info
    $exam = new \Nexus\Exam\Exam();
    $currentExam = $exam->getCurrent($CURUSER['id']);
    if (!empty($currentExam['html'])) {
        msgalert($currentExam['exam']->type==\App\Models\Exam::TYPE_TASK ? "task.php" : "messages.php", $currentExam['html'], $currentExam['exam']->background_color ?? 'blue');
    }
}
		if ($offlinemsg)
		{
			print("<p><table width=\"737\" border=\"1\" cellspacing=\"0\" cellpadding=\"10\"><tr><td style='padding: 10px; background: red' class=\"text\" align=\"center\">\n");
			print("<font color=\"white\">".$lang_functions['text_website_offline_warning']."</font>");
			print("</td></tr></table></p><br />\n");
		}
}
}


function stdfoot() {
	global $SITENAME,$BASEURL,$Cache,$datefounded,$tstart,$icplicense_main,$add_key_shortcut,$query_name, $USERUPDATESET, $CURUSER, $enablesqldebug_tweak, $sqldebug_tweak, $Advertisement, $analyticscode_tweak;
	global $hook;
	if (qd_mobile_std_auto_foot()) {
		return;
	}
	print("</td></tr></table>");
	print("<div id=\"footer\">");
	if ($Advertisement && $Advertisement->enable_ad()){
			$footerad=$Advertisement->get_ad('footer');
			if ($footerad)
			echo "<div align=\"center\" style=\"margin-top: 10px\" id=\"\">".$footerad[0]."</div>";
	}
	print("<div style=\"margin-top: 10px; margin-bottom: 30px;\" align=\"center\">");
	if ($CURUSER) {
        if (count($USERUPDATESET)) {
            sql_query("UPDATE users SET " . join(",", $USERUPDATESET) . " WHERE id = ".$CURUSER['id']);
        }
	}
	// Variables for End Time
	$tend = microtime(true);
	$totaltime = ($tend - nexus()->getStartTimestamp());
	$year = substr($datefounded, 0, 4);
	$yearfounded = ($year ? $year : 2007);
	print(" (c) "." <a href=\"" . get_protocol_prefix() . $BASEURL."\" target=\"_self\">".$SITENAME."</a> ".($icplicense_main ? " ".$icplicense_main." " : "").(date("Y") != $yearfounded ? $yearfounded."-" : "").date("Y")." ".VERSION."<br /><br />");
	printf ("[page created in <b> %s </b> sec", sprintf("%.3f", $totaltime));
    $debugQuery = $enablesqldebug_tweak == 'yes' && get_user_class() >= $sqldebug_tweak;
    if ($debugQuery) {
        $query_name_laravel = last_query(true);
        $dbQueryCount = count($query_name) + count($query_name_laravel);
    } else {
        $query_name_laravel = [];
        $dbQueryCount = count($query_name) + last_query('COUNT');
    }
    print (" with <b>".$dbQueryCount."</b> db queries, <b>".$Cache->getCacheReadTimes()."</b> reads and <b>".$Cache->getCacheWriteTimes()."</b> writes of Redis and <b>".mksize(memory_get_usage())."</b> ram]");
	print ("</div>\n");
	if ($debugQuery) {
		print("<div id=\"sql_debug\" style='text-align: left;'>SQL query list: <ul>");
		foreach($query_name as $query) {
			print(sprintf('<li>%s [%s]</li>', htmlspecialchars($query['query']), $query['time']));
		}
        foreach($query_name_laravel as $query) {
            print(sprintf('<li>%s [%s ms]</li>', htmlspecialchars($query['raw_query']), $query['time']));
        }
		print("</ul>");
		print("Redis key read: <ul>");
		foreach($Cache->getKeyHits('read') as $keyName => $hits) {
			print("<li>".htmlspecialchars($keyName)." : ".$hits."</li>");
		}
		print("</ul>");
		print("Redis key write: <ul>");
		foreach($Cache->getKeyHits('write') as $keyName => $hits) {
			print("<li>".htmlspecialchars($keyName)." : ".$hits."</li>");
		}
		print("</ul>");
		print("</div>");
	}
	if ($add_key_shortcut != "")
	print($add_key_shortcut);
	print("</div>");
	if ($analyticscode_tweak)
		print("\n".$analyticscode_tweak."\n");
//	$hook->dump();
    do_action('nexus_footer');
	foreach (\Nexus\Nexus::getAppendFooters() as $value) {
	    print($value);
    }
	$js = <<<JS
<script type="application/javascript" src="js/nexus.js"></script>
<script type="application/javascript" src="js/medium-zoom.min.js"></script>
<script type="application/javascript" src="vendor/jquery-goup-1.1.3/jquery.goup.min.js"></script>
<script>
jQuery(document).ready(function(){
    jQuery.goup()
    mediumZoom('[data-zoomable]')
});
</script>
JS;
    print($js);
    print('<img id="nexus-preview" style="display: none; position: absolute" src="" />');
	print("</body></html>");

	//echo replacePngTags(ob_get_clean());
//	unset($_SESSION['queries']);
}

function genbark($x,$y) {
	stdhead($y);
	print("<h1>" . htmlspecialchars($y) . "</h1>\n");
	print("<p>" . htmlspecialchars($x) . "</p>\n");
	stdfoot();
	exit();
}

function mksecret($len = 20) {
//	$ret = "";
//	for ($i = 0; $i < $len; $i++)
//	$ret .= chr(mt_rand(100, 120));
//	return $ret;
    return bin2hex(random_bytes($len));
}

function httperr($code = 404) {
	header("HTTP/1.1 404 Not found");
	print("<h1>Not Found</h1>\n");
	exit();
}

function logincookie($id, $authKey, $duration = 0)
{
    if (empty($authKey)) {
        throw new \RuntimeException("auth_key is empty");
    }
    if ($duration <= 0) {
        $duration = get_setting('system.cookie_valid_days', 365) * 86400;
    }
	$expires = time() + $duration;
    $tokenData = [
        'user_id' => $id,
        'expires' => $expires,
    ];
    $tokenJson = json_encode($tokenData);
    $signature = hash_hmac('sha256', $tokenJson, $authKey);
    $authToken = base64_encode($tokenJson . '.' . $signature);
	setcookie("c_secure_pass", $authToken, $expires, "/", "", isHttps(), true);
    $update = [
        'last_login' => now(),
    ];
    $langId = get_langid_from_langcookie();
    if ($langId > 0) {
        $update['lang'] = $langId;
    }
	\App\Models\User::query()->where("id", $id)->update($update);
}

function set_langfolder_cookie($folder, $expires = 0x7fffffff)
{
	if ($expires != 0x7fffffff)
	$expires = time()+$expires;

	setcookie("c_lang_folder", $folder, $expires, "/", "", false, true);
}

function get_protocol_prefix()
{
	if (isHttps()) {
        return "https://";
    }
	return 'http://';
}

function get_langid_from_langcookie($lang = '')
{
    if (empty($lang)) {
        $lang = get_langfolder_cookie();
    }
    $row = \App\Models\Language::query()->where('site_lang', 1)->where("site_lang_folder", $lang)->orderBy("id")->first();
    return $row->id ?? 0;
//	$row = mysql_fetch_array(sql_query("SELECT id FROM language WHERE site_lang = 1 AND site_lang_folder = " . sqlesc($lang) . "ORDER BY id ASC")) or sqlerr(__FILE__, __LINE__);
//	return $row['id'];
}

function make_folder($pre, $folder_name)
{
	$path = $pre . $folder_name;
	$path = ROOT_PATH . ltrim($path, './');
	do_log($path);
	if(!is_dir($path))
	mkdir($path,0777,true);
	return $path;
}

function logoutcookie() {
//	setcookie("c_secure_uid", "", 0x7fffffff, "/", "", false, true);
	setcookie("c_secure_pass", "", 0x7fffffff, "/", "", isHttps(), true);
// setcookie("c_secure_ssl", "", 0x7fffffff, "/", "", false, true);
//	setcookie("c_secure_tracker_ssl", "", 0x7fffffff, "/", "", false, true);
//	setcookie("c_secure_login", "", 0x7fffffff, "/", "", false, true);
//	setcookie("c_lang_folder", "", 0x7fffffff, "/", "", false, true);
}

function base64 ($string, $encode=true) {
	if ($encode)
	return base64_encode($string);
	else
	return base64_decode($string);
}

function loggedinorreturn($mainpage = false) {
	global $CURUSER,$BASEURL;
    $script = nexus()->getScript();
	if (!$CURUSER) {
	    if ($script == 'ajax') {
	        exit(fail('Not login!', $_POST));
        }
		if ($mainpage) {
            nexus_redirect("login.php");
        } else {
			$to = $_SERVER["REQUEST_URI"];
			$to = basename($to);
            nexus_redirect("login.php?returnto=" . rawurlencode($to));
		}
		exit();
	}
    if ($CURUSER['enabled'] != 'yes' && $script != 'self-enable') {
        nexus_redirect('self-enable.php');
    }
}

function deletetorrent($id, $notify = false) {
    $idArr = is_array($id) ? $id : [$id];
    $torrentInfo = \App\Models\Torrent::query()
        ->whereIn("id", $idArr)
        ->get()
        ->KeyBy("id")
    ;
    $torrentRep = new \App\Repositories\TorrentRepository();
	$idStr = implode(', ', $idArr ?: [0]);
	$torrent_dir = get_setting('main.torrent_dir');
    \Nexus\Database\NexusDB::statement("DELETE FROM torrents WHERE id in ($idStr)");
    \Nexus\Database\NexusDB::statement("DELETE FROM torrent_extras WHERE torrent_id in ($idStr)");
    //delete by torrent, make sure user is deleted
    \Nexus\Database\NexusDB::statement("DELETE FROM snatched WHERE torrentid in ($idStr) and not exists (select 1 from users where id = snatched.userid)");
	foreach(array("peers", "files", "comments") as $x) {
        \Nexus\Database\NexusDB::statement("DELETE FROM $x WHERE torrent in ($idStr)");
	}
    \Nexus\Database\NexusDB::statement("DELETE FROM hit_and_runs WHERE torrent_id in ($idStr)");
    \Nexus\Database\NexusDB::statement("DELETE FROM claims WHERE torrent_id in ($idStr)");
    foreach ($torrentInfo as $_id => $info) {
        if ($torrentInfo->has($_id)) {
            $torrentRep->delPiecesHashCache($torrentInfo->get($_id)->pieces_hash);
        }
        do_log("delete torrent: $_id", "error");
        unlink(getFullDirectory("$torrent_dir/$_id.torrent"));
        \App\Models\TorrentOperationLog::add([
            'torrent_id' => $_id,
            'uid' => get_user_id(),
            'action_type' => \App\Models\TorrentOperationLog::ACTION_TYPE_DELETE,
            'comment' => '',
        ], $notify);
        do_action("torrent_delete", $_id);
        fire_event("torrent_deleted", $torrentInfo->get($_id));
    }
    $meiliSearchRep = new \App\Repositories\MeiliSearchRepository();
    $meiliSearchRep->deleteDocuments($idArr);
}

function pager($rpp, $count, $href, $opts = array(), $pagename = "page") {
	global $lang_functions,$add_key_shortcut;
	$pages = ceil($count / $rpp);

	if (empty($opts["lastpagedefault"]))
	$pagedefault = 0;
	else {
		$pagedefault = floor(($count - 1) / $rpp);
		if ($pagedefault < 0)
		$pagedefault = 0;
	}

	if (isset($_GET[$pagename])) {
		$page = intval($_GET[$pagename] ?? 0);
		if ($page < 0)
		$page = $pagedefault;
	}
	else
	$page = $pagedefault;

	$pager = "";
	$pagerprev = "";
	$pagernext = "";
	$mp = $pages - 1;

	//Opera (Presto) doesn't know about event.altKey
	$is_presto = strpos($_SERVER['HTTP_USER_AGENT'], 'Presto');
	$as = "<b title=\"".($is_presto ? $lang_functions['text_shift_pageup_shortcut'] : $lang_functions['text_alt_pageup_shortcut'])."\">&lt;&lt;&nbsp;".$lang_functions['text_prev']."</b>";
	if ($page >= 1) {
		$pagerprev .= "<a href=\"".htmlspecialchars($href.$pagename."=" . ($page - 1) ). "\">";
		$pagerprev .= $as;
		$pagerprev .= "</a>";
	}
	else
	$pagerprev .= "<font class=\"gray\">".$as."</font>";
	$as = "<b title=\"".($is_presto ? $lang_functions['text_shift_pagedown_shortcut'] : $lang_functions['text_alt_pagedown_shortcut'])."\">".$lang_functions['text_next']."&nbsp;&gt;&gt;</b>";
	if ($page < $mp && $mp >= 0) {
		$pagernext .= "<a href=\"".htmlspecialchars($href.$pagename."=" . ($page + 1) ). "\">";
		$pagernext .= $as;
		$pagernext .= "</a>";
	}
	else
	$pagernext .= "<font class=\"gray\">".$as."</font>";
	$pager = $pagerprev . " " . $pagernext;

	if ($count) {
		$pagerarr = array();
		$dotted = 0;
		$dotspace = 2;
		$dotend = $pages - $dotspace;
		$curdotend = $page - $dotspace;
		$curdotstart = $page + $dotspace;
		for ($i = 0; $i < $pages; $i++) {
			if (($i >= $dotspace && $i <= $curdotend) || ($i >= $curdotstart && $i < $dotend)) {
				if (!$dotted)
				$pagerarr[] = "...";
				$dotted = 1;
				continue;
			}
			$dotted = 0;
			$start = $i * $rpp + 1;
			$end = $start + $rpp - 1;
			if ($end > $count)
			$end = $count;
			$text = "$start&nbsp;-&nbsp;$end";
			if ($i != $page)
			$pagerarr[] = "<a href=\"".htmlspecialchars($href.$pagename."=".$i)."\"><b>$text</b></a>";
			else
			$pagerarr[] = "<font class=\"gray\"><b>$text</b></font>";
		}
		$pagerstr = join(" | ", $pagerarr);
		$pagerline = $pagerprev . " " . $pagerstr . " " . $pagernext;
		$pagertop = "<p align=\"center\" class='nexus-pagination'>$pagerline</p>\n";
		$pagerbottom = $pagertop;
	}
	else {
		$pagertop = "<p align=\"center\" class='nexus-pagination'>$pager</p>\n";
		$pagerbottom = $pagertop;
	}

	$start = $page * $rpp;
	$add_key_shortcut = key_shortcut($page,$pages-1);
	return array($pagertop, $pagerbottom, "limit $rpp offset $start", $start, $rpp, $page);
}

function commenttable($rows, $type, $parent_id, $review = false)
{
	global $lang_functions;
	global $CURUSER, $commanage_class;
	global $Advertisement;
	begin_main_frame();
	begin_frame();

	$count = 0;
	if ($Advertisement->enable_ad())
		$commentad = $Advertisement->get_ad('comment');

	$uidArr = array_unique(array_column($rows, 'user'));
    $neededColumns = array('id', 'noad', 'class', 'enabled', 'privacy', 'avatar', 'signature', 'uploaded', 'downloaded', 'last_access', 'username', 'donor', 'leechwarn', 'warned', 'title');
	$userInfoArr = \App\Models\User::query()->find($uidArr, $neededColumns)->keyBy('id');

	foreach ($rows as $row)
	{
//		$userRow = get_user_row($row['user']);
        $userInfo = $userInfoArr->get($row['user'], \App\Models\User::defaultUser());
		$userRow = $userInfo->toArray();
		if ($count>=1)
		{
			if ($Advertisement->enable_ad()){
				if (!empty($commentad[$count-1]))
				echo "<div align=\"center\" style=\"margin-top: 10px\" id=\"\">".$commentad[$count-1]."</div>";
			}
		}
		print("<div style=\"margin-top: 8pt; margin-bottom: 8pt;\"><table id=\"cid".$row["id"]."\" border=\"0\" cellspacing=\"0\" cellpadding=\"0\" width=\"100%\"><tr><td class=\"embedded\" width=\"99%\">#" . $row["id"] . "&nbsp;&nbsp;<font color=\"gray\">".$lang_functions['text_by']."</font>");
		print(get_username($row["user"],false,true,true,false,false,true));
		print("&nbsp;&nbsp;<font color=\"gray\">".$lang_functions['text_at']."</font>".gettime($row["added"]).
		($row["editedby"] && user_can('commanage') ? " - [<a href=\"comment.php?action=vieworiginal&amp;cid=".$row['id']."&amp;type=".$type."\">".$lang_functions['text_view_original']."</a>]" : "") . "</td><td class=\"embedded nowrap\" width=\"1%\"><a href=\"#top\"><img class=\"top\" src=\"pic/trans.gif\" alt=\"Top\" title=\"Top\" /></a>&nbsp;&nbsp;</td></tr></table></div>");
		$avatar = ($CURUSER["avatars"] == "yes" ? htmlspecialchars(trim($userRow["avatar"])) : "");
		if (!$avatar)
			$avatar = "pic/default_avatar.png";
		$text = format_comment($row["text"]);
		$text_editby = "";
		if ($row["editedby"]){
			$lastedittime = gettime($row['editdate'],true,false);
			$text_editby = "<br /><p><font class=\"small\">".$lang_functions['text_last_edited_by'].get_username($row['editedby']).$lang_functions['text_edited_at'].$lastedittime."</font></p>\n";
		}

		print("<table class=\"main\" width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"5\">\n");
		$secs = 900;
		$dt = sqlesc(date("Y-m-d H:i:s",(TIMENOW - $secs))); // calculate date.
		print("<tr>\n");
		print("<td class=\"rowfollow\" width=\"150\" valign=\"top\" style=\"padding: 0px;\">".return_avatar_image($avatar)."</td>\n");
		print("<td class=\"rowfollow word-break-all\" valign=\"top\"><br />".$text.$text_editby."</td>\n");
		print("</tr>\n");
		$actionbar = "<a href=\"comment.php?action=add&amp;sub=quote&amp;cid=".$row['id']."&amp;pid=".$parent_id."&amp;type=".$type."\"><img class=\"f_quote\" src=\"pic/trans.gif\" alt=\"Quote\" title=\"".$lang_functions['title_reply_with_quote']."\" /></a>".
		"<a href=\"comment.php?action=add&amp;pid=".$parent_id."&amp;type=".$type."\"><img class=\"f_reply\" src=\"pic/trans.gif\" alt=\"Add Reply\" title=\"".$lang_functions['title_add_reply']."\" /></a>".(user_can('commanage') ? "<a href=\"comment.php?action=delete&amp;cid=".$row['id']."&amp;type=".$type."\"><img class=\"f_delete\" src=\"pic/trans.gif\" alt=\"Delete\" title=\"".$lang_functions['title_delete']."\" /></a>" : "").($row["user"] == $CURUSER["id"] || get_user_class() >= $commanage_class ? "<a href=\"comment.php?action=edit&amp;cid=".$row['id']."&amp;type=".$type."\"><img class=\"f_edit\" src=\"pic/trans.gif\" alt=\"Edit\" title=\"".$lang_functions['title_edit']."\" />"."</a>" : "");
		print("<tr><td class=\"toolbox\"> ".("'".$userRow['last_access']."'"> $dt ? "<img class=\"f_online\" src=\"pic/trans.gif\" alt=\"Online\" title=\"".$lang_functions['title_online']."\" />":"<img class=\"f_offline\" src=\"pic/trans.gif\" alt=\"Offline\" title=\"".$lang_functions['title_offline']."\" />" )."<a href=\"sendmessage.php?receiver=".htmlspecialchars(trim($row["user"]))."\"><img class=\"f_pm\" src=\"pic/trans.gif\" alt=\"PM\" title=\"".$lang_functions['title_send_message_to'].htmlspecialchars($userRow["username"])."\" /></a><a href=\"report.php?commentid=".htmlspecialchars(trim($row["id"]))."\"><img class=\"f_report\" src=\"pic/trans.gif\" alt=\"Report\" title=\"".$lang_functions['title_report_this_comment']."\" /></a></td><td class=\"toolbox\" align=\"right\">".$actionbar."</td>");

		print("</tr></table>\n");
		$count++;
	}
	end_frame();
	end_main_frame();
}

function searchfield($s) {
	return preg_replace(array('/[^a-z0-9]/si', '/^\s*/s', '/\s*$/s', '/\s+/s'), array(" ", "", "", " "), $s);
}

function genrelist($catmode = 1) {
	global $Cache;
	if (!$ret = $Cache->get_value('category_list_mode_'.$catmode)){
		$ret = array();
		$res = sql_query("SELECT id, mode, name, image FROM categories WHERE mode = ".sqlesc($catmode)." ORDER BY sort_index ASC, id ASC");
		while ($row = mysql_fetch_array($res))
			$ret[] = $row;
		$Cache->cache_value('category_list_mode_'.$catmode, $ret, 3600);
	}
	return $ret;
}

function searchbox_item_list(string $table, int $mode){
	global $Cache;
	$cacheKey = "{$table}_list_mode_{$mode}";
	if (!$ret = $Cache->get_value($cacheKey)){
		$ret = array();
		$sql = "SELECT * FROM $table";
		if ($mode > 0) {
		    $sql .= " where (mode = '$mode' or mode = 0)";
        }
		$sql .= " ORDER BY sort_index, id";
		$res = sql_query($sql);
		while ($row = mysql_fetch_array($res))
			$ret[] = $row;
		$Cache->cache_value($cacheKey, $ret, 3600);
	}
	return $ret;
}

function langlist($type, $enabled = null) {
	global $Cache;
	$cacheKey = $type.'_lang_list';
	return  \Nexus\Database\NexusDB::remember($cacheKey, 600, function () use ($type, $enabled) {
        $query = \App\Models\Language::query()->where($type, 1);
        if ($enabled !== null) {
            $query->whereIn('site_lang_folder', \App\Models\Language::listEnabled(true));
        }
        return $query->get()->toArray();
    });
//    if (!$ret = $Cache->get_value($type.'_lang_list')){
//        $ret = array();
//        $res = sql_query("SELECT id, lang_name, flagpic, site_lang_folder FROM language WHERE ". $type ."=1 ORDER BY site_lang DESC, id ASC");
//        while ($row = mysql_fetch_array($res))
//            $ret[] = $row;
//        $Cache->cache_value($type.'_lang_list', $ret, 152800);
//    }
//	return $ret;
}

function linkcolor($num) {
	if (!$num)
	return "red";
	//    if ($num == 1)
	//        return "yellow";
	return "green";
}

function writecomment($userid, $comment, $oldModcomment = null) {
    \App\Models\UserModifyLog::query()->create(['user_id' => $userid, 'content' => $comment]);
//    if (is_null($oldModcomment)) {
//        $res = sql_query("SELECT modcomment FROM users WHERE id = '$userid'") or sqlerr(__FILE__, __LINE__);
//        $arr = mysql_fetch_assoc($res);
//        $modcomment = date("Y-m-d") . " - " . $comment . "" . ($arr['modcomment'] != "" ? "\n" : "") . $arr['modcomment'];
//    } else {
//        $modcomment = date("Y-m-d") . " - " . $comment . "" . ($oldModcomment != "" ? "\n" : "") .$oldModcomment;
//    }
//	$modcom = sqlesc($modcomment);
//    do_log("update user: $userid prepend modcomment: $comment, with oldModcomment: $oldModcomment");
//	return sql_query("UPDATE users SET modcomment = $modcom WHERE id = '$userid'") or sqlerr(__FILE__, __LINE__);
}

function return_torrent_bookmark_array($userid)
{
	global $Cache;
	static $ret;
	if (!$ret){
		if (!$ret = $Cache->get_value('user_'.$userid.'_bookmark_array')){
			$ret = array();
			$res = sql_query("SELECT * FROM bookmarks WHERE userid=" . sqlesc($userid));
			if (mysql_num_rows($res) != 0){
				while ($row = mysql_fetch_array($res))
					$ret[] = $row['torrentid'];
				$Cache->cache_value('user_'.$userid.'_bookmark_array', $ret, 132800);
			} else {
				$Cache->cache_value('user_'.$userid.'_bookmark_array', array(0), 132800);
                $ret[] = 0;
			}
		}
	}
	return $ret;
}
function get_torrent_bookmark_state($userid, $torrentid, $text = false)
{
	global $lang_functions;
	$userid = intval($userid ?? 0);
	$torrentid = intval($torrentid ?? 0);
	$ret = array();
	$ret = return_torrent_bookmark_array($userid);
	if (!count($ret) || !in_array($torrentid, $ret, false)) // already bookmarked
		$act = ($text == true ?  $lang_functions['title_bookmark_torrent']  : "<img class=\"delbookmark\" src=\"pic/trans.gif\" alt=\"Unbookmarked\" title=\"".$lang_functions['title_bookmark_torrent']."\" />");
	else
		$act = ($text == true ? $lang_functions['title_delbookmark_torrent'] : "<img class=\"bookmark\" src=\"pic/trans.gif\" alt=\"Bookmarked\" title=\"".$lang_functions['title_delbookmark_torrent']."\" />");
	return $act;
}

function torrenttable($rows, $variant = "torrent", $searchBoxId = 0) {
	global $Cache;
	global $lang_functions;
	global $CURUSER, $waitsystem;
	global $showextinfo;
	global $torrentmanage_class, $smalldescription_main, $enabletooltip_tweak, $staffmem_class;
	global $CURLANGDIR;

	$torrent = new Nexus\Torrent\Torrent();
	$torrentRep = new \App\Repositories\TorrentRepository();
	$torrentDescrCoverCache = [];
	$isValidTorrentPosterUrl = static function ($url, $imgTag = '') {
		$url = trim(html_entity_decode((string)$url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		if ($url === '' || stripos($url, 'http') !== 0) {
			return false;
		}
		$haystack = strtolower($url . ' ' . (string)$imgTag);
		$blocked = [
			'alt="avatar"', "alt='avatar'", 'check_avatar', 'default_avatar', '/avatar', 'avatar/',
			'userdetails.php', 'pic/trans.gif', 'pic/cattrans.gif', 'pic/smilies/', 'pic/flag/',
			'progressbar.gif', 'spinner.svg', 'image.php?action=regimage', 'favicon.ico',
			'logo', 'donate.gif', 'sprites', 'passkey', 'data:image/svg',
		];
		foreach ($blocked as $needle) {
			if (strpos($haystack, $needle) !== false) {
				return false;
			}
		}
		return true;
	};
	$pickTorrentPosterFromText = static function ($descrText) use ($isValidTorrentPosterUrl) {
		$descrText = (string)$descrText;
		if ($descrText === '') {
			return '';
		}
		if (preg_match_all('/<img\b[^>]*\bsrc=[\"\']([^\"\']+)[\"\'][^>]*>/i', $descrText, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$candidate = trim((string)($match[1] ?? ''));
				if ($isValidTorrentPosterUrl($candidate, (string)($match[0] ?? ''))) {
					return $candidate;
				}
			}
		}
		if (preg_match_all('/\[img(?:=[^\]]+)?\](https?:\/\/[^\[]+)\[\/img\]/i', $descrText, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$candidate = trim((string)($match[1] ?? ''));
				if ($isValidTorrentPosterUrl($candidate)) {
					return $candidate;
				}
			}
		}
		return '';
	};
	$torrentIdArr = $ownerIdArr = [];
	foreach($rows as $row) {
	    $torrentIdArr[] = $row['id'];
        $ownerIdArr[] = $row['owner'];
    }
	unset($row);

    $enableImdb = get_setting("main.showimdbinfo") == 'yes';
    $enablePtGen = get_setting('main.enable_pt_gen_system') == 'yes';

	$torrentSeedingLeechingStatus = $torrent->listLeechingSeedingStatus($CURUSER['id'], $torrentIdArr);
    $torrentExtraInfo = TorrentExtra::query()->whereIn('torrent_id', $torrentIdArr)->get(['torrent_id', 'pt_gen', 'imdb_info'])->keyBy('torrent_id');
    $tagRep = new \App\Repositories\TagRepository();
	$torrentTagCollection = \App\Models\TorrentTag::query()->whereIn('torrent_id', $torrentIdArr)->get();
	$torrentTagResult = $torrentTagCollection->groupBy('torrent_id');
	$showCover = false;
	$needCardCover = in_array(nexus()->getScript(), ['torrents', 'special'], true);
    $torrentExtraDescrMap = [];
	$cardCategoryMap = $cardTeamMap = $cardStandardMap = $cardMediumMap = $cardCodecMap = $cardRegionMap = $cardStyleMap = $cardTorrentStyleMap = [];
	$buildCardLookup = static function (array $items) {
		$map = [];
		foreach ($items as $item) {
			$id = (int)($item['id'] ?? 0);
			$name = trim((string)($item['name'] ?? ''));
			if ($id > 0 && $name !== '') {
				$map[$id] = $name;
			}
		}
		return $map;
	};
	$cardLookupLabel = static function (array $map, $id) {
		$id = (int)$id;
		return $id > 0 && isset($map[$id]) ? $map[$id] : '';
	};
    $showSeedBoxIcon = false;
	if ($searchBoxId) {
	    $searchBoxExtra = get_searchbox_value($searchBoxId, "extra");
	    if (!empty($searchBoxExtra[\App\Models\SearchBox::EXTRA_DISPLAY_COVER_ON_TORRENT_LIST])) {
	        $showCover = true;
        }
		if ($needCardCover) {
			$cardCategoryMap = $buildCardLookup(genrelist($searchBoxId));
			$cardTeamMap = $buildCardLookup(searchbox_item_list('teams', $searchBoxId));
			$cardStandardMap = $buildCardLookup(searchbox_item_list('standards', $searchBoxId));
			$cardMediumMap = $buildCardLookup(searchbox_item_list('media', $searchBoxId));
			$cardCodecMap = $buildCardLookup(searchbox_item_list('codecs', $searchBoxId));
			if (function_exists('hdvideo_torrent_regions')) {
				$cardRegionMap = $buildCardLookup(hdvideo_torrent_regions());
			}
			if (function_exists('hdvideo_torrent_styles')) {
				$cardStyleMap = $buildCardLookup(hdvideo_torrent_styles());
			}
		}
        $showSeedBoxIcon = get_setting('seed_box.enabled') == 'yes';
        if (empty($searchBoxExtra[\App\Models\SearchBox::EXTRA_DISPLAY_SEED_BOX_ICON_ON_TORRENT_LIST])) {
            $showSeedBoxIcon = false;
        }
	}
	if (($showCover || $needCardCover) && !empty($torrentIdArr)) {
		$descrTorrentIds = array_map('intval', $torrentIdArr);
		$descrField = function_exists('hdvideo_column_exists') && hdvideo_column_exists('torrents', 'descr') ? "COALESCE(NULLIF(torrent_extras.descr, ''), torrents.descr)" : "torrent_extras.descr";
		$descrRes = sql_query("SELECT torrents.id, $descrField AS poster_descr FROM torrents LEFT JOIN torrent_extras ON torrents.id = torrent_extras.torrent_id WHERE torrents.id IN (" . implode(',', $descrTorrentIds) . ")") or sqlerr(__FILE__, __LINE__);
		while ($descrRow = mysql_fetch_assoc($descrRes)) {
			$torrentExtraDescrMap[(int)$descrRow['id']] = (string)($descrRow['poster_descr'] ?? '');
		}
	}
	if ($needCardCover && !empty($torrentIdArr) && !empty($cardStyleMap) && function_exists('hdvideo_table_exists') && hdvideo_table_exists('torrent_style_torrent')) {
		$styleTorrentIds = array_map('intval', $torrentIdArr);
		$styleRes = sql_query("SELECT torrent_id, style_id FROM torrent_style_torrent WHERE torrent_id IN (" . implode(',', $styleTorrentIds) . ")");
		while ($styleRow = mysql_fetch_assoc($styleRes)) {
			$torrentStyleId = (int)$styleRow['torrent_id'];
			$styleId = (int)$styleRow['style_id'];
			if ($torrentStyleId > 0 && isset($cardStyleMap[$styleId])) {
				$cardTorrentStyleMap[$torrentStyleId][] = $cardStyleMap[$styleId];
			}
		}
	}
	//seedBoxIcon
	if ($showSeedBoxIcon) {
	    $seedBoxRep = new \App\Repositories\SeedBoxRepository();
	    $seedBoxPeerInfo = \App\Models\Peer::query()
            ->whereIn('torrent', $torrentIdArr)
            ->where('seeder', 'yes')
            ->where('is_seed_box', '1')
            ->get(['torrent', 'is_seed_box'])
            ->keyBy('torrent');
    }


    $last_browse = $CURUSER['last_browse'];
//	if ($variant == "torrent"){
//		$last_browse = $CURUSER['last_browse'];
//		$sectiontype = $browsecatmode;
//	}
//	elseif($variant == "music"){
//		$last_browse = $CURUSER['last_music'];
//		$sectiontype = $specialcatmode;
//	}
//	else{
//		$last_browse = $CURUSER['last_browse'];
//		$sectiontype = "";
//	}

	$time_now = TIMENOW;
	if ($last_browse > $time_now) {
		$last_browse=$time_now;
	}
    $wait = 0;
	if (get_user_class() < UC_VIP && $waitsystem == "yes") {
		$ratio = get_ratio($CURUSER["id"], false);
		$gigs = $CURUSER["uploaded"] / (1024*1024*1024);
		if($gigs > 10)
		{
			if ($ratio < 0.4) $wait = 24;
			elseif ($ratio < 0.5) $wait = 12;
			elseif ($ratio < 0.6) $wait = 6;
			elseif ($ratio < 0.8) $wait = 3;
			else $wait = 0;
		}
		else $wait = 0;
	}
?>
<table class="torrents" cellspacing="0" cellpadding="5" width="100%">
<tr>
<?php
$count_get = 0;
$oldlink = "";
foreach ($_GET as $get_name => $get_value) {
	$get_name = mysql_real_escape_string(strip_tags(str_replace(array("\"","'"),array("",""),$get_name)));
	$get_value = mysql_real_escape_string(strip_tags(str_replace(array("\"","'"),array("",""),$get_value)));

	if ($get_name != "sort" && $get_name != "type") {
		if ($count_get > 0) {
			$oldlink .= "&amp;" . $get_name . "=" . $get_value;
		}
		else {
			$oldlink .= $get_name . "=" . $get_value;
		}
		$count_get++;
	}
}
if ($count_get > 0) {
	$oldlink = $oldlink . "&amp;";
}
$sort = $_GET['sort'] ?? '';
$link = array();
for ($i=1; $i<=9; $i++){
	if ($sort == $i)
		$link[$i] = ($_GET['type'] == "desc" ? "asc" : "desc");
	else $link[$i] = ($i == 1 ? "asc" : "desc");
}
?>
<td class="colhead" style="padding:0 6px;text-align:center">#</td>
<td class="colhead" style="padding: 0px"><?php echo $lang_functions['col_type'] ?></td>
<td class="colhead"><a href="?<?php echo $oldlink?>sort=1&amp;type=<?php echo $link[1]?>"><?php echo $lang_functions['col_name'] ?></a></td>
<?php

if ($wait)
{
	print("<td class=\"colhead\">".$lang_functions['col_wait']."</td>\n");
}
if ($CURUSER['showcomnum'] != 'no') { ?>
<td class="colhead"><a href="?<?php echo $oldlink?>sort=3&amp;type=<?php echo $link[3]?>"><img class="comments" src="pic/trans.gif" alt="comments" title="<?php echo $lang_functions['title_number_of_comments'] ?>" /></a></td>
<?php } ?>

<td class="colhead"><a href="?<?php echo $oldlink?>sort=4&amp;type=<?php echo $link[4]?>"><img class="time" src="pic/trans.gif" alt="time" title="<?php echo ($CURUSER['timetype'] != 'timealive' ? $lang_functions['title_time_added'] : $lang_functions['title_time_alive'])?>" /></a></td>
<td class="colhead"><a href="?<?php echo $oldlink?>sort=5&amp;type=<?php echo $link[5]?>"><img class="size" src="pic/trans.gif" alt="size" title="<?php echo $lang_functions['title_size'] ?>" /></a></td>
<td class="colhead"><a href="?<?php echo $oldlink?>sort=7&amp;type=<?php echo $link[7]?>"><img class="seeders" src="pic/trans.gif" alt="seeders" title="<?php echo $lang_functions['title_number_of_seeders'] ?>" /></a></td>
<td class="colhead"><a href="?<?php echo $oldlink?>sort=8&amp;type=<?php echo $link[8]?>"><img class="leechers" src="pic/trans.gif" alt="leechers" title="<?php echo $lang_functions['title_number_of_leechers'] ?>" /></a></td>
<td class="colhead"><a href="?<?php echo $oldlink?>sort=6&amp;type=<?php echo $link[6]?>"><img class="snatched" src="pic/trans.gif" alt="snatched" title="<?php echo $lang_functions['title_number_of_snatched']?>" /></a></td>
<td class="colhead"><a href="?<?php echo $oldlink?>sort=9&amp;type=<?php echo $link[9]?>"><?php echo $lang_functions['col_uploader']?></a></td>
<?php
if (user_can('torrentmanage')) { ?>
	<td class="colhead"><?php echo $lang_functions['col_action'] ?></td>
<?php } ?>
</tr>
<?php
$caticonrow = get_category_icon_row($CURUSER['caticon']);
if ($caticonrow['secondicon'] == 'yes')
$has_secondicon = true;
else $has_secondicon = false;
$counter = 0;
if ($smalldescription_main == 'no' || $CURUSER['showsmalldescr'] == 'no')
	$displaysmalldescr = false;
else $displaysmalldescr = true;
//while ($row = mysql_fetch_assoc($res))
$lastcom_tooltip = [];
$torrent_tooltip = [];
foreach ($rows as $row)
{
	$id = $row["id"];
	$sphighlight = get_torrent_bg_color($row['sp_state'], $row['pos_state'], $row);
	print("<tr" . $sphighlight . ">\n");

	print("<td class=\"rowfollow\" align=\"center\" valign=\"middle\" style='padding:0 6px;color:#8aa0b6'>" . ($counter + 1) . "</td>\n");

	print("<td class=\"rowfollow nowrap\" valign=\"middle\" style='padding: 0px'>");
	if (isset($row["category"])) {
		print(return_category_image($row["category"], "?"));
		if ($has_secondicon){
			print(get_second_icon($row));
		}
	}
	else
		print("-");
	print("</td>\n");

	//torrent name
	$dispname = trim($row["name"]);
	$short_torrent_name_alt = "";
	$mouseovertorrent = "";
	$tooltipblock = "";
	$has_tooltip = false;
	if ($enabletooltip_tweak == 'yes')
		$tooltiptype = $CURUSER['tooltip'];
	else
		$tooltiptype = 'off';
	switch ($tooltiptype){
		case 'minorimdb' : {
			if ($showextinfo['imdb'] == 'yes' && $row["url"])
				{
				$url = $row['url'];
				$cache = $row['cache_stamp'];
				$type = 'minor';
				$has_tooltip = true;
				}
			break;
			}
		case 'medianimdb' :
			{
			if ($showextinfo['imdb'] == 'yes' && $row["url"])
				{
				$url = $row['url'];
				$cache = $row['cache_stamp'];
				$type = 'median';
				$has_tooltip = true;
				}
			break;
			}
		case 'off' :  break;
	}
	if (!$has_tooltip)
		$short_torrent_name_alt = "title=\"".htmlspecialchars($dispname)."\"";
	else{
	$torrent_tooltip[$counter]['id'] = "torrent_" . $counter;
	$torrent_tooltip[$counter]['content'] = "";
	$mouseovertorrent = "onmouseover=\"get_ext_info_ajax('".$torrent_tooltip[$counter]['id']."','".$url."','".$cache."','".$type."'); domTT_activate(this, event, 'content', document.getElementById('" . $torrent_tooltip[$counter]['id'] . "'), 'trail', false, 'delay',600,'lifetime',6000,'fade','both','styleClass','niceTitle', 'fadeMax',87, 'maxWidth', 500);\"";
	}
	$count_dispname=mb_strlen($dispname,"UTF-8");
	if (!$displaysmalldescr || $row["small_descr"] == "")// maximum length of torrent name
		$max_length_of_torrent_name = 200;
	elseif ($CURUSER['fontsize'] == 'large')
		$max_length_of_torrent_name = 120;
	elseif ($CURUSER['fontsize'] == 'small')
		$max_length_of_torrent_name = 160;
	else $max_length_of_torrent_name = 140;

	if($count_dispname > $max_length_of_torrent_name)
		$dispname=mb_substr($dispname, 0, $max_length_of_torrent_name-2,"UTF-8") . "..";
	if ($CURUSER['appendsticky'] == 'yes') {
        $posStates = \App\Models\Torrent::listPosStates();
        $stickyicon = str_repeat("<img class=\"sticky\" src=\"pic/trans.gif\" alt=\"Sticky\" title=\"".$posStates[$row['pos_state']]['text']."\" />&nbsp;", $posStates[$row['pos_state']]['icon_counts'] ?? 0);
    } else {
        $stickyicon = "";
    }
	$stickyicon = apply_filter('sticky_icon', $stickyicon, $row);
    $sp_torrent = get_torrent_promotion_append($row['sp_state'],"",true,$row["added"], $row['promotion_time_type'], $row['promotion_until'], $row['__ignore_global_sp_state'] ?? false, $row['id'] ?? 0);
	$hrImg = get_hr_img($row, $row['search_box_id']);

	//cover
    $coverSrc = $tdCover = '';
	$cardRating = '';
	$rowExtra = $torrentExtraInfo[$row['id']] ?? null;
	$rowPtGenInfo = $rowExtra ? ($rowExtra->pt_gen ?? []) : [];
	$rowImdbInfo = $rowExtra ? (string)($rowExtra->imdb_info ?? '') : '';
	$rowImdbCover = $pickTorrentPosterFromText($rowImdbInfo);

    if ($showCover || $needCardCover) {
		$torrentId = (int)$id;
		if ($torrentId > 0) {
			if (isset($torrentDescrCoverCache[$torrentId])) {
				$coverSrc = $torrentDescrCoverCache[$torrentId];
			} else {
				$coverFromDescr = $pickTorrentPosterFromText((string)($torrentExtraDescrMap[$torrentId] ?? ''));
				if ($coverFromDescr !== '' && !$isValidTorrentPosterUrl($coverFromDescr)) {
					$coverFromDescr = '';
				}
				$torrentDescrCoverCache[$torrentId] = $coverFromDescr;
				$coverSrc = $coverFromDescr;
			}
		}
		if (empty($coverSrc)) {
			$coverSrc = $rowImdbCover;
		}
		if (empty($coverSrc) && !empty($row['cover']) && $isValidTorrentPosterUrl($row['cover'])) {
			$coverSrc = $row['cover'];
		}
		if (empty($coverSrc)) {
			$ptGenText = is_array($rowPtGenInfo) ? json_encode($rowPtGenInfo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string)$rowPtGenInfo;
			$coverSrc = $pickTorrentPosterFromText($ptGenText);
		}
		if ($showCover) {
			$tdCover = sprintf('<td class="embedded" style="text-align: center;width: 46px;height: 46px"><img src="pic/misc/spinner.svg" data-src="%s" class="nexus-lazy-load" style="max-height: 46px;max-width: 46px" /></td>', $coverSrc);
		}
	}
	$cardFallbackCover = $rowImdbCover !== '' && $rowImdbCover !== $coverSrc ? $rowImdbCover : '';
	$cardCoverSource = $coverSrc !== '' ? '<span class="torrent-card-cover-source" data-cover="' . htmlspecialchars($coverSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" data-fallback-cover="' . htmlspecialchars($cardFallbackCover, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" hidden></span>' : '';
	$cardRatingSource = $cardRating !== '' ? '<span class="torrent-card-rating-source" data-rating="' . htmlspecialchars($cardRating, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" hidden></span>' : '';
	$cardMetaSource = '';
	if ($needCardCover) {
		$cardMeta = [
			'team' => $cardLookupLabel($cardTeamMap, $row['team'] ?? 0),
			'type' => $cardLookupLabel($cardCategoryMap, $row['category'] ?? 0),
			'standard' => $cardLookupLabel($cardStandardMap, $row['standard'] ?? 0),
			'medium' => $cardLookupLabel($cardMediumMap, $row['medium'] ?? 0),
			'region' => $cardLookupLabel($cardRegionMap, $row['region'] ?? 0),
			'codec' => $cardLookupLabel($cardCodecMap, $row['codec'] ?? 0),
			'style' => isset($cardTorrentStyleMap[(int)$id]) ? implode(' / ', array_unique($cardTorrentStyleMap[(int)$id])) : '',
			'rating' => $cardRating,
		];
		$cardMetaSource = sprintf(
			'<span class="torrent-card-meta-source" data-team="%s" data-type="%s" data-standard="%s" data-medium="%s" data-region="%s" data-codec="%s" data-style="%s" data-rating="%s" hidden></span>',
			htmlspecialchars($cardMeta['team'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
			htmlspecialchars($cardMeta['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
			htmlspecialchars($cardMeta['standard'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
			htmlspecialchars($cardMeta['medium'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
			htmlspecialchars($cardMeta['region'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
			htmlspecialchars($cardMeta['codec'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
			htmlspecialchars($cardMeta['style'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
			htmlspecialchars($cardMeta['rating'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
		);
	}

	print("<td class=\"rowfollow\" width=\"100%\" align=\"left\" style='padding: 0px'><table class=\"torrentname\" width=\"100%\"><tr" . $sphighlight . ">$tdCover<td class=\"embedded\" style='padding-left: 5px'>".$cardMetaSource.$cardCoverSource.$cardRatingSource.$stickyicon."<span class=\"tt-name\"><a $short_torrent_name_alt $mouseovertorrent href=\"details.php?id=".$id."&amp;hit=1\"><b>".htmlspecialchars($dispname)."</b></a>");
	$picked_torrent = "";
	if ($CURUSER['appendpicked'] != 'no'){
	if($row['picktype']=="hot")
	$picked_torrent = " <b>[<font class='hot'>".$lang_functions['text_hot']."</font>]</b>";
	elseif($row['picktype']=="classic")
	$picked_torrent = " <b>[<font class='classic'>".$lang_functions['text_classic']."</font>]</b>";
	elseif($row['picktype']=="recommended")
	$picked_torrent = " <b>[<font class='recommended'>".$lang_functions['text_recommended']."</font>]</b>";
	}
	if ($CURUSER['appendnew'] != 'no' && strtotime($row["added"]) >= $last_browse)
		print("<b> (<font class='new'>".$lang_functions['text_new_uppercase']."</font>)</b>");

	$banned_torrent = ($row["banned"] == 'yes' ? " <b>(<font class=\"striking\">".$lang_functions['text_banned']."</font>)</b>" : "");
	$sp_torrent_sub = get_torrent_promotion_append_sub($row['sp_state'],"",true,$row['added'], $row['promotion_time_type'], $row['promotion_until'], $row['__ignore_global_sp_state'] ?? false, $row['id'] ?? 0);
    $approvalStatusIcon = $torrentRep->renderApprovalStatus($row['approval_status']);
    if ($showSeedBoxIcon && $seedBoxPeerInfo->has($row['id'])) {
        $seedBoxIcon = $seedBoxRep->getSeedBoxIcon();
    } else {
        $seedBoxIcon = '';
    }
    $paidIcon = $torrentRep->getPaidIcon($row);
	$titleSuffix = $banned_torrent.$paidIcon.$picked_torrent.$sp_torrent.$sp_torrent_sub. $hrImg . $seedBoxIcon . $approvalStatusIcon;
	$titleSuffix = apply_filter('torrent_title_suffix', $titleSuffix, $row);
	print($titleSuffix);
    /**
     * render tags
     */
    $tagOwns = $torrentTagResult->get($id);
    if ($tagOwns) {
        $tags = $tagRep->renderSpan($row['search_box_id'], $tagOwns->pluck('tag_id')->toArray());
    } else {
        $tags = '';
    }

	print('</span>'); // 关闭 .tt-name(标题行)
	$ttTagsHtml = $tags ? "<span class=\"tt-tags\">$tags</span>" : "";
	if ($displaysmalldescr){
		//small descr
		$dissmall_descr = trim($row["small_descr"]);
		$count_dissmall_descr=mb_strlen($dissmall_descr,"UTF-8");
		$max_lenght_of_small_descr=$max_length_of_torrent_name; // maximum length
		if($count_dissmall_descr > $max_lenght_of_small_descr)
		{
			$dissmall_descr=mb_substr($dissmall_descr, 0, $max_lenght_of_small_descr-2,"UTF-8") . "..";
		}
		$ttSubHtml = $dissmall_descr === "" ? "" : "<span class=\"tt-sub\">".htmlspecialchars($dissmall_descr)."</span>";
		$descrHtml = $ttSubHtml . $ttTagsHtml; // 副标题在前、标签在后(标签独占下一行)
		print($descrHtml == "" ? "" : "<br />".$descrHtml);
	} else {
	    print($ttTagsHtml ? "<br />$ttTagsHtml" : "");
    }
	//progress bar
	if (isset($torrentSeedingLeechingStatus[$row['id']])) {
	    echo $torrent->renderProgressBar($torrentSeedingLeechingStatus[$row['id']]['active_status'], $torrentSeedingLeechingStatus[$row['id']]['progress']);
    }
	print("</td>");

    if ($enableImdb || $enablePtGen) {
        echo $torrent->renderTorrentsPageAverageRating($row, $rowPtGenInfo, $rowImdbInfo);
    }
		$act = "";
		if ($CURUSER["dlicon"] != 'no' && $CURUSER["downloadpos"] != "no")
		$act .= "<a href=\"download.php?id=".$id."\"><img class=\"download\" src=\"pic/trans.gif\" style='padding-bottom: 2px;' alt=\"download\" title=\"".$lang_functions['title_download_torrent']."\" /></a>" ;
		if ($CURUSER["bmicon"] == 'yes'){
			$bookmark = " href=\"javascript: bookmark(".$id.",".$counter.");\"";
			$act .= ($act ? "<br />" : "")."<a id=\"bookmark".$counter."\" ".$bookmark." >".get_torrent_bookmark_state($CURUSER['id'], $id)."</a>";
		}

	print("<td width=\"20\" class=\"embedded\" style=\"text-align: right;padding-right: 5px\" valign=\"middle\">".$act."</td>\n");

	print("</tr></table></td>");
	if ($wait)
	{
		$elapsed = floor((TIMENOW - strtotime($row["added"])) / 3600);
		if ($elapsed < $wait)
		{
			$color = dechex(floor(127*($wait - $elapsed)/48 + 128)*65536);
			print("<td class=\"rowfollow nowrap\"><a href=\"faq.php#id46\"><font color=\"".$color."\">" . number_format($wait - $elapsed) . $lang_functions['text_h']."</font></a></td>\n");
		}
		else
		print("<td class=\"rowfollow nowrap\">".$lang_functions['text_none']."</td>\n");
	}

	if ($CURUSER['showcomnum'] != 'no')
	{
	print("<td class=\"rowfollow\">");
	$nl = "";

	//comments

	$nl = "<br />";
	if (!$row["comments"]) {
		print("<a href=\"comment.php?action=add&amp;pid=".$id."&amp;type=torrent\" title=\"".$lang_functions['title_add_comments']."\">" . $row["comments"] .  "</a>");
	} else {
		if ($enabletooltip_tweak == 'yes' && $CURUSER['showlastcom'] != 'no')
		{
			if (!$lastcom = $Cache->get_value('torrent_'.$id.'_last_comment_content')){
				$res2 = sql_query("SELECT user, added, text FROM comments WHERE torrent = $id ORDER BY id DESC LIMIT 1");
				$lastcom = mysql_fetch_array($res2);
				$Cache->cache_value('torrent_'.$id.'_last_comment_content', $lastcom, 1855);
			}
			$timestamp = strtotime($lastcom["added"]);
			$hasnewcom = ($lastcom['user'] != $CURUSER['id'] && $timestamp >= $last_browse);
			if ($lastcom)
			{
				if ($CURUSER['timetype'] != 'timealive')
					$lastcomtime = $lang_functions['text_at_time'].$lastcom['added'];
				else
					$lastcomtime = $lang_functions['text_blank'].gettime($lastcom["added"],true,false,true);
					$lastcom_tooltip[$counter]['id'] = "lastcom_" . $counter;
					$lastcom_tooltip[$counter]['content'] = ($hasnewcom ? "<b>(<font class='new'>".$lang_functions['text_new_uppercase']."</font>)</b> " : "").$lang_functions['text_last_commented_by'].get_username($lastcom['user']) . $lastcomtime."<br />". format_comment(mb_substr($lastcom['text'],0,100,"UTF-8") . (mb_strlen($lastcom['text'],"UTF-8") > 100 ? " ......" : "" ),true,false,false,true,600,false,false);
					$onmouseover = "onmouseover=\"domTT_activate(this, event, 'content', document.getElementById('" . $lastcom_tooltip[$counter]['id'] . "'), 'trail', false, 'delay', 500,'lifetime',3000,'fade','both','styleClass','niceTitle','fadeMax', 87,'maxWidth', 400);\"";
			}
		} else {
			$hasnewcom = false;
			$onmouseover = "";
		}
		print("<b><a href=\"details.php?id=".$id."&amp;hit=1&amp;cmtpage=1#startcomments\" ".$onmouseover.">". ($hasnewcom ? "<font class='new'>" : ""). $row["comments"] .($hasnewcom ? "</font>" : ""). "</a></b>");
	}

	print("</td>");
	}

	$time = $row["added"];
	$time = gettime($time,false,true);
	print("<td class=\"rowfollow nowrap\">". $time. "</td>");

	//size
	print("<td class=\"rowfollow\">" . mksize_compact($row["size"])."</td>");

	if ($row["seeders"]) {
			$ratio = ($row["leechers"] ? ($row["seeders"] / $row["leechers"]) : 1);
			$ratiocolor = get_slr_color($ratio);
			print("<td class=\"rowfollow\" align=\"center\"><b><a href=\"details.php?id=".$id."&amp;hit=1&amp;dllist=1#seeders\">".($ratiocolor ? "<font color=\"" .
			$ratiocolor . "\">" . number_format($row["seeders"]) . "</font>" : number_format($row["seeders"]))."</a></b></td>\n");
	}
	else
		print("<td class=\"rowfollow\"><span class=\"" . linkcolor($row["seeders"]) . "\">" . number_format($row["seeders"]) . "</span></td>\n");

	if ($row["leechers"]) {
		print("<td class=\"rowfollow\"><b><a href=\"details.php?id=".$id."&amp;hit=1&amp;dllist=1#leechers\">" .
		number_format($row["leechers"]) . "</a></b></td>\n");
	}
	else
		print("<td class=\"rowfollow\">0</td>\n");

	if ($row["times_completed"] >=1)
	print("<td class=\"rowfollow\"><a href=\"viewsnatches.php?id=".$row['id']."\"><b>" . number_format($row["times_completed"]) . "</b></a></td>\n");
	else
	print("<td class=\"rowfollow\">" . number_format($row["times_completed"]) . "</td>\n");

		if (
		    $row["anonymous"] == "yes"
            && (user_can('viewanonymous') || (isset($row['owner']) && $row['owner'] == $CURUSER['id']))
        ) {
			print("<td class=\"rowfollow\" align=\"center\"><i class=\"torrent-uploader-anonymous\">".$lang_functions['text_anonymous']."</i><br />".(isset($row["owner"]) ? "(" . get_username($row["owner"]) .")" : "<i>".$lang_functions['text_orphaned']."</i>") . "</td>\n");
		}
		elseif ($row["anonymous"] == "yes")
		{
			print("<td class=\"rowfollow\"><i class=\"torrent-uploader-anonymous\">".$lang_functions['text_anonymous']."</i></td>\n");
		}
		else
		{
			print("<td class=\"rowfollow\">" . (isset($row["owner"]) ? get_username($row["owner"]) : "<i>".$lang_functions['text_orphaned']."</i>") . "</td>\n");
		}

	if (user_can('torrentmanage'))
	{
        $actions = [];
        if (user_can('torrent-delete')) {
            $actions[] = "<a href=\"".htmlspecialchars("fastdelete.php?id=".$row['id'])."\"><img class=\"staff_delete\" src=\"pic/trans.gif\" alt=\"D\" title=\"".$lang_functions['text_delete']."\" /></a>";
        }
        $actions[] = "<a href=\"edit.php?returnto=" . rawurlencode($_SERVER["REQUEST_URI"]) . "&amp;id=" . $row["id"] . "\"><img class=\"staff_edit\" src=\"pic/trans.gif\" alt=\"E\" title=\"".$lang_functions['text_edit']."\" /></a>";
		echo sprintf("<td class=\"rowfollow\">%s</td>", implode("<br />", $actions));
	}
	print("</tr>\n");
	$counter++;
}
print("</table>");
if ($CURUSER['appendpromotion'] == 'highlight')
	print("<p align=\"center\"> ".$lang_functions['text_promoted_torrents_note']."</p>\n");

if($enabletooltip_tweak == 'yes' && (!isset($CURUSER) || $CURUSER['showlastcom'] == 'yes'))
create_tooltip_container($lastcom_tooltip, 400);
create_tooltip_container($torrent_tooltip, 500);
}

function get_username($id, $big = false, $link = true, $bold = true, $target = false, $bracket = false, $withtitle = false, $link_ext = "", $underline = false)
{
	static $usernameArray = array();
	$id = (int)$id;

	if (func_num_args() == 1 && isset($usernameArray[$id])) {  //One argument=is default display of username. Get it directly from static array if available
		return $usernameArray[$id];
	}
	$arr = get_user_row($id);
	if ($arr){
		if ($big)
		{
			$donorpic = "starbig";
			$leechwarnpic = "leechwarnedbig";
			$warnedpic = "warnedbig";
			$disabledpic = "disabledbig";
			$marginLeft = '4pt';
			$medalSize = '16px';
			$medalClass = 'nexus-username-medal-big';
			$style = "style='margin-left: $marginLeft'";
		}
		else
		{
			$donorpic = "star";
			$leechwarnpic = "leechwarned";
			$warnedpic = "warned";
			$disabledpic = "disabled";
            $marginLeft = '2pt';
            $medalSize = '11px';
            $medalClass = 'nexus-username-medal';
			$style = "style='margin-left: $marginLeft'";
		}
		$pics = $arr["donor"] == "yes" && ($arr['donoruntil'] === null || $arr['donoruntil'] < '1970' || $arr['donoruntil'] >= date('Y-m-d H:i:s')) ? "<img class=\"".$donorpic."\" src=\"/pic/trans.gif\" alt=\"Donor\" ".$style." />" : "";

		if ($arr["enabled"] == "yes")
			$pics .= ($arr["leechwarn"] == "yes" ? "<img class=\"".$leechwarnpic."\" src=\"/pic/trans.gif\" alt=\"Leechwarned\" ".$style." />" : "") . ($arr["warned"] == "yes" ? "<img class=\"".$warnedpic."\" src=\"/pic/trans.gif\" alt=\"Warned\" ".$style." />" : "");
		else
			$pics .= "<img class=\"".$disabledpic."\" src=\"/pic/trans.gif\" alt=\"Disabled\" ".$style." />\n";

		//Rainbow effect
		$username = $arr['username'];
		$rainbow = "";
		$hasSetRainbow = false;
		if (isset($arr['__is_rainbow']) && $arr['__is_rainbow']) {
		    $rainbow = ' class="rainbow"';
        }
		if ($underline) {
		    $hasSetRainbow = true;
		    $username = "<u{$rainbow}>{$username}</u>";
        }
		if ($bold) {
		    if ($hasSetRainbow) {
		        $username = "<b>{$username}</b>";
            } else {
                $hasSetRainbow = true;
		        $username = "<b{$rainbow}>{$username}</b>";
            }
        }
//        $username = ($underline == true ? "<u>" . $arr['username'] . "</u>" : $arr['username']);
//        $username = ($bold == true ? "<b>" . $username . "</b>" : $username);

        //medal
        $medalHtml = '';
		foreach ($arr['wearing_medals'] ?? [] as $medal) {
            $medalHtml .= sprintf(
                '<img src="%s" title="%s" class="%s preview" style="max-height: %s;max-width: %s;margin-left: %s"/>',
                $medal['image_large'], $medal['name'], $medalClass, $medalSize, $medalSize, $marginLeft
            );
        }

		$href = getSchemeAndHttpHost() . "/userdetails.php?id=$id";
		$username = ($link == true ? "<a ". $link_ext . " href=\"" . $href . "\"" . ($target == true ? " target=\"_blank\"" : "") . " class='". get_user_class_name($arr['class'],true, false, false) . "_Name'>" . $username . "</a>" : $username) . $pics . ($withtitle == true ? " (" . ($arr['title'] == "" ?  get_user_class_name($arr['class'],false,true,true, ['with_alias' => true]) : "<span class='".get_user_class_name($arr['class'],true, false, false) . "_Name'><b>".htmlspecialchars($arr['title'])) . "</b></span>)" : "");

		$username = "<span class=\"nowrap\">" . ( $bracket == true ? "(" . $username . ")" : $username) . "$medalHtml</span>";
	}
	else
	{
		$username = "<i>".nexus_trans('nexus.user_not_exists')."</i>";
		$username = "<span class=\"nowrap\">" . ( $bracket == true ? "(" . $username . ")" : $username) . "</span>";
	}
	if (func_num_args() == 1) { //One argument=is default display of username, save it in static array
		$usernameArray[$id] = $username;
	}
	return $username;
}

function get_percent_completed_image($p) {
	$maxpx = "45"; // Maximum amount of pixels for the progress bar

	if ($p == 0) $progress = "<img class=\"progbarrest\" src=\"pic/trans.gif\" style=\"width: " . ($maxpx) . "px;\" alt=\"\" />";
	if ($p == 100) $progress = "<img class=\"progbargreen\" src=\"pic/trans.gif\" style=\"width: " . ($maxpx) . "px;\" alt=\"\" />";
	if ($p >= 1 && $p <= 30) $progress = "<img class=\"progbarred\" src=\"pic/trans.gif\" style=\"width: " . ($p*($maxpx/100)) . "px;\" alt=\"\" /><img class=\"progbarrest\" src=\"pic/trans.gif\" style=\"width: " . ((100-$p)*($maxpx/100)) . "px;\" alt=\"\" />";
	if ($p >= 31 && $p <= 65) $progress = "<img class=\"progbaryellow\" src=\"pic/trans.gif\" style=\"width: " . ($p*($maxpx/100)) . "px;\" alt=\"\" /><img class=\"progbarrest\" src=\"pic/trans.gif\" style=\"width: " . ((100-$p)*($maxpx/100)) . "px;\" alt=\"\" />";
	if ($p >= 66 && $p <= 99) $progress = "<img class=\"progbargreen\" src=\"pic/trans.gif\" style=\"width: " . ($p*($maxpx/100)) . "px;\" alt=\"\" /><img class=\"progbarrest\" src=\"pic/trans.gif\" style=\"width: " . ((100-$p)*($maxpx/100)) . "px;\" alt=\"\" />";
	return "<img class=\"bar_left\" src=\"pic/trans.gif\" alt=\"\" />" . $progress ."<img class=\"bar_right\" src=\"pic/trans.gif\" alt=\"\" />";
}

function get_ratio_img($ratio)
{
	if ($ratio >= 16)
	$s = "163";
	else if ($ratio >= 8)
	$s = "117";
	else if ($ratio >= 4)
	$s = "5";
	else if ($ratio >= 2)
	$s = "3";
	else if ($ratio >= 1)
	$s = "2";
	else if ($ratio >= 0.5)
	$s = "34";
	else if ($ratio >= 0.25)
	$s = "10";
	else
	$s = "52";

	return "<img src=\"pic/smilies/".$s.".gif\" alt=\"\" />";
}

function GetVar ($name) {
	if ( is_array($name) ) {
		foreach ($name as $var) GetVar ($var);
	} else {
		if ( !isset($_REQUEST[$name]) )
		return false;
		$GLOBALS[$name] = $_REQUEST[$name];
		return $GLOBALS[$name];
	}
}

function ssr ($arg) {
	if (is_array($arg)) {
		foreach ($arg as $key=>$arg_bit) {
			$arg[$key] = ssr($arg_bit);
		}
	} else {
		$arg = stripslashes($arg);
	}
	return $arg;
}

function parked()
{
	global $lang_functions;
	global $CURUSER;
	if ($CURUSER["parked"] == "yes")
	stderr($lang_functions['std_access_denied'], $lang_functions['std_your_account_parked']);
}

function validusername($username)
{
	if ($username == "")
	return false;

	// The following characters are allowed in user names
	$allowedchars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    $length = strlen($username);
	for ($i = 0; $i < $length; ++$i)
	if (strpos($allowedchars, $username[$i]) === false)
	return false;

	if ($length < 3 || $length > 20) {
	    return false;
    }

	return true;
}

//Code for Viewing NFO file

// code: Takes a string and does a IBM-437-to-HTML-Unicode-Entities-conversion.
// swedishmagic specifies special behavior for Swedish characters.
// Some Swedish Latin-1 letters collide with popular DOS glyphs. If these
// characters are between ASCII-characters (a-zA-Z and more) they are
// treated like the Swedish letters, otherwise like the DOS glyphs.
function code($ibm_437, $view) {
    $swedishmagic = false;
    if ($view == "magic") {
        $swedishmagic = true;
    }
$table437 = array("\200", "\201", "\202", "\203", "\204", "\205", "\206", "\207",
"\210", "\211", "\212", "\213", "\214", "\215", "\216", "\217", "\220",
"\221", "\222", "\223", "\224", "\225", "\226", "\227", "\230", "\231",
"\232", "\233", "\234", "\235", "\236", "\237", "\240", "\241", "\242",
"\243", "\244", "\245", "\246", "\247", "\250", "\251", "\252", "\253",
"\254", "\255", "\256", "\257", "\260", "\261", "\262", "\263", "\264",
"\265", "\266", "\267", "\270", "\271", "\272", "\273", "\274", "\275",
"\276", "\277", "\300", "\301", "\302", "\303", "\304", "\305", "\306",
"\307", "\310", "\311", "\312", "\313", "\314", "\315", "\316", "\317",
"\320", "\321", "\322", "\323", "\324", "\325", "\326", "\327", "\330",
"\331", "\332", "\333", "\334", "\335", "\336", "\337", "\340", "\341",
"\342", "\343", "\344", "\345", "\346", "\347", "\350", "\351", "\352",
"\353", "\354", "\355", "\356", "\357", "\360", "\361", "\362", "\363",
"\364", "\365", "\366", "\367", "\370", "\371", "\372", "\373", "\374",
"\375", "\376", "\377");

$tablehtml = array("&#x00c7;", "&#x00fc;", "&#x00e9;", "&#x00e2;", "&#x00e4;",
"&#x00e0;", "&#x00e5;", "&#x00e7;", "&#x00ea;", "&#x00eb;", "&#x00e8;",
"&#x00ef;", "&#x00ee;", "&#x00ec;", "&#x00c4;", "&#x00c5;", "&#x00c9;",
"&#x00e6;", "&#x00c6;", "&#x00f4;", "&#x00f6;", "&#x00f2;", "&#x00fb;",
"&#x00f9;", "&#x00ff;", "&#x00d6;", "&#x00dc;", "&#x00a2;", "&#x00a3;",
"&#x00a5;", "&#x20a7;", "&#x0192;", "&#x00e1;", "&#x00ed;", "&#x00f3;",
"&#x00fa;", "&#x00f1;", "&#x00d1;", "&#x00aa;", "&#x00ba;", "&#x00bf;",
"&#x2310;", "&#x00ac;", "&#x00bd;", "&#x00bc;", "&#x00a1;", "&#x00ab;",
"&#x00bb;", "&#x2591;", "&#x2592;", "&#x2593;", "&#x2502;", "&#x2524;",
"&#x2561;", "&#x2562;", "&#x2556;", "&#x2555;", "&#x2563;", "&#x2551;",
"&#x2557;", "&#x255d;", "&#x255c;", "&#x255b;", "&#x2510;", "&#x2514;",
"&#x2534;", "&#x252c;", "&#x251c;", "&#x2500;", "&#x253c;", "&#x255e;",
"&#x255f;", "&#x255a;", "&#x2554;", "&#x2569;", "&#x2566;", "&#x2560;",
"&#x2550;", "&#x256c;", "&#x2567;", "&#x2568;", "&#x2564;", "&#x2565;",
"&#x2559;", "&#x2558;", "&#x2552;", "&#x2553;", "&#x256b;", "&#x256a;",
"&#x2518;", "&#x250c;", "&#x2588;", "&#x2584;", "&#x258c;", "&#x2590;",
"&#x2580;", "&#x03b1;", "&#x00df;", "&#x0393;", "&#x03c0;", "&#x03a3;",
"&#x03c3;", "&#x03bc;", "&#x03c4;", "&#x03a6;", "&#x0398;", "&#x03a9;",
"&#x03b4;", "&#x221e;", "&#x03c6;", "&#x03b5;", "&#x2229;", "&#x2261;",
"&#x00b1;", "&#x2265;", "&#x2264;", "&#x2320;", "&#x2321;", "&#x00f7;",
"&#x2248;", "&#x00b0;", "&#x2219;", "&#x00b7;", "&#x221a;", "&#x207f;",
"&#x00b2;", "&#x25a0;", "&#x00a0;");
$s = htmlspecialchars($ibm_437);


// 0-9, 11-12, 14-31, 127 (decimalt)
$control =
array("\000", "\001", "\002", "\003", "\004", "\005", "\006", "\007",
"\010", "\011", /*"\012",*/ "\013", "\014", /*"\015",*/ "\016", "\017",
"\020", "\021", "\022", "\023", "\024", "\025", "\026", "\027",
"\030", "\031", "\032", "\033", "\034", "\035", "\036", "\037",
"\177");

/* Code control characters to control pictures.
http://www.unicode.org/charts/PDF/U2400.pdf
(This is somewhat the Right Thing, but looks crappy with Courier New.)
$controlpict = array("&#x2423;","&#x2404;");
$s = str_replace($control,$controlpict,$s); */

// replace control chars with space - feel free to fix the regexp smile.gif
/*echo "[a\\x00-\\x1F]";
//$s = preg_replace("/[ \\x00-\\x1F]/", " ", $s);
$s = preg_replace("/[ \000-\037]/", " ", $s); */
$s = str_replace($control," ",$s);




if ($swedishmagic){
$s = str_replace("\345","\206",$s);
$s = str_replace("\344","\204",$s);
$s = str_replace("\366","\224",$s);
// $s = str_replace("\304","\216",$s);
//$s = "[ -~]\\xC4[a-za-z]";

// couldn't get ^ and $ to work, even through I read the man-pages,
// i'm probably too tired and too unfamiliar with posix regexps right now.
$s = preg_replace("/([ -~])\305([ -~])/", "\\1\217\\2", $s);
$s = preg_replace("/([ -~])\304([ -~])/", "\\1\216\\2", $s);
$s = preg_replace("/([ -~])\326([ -~])/", "\\1\231\\2", $s);

$s = str_replace("\311", "\220", $s); //
$s = str_replace("\351", "\202", $s); //
}

$s = str_replace($table437, $tablehtml, $s);
return $s;
}

/**
 * @param $ibm_437
 * @param $view
 * @return array|string|string[]
 * @ref https://github.com/HDInnovations/UNIT3D-Community-Edition/blob/master/app/Helpers/Nfo.php
 */
function code_new($ibm_437, $view)
{
    $swedishmagic = false;
    if ($view == "magic") {
        $swedishmagic = true;
    }
    $cf = array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 70, 71, 72, 73, 74, 75, 76, 77, 78, 79, 80, 81, 82, 83, 84, 85, 86, 87, 88, 89, 90, 91, 92, 93, 94, 95, 96, 97, 98, 99, 100, 101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112, 113, 114, 115, 116, 117, 118, 119, 120, 121, 122, 123, 124, 125, 126, 8962, 199, 252, 233, 226, 228, 224, 229, 231, 234, 235, 232, 239, 238, 236, 196, 197, 201, 230, 198, 244, 246, 242, 251, 249, 255, 214, 220, 162, 163, 165, 8359, 402, 225, 237, 243, 250, 241, 209, 170, 186, 191, 8976, 172, 189, 188, 161, 171, 187, 9617, 9618, 9619, 9474, 9508, 9569, 9570, 9558, 9557, 9571, 9553, 9559, 9565, 9564, 9563, 9488, 9492, 9524, 9516, 9500, 9472, 9532, 9566, 9567, 9562, 9556, 9577, 9574, 9568, 9552, 9580, 9575, 9576, 9572, 9573, 9561, 9560, 9554, 9555, 9579, 9578, 9496, 9484, 9608, 9604, 9612, 9616, 9600, 945, 223, 915, 960, 931, 963, 181, 964, 934, 920, 937, 948, 8734, 966, 949, 8745, 8801, 177, 8805, 8804, 8992, 8993, 247, 8776, 176, 8729, 183, 8730, 8319, 178, 9632, 160);
    $s = "";
    for ($c = 0; $c < strlen($ibm_437); $c++) {  // cyctle through the whole file doing a byte at a time.
        $byte = $ibm_437[$c];
        $ob = ord($byte);
        if ($ob >= 127) {  // is it in the normal ascii range
            $s .= '&#' . $cf[$ob] . ';';
        } else {
            $s .= $byte;
        }
    }

    if ($swedishmagic) {
        $s = str_replace("\345","\206",$s);
        $s = str_replace("\344","\204",$s);
        $s = str_replace("\366","\224",$s);
        $s = preg_replace("/([ -~])\305([ -~])/", "\\1\217\\2", $s);
        $s = preg_replace("/([ -~])\304([ -~])/", "\\1\216\\2", $s);
        $s = preg_replace("/([ -~])\326([ -~])/", "\\1\231\\2", $s);
        $s = str_replace ( "\311", "\220", $s ); //
        $s = str_replace ( "\351", "\202", $s ); //
    }

    return $s;
}


//Tooltip container for hot movie, classic movie, etc
function create_tooltip_container($id_content_arr, $width = 400)
{
	if(count($id_content_arr))
	{
		$result = "<div style=\"display: none\">";
		foreach($id_content_arr as $id_content_arr_each)
		{
			$result .= "<div id=\"" . $id_content_arr_each['id'] . "\">" . $id_content_arr_each['content'] . "</div>";
		}
		$result .= "</div>";
		print($result);
	}
}

function getimdb($imdb_id, $cache_stamp, $mode = 'minor')
{
	global $lang_functions;
	global $showextinfo;
	$thenumbers = $imdb_id;
	$imdb = new Nexus\Imdb\Imdb();
	$movie = $imdb->getMovie($imdb_id);
	$movieid = $thenumbers;
//	$movie->setid ($movieid);

	$target = array('Title', 'Credits', 'Plot');
	switch ($imdb->getCacheStatus($imdb_id))
	{
		case "0": //cache is not ready
			{
			return false;
			break;
			}
		case "1": //normal
			{
				$title = $movie->title ();
				$year = $movie->year ();
				$country = $movie->country ();
				$countries = "";
				$temp = "";
				for ($i = 0; $i < count ($country); $i++)
				{
					$temp .="$country[$i], ";
				}
				$countries = rtrim(trim($temp), ",");

				$director = $movie->director();
				$director_or_creator = "";
				if ($director)
				{
					$temp = "";
					for ($i = 0; $i < count ($director); $i++)
					{
						$temp .= $director[$i]["name"].", ";
					}
					$director_or_creator = "<strong><font color=\"DarkRed\">".$lang_functions['text_director'].": </font></strong>".rtrim(trim($temp), ",");
				}
				else { //for tv series
					$creator = $movie->creator();
                    $names = array_column($creator, "name");
					$director_or_creator = "<strong><font color=\"DarkRed\">".$lang_functions['text_creator'].": </font></strong>".implode(", ", $names);
				}
				$cast = $movie->cast();
				$temp = "";
				for ($i = 0; $i < count ($cast); $i++) //get names of first three casts
				{
					if ($i > 2)
					{
						break;
					}
					$temp .= $cast[$i]["name"].", ";
				}
				$casts = rtrim(trim($temp), ",");
				$gen = $movie->genres();
				$genres = $gen[0].(count($gen) > 1 ? ", ".$gen[1] : ""); //get first two genres;
				$rating = $movie->rating ();
				$votes = $movie->votes ();
				if ($votes)
					$imdbrating = "<b>".$rating."</b>/10 (".$votes.$lang_functions['text_votes'].")";
				else $imdbrating = $lang_functions['text_awaiting_five_votes'];

				$tagline = $movie->tagline ();
				switch ($mode)
				{
				case 'minor' :
					{
					$autodata = "<font class=\"big\"><b>".$title."</b></font> (".$year.") <br /><strong><font color=\"DarkRed\">".$lang_functions['text_imdb'].": </font></strong>".$imdbrating." <strong><font color=\"DarkRed\">".$lang_functions['text_country'].": </font></strong>".$countries." <strong><font color=\"DarkRed\">".$lang_functions['text_genres'].": </font></strong>".$genres."<br />".$director_or_creator."<strong><font color=\"DarkRed\"> ".$lang_functions['text_starring'].": </font></strong>".$casts."<br /><p><strong>".$tagline."</strong></p>";
					break;
					}
				case 'median':
					{
					if (($photo_url = $movie->photo() ) != FALSE)
						$smallth = "<img src=\"".$photo_url. "\" width=\"105\" alt=\"poster\" />";
					else $smallth = "";
					$runtime = $movie->runtime ();
					$language = $movie->language ();
					$plot = $movie->plot ();
					$plots = "";
					if(count($plot) != 0){ //get plots from plot page
							$plots .= "<font color=\"DarkRed\">*</font> ".strip_tags($plot[0], '<br /><i>');
							$plots = mb_substr($plots,0,300,"UTF-8") . (mb_strlen($plots,"UTF-8") > 300 ? " ..." : "" );
							$plots .= (strpos($plots,"<i>") == true && strpos($plots,"</i>") == false ? "</i>" : "");//sometimes <i> is open and not ended because of mb_substr;
							$plots = "<font class=\"small\">".$plots."</font>";
						}
					elseif ($plotoutline = $movie->plotoutline ()){ //get plot from title page
						$plots .= "<font color=\"DarkRed\">*</font> ".strip_tags($plotoutline, '<br /><i>');
						$plots = mb_substr($plots,0,300,"UTF-8") . (mb_strlen($plots,"UTF-8") > 300 ? " ..." : "" );
						$plots .= (strpos($plots,"<i>") == true && strpos($plots,"</i>") == false ? "</i>" : "");//sometimes <i> is open and not ended because of mb_substr;
						$plots = "<font class=\"small\">".$plots."</font>";
						}
					$autodata = "<table style=\"background-color: transparent;\" border=\"0\" cellspacing=\"0\" cellpadding=\"3\">
".($smallth ? "<td class=\"clear\" valign=\"top\" align=\"right\">
$smallth
</td>" : "")
."<td class=\"clear\" valign=\"top\" align=\"left\">
<table style=\"background-color: transparent;\" border=\"0\" cellspacing=\"0\" cellpadding=\"3\" width=\"350\">
<tr><td class=\"clear\" colspan=\"2\"><img class=\"imdb\" src=\"pic/trans.gif\" alt=\"imdb\" /> <font class=\"big\"><b>".$title."</b></font> (".$year.") </td></tr>
<tr><td class=\"clear\"><strong><font color=\"DarkRed\">".$lang_functions['text_imdb'].": </font></strong>".$imdbrating."</td>
".( $runtime ? "<td class=\"clear\"><strong><font color=\"DarkRed\">".$lang_functions['text_runtime'].": </font></strong>".$runtime.$lang_functions['text_min']."</td>" : "<td class=\"clear\"></td>")."</tr>
<tr><td class=\"clear\"><strong><font color=\"DarkRed\">".$lang_functions['text_country'].": </font></strong>".$countries."</td>
".( $language ? "<td class=\"clear\"><strong><font color=\"DarkRed\">".$lang_functions['text_language'].": </font></strong>".$language."</td>" : "<td class=\"clear\"></td>")."</tr>
<tr><td class=\"clear\">".$director_or_creator."</td>
<td class=\"clear\"><strong><font color=\"DarkRed\">".$lang_functions['text_genres'].": </font></strong>".$genres."</td></tr>
<tr><td class=\"clear\" colspan=\"2\"><strong><font color=\"DarkRed\">".$lang_functions['text_starring'].": </font></strong>".$casts."</td></tr>
".( $plots ? "<tr><td class=\"clear\" colspan=\"2\">".$plots."</td></tr>" : "")."
</table>
</td>
</table>";
					break;
					}
				}
				return $autodata;
			}
			case "2" :
			{
				return false;
				break;
			}
			case "3" :
			{
				return false;
				break;
			}
	}
}

function quickreply($formname, $taname,$submit){
	print("<textarea name='".$taname."' cols=\"100\" rows=\"8\" style=\"width: 450px\" onkeydown=\"ctrlenter(event,'compose','qr')\"></textarea>");
	print(smile_row($formname, $taname));
	print("<br />");
	print("<label class=\"forum-anonymous-option\"><input type=\"checkbox\" name=\"anonymous\" value=\"yes\" /> 匿名发表</label><br />");
 	print("<input type=\"submit\" id=\"qr\" class=\"btn\" value=\"".$submit."\" />");
}

function smile_row($formname, $taname){
	$quickSmilesNumbers = array(4, 5, 39, 25, 11, 8, 10, 15, 27, 57, 42, 122, 52, 28, 29, 30, 176);
	$smilerow = "<div align=\"center\">";
	foreach ($quickSmilesNumbers as $smilyNumber) {
		$smilerow .= getSmileIt($formname, $taname, $smilyNumber);
	}
	$smilerow .= "</div>";
	return $smilerow;
}
function getSmileIt($formname, $taname, $smilyNumber) {
	return "<a href=\"javascript: SmileIT('[em$smilyNumber]','".$formname."','".$taname."')\"  onmouseover=\"domTT_activate(this, event, 'content', '".htmlspecialchars("<table><tr><td><img src=\'pic/smilies/$smilyNumber.gif\' alt=\'\' /></td></tr></table>")."', 'trail', false, 'delay', 0,'lifetime',10000,'styleClass','smilies','maxWidth', 400);\"><img style=\"max-width: 25px;\" src=\"pic/smilies/$smilyNumber.gif\" alt=\"\" /></a>";
}

function classlist($selectname,$maxclass, $selected, $minClass = 0, $includeNoClass = false, $disabled = false){
    global $lang_functions;
    $disabledText = '';
    if ($disabled) {
        $disabledText = ' disabled = "disabled"';
    }
	$list = "<select name=\"".$selectname."\"$disabledText>";
	if ($includeNoClass) {
        $list .= sprintf('<option value="%s">%s</option>', \App\Models\Setting::PERMISSION_NO_CLASS, $lang_functions['select_an_user_class']);
    }
	for ($i = $minClass; $i <= $maxclass; $i++)
		$list .= "<option value=\"".$i."\"" . ($selected == $i ? " selected=\"selected\"" : "") . ">" . get_user_class_name($i,false,false,true) . "</option>\n";
	$list .= "</select>";
	return $list;
}

function permissiondenied($allowMinimumClass = null){
	global $lang_functions;
	if ($allowMinimumClass === null) {
        stderr($lang_functions['std_error'], $lang_functions['std_permission_denied']);
    } else {
        stderr($lang_functions['std_sorry'],$lang_functions['std_permission_denied_only'].get_user_class_name($allowMinimumClass,false,true,true).sprintf($lang_functions['std_or_above_can_view'], \App\Models\Setting::getSiteName()),false);
    }
}

function gettime($time, $withago = true, $twoline = false, $forceago = false, $oneunit = false, $isfuturetime = false){
    if (empty($time)) {
        return null;
    }
	if (!IN_NEXUS) {
        try {
            return \Carbon\Carbon::parse($time)->diffForHumans();
        } catch (\Exception $e) {
            do_log($e->getMessage() . $e->getTraceAsString(), 'error');
            return $time;
        }
    }
    global $lang_functions, $CURUSER;
	if (isset($CURUSER) && $CURUSER['timetype'] != 'timealive' && !$forceago){
		$newtime = $time;
		if ($twoline){
		$newtime = str_replace(" ", "<br />", $newtime);
		}
	}
	else{
		$timestamp = strtotime($time);
		if ($isfuturetime && $timestamp < TIMENOW)
			$newtime = false;
		else
		{
			$newtime = get_elapsed_time($timestamp,$oneunit).($withago ? $lang_functions['text_ago'] : "");
			if($twoline){
				$newtime = str_replace("&nbsp;", "<br />", $newtime);
			}
			elseif($oneunit){
				if ($length = strpos($newtime, "&nbsp;"))
					$newtime = substr($newtime,0,$length);
			}
			else $newtime = str_replace("&nbsp;", $lang_functions['text_space'], $newtime);
			$newtime = "<span title=\"".$time."\">".$newtime."</span>";
		}
	}
	return $newtime;
}

function get_forum_pic_folder(){
	global $CURLANGDIR;
	return "pic/forum_pic/".$CURLANGDIR;
}

function get_category_icon_row($typeid)
{
	global $Cache;
	static $rows;
	if (!$typeid) {
		$typeid=1;
	}
	if (!$rows && !$rows = $Cache->get_value('category_icon_content')){
		$rows = array();
		$res = sql_query("SELECT * FROM caticons ORDER BY id ASC");
		while($row = mysql_fetch_array($res)) {
			$rows[$row['id']] = $row;
		}
		$Cache->cache_value('category_icon_content', $rows, 156400);
	}
	return $rows[$typeid];
}
function get_category_row($catid = NULL)
{
	global $Cache;
	static $rows;
	if (!$rows && !$rows = $Cache->get_value('category_content')){
        $rows = [];
		$res = sql_query("SELECT categories.*, searchbox.name AS catmodename FROM categories LEFT JOIN searchbox ON categories.mode=searchbox.id");
		while($row = mysql_fetch_array($res)) {
			$rows[$row['id']] = $row;
		}
		$Cache->cache_value('category_content', $rows, 126400);
	}
	if ($catid) {
		return $rows[$catid];
	} else {
		return $rows;
	}
}

function get_second_icon($row) //for CHDBits
{
	global $CURUSER, $Cache;
	$source=$row['source'];
	$medium=$row['medium'];
	$codec=$row['codec'];
	$standard=$row['standard'];
	$processing=$row['processing'];
	$team=$row['team'];
	$audiocodec=$row['audiocodec'];
	$mode = $row['search_box_id'];
	$cacheKey = 'secondicon_'.$source.'_'.$medium.'_'.$codec.'_'.$standard.'_'.$processing.'_'.$team.'_'.$audiocodec.'_content';
	if (!$sirow = $Cache->get_value($cacheKey)){
		$res = sql_query("SELECT * FROM secondicons WHERE (mode = ".sqlesc($mode)." OR mode = 0) AND (source = ".sqlesc($source)." OR source=0) AND (medium = ".sqlesc($medium)." OR medium=0) AND (codec = ".sqlesc($codec)." OR codec = 0) AND (standard = ".sqlesc($standard)." OR standard = 0) AND (processing = ".sqlesc($processing)." OR processing = 0) AND (team = ".sqlesc($team)." OR team = 0) AND (audiocodec = ".sqlesc($audiocodec)." OR audiocodec = 0) LIMIT 1");
		$sirow = mysql_fetch_array($res);
		if (!$sirow)
			$sirow = 'not allowed';
		$Cache->cache_value($cacheKey, $sirow, 600);
	}
	$catimgurl = get_cat_folder($row['category']);
	if ($sirow == 'not allowed')
		return "<img src=\"pic/cattrans.gif\" style=\"background-image: url(pic/". $catimgurl. "/additional/notallowed.png);\" title=\"Not Allowed\" alt=\"Not Allowed\" />";
	else {
		return "<img".($sirow['class_name'] ? " class=\"".$sirow['class_name']."\"" : "")." src=\"pic/cattrans.gif\" style=\"background-image: url(pic/". $catimgurl. "/additional/". $sirow['image'].");\" alt=\"" . $sirow["name"] . "\" title=\"".$sirow['name']."\" />";
	}
}

/**
 * Build the "in effect / upcoming" promotion banner subject, combining the
 * site-wide (global) and official-group promotions of a torrents_state row.
 * e.g. "全站 [Free] /官组[2x]" -> wrapped by $wrapTpl (a sprintf "%s" template).
 */
function build_full_site_promotion_subject(array $promotion, string $wrapTpl): string
{
    global $lang_functions;
    $normal = \App\Models\Torrent::PROMOTION_NORMAL;
    $globalState = (int)($promotion['global_sp_state'] ?? $normal);
    $officialState = (int)($promotion['official_sp_state'] ?? $normal);
    $segGlobalTpl = $lang_functions['full_site_promotion_segment_global'] ?? '全站 [%s]';
    $segOfficialTpl = $lang_functions['full_site_promotion_segment_official'] ?? '官组[%s]';
    $segments = [];
    if ($globalState != $normal) {
        $segments[] = sprintf($segGlobalTpl, \App\Models\Torrent::$promotionTypes[$globalState]['text'] ?? '');
    }
    if ($officialState != $normal) {
        $segments[] = sprintf($segOfficialTpl, \App\Models\Torrent::$promotionTypes[$officialState]['text'] ?? '');
    }
    return sprintf($wrapTpl, implode(' /', $segments));
}

/**
 * SQL WHERE fragment selecting torrents whose *effective* promotion (taking the
 * active site-wide + official-group promotions into account) equals $n. Used by
 * the torrents browse "filter by promotion type".
 */
function get_promotion_filter_where_clause($n)
{
    $normal = \App\Models\Torrent::PROMOTION_NORMAL;
    $n = (int)$n;
    $global = (int)get_global_sp_state();
    $official = (int)get_official_sp_state();
    $officialTag = (int)get_setting('bonus.official_tag', 0);

    // For a bucket whose active promo is $activePromo:
    //  - promo active -> every torrent takes it (match iff it equals $n)
    //  - no promo     -> the torrent's own sp_state decides
    $cond = function ($activePromo) use ($n, $normal) {
        if ($activePromo != $normal) {
            return ($activePromo == $n) ? "1=1" : "1=0";
        }
        return "torrents.sp_state = $n";
    };

    $nonOfficialCond = $cond($global);

    // No official promotion in play -> classic global-only behaviour for everyone.
    if ($officialTag <= 0 || $official == $normal) {
        return "(" . $nonOfficialCond . ")";
    }

    $officialCond = $cond($official);
    $isOfficial = "EXISTS (SELECT 1 FROM torrent_tags tt WHERE tt.torrent_id = torrents.id AND tt.tag_id = $officialTag)";
    return "( ($isOfficial AND ($officialCond)) OR (NOT $isOfficial AND ($nonOfficialCond)) )";
}

function get_torrent_bg_color($promotion = 1, $posState = "", array $torrent = [])
{
	global $CURUSER;
    $sphighlight = null;
	if ($CURUSER['appendpromotion'] == 'highlight'){
		$global_promotion_state = get_global_sp_state();
		$official_promotion_state = get_official_sp_state();
		if ($official_promotion_state != \App\Models\Torrent::PROMOTION_NORMAL && !empty($torrent['id']) && torrent_has_official_tag($torrent['id'])) {
			$global_promotion_state = $official_promotion_state;
		}
		if ($global_promotion_state == 1){
			if($promotion==1)
				$sphighlight = "";
			elseif($promotion==2)
				$sphighlight = " class='free_bg'";
			elseif($promotion==3)
				$sphighlight = " class='twoup_bg'";
			elseif($promotion==4)
				$sphighlight = " class='twoupfree_bg'";
			elseif($promotion==5)
				$sphighlight = " class='halfdown_bg'";
			elseif($promotion==6)
				$sphighlight = " class='twouphalfdown_bg'";
			elseif($promotion==7)
				$sphighlight = " class='thirtypercentdown_bg'";
		}
		elseif($global_promotion_state == 2)
			$sphighlight = " class='free_bg'";
		elseif($global_promotion_state == 3)
			$sphighlight = " class='twoup_bg'";
		elseif($global_promotion_state == 4)
			$sphighlight = " class='twoupfree_bg'";
		elseif($global_promotion_state == 5)
			$sphighlight = " class='halfdown_bg'";
		elseif($global_promotion_state == 6)
			$sphighlight = " class='twouphalfdown_bg'";
		elseif($global_promotion_state == 7)
			$sphighlight = " class='thirtypercentdown_bg'";
	}
	if (is_null($sphighlight)) {
        $torrentSettings = get_setting('torrent');
	    if ($posState == \App\Models\Torrent::POS_STATE_STICKY_FIRST && !empty($torrentSettings['sticky_first_level_background_color'])) {
	        $sphighlight = sprintf(' style="background-color: %s"', $torrentSettings['sticky_first_level_background_color']);
        } elseif ($posState == \App\Models\Torrent::POS_STATE_STICKY_SECOND && !empty($torrentSettings['sticky_second_level_background_color'])) {
            $sphighlight = sprintf(' style="background-color: %s"', $torrentSettings['sticky_second_level_background_color']);
        }
    }
	return apply_filter('torrent_background_color', (string)$sphighlight, $torrent);
}

function get_torrent_promotion_append($promotion = 1,$forcemode = "",$showtimeleft = false, $added = "", $promotionTimeType = 0, $promotionUntil = '', $ignoreGlobal = false, $torrentId = null){
	global $CURUSER,$lang_functions;
	global $expirehalfleech_torrent, $expirefree_torrent, $expiretwoup_torrent, $expiretwoupfree_torrent, $expiretwouphalfleech_torrent, $expirethirtypercentleech_torrent;

	$globalSpState = get_global_sp_state();
	if (!$ignoreGlobal) {
		$officialSpState = get_official_sp_state();
		if ($officialSpState != \App\Models\Torrent::PROMOTION_NORMAL && $torrentId && torrent_has_official_tag($torrentId)) {
			$globalSpState = $officialSpState;
		}
	}
	$sp_torrent = "";
	$onmouseover = "";
	$log = "[GET_PROMOTION], promotion: $promotion, forcemode: $forcemode, showtimeleft: $showtimeleft, added: $added, promotionTimeType: $promotionTimeType, promotionUntil: $promotionUntil";
    if ($ignoreGlobal) {
        $globalSpState = 1;
        $log .= ", [IGNORE_GLOBAL]";
    }
	$log .= ", globalSpState == " . $globalSpState;
	if ($globalSpState == 1) {
	switch ($promotion){
		case 2:
		{
			if ($showtimeleft && (($expirefree_torrent && $promotionTimeType == 0) || $promotionTimeType == 2))
			{
				if ($promotionTimeType == 2) {
					$futuretime = strtotime($promotionUntil);
				} else {
					$futuretime = strtotime($added) + $expirefree_torrent * 86400;
				}
				$timeout = gettime(date("Y-m-d H:i:s", $futuretime), false, false, true, false, true);
				if ($timeout)
				$onmouseover = " onmouseover=\"domTT_activate(this, event, 'content', '".htmlspecialchars("<b><font class=\"free\">".$lang_functions['text_free']."</font></b>".$lang_functions['text_will_end_in']."<b>".$timeout."</b>")."', 'trail', false, 'delay',500,'lifetime',3000,'fade','both','styleClass','niceTitle', 'fadeMax',87, 'maxWidth', 300);\"";
				else $promotion = 1;
			}
			break;
		}
		case 3:
		{
			if ($showtimeleft && (($expiretwoup_torrent && $promotionTimeType == 0) || $promotionTimeType == 2))
			{
				if ($promotionTimeType == 2) {
					$futuretime = strtotime($promotionUntil);
				} else {
					$futuretime = strtotime($added) + $expiretwoup_torrent * 86400;
				}
				$timeout = gettime(date("Y-m-d H:i:s", $futuretime), false, false, true, false, true);
				if ($timeout)
				$onmouseover = " onmouseover=\"domTT_activate(this, event, 'content', '".htmlspecialchars("<b><font class=\"twoup\">".$lang_functions['text_two_times_up']."</font></b>".$lang_functions['text_will_end_in']."<b>".$timeout."</b>")."', 'trail', false, 'delay',500,'lifetime',3000,'fade','both','styleClass','niceTitle', 'fadeMax',87, 'maxWidth', 300);\"";
				else $promotion = 1;
			}
			break;
		}
		case 4:
		{
			if ($showtimeleft && (($expiretwoupfree_torrent && $promotionTimeType == 0) || $promotionTimeType == 2))
			{
				if ($promotionTimeType == 2) {
					$futuretime = strtotime($promotionUntil);
				} else {
					$futuretime = strtotime($added) + $expiretwoupfree_torrent * 86400;
				}
				$timeout = gettime(date("Y-m-d H:i:s", $futuretime), false, false, true, false, true);
				if ($timeout)
				$onmouseover = " onmouseover=\"domTT_activate(this, event, 'content', '".htmlspecialchars("<b><font class=\"twoupfree\">".$lang_functions['text_free_two_times_up']."</font></b>".$lang_functions['text_will_end_in']."<b>".$timeout."</b>")."', 'trail', false, 'delay',500,'lifetime',3000,'fade','both','styleClass','niceTitle', 'fadeMax',87, 'maxWidth', 300);\"";
				else $promotion = 1;
			}
			break;
		}
		case 5:
		{
			if ($showtimeleft && (($expirehalfleech_torrent && $promotionTimeType == 0) || $promotionTimeType == 2))
			{
				if ($promotionTimeType == 2) {
					$futuretime = strtotime($promotionUntil);
				} else {
					$futuretime = strtotime($added) + $expirehalfleech_torrent * 86400;
				}
				$timeout = gettime(date("Y-m-d H:i:s", $futuretime), false, false, true, false, true);
				if ($timeout)
				$onmouseover = " onmouseover=\"domTT_activate(this, event, 'content', '".htmlspecialchars("<b><font class=\"halfdown\">".$lang_functions['text_half_down']."</font></b>".$lang_functions['text_will_end_in']."<b>".$timeout."</b>")."', 'trail', false, 'delay',500,'lifetime',3000,'fade','both','styleClass','niceTitle', 'fadeMax',87, 'maxWidth', 300);\"";
				else $promotion = 1;
			}
			break;
		}
		case 6:
		{
			if ($showtimeleft && (($expiretwouphalfleech_torrent && $promotionTimeType == 0) || $promotionTimeType == 2))
			{
				if ($promotionTimeType == 2) {
					$futuretime = strtotime($promotionUntil);
				} else {
					$futuretime = strtotime($added) + $expiretwouphalfleech_torrent * 86400;
				}
				$timeout = gettime(date("Y-m-d H:i:s", $futuretime), false, false, true, false, true);
				if ($timeout)
				$onmouseover = " onmouseover=\"domTT_activate(this, event, 'content', '".htmlspecialchars("<b><font class=\"twouphalfdown\">".$lang_functions['text_half_down_two_up']."</font></b>".$lang_functions['text_will_end_in']."<b>".$timeout."</b>")."', 'trail', false, 'delay',500,'lifetime',3000,'fade','both','styleClass','niceTitle', 'fadeMax',87, 'maxWidth', 300);\"";
				else $promotion = 1;
			}
			break;
		}
		case 7:
		{
			if ($showtimeleft && (($expirethirtypercentleech_torrent && $promotionTimeType == 0) || $promotionTimeType == 2))
			{
				if ($promotionTimeType == 2) {
					$futuretime = strtotime($promotionUntil);
				} else {
					$futuretime = strtotime($added) + $expirethirtypercentleech_torrent * 86400;
				}
				$timeout = gettime(date("Y-m-d H:i:s", $futuretime), false, false, true, false, true);
				if ($timeout)
				$onmouseover = " onmouseover=\"domTT_activate(this, event, 'content', '".htmlspecialchars("<b><font class=\"thirtypercent\">".$lang_functions['text_thirty_percent_down']."</font></b>".$lang_functions['text_will_end_in']."<b>".$timeout."</b>")."', 'trail', false, 'delay',500,'lifetime',3000,'fade','both','styleClass','niceTitle', 'fadeMax',87, 'maxWidth', 300);\"";
				else $promotion = 1;
			}
			break;
		}
	}
	}
	if (($CURUSER['appendpromotion'] == 'word' && $forcemode == "" ) || $forcemode == 'word'){
        $log .= ", user appendpromotion = word";
		if(($promotion==2 && $globalSpState == 1) || $globalSpState == 2){
		    $log .= ", promotion or global_sp_state = 2";
			$sp_torrent = " <b>[<font class='free' ".$onmouseover.">".$lang_functions['text_free']."</font>]</b>";
		}
		elseif(($promotion==3 && $globalSpState == 1) || $globalSpState == 3){
            $log .= ", promotion or global_sp_state = 3";
			$sp_torrent = " <b>[<font class='twoup' ".$onmouseover.">".$lang_functions['text_two_times_up']."</font>]</b>";
		}
		elseif(($promotion==4 && $globalSpState == 1) || $globalSpState == 4){
            $log .= ", promotion or global_sp_state = 4";
			$sp_torrent = " <b>[<font class='twoupfree' ".$onmouseover.">".$lang_functions['text_free_two_times_up']."</font>]</b>";
		}
		elseif(($promotion==5 && $globalSpState == 1) || $globalSpState == 5){
            $log .= ", promotion or global_sp_state = 5";
			$sp_torrent = " <b>[<font class='halfdown' ".$onmouseover.">".$lang_functions['text_half_down']."</font>]</b>";
		}
		elseif(($promotion==6 && $globalSpState == 1) || $globalSpState == 6){
            $log .= ", promotion or global_sp_state = 6";
			$sp_torrent = " <b>[<font class='twouphalfdown' ".$onmouseover.">".$lang_functions['text_half_down_two_up']."</font>]</b>";
		}
		elseif(($promotion==7 && $globalSpState == 1) || $globalSpState == 7){
            $log .= ", promotion or global_sp_state = 7";
			$sp_torrent = " <b>[<font class='thirtypercent' ".$onmouseover.">".$lang_functions['text_thirty_percent_down']."</font>]</b>";
		}
	}
	elseif (($CURUSER['appendpromotion'] == 'icon' && $forcemode == "") || $forcemode == 'icon'){
        $log .= ", user appendpromotion = icon";
		if(($promotion==2 && $globalSpState == 1) || $globalSpState == 2) {
            $log .= ", promotion or global_sp_state = 2";
            $sp_torrent = " <img class=\"pro_free\" src=\"pic/trans.gif\" alt=\"Free\" ".($onmouseover ? $onmouseover : "title=\"".$lang_functions['text_free']."\"")." />";
        }
		elseif(($promotion==3 && $globalSpState == 1) || $globalSpState == 3) {
            $log .= ", promotion or global_sp_state = 3";
            $sp_torrent = " <img class=\"pro_2up\" src=\"pic/trans.gif\" alt=\"2X\" ".($onmouseover ? $onmouseover : "title=\"".$lang_functions['text_two_times_up']."\"")." />";
        }
		elseif(($promotion==4 && $globalSpState == 1) || $globalSpState == 4) {
            $log .= ", promotion or global_sp_state = 4";
            $sp_torrent = " <img class=\"pro_free2up\" src=\"pic/trans.gif\" alt=\"2X Free\" ".($onmouseover ? $onmouseover : "title=\"".$lang_functions['text_free_two_times_up']."\"")." />";
        }
		elseif(($promotion==5 && $globalSpState == 1) || $globalSpState == 5) {
            $log .= ", promotion or global_sp_state = 5";
            $sp_torrent = " <img class=\"pro_50pctdown\" src=\"pic/trans.gif\" alt=\"50%\" ".($onmouseover ? $onmouseover : "title=\"".$lang_functions['text_half_down']."\"")." />";
        }
		elseif(($promotion==6 && $globalSpState == 1) || $globalSpState == 6) {
            $log .= ", promotion or global_sp_state = 6";
            $sp_torrent = " <img class=\"pro_50pctdown2up\" src=\"pic/trans.gif\" alt=\"2X 50%\" ".($onmouseover ? $onmouseover : "title=\"".$lang_functions['text_half_down_two_up']."\"")." />";
        }
		elseif(($promotion==7 && $globalSpState == 1) || $globalSpState == 7) {
            $log .= ", promotion or global_sp_state = 7";
            $sp_torrent = " <img class=\"pro_30pctdown\" src=\"pic/trans.gif\" alt=\"30%\" ".($onmouseover ? $onmouseover : "title=\"".$lang_functions['text_thirty_percent_down']."\"")." />";
        }
	}
	do_log("$log, sp_torrent: $sp_torrent");
	return $sp_torrent;
}

function get_torrent_promotion_append_sub($promotion = 1,$forcemode = "",$showtimeleft = false, $added = "", $promotionTimeType = 0, $promotionUntil = '', $ignoreGlobal = false, $torrentId = null){
	global $CURUSER,$lang_functions;
	global $expirehalfleech_torrent, $expirefree_torrent, $expiretwoup_torrent, $expiretwoupfree_torrent, $expiretwouphalfleech_torrent, $expirethirtypercentleech_torrent;

    $globalSpState = get_global_sp_state();
	if (!$ignoreGlobal) {
		$officialSpState = get_official_sp_state();
		if ($officialSpState != \App\Models\Torrent::PROMOTION_NORMAL && $torrentId && torrent_has_official_tag($torrentId)) {
			$globalSpState = $officialSpState;
		}
	}
	$sp_torrent = "";
	$onmouseover = "";
	$log = "[GET_PROMOTION], promotion: $promotion, forcemode: $forcemode, showtimeleft: $showtimeleft, added: $added, promotionTimeType: $promotionTimeType, promotionUntil: $promotionUntil";
    if ($ignoreGlobal) {
        $globalSpState = 1;
        $log .= ", [IGNORE_GLOBAL]";
    }
	$log .= ", globalSpState == " . $globalSpState;
	if ($globalSpState == 1) {
	switch ($promotion){
		case 2:
		{
			if ($showtimeleft && (($expirefree_torrent && $promotionTimeType == 0) || $promotionTimeType == 2))
			{
				if ($promotionTimeType == 2) {
					$futuretime = strtotime($promotionUntil);
				} else {
					$futuretime = strtotime($added) + $expirefree_torrent * 86400;
				}
				$timeout = gettime(date("Y-m-d H:i:s", $futuretime), false, false, true, false, true);
				if ($timeout)
				$onmouseover = " <font color='#0000FF'>".$lang_functions['text_will_end_in'].$timeout."</font>"; //free类型字符显示为蓝色，可以更改它
				else $promotion = 1;
			}
			break;
		}
		case 3:
		{
			if ($showtimeleft && (($expiretwoup_torrent && $promotionTimeType == 0) || $promotionTimeType == 2))
			{
				if ($promotionTimeType == 2) {
					$futuretime = strtotime($promotionUntil);
				} else {
					$futuretime = strtotime($added) + $expiretwoup_torrent * 86400;
				}
				$timeout = gettime(date("Y-m-d H:i:s", $futuretime), false, false, true, false, true);
				if ($timeout)
				$onmouseover = " ".$lang_functions['text_will_end_in'].$timeout;
				else $promotion = 1;
			}
			break;
		}
		case 4:
		{
			if ($showtimeleft && (($expiretwoupfree_torrent && $promotionTimeType == 0) || $promotionTimeType == 2))
			{
				if ($promotionTimeType == 2) {
					$futuretime = strtotime($promotionUntil);
				} else {
					$futuretime = strtotime($added) + $expiretwoupfree_torrent * 86400;
				}
				$timeout = gettime(date("Y-m-d H:i:s", $futuretime), false, false, true, false, true);
				if ($timeout)
				$onmouseover = " <font color='#00CC66'>".$lang_functions['text_will_end_in'].$timeout."</font>"; //2XFree 显示为青色，可以更改它
				else $promotion = 1;
			}
			break;
		}
		case 5:
		{
			if ($showtimeleft && (($expirehalfleech_torrent && $promotionTimeType == 0) || $promotionTimeType == 2))
			{
				if ($promotionTimeType == 2) {
					$futuretime = strtotime($promotionUntil);
				} else {
					$futuretime = strtotime($added) + $expirehalfleech_torrent * 86400;
				}
				$timeout = gettime(date("Y-m-d H:i:s", $futuretime), false, false, true, false, true);
				if ($timeout)
				$onmouseover = " ".$lang_functions['text_will_end_in'].$timeout;
				else $promotion = 1;
			}
			break;
		}
		case 6:
		{
			if ($showtimeleft && (($expiretwouphalfleech_torrent && $promotionTimeType == 0) || $promotionTimeType == 2))
			{
				if ($promotionTimeType == 2) {
					$futuretime = strtotime($promotionUntil);
				} else {
					$futuretime = strtotime($added) + $expiretwouphalfleech_torrent * 86400;
				}
				$timeout = gettime(date("Y-m-d H:i:s", $futuretime), false, false, true, false, true);
				if ($timeout)
				$onmouseover = " ".$lang_functions['text_will_end_in'].$timeout;
				else $promotion = 1;
			}
			break;
		}
		case 7:
		{
			if ($showtimeleft && (($expirethirtypercentleech_torrent && $promotionTimeType == 0) || $promotionTimeType == 2))
			{
				if ($promotionTimeType == 2) {
					$futuretime = strtotime($promotionUntil);
				} else {
					$futuretime = strtotime($added) + $expirethirtypercentleech_torrent * 86400;
				}
				$timeout = gettime(date("Y-m-d H:i:s", $futuretime), false, false, true, false, true);
				if ($timeout)
				$onmouseover = " ".$lang_functions['text_will_end_in'].$timeout;
				else $promotion = 1;
			}
			break;
		}
	}
	}
	if (($CURUSER['appendpromotion'] == 'word' && $forcemode == "" ) || $forcemode == 'word'){
        $log .= ", user appendpromotion = word";
		if(($promotion==2 && $globalSpState == 1) || $globalSpState == 2){
		    $log .= ", promotion or global_sp_state = 2";
			$sp_torrent = $onmouseover;
		}
		elseif(($promotion==3 && $globalSpState == 1) || $globalSpState == 3){
            $log .= ", promotion or global_sp_state = 3";
			$sp_torrent = $onmouseover;
		}
		elseif(($promotion==4 && $globalSpState == 1) || $globalSpState == 4){
            $log .= ", promotion or global_sp_state = 4";
			$sp_torrent = $onmouseover;
		}
		elseif(($promotion==5 && $globalSpState == 1) || $globalSpState == 5){
            $log .= ", promotion or global_sp_state = 5";
			$sp_torrent = $onmouseover;
		}
		elseif(($promotion==6 && $globalSpState == 1) || $globalSpState == 6){
            $log .= ", promotion or global_sp_state = 6";
			$sp_torrent = $onmouseover;
		}
		elseif(($promotion==7 && $globalSpState == 1) || $globalSpState == 7){
            $log .= ", promotion or global_sp_state = 7";
			$sp_torrent = $onmouseover;
		}
	}
	elseif (($CURUSER['appendpromotion'] == 'icon' && $forcemode == "") || $forcemode == 'icon'){
        $log .= ", user appendpromotion = icon";
		if(($promotion==2 && $globalSpState == 1) || $globalSpState == 2) {
            $log .= ", promotion or global_sp_state = 2";
            $sp_torrent = $onmouseover;
        }
		elseif(($promotion==3 && $globalSpState == 1) || $globalSpState == 3) {
            $log .= ", promotion or global_sp_state = 3";
            $sp_torrent = $onmouseover;
        }
		elseif(($promotion==4 && $globalSpState == 1) || $globalSpState == 4) {
            $log .= ", promotion or global_sp_state = 4";
            $sp_torrent = $onmouseover;
        }
		elseif(($promotion==5 && $globalSpState == 1) || $globalSpState == 5) {
            $log .= ", promotion or global_sp_state = 5";
            $sp_torrent = $onmouseover;
        }
		elseif(($promotion==6 && $globalSpState == 1) || $globalSpState == 6) {
            $log .= ", promotion or global_sp_state = 6";
            $sp_torrent = $onmouseover;
        }
		elseif(($promotion==7 && $globalSpState == 1) || $globalSpState == 7) {
            $log .= ", promotion or global_sp_state = 7";
            $sp_torrent = $onmouseover;
        }
	}
	do_log("$log, sp_torrent: $sp_torrent");
	return $sp_torrent;
}

function get_hr_img(array $torrent, $searchBoxId)
{
    $mode = \App\Models\HitAndRun::getConfig('mode', $searchBoxId);
    $result = '';
    if ($mode == \App\Models\HitAndRun::MODE_GLOBAL || ($mode == \App\Models\HitAndRun::MODE_MANUAL && isset($torrent['hr']) && $torrent['hr'] == \App\Models\Torrent::HR_YES)) {
        $result = '<img class="hitandrun" src="pic/trans.gif" alt="H&R" title="H&R" />';
    }
    return $result;
}

function get_user_id_from_name($username){
	global $lang_functions;
	$res = sql_query("SELECT id FROM users WHERE LOWER(username)=LOWER(" . sqlesc($username).")");
	$arr = mysql_fetch_array($res);
	if (!$arr){
		stderr($lang_functions['std_error'],$lang_functions['std_no_user_named']."'".$username."'");
	}
	else return $arr['id'];
}

function is_forum_moderator($id, $in = 'post'){
	global $CURUSER;
	switch($in){
		case 'post':{
			$res = sql_query("SELECT topicid FROM posts WHERE id=$id") or sqlerr(__FILE__, __LINE__);
			if ($arr = mysql_fetch_array($res)){
				if (is_forum_moderator($arr['topicid'],'topic'))
					return true;
			}
			return false;
			break;
		}
		case 'topic':{
			$modcount = sql_query("SELECT COUNT(forummods.userid) FROM forummods LEFT JOIN topics ON forummods.forumid = topics.forumid WHERE topics.id=$id AND forummods.userid=".sqlesc($CURUSER['id'])) or sqlerr(__FILE__, __LINE__);
			$arr = mysql_fetch_array($modcount);
			if ($arr[0])
				return true;
			else return false;
			break;
		}
		case 'forum':{
			$modcount = get_row_count("forummods","WHERE forumid=$id AND userid=".sqlesc($CURUSER['id']));
			if ($modcount)
				return true;
			else return false;
			break;
		}
		default: {
		return false;
		}
	}
}

function get_guest_lang_id(){
	global $CURLANGDIR;
	$langfolder=$CURLANGDIR;
	$res = sql_query("SELECT id FROM language WHERE site_lang_folder=".sqlesc($langfolder)." AND site_lang=1");
	$row = mysql_fetch_array($res);
	if ($row){
		return $row['id'];
	}
	else return 6;//return English
}

function set_forum_moderators($name, $forumid, $limit=3){
	$name = rtrim(trim($name), ",");
	$users = explode(",", $name);
	$userids = array();
	foreach ($users as $user){
		$userids[]=get_user_id_from_name(trim($user));
	}
	$max = count($userids);
	sql_query("DELETE FROM forummods WHERE forumid=".sqlesc($forumid)) or sqlerr(__FILE__, __LINE__);
	for($i=0; $i < $limit && $i < $max; $i++){
		sql_query("INSERT INTO forummods (forumid, userid) VALUES (".sqlesc($forumid).",".sqlesc($userids[$i]).")") or sqlerr(__FILE__, __LINE__);
	}
}

function get_plain_username($id){
	$row = get_user_row($id);
	if ($row)
		$username = $row['username'];
	else $username = "";
	return $username;
}

function get_searchbox_value($mode = 1, $item = 'showsubcat'){
	global $Cache;
	static $rows;
	$cacheKey = "search_box_content";
	if (!$rows && !$rows = $Cache->get_value($cacheKey)){
		$rows = array();
		$res = sql_query("SELECT * FROM searchbox ORDER BY id ASC");
		while ($row = mysql_fetch_array($res)) {
		    if (isset($row['extra'])) {
		        $row['extra'] = json_decode($row['extra'], true);
            }
            if (isset($row['section_name'])) {
                $row['section_name'] = json_decode($row['section_name'], true);
            }
			$rows[$row['id']] = $row;
		}
		$Cache->cache_value($cacheKey, $rows, 100500);
	}
	return $rows[$mode][$item] ?? '';
}

function get_ratio($userid, $html = true){
	$row = get_user_row($userid);
    if (empty($row)) {
        return "---";
    }
	$uped = $row['uploaded'];
	$downed = $row['downloaded'];
	if ($html == true){
		if ($downed > 0)
		{
			$ratio = $uped / $downed;
			$color = get_ratio_color($ratio);
			$ratio = number_format($ratio, 3);

			if ($color)
				$ratio = "<font color=\"".$color."\">".$ratio."</font>";
		}
		elseif ($uped > 0)
			$ratio = nexus_trans("label.infinite");
		else
			$ratio = "---";
	}
	else{
		if ($downed > 0)
		{
			$ratio = $uped / $downed;
		}
		else $ratio = 1;
	}
	return $ratio;
}

function add_s($num, $es = false)
{
	global $lang_functions;
	return ($num > 1 ? ($es ? ($lang_functions['text_es'] ?? '') : $lang_functions['text_s']) : "");
}

function is_or_are($num)
{
	global $lang_functions;
	return ($num > 1 ? $lang_functions['text_are'] : $lang_functions['text_is']);
}

function getmicrotime(){
	list($usec, $sec) = explode(" ",microtime());
	return ((float)$usec + (float)$sec);
}

function get_user_class_image($class){
	$UC = array(
		"Staff Leader" => "pic/staffleader.gif",
		"SysOp" => "pic/sysop.gif",
		"Administrator" => "pic/administrator.gif",
		"Moderator" => "pic/moderator.gif",
		"Forum Moderator" => "pic/forummoderator.gif",
		"Uploader" => "pic/uploader.gif",
		"Retiree" => "pic/retiree.gif",
		"VIP" => "pic/vip.gif",
		"Nexus Master" => "pic/nexus.gif",
		"Ultimate User" => "pic/ultimate.gif",
		"Extreme User" => "pic/extreme.gif",
		"Veteran User" => "pic/veteran.gif",
		"Insane User" => "pic/insane.gif",
		"Crazy User" => "pic/crazy.gif",
		"Elite User" => "pic/elite.gif",
		"Power User" => "pic/power.gif",
		"User" => "pic/user.gif",
		"Peasant" => "pic/peasant.gif"
	);
	if (isset($class)) {
        $className = get_user_class_name($class,false,false,false);
	    if (str_contains($className, '(')) {
            $className = strstr($className, '(', true);
        }
        $uclass = $UC[$className];
    } else {
        $uclass = "pic/banned.gif";
    }
	return $uclass;
}

function user_can_upload($where = "torrents"){
	global $CURUSER,$upload_class,$enablespecial,$uploadspecial_class, $lang_functions;
	static $denyCheckInProgress = false;
	if ($CURUSER["uploadpos"] != 'yes') {
        return false;
    }
    // 防止无限递归：本函数被 stdhead() 每页调用来构建顶部导航；当用户"被拒审种子数"
    // 达上限时这里会 stderr()，而 stderr() 又会调 stdhead() -> user_can_upload()，
    // 形成 stderr→stdhead→user_can_upload 死循环，导致请求 100 秒超时拖垮 FPM。
    // 渲染本次错误页期间再次进入时，跳过该检查、按普通权限返回布尔值即可。
    if (!$denyCheckInProgress) {
        $uploadDenyApprovalDenyCount = get_setting('main.upload_deny_approval_deny_count');
        $approvalDenyCount = \App\Models\Torrent::query()->where('owner', $CURUSER['id'])
            ->where('approval_status', \App\Models\Torrent::APPROVAL_STATUS_DENY)
            ->count()
        ;
        if ($uploadDenyApprovalDenyCount > 0 && $approvalDenyCount >= $uploadDenyApprovalDenyCount) {
            $denyCheckInProgress = true;
            stderr($lang_functions['std_sorry'], sprintf($lang_functions['approval_deny_reach_upper_limit'], $uploadDenyApprovalDenyCount),false);
            $denyCheckInProgress = false;
        }
    }
	if ($where == "torrents")
	{
        $offerSkipApprovedCount = get_setting('main.offer_skip_approved_count');
        if ($CURUSER['offer_allowed_count'] >= $offerSkipApprovedCount) {
            return true;
        }
		if (user_can('upload'))
			return true;
		if (get_if_restricted_is_open())
			return true;
	}
	if ($where == "music")
	{
		if ($enablespecial == 'yes' && user_can('uploadspecial'))
			return true;
	}
	return false;
}

function torrent_selection($name,$selname,$listname,$selectedid = 0, $mode = 0)
{
	global $lang_functions;
	$selection = "<b>".$name."</b>&nbsp;<select name=\"".$selname."\">\n<option value=\"0\">".$lang_functions['select_choose_one']."</option>\n";
	$listarray = searchbox_item_list($listname, $mode);
	foreach ($listarray as $row)
		$selection .= "<option value=\"" . $row["id"] . "\"". ($row["id"]==$selectedid ? " selected=\"selected\"" : "").">" . htmlspecialchars($row["name"]) . "</option>\n";
	$selection .= "</select>&nbsp;&nbsp;&nbsp;\n";
	return $selection;
}

function get_hl_color($color=0)
{
	switch ($color){
		case 0: return false;
		case 1: return "Black";
		case 2: return "Sienna";
		case 3: return "DarkOliveGreen";
		case 4: return "DarkGreen";
		case 5: return "DarkSlateBlue";
		case 6: return "Navy";
		case 7: return "Indigo";
		case 8: return "DarkSlateGray";
		case 9: return "DarkRed";
		case 10: return "DarkOrange";
		case 11: return "Olive";
		case 12: return "Green";
		case 13: return "Teal";
		case 14: return "Blue";
		case 15: return "SlateGray";
		case 16: return "DimGray";
		case 17: return "Red";
		case 18: return "SandyBrown";
		case 19: return "YellowGreen";
		case 20: return "SeaGreen";
		case 21: return "MediumTurquoise";
		case 22: return "RoyalBlue";
		case 23: return "Purple";
		case 24: return "Gray";
		case 25: return "Magenta";
		case 26: return "Orange";
		case 27: return "Yellow";
		case 28: return "Lime";
		case 29: return "Cyan";
		case 30: return "DeepSkyBlue";
		case 31: return "DarkOrchid";
		case 32: return "Silver";
		case 33: return "Pink";
		case 34: return "Wheat";
		case 35: return "LemonChiffon";
		case 36: return "PaleGreen";
		case 37: return "PaleTurquoise";
		case 38: return "LightBlue";
		case 39: return "Plum";
		case 40: return "White";
		default: return false;
	}
}

function get_forum_moderators($forumid, $plaintext = true)
{
	global $Cache;
	static $moderatorsArray;

	if (!$moderatorsArray && !$moderatorsArray = $Cache->get_value('forum_moderator_array')) {
		$moderatorsArray = array();
		$res = sql_query("SELECT forumid, userid FROM forummods ORDER BY forumid ASC") or sqlerr(__FILE__, __LINE__);
		while ($row = mysql_fetch_array($res)) {
			$moderatorsArray[$row['forumid']][] = $row['userid'];
		}
		$Cache->cache_value('forum_moderator_array', $moderatorsArray, 86200);
	}
	$ret = $moderatorsArray[$forumid] ?? [];

	$moderators = "";
	foreach($ret as $userid) {
		if ($plaintext)
			$moderators .= get_plain_username($userid).", ";
		else $moderators .= get_username($userid).", ";
	}
	$moderators = rtrim(trim($moderators), ",");
	return $moderators;
}
function key_shortcut($page=1,$pages=1)
{
	$currentpage = "var currentpage=".$page.";";
	$maxpage = "var maxpage=".$pages.";";
	$key_shortcut_block = "\n<script type=\"text/javascript\">\n//<![CDATA[\n".$maxpage."\n".$currentpage."\n//]]>\n</script>\n";
	return $key_shortcut_block;
}
function promotion_selection($selected = 0, $hide = 0)
{
	global $lang_functions;
	$selection = "";
	if ($hide != 1)
		$selection .= "<option value=\"1\"".($selected == 1 ? " selected=\"selected\"" : "").">".$lang_functions['text_normal']."</option>";
	if ($hide != 2)
		$selection .= "<option value=\"2\"".($selected == 2 ? " selected=\"selected\"" : "").">".$lang_functions['text_free']."</option>";
	if ($hide != 3)
		$selection .= "<option value=\"3\"".($selected == 3 ? " selected=\"selected\"" : "").">".$lang_functions['text_two_times_up']."</option>";
	if ($hide != 4)
		$selection .= "<option value=\"4\"".($selected == 4 ? " selected=\"selected\"" : "").">".$lang_functions['text_free_two_times_up']."</option>";
	if ($hide != 5)
		$selection .= "<option value=\"5\"".($selected == 5 ? " selected=\"selected\"" : "").">".$lang_functions['text_half_down']."</option>";
	if ($hide != 6)
		$selection .= "<option value=\"6\"".($selected == 6 ? " selected=\"selected\"" : "").">".$lang_functions['text_half_down_two_up']."</option>";
	if ($hide != 7)
		$selection .= "<option value=\"7\"".($selected == 7 ? " selected=\"selected\"" : "").">".$lang_functions['text_thirty_percent_down']."</option>";
	return $selection;
}

function get_post_row($postid)
{
	global $Cache;
	if (!$row = $Cache->get_value('post_'.$postid.'_content')){
		$res = sql_query("SELECT * FROM posts WHERE id=".sqlesc($postid)." LIMIT 1") or sqlerr(__FILE__,__LINE__);
		$row = mysql_fetch_array($res);
		$Cache->cache_value('post_'.$postid.'_content', $row, 7200);
	}
	if (!$row)
		return false;
	else return $row;
}

function get_country_row($id)
{
	global $Cache;
	if (!$row = $Cache->get_value('country_'.$id.'_content')){
		$res = sql_query("SELECT * FROM countries WHERE id=".sqlesc($id)." LIMIT 1") or sqlerr(__FILE__,__LINE__);
		$row = mysql_fetch_array($res);
		$Cache->cache_value('country_'.$id.'_content', $row, 86400);
	}
	if (!$row)
		return false;
	else return $row;
}

function get_downloadspeed_row($id)
{
	global $Cache;
	if (!$row = $Cache->get_value('downloadspeed_'.$id.'_content')){
		$res = sql_query("SELECT * FROM downloadspeed WHERE id=".sqlesc($id)." LIMIT 1") or sqlerr(__FILE__,__LINE__);
		$row = mysql_fetch_array($res);
		$Cache->cache_value('downloadspeed_'.$id.'_content', $row, 86400);
	}
	if (!$row)
		return false;
	else return $row;
}

function get_uploadspeed_row($id)
{
	global $Cache;
	if (!$row = $Cache->get_value('uploadspeed_'.$id.'_content')){
		$res = sql_query("SELECT * FROM uploadspeed WHERE id=".sqlesc($id)." LIMIT 1") or sqlerr(__FILE__,__LINE__);
		$row = mysql_fetch_array($res);
		$Cache->cache_value('uploadspeed_'.$id.'_content', $row, 86400);
	}
	if (!$row)
		return false;
	else return $row;
}

function get_isp_row($id)
{
	global $Cache;
	if (!$row = $Cache->get_value('isp_'.$id.'_content')){
		$res = sql_query("SELECT * FROM isp WHERE id=".sqlesc($id)." LIMIT 1") or sqlerr(__FILE__,__LINE__);
		$row = mysql_fetch_array($res);
		$Cache->cache_value('isp_'.$id.'_content', $row, 86400);
	}
	if (!$row)
		return false;
	else return $row;
}

function valid_file_name($filename)
{
	$allowedchars = "abcdefghijklmnopqrstuvwxyz0123456789_./";

	$total=strlen($filename);
	for ($i = 0; $i < $total; ++$i)
	if (strpos($allowedchars, $filename[$i]) === false)
		return false;
	return true;
}

function valid_class_name($filename)
{
	$allowedfirstchars = "abcdefghijklmnopqrstuvwxyz";
	$allowedchars = "abcdefghijklmnopqrstuvwxyz0123456789_";

	if(strpos($allowedfirstchars, $filename[0]) === false)
		return false;
	$total=strlen($filename);
	for ($i = 1; $i < $total; ++$i)
	if (strpos($allowedchars, $filename[$i]) === false)
		return false;
	return true;
}

function return_avatar_image($url)
{
	global $CURLANGDIR;
	return "<img src=\"".$url."\" alt=\"avatar\" width=\"150px\" onload=\"check_avatar(this, '".$CURLANGDIR."');\" />";
}
function return_category_image($categoryid, $link="")
{
	static $catImg = array();
	if (isset($catImg[$categoryid])) {
		$catimg = $catImg[$categoryid];
	} else {
		$categoryrow = get_category_row($categoryid);
		$catimgurl = get_cat_folder($categoryid);
		$catImg[$categoryid] = $catimg = "<img".($categoryrow['class_name'] ? " class=\"".$categoryrow['class_name']."\"" : "")." src=\"pic/cattrans.gif\" alt=\"" . $categoryrow["name"] . "\" title=\"" .$categoryrow["name"]. "\" style=\"background-image: url(pic/" . $catimgurl . '/' . $categoryrow["image"].");\" />";
	}
	if ($link) {
		$catimg = "<a href=\"".$link."cat=" . $categoryid . "\">".$catimg."</a>";
	}
	return $catimg;
}

/******************************************** bellow functioons avaliable since v1.6 ***********************************************************/

function get_requestcount()
{
    global $CURUSER, $Cache;
    //return;
    $CURUSERID = 0 + $CURUSER['id'];
    if (!$count = $Cache->get_value($CURUSERID . '_get_requestcount')) {
        $row = @mysql_fetch_array(sql_query(" SELECT count(*) FROM requests LEFT JOIN resreq ON reqid=requests.id WHERE reqid>0 and finish = 'no' and userid= " . $CURUSERID));
        $count = ($row[0] ? " style='background: none red;' " : " style='' ");
        $Cache->cache_value($CURUSERID . '_get_requestcount', $count, 120);
    }
    return $count;
}

function torrentTags($tags = 0, $type = 'checkbox')
{
    global $lang_functions;
    $tagsOptions = [
        [
            'text' => $lang_functions['text_tag_no_release_to_any_other'],
            'color' => '#ff0000',
        ],
        [
            'text' => $lang_functions['text_tag_first_release'],
            'color' => '#8F77B5',
        ],
        [
            'text' => $lang_functions['text_tag_official'],
            'color' => '#0000ff',
        ],
        [
            'text' => $lang_functions['text_tag_diy'],
            'color' => '#46d5ff',
        ],
        [
            'text' => $lang_functions['text_tag_mother_language'],
            'color' => '#6a3906',
        ],
        [
            'text' => $lang_functions['text_tag_mother_language_subtitle'],
            'color' => '#006400',
        ],
        [
            'text' => $lang_functions['text_tag_hdr'],
            'color' => '#38b03f',
        ],
    ];
    $html = '';
    foreach ($tagsOptions as $key => $value) {
        $currentValue = pow(2, $key);
        if ($type == 'checkbox') {
            $checked = '';
            if ($currentValue & $tags) {
                $checked = 'checked';
            }
            $html .= sprintf(
                '<label><input type="checkbox" name="tags[]" value="%s" %s />%s</label>',
                $currentValue, $checked, $value['text']
            );
        }
        if ($type == 'span' && ($currentValue & $tags)) {
            $html .= "<span style=\"background-color:{$value['color']};color:white;border-radius:15%\">{$value['text']}</span> ";
        }
    }
    return $html;
}

function saveSetting(string $prefix, array $nameAndValue, string $autoload = 'yes'): void
{
    $prefix = strtolower($prefix);
    $datetimeNow = date('Y-m-d H:i:s');
    $sql = "insert into settings (name, value, created_at, updated_at, autoload) values ";
    $data = [];
    foreach ($nameAndValue as $name => $value) {
        if (is_array($value)) {
            $value = json_encode($value);
        }
        $data[] = sprintf("(%s, %s, %s, %s, '%s')", sqlesc("$prefix.$name"), sqlesc($value), sqlesc($datetimeNow), sqlesc($datetimeNow), $autoload);
    }
    $sql .= implode(",", $data) . " " . \Nexus\Database\NexusDB::upsertField(['name'], ['value']);
    \Nexus\Database\NexusDB::statement($sql);
    clear_setting_cache();
    do_action("nexus_setting_update");
}

function getFullDirectory($dir)
{
    if (is_file($dir) && file_exists($dir)) {
        return $dir;
    }
    if (!is_dir($dir)) {
        $dir = ROOT_PATH . $dir;
    }
    if (is_dir($dir)) {
        return realpath($dir);
    }
    return $dir;
}

function checkGuestVisit()
{
    if (userlogin()) {
        //already login
        return;
    }
    $setting = get_setting('security');
    //all type: normal, static_page, custom_content, redirect
    $guestVisitType = $setting['guest_visit_type'] ?? '';
    if (empty($guestVisitType) || $guestVisitType == 'normal') {
        return;
    }

    if (in_array(nexus()->getScript(), ['login', 'takelogin', 'image']) && canDoLogin()) {
        return;
    }

    $valueKey = "guest_visit_value_$guestVisitType";
    if (empty($setting[$valueKey])) {
        do_log("setting: security.$valueKey empty");
        die(0);
    }
    $guestVisitValue = $setting[$valueKey];
    if ($guestVisitType == 'static_page') {
        $pageFile = ROOT_PATH . 'resources/static-pages/' . $guestVisitValue;
        if (!file_exists($pageFile) || !is_readable($pageFile)) {
            do_log("pageFile: $pageFile is not exists or readable");
            die(0);
        }
        $content = file_get_contents($pageFile);
        die($content);
    }
    if ($guestVisitType == 'custom_content') {
        $content = format_comment($guestVisitValue);
        render('resources/templates/guest-visit-custom-content', ['content' => $content]);
    }
    if ($guestVisitType == 'redirect') {
        header('Location: ' . $guestVisitValue);
        die(0);
    }

}

function render($view, $data = [], $return = false)
{
    extract($data);
    if (!file_exists($view)) {
        $view = ROOT_PATH . $view;
    }
    if (substr($view, -4) !== '.php') {
        $view .= ".php";
    }
    ob_start();
    ob_implicit_flush(0);
    require $view;
    $result = ob_get_clean();
    if ($return) {
        return $result;
    }
    die($result);
}

function canDoLogin()
{
    $setting = get_setting('security');
    if (empty($setting['login_type']) || $setting['login_type'] == 'normal') {
        return true;
    }
    $loginType = $setting['login_type'];
    if ($loginType == 'secret') {
        if (empty($_REQUEST['secret'])) {
            do_log("no secret");
            return false;
        }
        if ($_REQUEST['secret'] != $setting['login_secret']) {
            do_log("invlaid secret: " . $_REQUEST['secret']);
            return false;
        }
        if ($setting['login_secret_deadline'] < date('Y-m-d H:i:s')) {
            do_log("secret: {$_REQUEST['secret']} expires(deadline: {$setting['login_secret_deadline']})");
            return false;
        }
        return true;
    }
    if ($loginType == 'passkey') {
        return false;
    }
    return true;
}

function displayHotAndClassic()
{
    global $showextinfo, $showmovies, $Cache, $lang_functions, $browsecatmode, $specialcatmode;

    if ($showmovies['hot'] == "yes" || $showmovies['classic'] == "yes")
    {
        if (nexus()->getScript() == 'special') {
            $mode = $specialcatmode;
        } else {
            $mode = $browsecatmode;
        }
        $imdb = new \Nexus\Imdb\Imdb();
        $type = array('hot', 'classic');
        foreach($type as $type_each)
        {
            if($showmovies[$type_each] == 'yes' && (!isset($CURUSER) || $CURUSER['show' . $type_each] == 'yes'))
            {
                $Cache->new_page("{$type_each}_{$mode}_resources", 900, true);
                if (!$Cache->get_page())
                {
                    $Cache->add_whole_row();

                    $res = sql_query("SELECT torrents.sp_state, torrents.url, torrents.id, torrents.name, torrents.small_descr, torrents.cover FROM torrents LEFT JOIN categories ON torrents.category = categories.id WHERE categories.mode = $mode AND picktype = " . sqlesc($type_each) . " AND seeders > 0 AND (url != '' OR cover != '') ORDER BY id DESC LIMIT 30") or sqlerr(__FILE__, __LINE__);
                    if (mysql_num_rows($res) > 0)
                    {
                        $movies_list = "";
                        $count = 0;
                        $allImdb = array();
                        $width = 101;
                        $height = 140;
                        while($array = mysql_fetch_array($res))
                        {
                            $pro_torrent = get_torrent_promotion_append($array['sp_state'],'word', false, '', 0, '', $array['__ignore_global_sp_state'] ?? false, $array['id'] ?? 0);
                            $photo_url = '';
                            if ($imdb_id = parse_imdb_id($array["url"])) {
                                if (array_search($imdb_id, $allImdb) !== false) { //a torrent with the same IMDb url already exists
                                    continue;
                                }
                                $allImdb[]=$imdb_id;
                                try {
                                    $photo_url = $imdb->getMovie($imdb_id)->photo(true);
                                    if (empty($photo_url)) {
                                        do_log("torrent: {$array['id']}, url: {$array['url']}, imdb_id: $imdb_id can not get photo", 'error');
                                    }
                                } catch (\Exception $exception) {
                                    do_log($exception->getMessage() . "\n[stacktrace]\n" . $exception->getTraceAsString(), 'error');
                                }
                            }
                            if (empty($photo_url) && !empty($array['cover'])) {
                                $photo_url = $array['cover'];
                            }
                            if (empty($photo_url)) {
                                continue;
                            }

                            $thumbnail = "<img width=\"{$width}\" height=\"{$height}\" src=\"".$photo_url."\" border=\"0\" alt=\"poster\" />";

                            $thumbnail = "<a style=\"margin-right: 2px\" href=\"details.php?id=" . $array['id'] . "&amp;hit=1\" onmouseover=\"domTT_activate(this, event, 'content', '" . htmlspecialchars("<font class=\'big\'><b>" . (addslashes($array['name'] . $pro_torrent)) . "</b></font><br /><font class=\'medium\'>".(addslashes($array['small_descr'])) ."</font>"). "', 'trail', true, 'delay', 0,'lifetime',5000,'styleClass','niceTitle','maxWidth', 600);\">" . $thumbnail . "</a>";
                            $movies_list .= $thumbnail;
                            $count++;
                            if ($count >= 10)
                                break;
                        }
                        ?>
                        <h2><?php echo $lang_functions['text_' . $type_each] ?></h2>
                        <table width="100%" border="1" cellspacing="0" cellpadding="5"><tr><td class="text nowrap" align="center">
                                    <?php echo $movies_list ?></td></tr></table>
                        <?php
                    }
                    $Cache->end_whole_row();
                    $Cache->cache_page();
                }
                echo $Cache->next_row();
            }
        }
    }

}

function build_table(array $header, array $rows, array $options = [])
{
    $table = '<table border="1" cellspacing="0" cellpadding="5" width="100%"><thead><tr>';
    foreach ($header as $key => $value) {
        $table .= sprintf('<td class="colhead">%s</td>', $value);
    }
    $table .= '</tr></thead><tbody>';
    $tdClass = '';
    if (isset($options['td-center']) && $options['td-center']) {
        $tdClass = 'colfollow';
    }
    foreach ($rows as $row) {
        $table .= '<tr>';
        foreach ($header as $headerKey => $headerValue) {
            $table .= sprintf('<td class="%s">%s</td>', $tdClass, $row[$headerKey] ?? '');
        }
        $table .= '</tr>';
    }
    $table .= '</tbody></table>';
    return $table;
}

/**
 * 返回链接中附件的key
 *
 * @param $url
 * @return string
 */
function attachmentKey($url)
{
    if (!filter_var($url, FILTER_VALIDATE_URL))
    {
        throw new \InvalidArgumentException("URL: '$url' invalid.");
    }
    $parsed = parse_url($url);
    $driver = config('admin.upload.disk');
    if ($driver == 'qiniu') {
        return trim($parsed['path'], "/");
    } elseif ($driver == 'cloudinary') {
        $parts = explode('/', $parsed['path']);
        $key = end($parts);
        if (\Illuminate\Support\Str::contains($key,'.')) {
            $key = strstr($key, '.', true);
        }
        return $key;

    } else {
        throw new \RuntimeException('不支持的云盘驱动');
    }

}

/**
 * 根据key返回链接
 *
 * @param $location
 * @param null $width
 * @param null $height
 * @param array $options
 * @return string
 */
function attachmentUrl($location, $width = null, $height = null, $options = [])
{
    return sprintf('%s/attachments/%s', getSchemeAndHttpHost(), trim($location, '/'));
}


function strip_all_tags($text)
{
    //替换掉无参数标签
    $bbTags = [
        '[*]', '[b]', '[/b]', '[i]', '[/i]', '[u]', '[/u]', '[s]', '[/s]', '[pre]', '[/pre]', '[quote]', '[/quote]',
        '[/color]', '[/font]', '[/size]', '[/url]', '[/youtube]', '[/spoiler]',
    ];
    $text = str_replace($bbTags, '', $text);
    //替换掉有参数标签
    $pattern = '/\[url=.*\]|\[color=.*\]|\[font=.*\]|\[size=.*\]|\[youtube.*\]|\[spoiler.*\]/isU';
    $text = preg_replace($pattern, "", $text);
    //去掉表情
    static $emoji = null;
    if (is_null($emoji)) {
        $emoji = nexus_config('emoji');
    }
//    $text = preg_replace("/\[em([1-9][0-9]*)\]/isU", "", $text);
    $text = preg_replace_callback("/\[em([1-9][0-9]*)\]/isU", function ($matches) use ($emoji) {
        return $emoji[$matches[1]] ?? '';
    }, $text);

    $text = strip_tags($text);

    return trim($text);
}

function format_description($description)
{
    //替换附件
    $pattern = '/(\[attach\](.*)\[\/attach\])/isU';
    $matchCount = preg_match_all($pattern, $description, $matches);
    if ($matchCount) {
        $attachments = \App\Models\Attachment::query()->whereIn('dlkey', $matches[2])->get()->keyBy('dlkey');
        if ($attachments->isNotEmpty()) {
            $description = preg_replace_callback($pattern, function ($matches) use ($attachments) {
                $item = $attachments->get($matches[2]);
                $url = \Nexus\Attachment\Storage::getDriver($item->driver)->getImageUrl($item->location);
                do_log(sprintf("location: %s, driver: %s, url: %s", $item->location, $item->driver, $url));
                return str_replace($matches[2], $url, $matches[1]);
            }, $description);
        }
    }
    //去除引用
//    $pattern = '/\[quote.*\].*\[\/quote\]/is';
//    $description = preg_replace($pattern, '', $description);

    //去掉引用自
    $pattern = '/\[quote=.*\]/isU';
    $description = preg_replace_callback($pattern, function ($matches) {
        return '[quote]';
    }, $description);

    //过虑多层引用
    $delimiter = '__CYLX__';
    $pattern = '/(\[quote\]){2,}(((?!\[quote\]).)*)\[\/quote\]/isU';
    $description = preg_replace_callback($pattern, function ($matches) use ($delimiter) {
        return $delimiter;
    }, $description);

    $pattern = "/$delimiter(((?!\[quote\]).)+)\[\/quote\]/is";
    $description = preg_replace_callback($pattern, function ($matches) use ($delimiter) {
        $arr = array_reverse(explode('[/quote]', $matches[0]));
        foreach ($arr as $value) {
            $value = trim(str_replace($delimiter, '', $value));
            if (!empty($value)) {
                return "[quote]{$value}[/quote]";
            }
        }
    }, $description);


    //匹配不同块
    $attachPattern = '\[attach\].*\[\/attach\]';
    $imgPattern = '\[img\].*\[\/img\]';
    $imgPattern2 = '\[img=.*\]';
    $urlPattern = '\[url=.*\].*\[\/url\]';
    $quotePattern = '\[quote.*\].*\[\/quote\]';
    $pattern = "/($attachPattern)|($imgPattern)|($imgPattern2)|($urlPattern)|($quotePattern)/isU";
//    $pattern = "/($attachPattern)|($imgPattern)|($urlPattern)/isU";
    $delimiter = '{{{}}}';
    $description = preg_replace_callback($pattern, function ($matches) use ($delimiter) {
        return $delimiter . $matches[0] . $delimiter;
    }, $description);

    //再进行分割
    $descriptionArr = preg_split("/[$delimiter]+/", $description);
    $results = [];
    foreach ($descriptionArr as $item) {
        if (preg_match('/\[attach\](.*)\[\/attach\]/isU', $item, $matches)) {
            //是否附件
            $results[] = [
                'type' => 'attachment',
                'data' => [
                    'url' => $matches[1]
                ]
            ];
        } elseif (preg_match('/\[img\](.*)\[\/img\]/isU', $item, $matches)) {
            //是否图片
            $results[] = [
                'type' => 'image',
                'data' => [
                    'url' => $matches[1]
                ]
            ];
        } elseif (preg_match('/\[img=(.*)\]/isU', $item, $matches)) {
            //是否图片
            $results[] = [
                'type' => 'image',
                'data' => [
                    'url' => $matches[1]
                ]
            ];
        } elseif (preg_match('/\[url=(.*)\](.*)\[\/url\]/isU', $item, $matches)) {
            $results[] = [
                'type' => 'url',
                'data' => [
                    'url' => $matches[1],
                    'text' => strip_all_tags($matches[2])
                ]
            ];
        } elseif (preg_match('/\[quote=?(.*)\](.*)\[\/quote\]/isU', $item, $matches)) {
            $results[] = [
                'type' => 'quote',
                'data' => [
                    'quote_text' => $matches[1],
                    'text' => strip_all_tags($matches[2]),
                ]
            ];
        } elseif (!empty($item)) {
            $results[] = [
                'type' => 'text',
                'data' => [
                    'text' => strip_all_tags($item)
                ]
            ];
        }
    }
//        dd($description, $results);
    return $results;
}

function get_image_from_description(array $descriptionArr, $first = false, $useDefault = true)
{
    $imageType = ['attachment', 'image'];
    $images = [];
    foreach ($descriptionArr as $value) {
        if (!in_array($value['type'], $imageType)) {
            continue;
        }
        $url = $value['data']['url'] ?? '';
        if (!$url) {
            continue;
        }
        if ($first) {
            return $url;
        } else {
            $images[] = $url;
        }
    }
    if ($first) {
        if ($useDefault) {
            return getSchemeAndHttpHost() . "/pic/imdb_pic/nophoto.gif";
        } else {
            return '';
        }
    }
    return $images;
}

function resize_image($url, $with = null, $height = null, $fit = "cover")
{
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($scheme === false) {
        return $url;
    }
    $source = preg_replace('#^https?://#i', '', $url);
    if ($scheme === 'https') {
        $source = 'ssl:' . $source;
    }
    $url = "$scheme://images.weserv.nl/?url=" . $source;
    if ($with !== null) {
        $url .= "&w=$with";
    }
    if ($height !== null) {
        $url .= "&h=$height";
    }
    $url .= "&fit=$fit";
    return $url;
}

function get_share_ratio($uploaded, $downloaded)
{
    if ($downloaded) {
        $ratio = floor(($uploaded / $downloaded) * 1000) / 1000;
    } elseif ($uploaded) {
        //@todo 读语言文件
        $ratio = 'Infinity';
    } else {
        $ratio = '---';
    }
    return $ratio;
}

function EchoRow($class = ''){
    if(func_num_args() < 2) return '<tr></tr>';
    $args = func_get_args();
    $cells = array_splice($args, 1);
    $class = empty($class) ? '' : sprintf(' class="%s"', $class);
    $s = '<tr>';
    foreach($cells as $cell) $s .= sprintf('<td%s>%s</td>', $class, $cell);
    $s .= "</tr>\n";
    return $s;
}

function list_require_search_box_id()
{
    $setting = get_setting('main');
    $maps = [
        'torrents' => [$setting['browsecat']],
        'special' => [$setting['specialcat']],
        'usercp' => [$setting['browsecat'], $setting['specialcat']],
        'getrss' => [$setting['browsecat'], $setting['specialcat']],
        'userdetails' => [$setting['browsecat'], $setting['specialcat']],
        'offers' => [$setting['browsecat'], $setting['specialcat']],
        'details' => [$setting['browsecat'], $setting['specialcat']],
        'search' => [$setting['browsecat'], $setting['specialcat']],
    ];
    return $maps[nexus()->getScript()] ?? [];
}

function can_access_torrent($torrent, $uid)
{
    global $specialcatmode;
    if (get_setting('main.spsct') != 'yes') {
        return true;
    }
    if (is_array($torrent) && isset($torrent['search_box_id'])) {
        $searchBoxId = $torrent['search_box_id'];
    } elseif (is_numeric($torrent)) {
        $torrent = \App\Models\Torrent::query()->findOrFail(intval($torrent), ['id', 'category']);
        $searchBoxId = $torrent->basic_category->mode ?? 0;
        if ($searchBoxId == 0) {
            do_log("[INVALID_CATEGORY], torrent: " . $torrent->id, 'error');
            return false;
        }
    } else {
        throw new \InvalidArgumentException("Unsupported argument: " . json_encode($torrent));
    }
    if ($searchBoxId != $specialcatmode) {
        return true;
    }
    if (user_can('view_special_torrent', false, $uid)) {
        return true;
    }
    return false;
}

function get_ip_location_from_geoip($ip): bool|array
{
    $locationInfo = \Nexus\Database\NexusDB::remember("locations_{$ip}", 864000, function () use ($ip) {
        $lang = get_langfolder_cookie();
        $langMap = [
            'chs' => 'zh-CN',
            'cht' => 'zh-CN',
            'en' => 'en',
        ];
        $locale = $langMap[$lang] ?? $lang;
        $info = [
            'ip' => $ip,
            'version' => '',
            'country' => '',
            'city' => '',
            'country_en' => '',
            'city_en' => '',
            'continent_en' => '',
        ];
        try {
            $database = nexus_env('GEOIP2_DATABASE');
            if (empty($database)) {
                do_log("no geoip2 database.");
                return false;
            }
            if (!is_readable($database)) {
                do_log("geoip2 database: $database is not readable.");
                return false;
            }
            $reader = new \GeoIp2\Database\Reader($database);
            $record = $reader->city($ip);
            $countryName =  $record->country->names[$locale] ?? $record->country->names['en'] ?? '';
            $cityName = $record->city->names[$locale] ?? $record->city->names['en'] ?? '';
            $continentName = $record->continent->names[$locale] ?? $record->continent->names['en'] ?? '';
            if (isIPV4($ip)) {
                $info['version'] = 4;
            } elseif (isIPV6($ip)) {
                $info['version'] = 6;
            }
            $info['country'] = $countryName;
            $info['country_en'] = $record->country->names['en'] ?? '';
            $info['city'] = $cityName;
            $info['city_en'] = $record->city->names['en'] ?? '';
            $info['continent'] = $continentName;
            $info['continent_en'] = $record->continent->names['en'] ?? '';
        } catch (\Exception $exception) {
            do_log($exception->getMessage() . ", trace: " .  $exception->getTraceAsString(), 'error');
        }
        return $info;
    });
    do_log("ip: $ip, result: " . nexus_json_encode($locationInfo));
    if ($locationInfo === false) {
        return false;
    }
    $name = sprintf('%s[v%s]', $locationInfo['city'] ? ($locationInfo['city'] . "·" . $locationInfo['country']) : $locationInfo['country'], $locationInfo['version']);
    return [
        'name' => $name,
        'location_main' => '',
        'location_sub' => '',
        'flagpic' => '',
        'start_ip' => $ip,
        'end_ip' => $ip,
        'ip_version' => $locationInfo['version'],
        'country_en' => $locationInfo['country_en'],
        'city_en' => $locationInfo['city_en'],
        'continent_en' => $locationInfo['continent_en'],
    ];
}

function msgalert($url, $text, $bgcolor = "red")
{
    print("<table border=\"0\" cellspacing=\"0\" cellpadding=\"10\" style=\"margin: 0 auto;\"><tr><td style='border: none; padding: 10px; background: ".$bgcolor."; text-align: center;'>\n");
    if (!empty($url)) {
        print("<b><a href=\"".$url."\" target='_blank'><font color=\"white\">".$text."</font></a></b>");
    } else {
        print("<b><font color=\"white\">".$text."</font></b>");
    }
    print("</td></tr></table><br />");
}

function build_medal_image(\Illuminate\Support\Collection $medals, $maxHeight = 200, $withActions = false): string
{
    $medalImages = [];
    $wrapBefore = '<form><div style="display: flex;flex-wrap: wrap;justify-content: center;margin-top: 10px;">';
    $wrapAfter = '</div></form>';
    foreach ($medals as $medal) {
        $html = sprintf('<div style="display: flex;flex-direction: column;justify-content: space-between;margin-right: 10px"><div><img src="%s" title="%s" class="preview" style="max-height: %spx;max-width: %spx"/></div>', $medal->image_large, $medal->name, $maxHeight, $maxHeight);
        if ($withActions) {
            $html .= sprintf(
                '<div style="display: flex;flex-direction: column;align-items:flex-start"><span>%s: %s</span><span>%s: %s</span><span>%s: %s</span><label>%s: <input type="number" name="priority_%s" value="%s" style="width: 50px" placeholder="%s"></label>',
                nexus_trans('label.expire_at'),
                $medal->pivot->expire_at ? format_datetime($medal->pivot->expire_at) : nexus_trans('label.permanent'),
                nexus_trans('medal.fields.bonus_addition_factor'),
                $medal->bonus_addition_factor ?? 0,
                nexus_trans('medal.bonus_addition_expire_at'),
                $medal->pivot->bonus_addition_expire_at ? format_datetime($medal->pivot->bonus_addition_expire_at) : nexus_trans('label.permanent'),
                nexus_trans('label.priority'),
                $medal->pivot->id,
                $medal->pivot->priority ?? 0,
                nexus_trans('label.priority_help')
            );
            $checked = '';
            if ($medal->pivot->status == \App\Models\UserMedal::STATUS_WEARING) {
                $checked = ' checked';
            }
            $html .= sprintf('<label>%s<input type="checkbox" name="status_%s" value="1"%s></label>', nexus_trans('medal.action_wearing'), $medal->pivot->id, $checked);
            $html .= '</div>';
        }
        $html .= '</div>';
        $medalImages[] = $html;
    }
    if ($withActions) {
        $medalImages[] = sprintf('<div style="display: flex;flex-direction: column;justify-content: space-between;margin-right: 10px"><div></div><div><input type="button" id="save-user-medal-btn" value="%s"/></div></div>', nexus_trans('label.save'));
    }
    return $wrapBefore . implode('', $medalImages) . $wrapAfter;
}

function insert_torrent_tags($torrentId, $tagIdArr, $sync = false)
{
    $specialTags = \App\Models\Tag::listSpecial();
    $canSetSpecialTag = \App\Auth\Permission::canSetTorrentSpecialTag();
    $dateTimeStringNow = date('Y-m-d H:i:s');
    if ($sync) {
        $delQuery = \App\Models\TorrentTag::query()->where("torrent_id", $torrentId);
        if (!$canSetSpecialTag) {
            $delQuery->whereNotIn("tag_id", $specialTags);
        }
        $delQuery->delete();
    }
    if (empty($tagIdArr)) {
        return;
    }
    $insertTagsSql = 'insert into torrent_tags (torrent_id, tag_id, created_at, updated_at) values ';
    $values = [];
    foreach ($tagIdArr as $tagId) {
        if (in_array($tagId, $specialTags) && !$canSetSpecialTag) {
            do_log("special tag: $tagId, and user no permission");
            continue;
        }
        if (!isset($values[$tagId])) {
            $values[$tagId] = sprintf("(%s, %s, '%s', '%s')", $torrentId, $tagId, $dateTimeStringNow, $dateTimeStringNow);
        }
    }
    $insertTagsSql .= implode(', ', $values);
    do_log("[INSERT_TAGS], torrent: $torrentId with tags: " . nexus_json_encode($tagIdArr));
    \Nexus\Database\NexusDB::statement($insertTagsSql);
}

function get_smile($num)
{
    static $all;
    if (is_null($all)) {
        $all = [];
        $prefix = getFullDirectory('public');
        foreach (glob(getFullDirectory('public/pic/smilies') . '/*') as $value) {
            $subPath = substr($value, strlen($prefix));
            $basename = basename($subPath);
            $all[strstr($basename, '.', true)] = $subPath;
        }
    }
    return $all[$num] ?? null;
}

function get_filament_class_alias($class): string
{
    return Str::of($class)
        ->replace(['/', '\\'], '.')
        ->explode('.')
        ->map([Str::class, 'kebab'])
        ->implode('.');
}

/**
 * Calculate user seed bonus per hour
 *
 * @param $uid
 * @param $torrentIdArr
 * @return array
 * @throws \Nexus\Database\DatabaseException
 */
function calculate_seed_bonus($uid, $torrentIdArr = null): array
{
    $settingBonus = \App\Models\Setting::get('bonus');
    $donortimes_bonus = $settingBonus['donortimes'];
    $perseeding_bonus = $settingBonus['perseeding'];
    $maxseeding_bonus = $settingBonus['maxseeding'];
    $tzero_bonus = $settingBonus['tzero'];
    $nzero_bonus = $settingBonus['nzero'];
    $bzero_bonus = $settingBonus['bzero'];
    $l_bonus = $settingBonus['l'];
    $minSize = $settingBonus['min_size'] ?? 0;

    $sqrtof2 = sqrt(2);
    $logofpointone = log(0.1);
    $valueone = $logofpointone / $tzero_bonus;
    $pi = 3.141592653589793;
    $valuetwo = $bzero_bonus * ( 2 / $pi);
    $valuethree = $logofpointone / ($nzero_bonus - 1);
    $timenow = time();
    $nowStr = date('Y-m-d H:i:s');
    $sectoweek = 7*24*60*60;

    $A = $official_a = $size = $official_size = 0;
    $count = $torrent_peer_count = $official_torrent_peer_count = 0;
    $logPrefix = "[CALCULATE_SEED_BONUS], uid: $uid, torrentIdArr: " . json_encode($torrentIdArr);
    if ($torrentIdArr !== null) {
        if (empty($torrentIdArr)) {
            $torrentIdArr = [-1];
        }
        $idStr = implode(',', \Illuminate\Support\Arr::wrap($torrentIdArr));
        $sql = "select torrents.id, torrents.added, torrents.size, torrents.seeders, 'NO_PEER_ID' as peerID, '' as last_action, '' as ip from torrents  WHERE id in ($idStr) and size >= $minSize";
    } else {
        $sql = "select torrents.id, torrents.added, torrents.size, torrents.seeders, peers.id as peerID, peers.last_action, peers.ip from torrents LEFT JOIN peers ON peers.torrent = torrents.id WHERE peers.userid = $uid AND peers.seeder ='yes' and torrents.size > $minSize group by torrents.id, peers.id";
    }
    $tagGrouped = [];
    $torrentResult = \Nexus\Database\NexusDB::select($sql);
    if (!empty($torrentResult)) {
        $torrentIdArrReal = array_column($torrentResult, 'id');
        $tagResult = \Nexus\Database\NexusDB::select(sprintf("select torrent_id, tag_id from torrent_tags where torrent_id in (%s)", implode(',', $torrentIdArrReal)));
        foreach ($tagResult as $tagItem) {
            $tagGrouped[$tagItem['torrent_id']][$tagItem['tag_id']] = 1;
        }
    }
    $officialTag = \App\Models\Setting::get('bonus.official_tag');
    $officialAdditionalFactor = \App\Models\Setting::get('bonus.official_addition');
    $zeroBonusTag = \App\Models\Setting::get('bonus.zero_bonus_tag');
    $zeroBonusFactor = \App\Models\Setting::get('bonus.zero_bonus_factor');
    if (\Nexus\Database\NexusDB::isMysql()) {
        $factorField = "round(sum(bonus_addition_factor), 5)";
    } elseif (\Nexus\Database\NexusDB::isPgsql()) {
        $factorField = "round(sum(bonus_addition_factor)::numeric, 5)";
    } else {
        throw new \RuntimeException("Not supported database");
    }
    $userMedalResult = \Nexus\Database\NexusDB::select("select $factorField as factor from medals where id in (select medal_id from user_medals where uid = $uid and (expire_at is null or expire_at > '$nowStr') and (bonus_addition_expire_at is null or bonus_addition_expire_at > '$nowStr'))");
    $medalAdditionalFactor = floatval($userMedalResult[0]['factor'] ?? 0);
    do_log("$logPrefix, sql: $sql, count: " . count($torrentResult) . ", officialTag: $officialTag, officialAdditionalFactor: $officialAdditionalFactor, zeroBonusTag: $zeroBonusTag, zeroBonusFactor: $zeroBonusFactor, medalAdditionalFactor: $medalAdditionalFactor");
    $last_action = "";
    $ip_arr = [];
    foreach ($torrentResult as $torrent)
    {
        if ($torrent['last_action'] > $last_action) {
            $last_action = $torrent['last_action'];
        }
        if (!empty($torrent['ip']) && !isset($ip_arr[$torrent['ip']])) {
            $ip_arr[$torrent['ip']] = $torrent['ip'];
        }
        $size = bcadd($size, $torrent['size']);
        $weeks_alive = ($timenow - strtotime($torrent['added'])) / $sectoweek;
        $gb_size = $gb_size_raw = $torrent['size'] / 1073741824;
        if ($zeroBonusTag && isset($tagGrouped[$torrent['id']][$zeroBonusTag]) && is_numeric($zeroBonusFactor)) {
            $gb_size = $gb_size * $zeroBonusFactor;
        }
        $temp = (1 - exp($valueone * $weeks_alive)) * $gb_size * (1 + $sqrtof2 * exp($valuethree * ($torrent['seeders'] - 1)));
        $A += $temp;
        $count++;
        $torrent_peer_count++;
        $officialAIncrease = 0;
        if ($officialTag && isset($tagGrouped[$torrent['id']][$officialTag])) {
            $officialAIncrease = $temp;
            $official_torrent_peer_count++;
            $official_size = bcadd($official_size, $torrent['size']);
        }
        $official_a += $officialAIncrease;
        do_log(sprintf(
            "$logPrefix, torrent: %s, peer ID: %s, weeks: %s, size_raw: %s GB, size: %s GB, increase A: %s, increase official A: %s",
            $torrent['id'], $torrent['peerID'], $weeks_alive, $gb_size_raw, $gb_size, $temp, $officialAIncrease
        ), "debug");
    }
    if ($count > $maxseeding_bonus)
        $count = $maxseeding_bonus;
    $seed_bonus = $seed_points = $valuetwo * atan($A / $l_bonus) + ($perseeding_bonus * $count);
    //Official addition don't think about the minimum value
    $official_bonus =  $valuetwo * atan($official_a / $l_bonus);
    $medal_bonus = $valuetwo * atan($A / $l_bonus);
    $result = compact(
        'seed_points','seed_bonus', 'A', 'count', 'torrent_peer_count', 'size', 'last_action',
        'official_bonus', 'official_a', 'official_torrent_peer_count', 'official_size', 'medal_bonus',
    );
    $result['donor_times'] = $donortimes_bonus;
    $result['official_additional_factor'] = $officialAdditionalFactor;
    $result['medal_additional_factor'] = $medalAdditionalFactor;
    $result['ip_arr'] = array_keys($ip_arr);
    do_log("$logPrefix, result: " . json_encode($result));
    return $result;
}

function calculate_harem_addition($uid)
{
//    $harems = \App\Models\User::query()
//        ->where('invited_by', $uid)
//        ->where('status', \App\Models\User::STATUS_CONFIRMED)
//        ->where('enabled', \App\Models\User::ENABLED_YES)
//        ->get(['id']);
//    $addition = 0;
//    $haremsCount = $harems->count();
//    foreach ($harems as $harem) {
//        $result = calculate_seed_bonus($harem->id);
//        $addition += $result['seed_points'];
//    }
//    do_log("[HAREM_ADDITION], user: $uid, haremsCount: $haremsCount ,addition: $addition");

    $addition = \Nexus\Database\NexusDB::table("users")
        ->where("invited_by", $uid)
        ->where('status', \App\Models\User::STATUS_CONFIRMED)
        ->where('enabled', \App\Models\User::ENABLED_YES)
        ->sum("seed_points_per_hour")
    ;
    do_log("[HAREM_ADDITION], user: $uid, addition: $addition");
    return $addition;
}


function build_search_box_category_table($mode, $checkboxValue, $categoryHrefPrefix, $taxonomyHrefPrefix, $taxonomyNameLength, $checkedValues = '', array $options = [])
{
    parse_str($checkedValues, $checkedValuesArr);
    $searchBox = \App\Models\SearchBox::query()->with(['categories', 'categories.icon'])->findOrFail($mode);
    $lang = get_langfolder_cookie();
    $withTaxonomies = [];
    if ($searchBox->showsubcat) {
        //Keep the order
        if (!empty($searchBox->extra[SearchBox::EXTRA_TAXONOMY_LABELS])) {
            foreach ($searchBox->extra[SearchBox::EXTRA_TAXONOMY_LABELS] as $taxonomyLabelInfo) {
                $torrentField = $taxonomyLabelInfo["torrent_field"];
                $showField = "show" . $torrentField;
                if ($searchBox->{$showField}) {
                    $withTaxonomies[$torrentField] = \App\Models\SearchBox::$taxonomies[$torrentField]['table'];
                }
            }
        } else {
            foreach (\App\Models\SearchBox::$taxonomies as $torrentField => $taxonomyTableModel) {
                $showField = "show" . $torrentField;
                if ($searchBox->{$showField}) {
                    $withTaxonomies[$torrentField] = $taxonomyTableModel['table'];
                }
            }
        }
    }
    $html = '<table>';
    if (!empty($options['section_name'])) {
        $html .= sprintf('<caption><font class="big">%s</font></caption>', $searchBox->section_name[$lang] ?? '');
    }
    //Category
    $html .= sprintf('<tr><td class="embedded" align="left">%s</td></tr>', nexus_trans('label.search_box.category'));
    /** @var \Illuminate\DataBase\Eloquent\Collection $categoryCollection */
    $categoryCollection = $searchBox->categories()->with('icon')->orderBy('sort_index')->orderBy('id')->get();
    if (!empty($options['select_unselect'])) {
        $categoryCollection->push(new \App\Models\Category(['mode' => -1]));
    }
    $categoryChunks = $categoryCollection->chunk($searchBox->catsperrow);
    $checkPrefix = 'cat';
    foreach ($categoryChunks as $chunk) {
        $html .= '<tr>';
        foreach ($chunk as $item) {
            if ($item->mode != -1) {
                $checked = '';
                if ($checkedValues) {
                    if (
                        str_contains($checkedValues, "[cat{$item->id}]")
                        || (isset($checkedValuesArr["cat{$item->id}"]) && $checkedValuesArr["cat{$item->id}"] == 1)
                        || (isset($checkedValuesArr["cat"]) && $checkedValuesArr["cat"] == $item->id)
                    ) {
                        $checked = " checked";
                    }
                } elseif (!empty($options['user_notifs'])) {
                    $userNotifsKey = sprintf('[%s%s]', 'cat', $item->id);
                    if (str_contains($options['user_notifs'], $userNotifsKey)) {
                        $checked = ' checked';
                    }
                }
                $icon = $item->icon;
                $iconFolder = trim($icon->folder, '/');
                $langAndFile = sprintf('%s%s',  $icon->multilang == 'yes' ? "$lang/" : "", $item->image);
                if (file_exists(getFullDirectory("pic/category/$iconFolder/$langAndFile"))) {
                    $backgroundImagePath = "pic/category/$iconFolder/$langAndFile";
                } else {
                    $backgroundImagePath = "pic/category/{$searchBox->name}/$iconFolder/$langAndFile";
                }
                $tdContent = <<<TDCONTENT
<input type="checkbox" id="cat{$item->id}" name="cat{$item->id}" value="{$checkboxValue}"{$checked} />
<a href="{$categoryHrefPrefix}cat={$item->id}"><img src="pic/cattrans.gif" class="{$item->class_name}" alt="{$item->name}" title="{$item->name}" style="background-image: url({$backgroundImagePath})" /></a>
TDCONTENT;
            } else {
                $tdContent = sprintf(
                    "<input name=\"%s_check\" value=\"%s\" class=\"btn medium\" type=\"button\" onclick=\"javascript:SetChecked('%s','%s_check','%s','%s',-1,10)\">",
                    $checkPrefix, nexus_trans('nexus.select_all'), $checkPrefix, $checkPrefix, nexus_trans('nexus.select_all'), nexus_trans('nexus.unselect_all')
                );
            }
            $td = <<<TD
<td align="left" class="bottom" style="padding-bottom: 4px;padding-left: {$searchBox->catpadding}px">
    $tdContent
</td>
TD;
            $html .= $td;
        }
        $html .= '</tr>';
    }
    //Taxonomy
    foreach ($withTaxonomies as $torrentField => $tableName) {
        if ($taxonomyNameLength > 0) {
            $namePrefix = substr($torrentField, 0, $taxonomyNameLength);
        } else {
            $namePrefix = $torrentField;
        }
        $html .= sprintf('<tr><td class="embedded" align="left">%s</td></tr>', $searchBox->getTaxonomyLabel($torrentField));
        /** @var \Illuminate\DataBase\Eloquent\Collection $taxonomyCollection */
        $taxonomyCollection = \Nexus\Database\NexusDB::table($tableName)
            ->where(function (\Illuminate\Database\Query\Builder $query) use ($mode) {
                return $query->whereIn('mode', [$mode, 0]);
            })
            ->orderBy('sort_index', 'desc')
            ->get()
        ;
        $modelName = \App\Models\SearchBox::$taxonomies[$torrentField]['model'];
        $checkPrefix = $torrentField;
        if (!empty($options['select_unselect'])) {
            $taxonomyCollection->push(new $modelName(['mode' => -1]));
        }
        $taxonomyChunks = $taxonomyCollection->chunk($searchBox->catsperrow);
        foreach ($taxonomyChunks as $chunk) {
            $html .= '<tr>';
            foreach ($chunk as $item) {
                if ($item->mode != -1) {
                    if ($taxonomyHrefPrefix) {
                        $afterInput = sprintf('<a href="%s%s=%s">%s</a>', $taxonomyHrefPrefix, $namePrefix, $item->id, $item->name);
                    } else {
                        $afterInput = $item->name;
                    }
                    $checked = '';
                    do_log("toCheck: $checkedValues, $namePrefix - {$item->id}", 'debug');
                    if ($checkedValues) {
                        if (
                            str_contains($checkedValues, "[{$namePrefix}{$item->id}]")
                            || (isset($checkedValuesArr["{$namePrefix}{$item->id}"]) && $checkedValuesArr["{$namePrefix}{$item->id}"] == 1)
                            || (isset($checkedValuesArr[$namePrefix]) && $checkedValuesArr[$namePrefix] == $item->id)
                        ) {
                            $checked = ' checked';
                        }
                    } elseif (!empty($options['user_notifs'])) {
                        $userNotifsKey = sprintf('[%s%s]', substr($torrentField, 0, 3), $item->id);
                        if (str_contains($options['user_notifs'], $userNotifsKey)) {
                            $checked = ' checked';
                        }
                    }
                    $tdContent = <<<TDCONTENT
<label><input type="checkbox" id="{$namePrefix}{$item->id}" name="{$namePrefix}{$item->id}" value="{$checkboxValue}"{$checked} />$afterInput</label>
TDCONTENT;
                } else {
                    $tdContent = sprintf(
                        "<input name=\"%s_check\" value=\"%s\" class=\"btn medium\" type=\"button\" onclick=\"javascript:SetChecked('%s','%s_check','%s','%s',-1,10)\">",
                        $checkPrefix, nexus_trans('nexus.select_all'), $checkPrefix, $checkPrefix, nexus_trans('nexus.select_all'), nexus_trans('nexus.unselect_all')
                    );
                }
                $td = <<<TD
<td align="left" class="bottom" style="padding-bottom: 4px;padding-left: {$searchBox->catpadding}px">
    $tdContent
</td>
TD;
                $html .= $td;
            }
            $html .= '</tr>';
        }
        $html .= '</tr>';
    }
    $html .= '</table>';
    return $html;
}

function datetimepicker_input($name, $value = '', $label = '', array $options = [])
{
    $lang = get_langfolder_cookie(true);
    if ($lang == 'zh_CN') {
        $lang = 'zh';
    }
    $lang = str_replace('_', '-', $lang);
    $js = '';
    if (!empty($options['require_files'])) {
        \Nexus\Nexus::css('vendor/jquery-datetimepicker/jquery.datetimepicker.min.css', 'footer', true);
        \Nexus\Nexus::js('vendor/jquery-datetimepicker/jquery.datetimepicker.full.min.js', 'footer', true);
        $js = "jQuery.datetimepicker.setLocale('{$lang}');";
    }
    $id = "datetime-picker-$name";
    $input = sprintf('%s<input type="text" id="%s" name="%s" value="%s" autocomplete="off" style="%s">', $label, $id, $name, $value, $options['style'] ?? '');
    $format = $options['format'] ?? 'Y-m-d H:i';
    $js .= <<<JS
jQuery("#{$id}").datetimepicker({
    format: '{$format}'
})
JS;
    \Nexus\Nexus::js($js, 'footer', false);
    return $input;
}

function build_bonus_table(array $user, array $bonusResult = [], array $options = [])
{
    if (empty($bonusResult)) {
        $bonusResult = calculate_seed_bonus($user['id']);
    }
    $officialTag = get_setting('bonus.official_tag');
    $officialAdditionalFactor = get_setting('bonus.official_addition', 0);
    $haremFactor = get_setting('bonus.harem_addition');
    $haremAddition = calculate_harem_addition($user['id']);
    $isDonor = is_donor($user);
    $donortimes_bonus = get_setting('bonus.donortimes');
    $baseBonusFactor = 1;
    if ($isDonor && $donortimes_bonus != 0) {
        $baseBonusFactor = $donortimes_bonus;
    }
    $baseBonus = $bonusResult['seed_bonus'] * $baseBonusFactor;
    $totalBonus = $baseBonus;

    $rowSpan = 1;
    $hasHaremAddition = $hasOfficialAddition = $hasMedalAddition = false;
    if ($haremFactor > 0) {
        $rowSpan++;
        $hasHaremAddition = true;
        $totalBonus +=  $haremAddition * $haremFactor;
    }
    if ($officialAdditionalFactor > 0 && $officialTag) {
        $rowSpan++;
        $hasOfficialAddition = true;
        $totalBonus += $bonusResult['official_bonus'] * $officialAdditionalFactor;
    }
    if ($bonusResult['medal_additional_factor'] > 0) {
        $rowSpan++;
        $hasMedalAddition = true;
        $totalBonus += $bonusResult['medal_bonus'] * $bonusResult['medal_additional_factor'];
    }

    // 各列标签(手机端卡片化时用 data-label 显示)
    $Lc = htmlspecialchars(nexus_trans('bonus.table_thead.count'), ENT_QUOTES);
    $Ls = htmlspecialchars(nexus_trans('bonus.table_thead.size'), ENT_QUOTES);
    $La = htmlspecialchars(nexus_trans('bonus.table_thead.a_value'), ENT_QUOTES);
    $Lb = htmlspecialchars(nexus_trans('bonus.table_thead.bonus_base'), ENT_QUOTES);
    $Lf = htmlspecialchars(nexus_trans('bonus.table_thead.factor'), ENT_QUOTES);
    $Lg = htmlspecialchars(nexus_trans('bonus.table_thead.got_bonus'), ENT_QUOTES);
    $Lt = htmlspecialchars(nexus_trans('bonus.table_thead.total'), ENT_QUOTES);
    $dc = "<td data-label=\"$Lc\">%s</td><td data-label=\"$Ls\">%s</td><td data-label=\"$La\">%s</td><td data-label=\"$Lb\">%s</td><td data-label=\"$Lf\">%s</td><td data-label=\"$Lg\">%s</td>";

    $table = sprintf('<table cellpadding="5" class="bonus-breakdown" style="%s">', $options['table_style'] ?? '');
    $table .= '<tr>';
    $table .= sprintf('<td class="colhead">%s</td>', nexus_trans('bonus.table_thead.reward_type'));
    $table .= sprintf('<td class="colhead">%s</td>', nexus_trans('bonus.table_thead.count'));
    $table .= sprintf('<td class="colhead">%s</td>', nexus_trans('bonus.table_thead.size'));
    $table .= sprintf('<td class="colhead">%s</td>', nexus_trans('bonus.table_thead.a_value'));
    $table .= sprintf('<td class="colhead">%s</td>', nexus_trans('bonus.table_thead.bonus_base'));
    $table .= sprintf('<td class="colhead">%s</td>', nexus_trans('bonus.table_thead.factor'));
    $table .= sprintf('<td class="colhead">%s</td>', nexus_trans('bonus.table_thead.got_bonus'));
    $table .= sprintf('<td class="colhead">%s</td>', nexus_trans('bonus.table_thead.total'));
    $table .= '</tr>';

    $table .= sprintf(
        '<tr><td class="brk-type">%s</td>' . $dc . '<td class="brk-total" data-label="' . $Lt . '" rowspan="%s">%s</td></tr>',
        nexus_trans('bonus.reward_types.basic'),
        $bonusResult['torrent_peer_count'],
        mksize($bonusResult['size']),
        number_format($bonusResult['A'], 3),
        number_format($bonusResult['seed_bonus'],3),
        $baseBonusFactor,
        number_format($baseBonus,3),
        $rowSpan,
        number_format($totalBonus, 3)
    );
    if ($hasMedalAddition) {
        $table .= sprintf(
            '<tr><td class="brk-type">%s</td>' . $dc . '</tr>',
            nexus_trans('bonus.reward_types.medal_addition'),
            $bonusResult['torrent_peer_count'],
            mksize($bonusResult['size']),
            number_format($bonusResult['A'], 3),
            number_format($bonusResult['medal_bonus'], 3),
            number_format($bonusResult['medal_additional_factor'], 3),
            number_format($bonusResult['medal_bonus'] * $bonusResult['medal_additional_factor'], 3)
        );
    }

    if ($hasOfficialAddition) {
        $table .= sprintf(
            '<tr><td class="brk-type">%s</td>' . $dc . '</tr>',
            nexus_trans('bonus.reward_types.official_addition'),
            $bonusResult['official_torrent_peer_count'],
            mksize($bonusResult['official_size']),
            number_format($bonusResult['official_a'], 3),
            number_format($bonusResult['official_bonus'], 3),
            number_format($officialAdditionalFactor, 3),
            number_format($bonusResult['official_bonus'] * $officialAdditionalFactor, 3)
        );
    }

    if ($hasHaremAddition) {
        $table .= sprintf(
            '<tr><td class="brk-type">%s</td>' . $dc . '</tr>',
            nexus_trans('bonus.reward_types.harem_addition'),
            '--',
            '--',
            '--',
            number_format($haremAddition, 3),
            number_format($haremFactor, 3),
            number_format($haremAddition * $haremFactor, 3)
        );
    }

    $table .= '</table>';

    return [
        'table' => $table,
        'has_harem_addition' => $hasHaremAddition,
        'harem_addition_factor' => $haremFactor,
        'has_official_addition' => $hasOfficialAddition,
        'official_addition_factor' => $officialAdditionalFactor,
        'has_medal_addition' => $hasMedalAddition,
        'medal_addition_factor' => $bonusResult['medal_additional_factor'],
    ];

}

function build_search_area($searchArea, array $options = [])
{
    $result = sprintf('<select name="search_area" style="%s">', $options['style'] ?? '');
    foreach ([0, 1, 3, 4] as $item) {
        $result .= sprintf(
            '<option value="%s"%s>%s</option>',
            $item, $item == $searchArea ? ' selected' : '', nexus_trans("search.search_area_options.$item")
        );
    }
    $result .= '</select>';
    return $result;
}

function torrent_name_for_admin(\App\Models\Torrent|null $torrent, $withTags = false, $length = 40)
{
    if (empty($torrent)) {
        return '';
    }
    $name = sprintf(
        '<div class="fi-color fi-color-primary fi-text-color-600 dark:fi-text-color-300 fi-link fi-size-sm fi-ac-link-action"><a href="/details.php?id=%s" target="_blank" title="%s">%s</a></div>',
        $torrent->id, $torrent->name, Str::limit($torrent->name, $length)
    );
    $tags = '';
    if ($withTags) {
        $tags = sprintf('&nbsp;<div>%s</div>', $torrent->tagsFormatted);
    }
    return new HtmlString('<div style="display:flex">' . $name . $tags . '</div>');
}

function username_for_admin(int $id)
{
    if (empty($id)) {
        return '';
    }
    return new HtmlString(get_username($id, false, true, true, true));
}

function can_view_post($uid, $post)
{
    static $topics = [];
    static $protectedForumIdArr;
    static $forumMods;
    if (!is_array($post)) {
        $post = \App\Models\Post::query()->findOrFail(intval($post))->toArray();
    }
    $topicId = $post['topicid'];
    if (!isset($topics[$topicId])) {
        $topics[$topicId] = \App\Models\Topic::query()->findOrFail($topicId);
    }
    /** @var \App\Models\Topic $topicInfo */
    $topicInfo = $topics[$topicId];

    $forumId = $topicInfo->forumid;

    if (is_null($protectedForumIdArr)) {
        $protectedForumIdArr = [];
        $protectedForumIds = \Nexus\Database\NexusDB::remember("setting_protected_forum", 600, function () {
            return \App\Models\Setting::getByName('misc.protected_forum');
        });
        $protectedForumIdArr = $protectedForumIds ? preg_split("/[,\s]+/", $protectedForumIds) : [];
    }
    if (is_null($forumMods)) {
        $forumMods = [];
        $results = \App\Models\ForumMod::query()->get();
        foreach ($results as $item) {
            $forumMods[$item->forumid] = $item->userid;
        }
    }
    $isForumMod = isset($forumMods[$forumId]) && $forumMods[$forumId] == $uid;
    $log = sprintf(
        "uid: $uid, class: %s,  post: {$post['id']}, forumId: $forumId, protectedForumIdArr: %s, forumMods: %s, isForumMod: %s",
        get_user_class(), json_encode($protectedForumIdArr), json_encode($forumMods), $isForumMod
    );
    if (
        in_array($forumId, $protectedForumIdArr)
        && get_user_class() < \App\Models\User::CLASS_ADMINISTRATOR
        && $uid != $post['userid']
        && $uid != $topicInfo->userid
        && !$isForumMod
    ) {
        do_log("$log, FALSE");
        return false;
    }
    do_log("$log, TRUE");
    return true;
}

function hide_text($text) {
    return '<span class="hidden-text">' . $text . '</span>';
}

function make_content_disposition(string $filename, string $disposition = 'attachment'): string {
    $filenameFallback = str_replace('%', '', Str::ascii($filename));
    return \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition($disposition, $filename, $filenameFallback);
}

function bbcode_attach_to_img(string $text) {
    $pattern = "/\[attach\]([0-9a-zA-z][0-9a-zA-z]*)\[\/attach\]/is";
    return preg_replace_callback($pattern, function ($matches) {
        $dlkey = $matches[1];
        $httpdirectory_attachment = get_setting('attachment.httpdirectory');
        $row = \Nexus\Database\NexusDB::remember('attachment_'.$dlkey.'_content', 86400, function() use ($dlkey) {
            $record =  \App\Models\Attachment::query()->where("dlkey", $dlkey)->first();
            if ($record) {
                return $record->toArray();
            }
            return [];
        });
        if (empty($row) || $row['isimage'] != 1) {
            do_log(sprintf("dlkey: %s get attachment %s not exists or not image", $dlkey, json_encode($row)));
            return $matches[0];
        }
        $driver = $row['driver'] ?? 'local';
        if ($driver == "local") {
            if ($row['thumb'] == 1){
                $url = $httpdirectory_attachment."/".$row['location'].".thumb.jpg";
            } else {
                $url = $httpdirectory_attachment."/".$row['location'];
            }
            $url = sprintf("%s/%s", getSchemeAndHttpHost(true), trim($url, "/"));
        } else {
            $url = \Nexus\Attachment\Storage::getDriver($driver)->getImageUrl($row['location']);
        }
        return "[img]" . $url . "[/img]";
    }, $text, 20);
}

function hdvideo_run_schema_sql($sql)
{
    $res = @sql_query($sql);
    if (!$res) {
        do_log('[HDVIDEO_REGION_STYLE_SCHEMA_ERROR] ' . $sql . ' :: ' . mysql_error(), 'error');
        return false;
    }
    return true;
}

function hdvideo_ensure_region_style_schema()
{
    static $done = false;
    if ($done) {
        return;
    }
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

function hdvideo_seed_filter_options($table, array $names)
{
    $now = sqlesc(date('Y-m-d H:i:s'));
    // 先读出已有 name => id，避免用 INSERT ... ON DUPLICATE KEY UPDATE。
    // 后者即使命中"重复→更新"分支，InnoDB 仍会白白消耗一个自增值，
    // 列表页每次加载都同步一遍，会很快把 SMALLINT 自增主键烧到 65535 上限，
    // 导致之后任何新地区/风格都插不进来。这里改成：已存在只 UPDATE，只有新名字才 INSERT。
    $existing = [];
    $res = @sql_query("SELECT id, name FROM `$table`");
    if ($res) {
        while ($row = mysql_fetch_assoc($res)) {
            $existing[(string)$row['name']] = (int)$row['id'];
        }
    }
    $clean = [];
    foreach ($names as $name) {
        $name = trim((string)$name);
        if ($name !== '' && !in_array($name, $clean, true)) {
            $clean[] = $name;
        }
    }
    $count = count($clean);
    $listedNames = [];
    foreach ($clean as $index => $name) {
        $listedNames[] = sqlesc($name);
        $sortIndex = $count - $index;
        if (isset($existing[$name])) {
            hdvideo_run_schema_sql("UPDATE `$table` SET sort_index = $sortIndex, enabled = 1, updated_at = $now WHERE id = " . $existing[$name]);
        } else {
            hdvideo_run_schema_sql("INSERT INTO `$table` (name, sort_index, enabled, created_at, updated_at) VALUES (" . sqlesc($name) . ", $sortIndex, 1, $now, $now)");
        }
    }
    if ($listedNames) {
        hdvideo_run_schema_sql("UPDATE `$table` SET enabled = 0, updated_at = $now WHERE name NOT IN (" . implode(',', $listedNames) . ")");
    }
}

function hdvideo_region_style_default_options($type)
{
    $defaults = [
        'styles' => ['短片', '喜剧', '动作', '科幻', '惊悚', '剧情', '爱情', '恐怖', '犯罪', '悬疑'],
        'regions' => ['中国大陆', '美国', '韩国', '英国', '泰国', '中国港台', '日本', '法国', '德国', '意大利'],
    ];
    return $defaults[$type] ?? [];
}

function hdvideo_region_style_option_names($type)
{
    // 直接读数据库（不走设置缓存），保证后台改了发布页地区/风格后能立即同步到
    // torrent_regions / torrent_styles 表，避免 legacy 侧设置缓存未刷新导致一直用默认值。
    $raw = get_setting_from_db("torrent_region_style.$type", '');
    if (is_array($raw)) {
        $raw = implode("\n", $raw);
    }
    $raw = trim((string)$raw);
    if ($raw === '') {
        return hdvideo_region_style_default_options($type);
    }
    $names = preg_split('/[\r\n,，、]+/u', $raw);
    $result = [];
    foreach ($names as $name) {
        $name = trim((string)$name);
        if ($name !== '') {
            $result[$name] = $name;
        }
    }
    return array_values($result ?: hdvideo_region_style_default_options($type));
}

function hdvideo_region_style_enabled()
{
    return get_setting_from_db('torrent_region_style.enabled', 'yes') !== 'no';
}

function hdvideo_region_style_required()
{
    return get_setting_from_db('torrent_region_style.required', 'yes') !== 'no';
}

function hdvideo_table_exists($table)
{
    static $exists = [];
    $table = preg_replace('/[^a-z0-9_]/i', '', (string)$table);
    if ($table === '') {
        return false;
    }
    if (!array_key_exists($table, $exists)) {
        $res = @sql_query("SHOW TABLES LIKE " . sqlesc($table));
        $exists[$table] = $res && mysql_num_rows($res) > 0;
    }
    return $exists[$table];
}

function hdvideo_column_exists($table, $column)
{
    static $exists = [];
    $table = preg_replace('/[^a-z0-9_]/i', '', (string)$table);
    $column = preg_replace('/[^a-z0-9_]/i', '', (string)$column);
    $key = $table . '.' . $column;
    if ($table === '' || $column === '') {
        return false;
    }
    if (!array_key_exists($key, $exists)) {
        $res = @sql_query("SHOW COLUMNS FROM `$table` LIKE " . sqlesc($column));
        $exists[$key] = $res && mysql_num_rows($res) > 0;
    }
    return $exists[$key];
}

function hdvideo_torrent_filter_items($table)
{
    if (!hdvideo_region_style_enabled()) {
        return [];
    }
    hdvideo_ensure_region_style_schema();
    if (!hdvideo_table_exists($table)) {
        return [];
    }
    $items = [];
    $res = sql_query("SELECT id, name FROM `$table` WHERE enabled = 1 ORDER BY sort_index DESC, id ASC");
    while ($row = mysql_fetch_assoc($res)) {
        $items[] = $row;
    }
    return $items;
}

function hdvideo_torrent_regions()
{
    return hdvideo_torrent_filter_items('torrent_regions');
}

function hdvideo_torrent_styles()
{
    return hdvideo_torrent_filter_items('torrent_styles');
}

function hdvideo_filter_valid_ids($ids, $items)
{
    $valid = [];
    foreach ($items as $item) {
        $valid[(int)$item['id']] = true;
    }
    $result = [];
    foreach ((array)$ids as $id) {
        $id = (int)$id;
        if ($id > 0 && isset($valid[$id])) {
            $result[$id] = $id;
        }
    }
    return array_values($result);
}

function hdvideo_render_region_select($mode, $selected = 0)
{
    $regions = hdvideo_torrent_regions();
    if (!$regions) {
        return '';
    }
    $html = '<b>地区: </b><select name="region_sel[' . (int)$mode . ']">';
    $html .= '<option value="0">请选择</option>';
    foreach ($regions as $region) {
        $id = (int)$region['id'];
        $html .= '<option value="' . $id . '"' . ((int)$selected === $id ? ' selected="selected"' : '') . '>' . htmlspecialchars($region['name']) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

function hdvideo_render_style_checkboxes($mode, array $selected = [])
{
    $styles = hdvideo_torrent_styles();
    if (!$styles) {
        return '';
    }
    $selectedMap = array_flip(array_map('intval', $selected));
    $html = '<div class="torrent-upload-style-grid">';
    foreach ($styles as $style) {
        $id = (int)$style['id'];
        $html .= '<label><input type="checkbox" name="style_sel[' . (int)$mode . '][]" value="' . $id . '"' . (isset($selectedMap[$id]) ? ' checked="checked"' : '') . ' />' . htmlspecialchars($style['name']) . '</label>';
    }
    $html .= '</div>';
    return $html;
}

function hdvideo_render_upload_region_style_rows($mode, $selectedRegion = 0, array $selectedStyles = [])
{
    if (!hdvideo_region_style_enabled()) {
        return;
    }
    $relation = "mode_" . (int)$mode;
    $requiredMark = hdvideo_region_style_required() ? '<font color="red">*</font>' : '';
    $regionSelect = hdvideo_render_region_select($mode, $selectedRegion);
    if ($regionSelect !== '') {
        tr('地区' . $requiredMark, $regionSelect, 1, $relation);
    }
    $styleCheckboxes = hdvideo_render_style_checkboxes($mode, $selectedStyles);
    if ($styleCheckboxes !== '') {
        tr('风格' . $requiredMark, $styleCheckboxes, 1, $relation);
    }
}

function hdvideo_get_post_region($mode)
{
    return (int)($_POST['region_sel'][$mode] ?? 0);
}

function hdvideo_get_post_styles($mode)
{
    return hdvideo_filter_valid_ids($_POST['style_sel'][$mode] ?? [], hdvideo_torrent_styles());
}

function hdvideo_validate_region_style($mode, $barkCallback)
{
    if (!hdvideo_region_style_enabled()) {
        return [0, []];
    }
    hdvideo_ensure_region_style_schema();
    if (!hdvideo_column_exists('torrents', 'region') || !hdvideo_table_exists('torrent_style_torrent')) {
        $barkCallback('风格和地区数据表尚未初始化，请联系管理员。');
        return [0, []];
    }
    $regionId = hdvideo_get_post_region($mode);
    $validRegionIds = hdvideo_filter_valid_ids([$regionId], hdvideo_torrent_regions());
    if (!$validRegionIds && hdvideo_region_style_required()) {
        $barkCallback('请选择地区。');
    }
    $styleIds = hdvideo_get_post_styles($mode);
    if (!$styleIds && hdvideo_region_style_required()) {
        $barkCallback('请至少选择一个风格。');
    }
    return [$validRegionIds[0] ?? 0, $styleIds];
}

function hdvideo_get_torrent_style_ids($torrentId)
{
    hdvideo_ensure_region_style_schema();
    if (!hdvideo_table_exists('torrent_style_torrent')) {
        return [];
    }
    $ids = [];
    $res = sql_query("SELECT style_id FROM torrent_style_torrent WHERE torrent_id = " . sqlesc((int)$torrentId));
    while ($row = mysql_fetch_assoc($res)) {
        $ids[] = (int)$row['style_id'];
    }
    return $ids;
}

function hdvideo_save_torrent_styles($torrentId, array $styleIds)
{
    hdvideo_ensure_region_style_schema();
    if (!hdvideo_table_exists('torrent_style_torrent')) {
        return;
    }
    $torrentId = (int)$torrentId;
    sql_query("DELETE FROM torrent_style_torrent WHERE torrent_id = " . sqlesc($torrentId));
    $styleIds = hdvideo_filter_valid_ids($styleIds, hdvideo_torrent_styles());
    if (!$styleIds) {
        return;
    }
    $values = [];
    $now = sqlesc(date('Y-m-d H:i:s'));
    foreach ($styleIds as $styleId) {
        $values[] = "(" . sqlesc($torrentId) . ", " . sqlesc((int)$styleId) . ", $now, $now)";
    }
    sql_query("INSERT IGNORE INTO torrent_style_torrent (torrent_id, style_id, created_at, updated_at) VALUES " . implode(',', $values));
}

?>
