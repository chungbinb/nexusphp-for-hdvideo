<?php
require "../include/bittorrent.php";
dbconn();
loggedinorreturn();

if (get_user_class() < UC_ADMINISTRATOR) {
	permissiondenied();
}

stdhead("FAQ Management");
$isMobileFaqManage = function_exists('mobile_is') && mobile_is();
begin_main_frame();

print("<h1 align=\"center\" class=\"faqmanage-title\">FAQ Management</h1>");

// make the array that has all the faq in a nice structured
$res = sql_query("SELECT faq.id, faq.link_id, faq.lang_id, lang_name, faq.question, faq.flag, faq.order FROM faq LEFT JOIN language on faq.lang_id = language.id WHERE type='categ' ORDER BY lang_name, `order` ASC");
while ($arr = mysql_fetch_array($res, MYSQLI_BOTH)) {
	$faq_categ[$arr['lang_id']][$arr['link_id']]['title'] = $arr['question'];
	$faq_categ[$arr['lang_id']][$arr['link_id']]['flag'] = $arr['flag'];
	$faq_categ[$arr['lang_id']][$arr['link_id']]['order'] = $arr['order'];
	$faq_categ[$arr['lang_id']][$arr['link_id']]['id'] = $arr['id'];
	$faq_categ[$arr['lang_id']][$arr['link_id']]['lang_name'] = $arr['lang_name'];
}

$res = sql_query("SELECT faq.id, faq.question, faq.lang_id, faq.flag, faq.categ, faq.order FROM faq WHERE type='item' ORDER BY `order` ASC");
while ($arr = mysql_fetch_array($res)) {
	$faq_categ[$arr['lang_id']][$arr['categ']]['items'][$arr['id']]['question'] = $arr['question'];
	$faq_categ[$arr['lang_id']][$arr['categ']]['items'][$arr['id']]['flag'] = $arr['flag'];
	$faq_categ[$arr['lang_id']][$arr['categ']]['items'][$arr['id']]['order'] = $arr['order'];
}

if (isset($faq_categ))
{
// gather orphaned items
	foreach ($faq_categ as $lang => $temp2){
		foreach ($temp2 as $id => $temp)
		{
			if (!array_key_exists("title", $temp2[$id]))
			{
				foreach ($temp2[$id]['items'] as $id2 => $temp)
				{
					$faq_orphaned[$lang][$id2]['question'] = $temp2[$id]['items'][$id2]['question'];
					$faq_orphaned[$lang][$id2]['flag'] = $temp2[$id]['items'][$id2]['flag'];
				}
				unset($faq_categ[$lang][$id]);
			}
		}
	}
}

// print the faq table
print("<form method=\"post\" action=\"faqactions.php?action=reorder\" class=\"faqmanage-form\">");
if (isset($faq_categ))
{
	foreach ($faq_categ as $lang => $temp2)
	{
		foreach ($temp2 as $id => $temp)
		{
			if ($isMobileFaqManage) {
				print("<section class=\"faqmanage-section-card\">\n");
				print("<div class=\"faqmanage-section-head\"><div class=\"faqmanage-order\"><span>Section</span><select name=\"order[". $id ."]\">");
				for ($n=1; $n <= count($temp2); $n++)
				{
					$sel = ($n == $temp2[$id]['order']) ? " selected=\"selected\"" : "";
					print("<option value=\"$n\"". $sel .">". $n ."</option>");
				}
				$status = ($temp2[$id]['flag'] == "0") ? "<span class=\"faqmanage-status is-hidden\">Hidden</span>" : "<span class=\"faqmanage-status\">Normal</span>";
				print("</select></div><div class=\"faqmanage-section-title\"><b>". $temp2[$id]['title'] ."</b><span>". $temp2[$id]['lang_name'] ."</span></div></div>\n");
				print("<div class=\"faqmanage-section-tools\"><div>".$status."</div><a href=\"faqactions.php?action=edit&id=". $temp2[$id]['id'] ."\">Edit</a><a class=\"faqmanage-danger\" href=\"faqactions.php?action=delete&id=". $temp2[$id]['id'] ."\">Delete</a></div>\n");
				print("<div class=\"faqmanage-items\">\n");
				if (array_key_exists("items", $temp2[$id]))
				{
					foreach ($temp2[$id]['items'] as $id2 => $temp)
					{
						print("<article class=\"faqmanage-item-card\"><div class=\"faqmanage-item-main\"><div class=\"faqmanage-order\"><span>Item</span><select name=\"order[". $id2 ."]\">");
						for ($n=1; $n <= count($temp2[$id]['items']); $n++)
						{
							$sel = ($n == $temp2[$id]['items'][$id2]['order']) ? " selected=\"selected\"" : "";
							print("<option value=\"$n\"". $sel .">". $n ."</option>");
						}
						if ($temp2[$id]['items'][$id2]['flag'] == "0") $status = "<span class=\"faqmanage-status is-hidden\">Hidden</span>";
						elseif ($temp2[$id]['items'][$id2]['flag'] == "2") $status = "<span class=\"faqmanage-status is-updated\"><img src=\"pic/updated.png\" alt=\"Updated\" width=\"46\" height=\"11\" align=\"absbottom\"></span>";
						elseif ($temp2[$id]['items'][$id2]['flag'] == "3") $status = "<span class=\"faqmanage-status is-new\"><img src=\"pic/new.png\" alt=\"New\" width=\"27\" height=\"11\" align=\"absbottom\"></span>";
						else $status = "<span class=\"faqmanage-status\">Normal</span>";
						print("</select></div><div class=\"faqmanage-item-title\">". $temp2[$id]['items'][$id2]['question'] ."</div></div><div class=\"faqmanage-item-tools\"><div>".$status."</div><a href=\"faqactions.php?action=edit&id=". $id2 ."\">Edit</a><a class=\"faqmanage-danger\" href=\"faqactions.php?action=delete&id=". $id2 ."\">Delete</a></div></article>\n");
					}
				} else {
					print("<div class=\"faqmanage-empty\">No items in this section.</div>\n");
				}
				print("</div><a class=\"faqmanage-add-item\" href=\"faqactions.php?action=additem&inid=". $id ."&langid=".$lang."\">Add new item</a>\n");
				print("</section>\n");
			} else {
			print("<br />\n<table border=\"1\" cellspacing=\"0\" cellpadding=\"5\" align=\"center\" width=\"95%\">\n");
			print("<tr><td class=\"colhead\" align=\"center\" colspan=\"2\">Position</td><td class=\"colhead\" align=\"left\">Section/Item Title</td><td class=\"colhead\" align=\"center\">Language</td><td class=\"colhead\" align=\"center\">Status</td><td class=\"colhead\" align=\"center\">Actions</td></tr>\n");

			print("<tr><td align=\"center\" width=\"40px\"><select name=\"order[". $id ."]\">");
			for ($n=1; $n <= count($temp2); $n++)
			{
   				$sel = ($n == $temp2[$id]['order']) ? " selected=\"selected\"" : "";
   				print("<option value=\"$n\"". $sel .">". $n ."</option>");
			}
			$status = ($temp2[$id]['flag'] == "0") ? "<font color=\"red\">Hidden</font>" : "Normal";
			print("</select></td><td align=\"center\" width=\"40px\">&nbsp;</td><td><b>". $temp2[$id]['title'] ."</b></td><td align=\"center\" width=\"60px\">". $temp2[$id]['lang_name'] ."</td><td align=\"center\" width=\"60px\">". $status ."</td><td align=\"center\" width=\"60px\"><a href=\"faqactions.php?action=edit&id=". $temp2[$id]['id'] ."\">Edit</a> <a href=\"faqactions.php?action=delete&id=". $temp2[$id]['id'] ."\">Delete</a></td></tr>\n");

			if (array_key_exists("items", $temp2[$id]))
			{
				foreach ($temp2[$id]['items'] as $id2 => $temp)
				{
					print("<tr><td align=\"center\" width=\"40px\">&nbsp;</td><td align=\"center\" width=\"40px\"><select name=\"order[". $id2 ."]\">");
					for ($n=1; $n <= count($temp2[$id]['items']); $n++)
					{
						$sel = ($n == $temp2[$id]['items'][$id2]['order']) ? " selected=\"selected\"" : "";
     						print("<option value=\"$n\"". $sel .">". $n ."</option>");
    					}
    					if ($temp2[$id]['items'][$id2]['flag'] == "0") $status = "<font color=\"#FF0000\">Hidden</font>";
    					elseif ($temp2[$id]['items'][$id2]['flag'] == "2") $status = "<font color=\"#0000FF\"><img src=\"pic/updated.png\" alt=\"Updated\" width=\"46\" height=\"11\" align=\"absbottom\"></font>";
					elseif ($temp2[$id]['items'][$id2]['flag'] == "3") $status = "<font color=\"#008000\"><img src=\"pic/new.png\" alt=\"New\" width=\"27\" height=\"11\" align=\"absbottom\"></font>";
					else $status = "Normal";
					print("</select></td><td>". $temp2[$id]['items'][$id2]['question'] ."</td><td align=\"center\"></td><td align=\"center\" width=\"60px\">". $status ."</td><td align=\"center\" width=\"60px\"><a href=\"faqactions.php?action=edit&id=". $id2 ."\">Edit</a> <a href=\"faqactions.php?action=delete&id=". $id2 ."\">Delete</a></td></tr>\n");
				}
			}

			print("<tr><td colspan=\"6\" align=\"center\"><a href=\"faqactions.php?action=additem&inid=". $id ."&langid=".$lang."\">Add new item</a></td></tr>\n");
			print("</table>\n");
			}
		}
	}
}

// print the orphaned items table
if (isset($faq_orphaned)) {
	if ($isMobileFaqManage) {
		print("<section class=\"faqmanage-orphan-card\"><h2>Orphaned Items</h2><div class=\"faqmanage-items\">\n");
		foreach ($faq_orphaned as $lang => $temp2){
			foreach ($temp2 as $id => $temp)
			{
				if ($temp2[$id]['flag'] == "0") $status = "<span class=\"faqmanage-status is-hidden\">Hidden</span>";
				elseif ($temp2[$id]['flag'] == "2") $status = "<span class=\"faqmanage-status is-updated\">Updated</span>";
				elseif ($temp2[$id]['flag'] == "3") $status = "<span class=\"faqmanage-status is-new\">New</span>";
				else $status = "<span class=\"faqmanage-status\">Normal</span>";
				print("<article class=\"faqmanage-item-card\"><div class=\"faqmanage-item-title\">". $temp2[$id]['question'] ."</div><div class=\"faqmanage-item-tools\"><div>".$status."</div><a href=\"faqactions.php?action=edit&id=". $id ."\">edit</a><a class=\"faqmanage-danger\" href=\"faqactions.php?action=delete&id=". $id ."\">delete</a></div></article>\n");
			}
		}
		print("</div></section>\n");
	} else {
	print("<br />\n<table border=\"1\" cellspacing=\"0\" cellpadding=\"5\" align=\"center\" width=\"95%\">\n");
	print("<tr><td align=\"center\" colspan=\"3\"><b style=\"color: #FF0000\">Orphaned Items</b></td>\n");
	print("<tr><td class=\"colhead\" align=\"left\">Item Title</td><td class=\"colhead\" align=\"center\">Status</td><td class=\"colhead\" align=\"center\">Actions</td></tr>\n");
	foreach ($faq_orphaned as $lang => $temp2){
		foreach ($temp2 as $id => $temp)
		{
			if ($temp2[$id]['flag'] == "0") $status = "<font color=\"#FF0000\">Hidden</font>";
			elseif ($temp2[$id]['flag'] == "2") $status = "<font color=\"#0000FF\">Updated</font>";
			elseif ($temp2[$id]['flag'] == "3") $status = "<font color=\"#008000\">New</font>";
			else $status = "Normal";
			print("<tr><td>". $temp2[$id]['question'] ."</td><td align=\"center\" width=\"60px\">". $status ."</td><td align=\"center\" width=\"60px\"><a href=\"faqactions.php?action=edit&id=". $id ."\">edit</a> <a href=\"faqactions.php?action=delete&id=". $id ."\">delete</a></td></tr>\n");
		}
	}
	print("</table>\n");
	}
}

if ($isMobileFaqManage) {
	print("<div class=\"faqmanage-bottom-actions\"><a href=\"faqactions.php?action=addsection\">Add new section</a><input type=\"submit\" name=\"reorder\" value=\"Reorder\"></div>\n");
} else {
	print("<br />\n<table border=\"1\" cellspacing=\"0\" cellpadding=\"5\" align=\"center\" width=\"95%\">\n<tr><td align=\"center\"><a href=\"faqactions.php?action=addsection\">Add new section</a></td></tr>\n</table>\n");
	print("<p align=\"center\"><input type=\"submit\" name=\"reorder\" value=\"Reorder\"></p>\n");
}
print("</form>\n");
print("<p class=\"faqmanage-note\">When the position numbers don't reflect the position in the table, it means the order id is bigger than the total number of sections/items and you should check all the order id's in the table and click \"reorder\"</p>");
echo $pagerbottom ?? '';

end_main_frame();
stdfoot();
?>
