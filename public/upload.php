<?php
require_once("../include/bittorrent.php");
dbconn();
require_once(get_langfile_path());
require_once(get_langfile_path('edit.php'));
loggedinorreturn();
parked();
if ($CURUSER["uploadpos"] == 'no')
	stderr($lang_upload['std_sorry'], $lang_upload['std_unauthorized_to_upload'],false);

if ($enableoffer == 'yes')
    $has_allowed_offer = get_row_count("offers","WHERE allowed='allowed' AND userid = ". sqlesc($CURUSER["id"]));
else $has_allowed_offer = 0;
$uploadfreely = user_can_upload("torrents");
$allowtorrents = ($has_allowed_offer || $uploadfreely);
$allowspecial = user_can_upload("music");

if (!$allowtorrents && !$allowspecial)
	stderr($lang_upload['std_sorry'],$lang_upload['std_please_offer'],false);
$allowtwosec = ($allowtorrents && $allowspecial);

$brsectiontype = $browsecatmode;
$spsectiontype = $specialcatmode;
/*
$showsource = (($allowtorrents && get_searchbox_value($brsectiontype, 'showsource')) || ($allowspecial && get_searchbox_value($spsectiontype, 'showsource'))); //whether show sources or not
$showmedium = (($allowtorrents && get_searchbox_value($brsectiontype, 'showmedium')) || ($allowspecial && get_searchbox_value($spsectiontype, 'showmedium'))); //whether show media or not
$showcodec = (($allowtorrents && get_searchbox_value($brsectiontype, 'showcodec')) || ($allowspecial && get_searchbox_value($spsectiontype, 'showcodec'))); //whether show codecs or not
$showstandard = (($allowtorrents && get_searchbox_value($brsectiontype, 'showstandard')) || ($allowspecial && get_searchbox_value($spsectiontype, 'showstandard'))); //whether show standards or not
$showprocessing = (($allowtorrents && get_searchbox_value($brsectiontype, 'showprocessing')) || ($allowspecial && get_searchbox_value($spsectiontype, 'showprocessing'))); //whether show processings or not
$showteam = (($allowtorrents && get_searchbox_value($brsectiontype, 'showteam')) || ($allowspecial && get_searchbox_value($spsectiontype, 'showteam'))); //whether show teams or not
$showaudiocodec = (($allowtorrents && get_searchbox_value($brsectiontype, 'showaudiocodec')) || ($allowspecial && get_searchbox_value($spsectiontype, 'showaudiocodec'))); //whether show languages or not
*/
$settingMain = get_setting('main');
$torrentRep = new \App\Repositories\TorrentRepository();
$searchBoxRep = new \App\Repositories\SearchBoxRepository();
$tagRep = new \App\Repositories\TagRepository();
stdhead($lang_upload['head_upload']);
?>
	<form id="compose" enctype="multipart/form-data" action="takeupload.php" method="post" name="upload">
			<?php
			print("<p align=\"center\">".$lang_upload['text_red_star_required']."</p>");
			?>
			<table border="1" cellspacing="0" cellpadding="5" width="97%">
				<tr>
					<td class='colhead' colspan='2' align='center'>
						<?php echo $lang_upload['text_tracker_url'] ?>: &nbsp;&nbsp;&nbsp;&nbsp;<b><?php echo  get_tracker_schema_and_host($CURUSER['tracker_url_id'], true)?></b>
						<?php
						if(!is_writable(getFullDirectory($torrent_dir)))
						print("<br /><br /><b>ATTENTION</b>: Torrent directory isn't writable. Please contact the administrator about this problem!");
						if(!$max_torrent_size)
						print("<br /><br /><b>ATTENTION</b>: Max. Torrent Size not set. Please contact the administrator about this problem!");
						?>
					</td>
				</tr>
				<?php
				tr($lang_upload['row_torrent_file']."<font color=\"red\">*</font>", "<input type=\"file\" class=\"file\" id=\"torrent\" name=\"file\" onchange=\"getname()\" />\n", 1);
				if ($altname_main == 'yes'){
					tr($lang_upload['row_torrent_name'], "<b>".$lang_upload['text_english_title']."</b>&nbsp;<input type=\"text\" style=\"width: 250px;\" name=\"name\" />&nbsp;&nbsp;&nbsp;
<b>".$lang_upload['text_chinese_title']."</b>&nbsp;<input type=\"text\" style=\"width: 250px\" name=\"cnname\"><br /><font class=\"medium\">".$lang_upload['text_titles_note']."</font>", 1);
				} else {
				    $autoFillText = $lang_upload['fill_quality'];
				    $nameInput = $torrentRep->buildUploadFieldInput("name", "", $lang_upload['text_torrent_name_note'], $autoFillText);
                    tr($lang_upload['row_torrent_name'], $nameInput, 1);
                }

				if ($smalldescription_main == 'yes')
				tr($lang_upload['row_small_description'], "<input type=\"text\" style=\"width: 99%;\" name=\"small_descr\" /><br /><font class=\"medium\">".$lang_upload['text_small_description_note']."</font>", 1);
				get_external_tr();
				if ($settingMain['enable_pt_gen_system'] == 'yes') {
                    $ptGen = new \Nexus\PTGen\PTGen();
                    echo $ptGen->renderUploadPageFormInput("");
                }
				if ($enablenfo_main=='yes') {
                    tr($lang_upload['row_nfo_file'], "<input type=\"file\" class=\"file\" name=\"nfo\" /><br /><font class=\"medium\">".$lang_upload['text_only_viewed_by'].get_user_class_name($viewnfo_class,false,true,true).$lang_upload['text_or_above']."</font>", 1);
                }
                //price
                if (user_can('torrent-set-price') && get_setting("torrent.paid_torrent_enabled") == "yes") {
                    $maxPrice = get_setting("torrent.max_price");
                    $pricePlaceholder = "";
                    if ($maxPrice > 0) {
                        $pricePlaceholder = nexus_trans("label.torrent.max_price_help", ["max_price" => $maxPrice]);
                    }
                    tr(nexus_trans('label.torrent.price'), '<input type="number" min="0" name="price" placeholder="'.$pricePlaceholder.'" />&nbsp;&nbsp;' . nexus_trans('label.torrent.price_help', ['tax_factor' => (floatval(get_setting('torrent.tax_factor', 0)) * 100) . '%']), 1);
                }

				print("<tr><td class=\"rowhead\" style='padding: 3px' valign=\"top\">".$lang_upload['row_description']."<font color=\"red\">*</font></td><td class=\"rowfollow\">");
				textbbcode("upload","descr", "", false, 130, true);
				print('<div style="margin-top: 6px;"><input type="button" class="btn btn-parse-desc" value="解析简介" /></div>');
				print("</td></tr>\n");

                if ($settingMain['enable_technical_info'] == 'yes') {
                    tr($lang_functions['text_technical_info'], '<textarea name="technical_info" rows="8" style="width: 99%;"></textarea><br/>' . $lang_functions['text_technical_info_help_text'], 1);
                }

				if ($allowtorrents){
					$disablespecial = " onchange=\"disableother('browsecat','specialcat')\"";
					$s = "<select name=\"type\" id=\"browsecat\" data-mode='$browsecatmode' ".($allowtwosec ? $disablespecial : "").">\n<option value=\"0\">".$lang_upload['select_choose_one']."</option>\n";
					$cats = genrelist($browsecatmode);
					foreach ($cats as $row)
						$s .= "<option value=\"" . $row["id"] . "\">" . htmlspecialchars($row["name"]) . "</option>\n";
					$s .= "</select>\n";
				}
				else $s = "";
				if ($allowspecial){
					$disablebrowse = " onchange=\"disableother('specialcat','browsecat')\"";
					$s2 = "<select name=\"type\" id=\"specialcat\" data-mode='$specialcatmode' ".$disablebrowse.">\n<option value=\"0\">".$lang_upload['select_choose_one']."</option>\n";
					$cats2 = genrelist($specialcatmode);
					foreach ($cats2 as $row)
						$s2 .= "<option value=\"" . $row["id"] . "\">" . htmlspecialchars($row["name"]) . "</option>\n";
					$s2 .= "</select>\n";
				}
				else $s2 = "";
				tr($lang_upload['row_type']."<font color=\"red\">*</font>", ($allowtwosec ? $lang_upload['text_to_browse_section'] : "").$s.($allowtwosec ? $lang_upload['text_to_special_section'] : "").$s2.($allowtwosec ? $lang_upload['text_type_note'] : ""),1);
/*
				if ($showsource || $showmedium || $showcodec || $showaudiocodec || $showstandard || $showprocessing){
					if ($showsource){
						$source_select = torrent_selection($lang_upload['text_source'],"source_sel","sources");
					}
					else $source_select = "";

					if ($showmedium){
						$medium_select = torrent_selection($lang_upload['text_medium'],"medium_sel","media");
					}
					else $medium_select = "";

					if ($showcodec){
						$codec_select = torrent_selection($lang_upload['text_codec'],"codec_sel","codecs");
					}
					else $codec_select = "";

					if ($showaudiocodec){
						$audiocodec_select = torrent_selection($lang_upload['text_audio_codec'],"audiocodec_sel","audiocodecs");
					}
					else $audiocodec_select = "";

					if ($showstandard){
						$standard_select = torrent_selection($lang_upload['text_standard'],"standard_sel","standards");
					}
					else $standard_select = "";

					if ($showprocessing){
						$processing_select = torrent_selection($lang_upload['text_processing'],"processing_sel","processings");
					}
					else $processing_select = "";

					tr($lang_upload['row_quality'], $source_select . $medium_select. $codec_select . $audiocodec_select. $standard_select . $processing_select, 1 );
				}

				if ($showteam){
					if ($showteam){
						$team_select = torrent_selection($lang_upload['text_team'],"team_sel","teams");
					}
					else $showteam = "";

					tr($lang_upload['row_content'],$team_select,1);
				}
*/
                $customField = new \Nexus\Field\Field();
                $hitAndRunRep = new \App\Repositories\HitAndRunRepository();
                if ($allowtorrents) {
                    $selectNormal = $searchBoxRep->renderTaxonomySelect($browsecatmode);
                    tr($lang_upload['row_quality'], $selectNormal, 1, "mode_$browsecatmode");
                    hdvideo_render_upload_region_style_rows($browsecatmode);
                    echo $customField->renderOnUploadPage(0, $browsecatmode);
                    echo $hitAndRunRep->renderOnUploadPage('', $browsecatmode);
                    tr($lang_functions['text_tags'], $tagRep->renderCheckbox($browsecatmode), 1, "mode_$browsecatmode");
                }
                if ($allowspecial) {
                    $selectNormal = $searchBoxRep->renderTaxonomySelect($specialcatmode);
                    tr($lang_upload['row_quality'], $selectNormal, 1, "mode_$specialcatmode");
                    hdvideo_render_upload_region_style_rows($specialcatmode);
                    echo $customField->renderOnUploadPage(0, $specialcatmode);
                    echo $hitAndRunRep->renderOnUploadPage('', $specialcatmode);
                    tr($lang_functions['text_tags'], $tagRep->renderCheckbox($specialcatmode), 1, "mode_$specialcatmode");
                }

				//==== offer dropdown for offer mod  from code by S4NE
				$offerres = sql_query("SELECT id, name FROM offers WHERE userid = ".sqlesc($CURUSER['id'])." AND allowed = 'allowed' ORDER BY name ASC") or sqlerr(__FILE__, __LINE__);
				if (mysql_num_rows($offerres) > 0)
				{
					$offer = "<select name=\"offer\"><option value=\"0\">".$lang_upload['select_choose_one']."</option>";
					while($offerrow = mysql_fetch_array($offerres))
						$offer .= "<option value=\"" . $offerrow["id"] . "\">" . htmlspecialchars($offerrow["name"]) . "</option>";
					$offer .= "</select>";
					tr($lang_upload['row_your_offer']. (!$uploadfreely && !$allowspecial ? "<font color=red>*</font>" : ""), $offer.$lang_upload['text_please_select_offer'] , 1);
					$getOfferJs = <<<JS
jQuery('select[name="offer"]').on("change", function () {
    let id = this.value
    if (id == 0) {
        return
    }
    let params = {action: "getOffer", params: {id: id}}
    jQuery.post("ajax.php", params, function (response) {
        console.log(response)
        if (response.ret != 0) {
            alert(response.msg)
            return
        }
        jQuery("#name").val(response.data.name)
        clearContent()
        doInsert(response.data.descr, '', false)
        jQuery("#specialcat").prop('disabled', false).val(0)
        jQuery("#browsecat").prop('disabled', false).val(response.data.category)
    }, 'json')
})
JS;
					\Nexus\Nexus::js($getOfferJs, 'footer', false);

				}
				//===end

                //pick
                $pickcontent = '';
                if(user_can('torrentsticky'))
                {
                    $options = [];
                    foreach (\App\Models\Torrent::listPosStates() as $key => $value) {
                        $options[] = "<option" . (($row["pos_state"] == $key) ? " selected=\"selected\"" : "" ) . " value=\"" . $key . "\">".$value['text']."</option>";
                    }
                    $pickcontent .= "<b>".$lang_edit['row_torrent_position'].":&nbsp;</b>"."<select name=\"pos_state\" style=\"width: 100px;\">" . implode('', $options) . "</select>&nbsp;&nbsp;&nbsp;";
                    $pickcontent .= datetimepicker_input('pos_state_until', '', nexus_trans('label.deadline') . ":&nbsp;", ['require_files' => true]);
                }
                if(user_can('torrentmanage') && ($CURUSER["picker"] == 'yes' || get_user_class() >= \App\Models\User::CLASS_SYSOP))
                {
                    if ($pickcontent) $pickcontent .= '<br />';
                    $pickcontent .= "<b>".$lang_edit['row_recommended_movie'].":&nbsp;</b>"."<select name=\"picktype\" style=\"width: 100px;\">";
                    foreach (\App\Models\Torrent::listPickInfo(true) as $_pick_type => $_pick_type_text) {
                        $pickcontent .= sprintf('<option value="%s">%s</option>', $_pick_type, $_pick_type_text);
                    }
                    $pickcontent .= '</select>';
                }
                if ($pickcontent) {
                    tr($lang_edit['row_pick'], $pickcontent, 1);
                }

				if(user_can('beanonymous'))
				{
					tr($lang_upload['row_show_uploader'], "<input type=\"checkbox\" name=\"uplver\" value=\"yes\" />".$lang_upload['checkbox_hide_uploader_note'], 1);
				}
				?>
				<tr><td class="toolbox" align="center" colspan="2"><b><?php echo $lang_upload['text_read_rules']?></b> <input id="qr" type="submit" class="btn" value="<?php echo $lang_upload['submit_upload']?>" /></td></tr>
		</table>
	</form>
<?php
\Nexus\Nexus::js('vendor/jquery-loading/jquery.loading.min.js', 'footer', true);
\Nexus\Nexus::js('js/ptgen.js', 'footer', true);
$customFieldJs = <<<JS
jQuery("#compose").on("change", "select[name=type]", function () {
    let _this = jQuery(this);
    let mode = _this.attr("data-mode");
    let value = _this.val();
    console.log(mode)
    jQuery("tr[relation]").hide();
    if (value > 0) {
        jQuery("tr[relation=mode_" + mode +"]").show();
    }
})
jQuery("tr[relation]").hide();

// Parse button handler independent from PT-Gen.
function __nexusFillDescription(form, response, replaceDescription) {
	if (!response || response.ret != 0 || !response.data) {
		alert((response && response.msg) ? response.msg : '解析失败')
		return
	}
	let desc = response.data.descr || ''
	if (!desc) {
		alert('未获取到简介内容')
		return
	}
	if (replaceDescription && typeof clearContent === 'function') {
		clearContent()
	}
	doInsert(desc, '', false)
	if (response.data.aka && response.data.site === 'douban') {
		let aka = response.data.aka
		if (response.data.chinese_title) {
			aka.unshift(response.data.chinese_title)
		}
		form.find("input[name=small_descr]").val(aka.join("/"))
	}
	if (response.data.small_descr) {
		form.find("input[name=small_descr]").val(response.data.small_descr)
	}
}

function __nexusRequestDescription(doubanUrl, imdbUrl, onDone) {
	let imdbId = ''
	let match = (imdbUrl || '').match(/(tt\d{5,})/i)
	if (match && match[1]) {
		imdbId = match[1].toLowerCase()
	}

	let sendRequest = function (browserData) {
		let params = {
			action: 'parseExternalDescription',
			params: {
				douban_url: doubanUrl,
				imdb_url: imdbUrl,
				imdb_browser_data: browserData || {}
			}
		}
		jQuery.post('ajax.php', params, function (response) {
			onDone(response)
		}, 'json').fail(function () {
			onDone({ret: 1, msg: '请求失败'})
		})
	}

	if (!imdbId) {
		sendRequest({})
		return
	}

	fetch('https://api.imdbapi.dev/titles/' + imdbId)
		.then(function (resp) {
			if (!resp.ok) {
				throw new Error('imdb browser fetch failed: ' + resp.status)
			}
			return resp.json()
		})
		.then(function (json) {
			sendRequest(json)
		})
		.catch(function () {
			sendRequest({})
		})
}

jQuery("#compose").off("click", ".btn-parse-desc").on("click.nexusParseDesc", ".btn-parse-desc", function () {
	let form = jQuery(this).closest('form')
	let imdbUrl = (form.find("input[name=url]").val() || '').trim()
	let doubanUrl = (form.find("input[name=douban_url]").val() || '').trim()
	if (imdbUrl == '' && doubanUrl == '') {
		alert('请先填写豆瓣链接或IMDb链接')
		return
	}
	jQuery('body').loading({stoppable: false})
	__nexusRequestDescription(doubanUrl, imdbUrl, function (response) {
		jQuery('body').loading('stop')
		__nexusFillDescription(form, response, true)
	})
});
JS;
\Nexus\Nexus::js($customFieldJs, 'footer', false);
?>
<link rel="stylesheet" href="/vendor/sceditor/themes/default.min.css?v=3">
<script src="/vendor/sceditor/sceditor.min.js?v=3"></script>
<script src="/vendor/sceditor/formats/bbcode.js?v=3"></script>
<script src="/vendor/sceditor/icons/monocons.js?v=3"></script>
<script>
/* SCEditor WYSIWYG for the 简介 editor (upload page only). Toolbar 'source' = 纯文本/源码 toggle. */
(function(){
  function boot(){
    var ta=document.getElementById('descr');
    if(!ta||typeof sceditor==='undefined'||ta.getAttribute('data-sce')==='1')return;
    try{
      sceditor.create(ta,{format:'bbcode',icons:'monocons',style:'/vendor/sceditor/themes/content/default.min.css?v=4',width:'100%',height:240,autoExpand:true,resizeWidth:false,emoticonsEnabled:false,toolbar:'bold,italic,underline,strike,subscript,superscript|left,center,right,justify|font,size,color,removeformat|bulletlist,orderedlist,indent,outdent|table|code,quote|horizontalrule,image,link,unlink,youtube|maximize,source'});
      var inst=sceditor.instance(ta);
      if(!inst)return;
      ta.setAttribute('data-sce','1');
      window.__sceDescr=inst;
      function qdEdTheme(){var nb=document.documentElement.getAttribute('data-site-theme')==='night'||(document.body&&document.body.classList.contains('theme-night'));try{if(nb){inst.css('html,body{background:#0b1422 !important;color:#dbe5f3 !important;}a{color:#5fa7ff !important;}');}else{var cs=getComputedStyle(document.documentElement);var s=(cs.getPropertyValue('--bili-surface')||'').trim()||'#ffffff';var t=(cs.getPropertyValue('--bili-text')||'').trim()||'#18191c';inst.css('html,body{background:'+s+' !important;color:'+t+' !important;}');}}catch(e){}}
      qdEdTheme();
      try{var qdEtMO=new MutationObserver(qdEdTheme);qdEtMO.observe(document.documentElement,{attributes:true,attributeFilter:['data-site-theme','class','style']});if(document.body){qdEtMO.observe(document.body,{attributes:true,attributeFilter:['class']});}}catch(e){}
      var TT={bold:'粗体',italic:'斜体',underline:'下划线',strike:'删除线',subscript:'下标',superscript:'上标',left:'左对齐',center:'居中',right:'右对齐',justify:'两端对齐',font:'字体',size:'字号',color:'文字颜色',removeformat:'清除格式',bulletlist:'无序列表',orderedlist:'有序列表',indent:'增加缩进',outdent:'减少缩进',table:'插入表格',code:'代码块',quote:'引用',horizontalrule:'水平分割线',image:'插入图片',link:'插入链接',unlink:'取消链接',email:'邮箱',youtube:'插入视频',emoticon:'表情',date:'日期',time:'时间',print:'打印',maximize:'全屏',source:'源码/纯文本'};setTimeout(function(){var bs=document.querySelectorAll('.sceditor-button');for(var k=0;k<bs.length;k++){var c=bs[k].getAttribute('data-sceditor-command');if(c&&TT[c]){bs[k].setAttribute('title',TT[c]);bs[k].setAttribute('aria-label',TT[c]);}}},0);
      var wrap=ta.closest('.nexus-bbcode-editor');
      if(wrap){var tb=wrap.querySelector('.nexus-bbcode-toolbar');if(tb){tb.style.display='none';}var sm=wrap.querySelector('.nexus-bbcode-smilies');if(sm){sm.style.display='none';}}
      function toTextarea(){try{inst.updateOriginal();}catch(e){try{ta.value=inst.val();}catch(_){}}}
      function toEditor(){try{inst.val(ta.value);}catch(e){}}
      if(typeof window.doInsert==='function'){var _di=window.doInsert;window.doInsert=function(o,c,s){toTextarea();var r=_di(o,c,s);toEditor();return r;};}
      if(typeof window.clearContent==='function'){var _cc=window.clearContent;window.clearContent=function(){_cc();toEditor();};}
      if(typeof window.textBBCodePreview==='function'){var _pv=window.textBBCodePreview;window.textBBCodePreview=function(){toTextarea();return _pv();};}
      var form=document.getElementById('compose');
      if(form&&form.addEventListener){form.addEventListener('submit',toTextarea,true);}
    }catch(e){if(window.console){console.warn('SCEditor init failed',e);}}
  }
  if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',boot);}else{boot();}
})();
</script>
<?php
stdfoot();
