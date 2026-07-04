<?php
require "../include/bittorrent.php";
dbconn();
loggedinorreturn();
if (get_user_class() < UC_MODERATOR)
	stderr("Error", "Permission denied.");

stdhead("Stats");
?>

<STYLE TYPE="text/css" MEDIA=screen>
  a.colheadlink:link, a.colheadlink:visited{
	font-weight: bold;
	color: #FFFFFF;
	text-decoration: none;
	}

	a.colheadlink:hover {
  	text-decoration: underline;
	}
</STYLE>

<?php
begin_main_frame();
print("<div class=\"stats-page\">\n");

$res = sql_query("SELECT COUNT(*) FROM torrents") or sqlerr(__FILE__, __LINE__);
$n = mysql_fetch_row($res);
$n_tor = $n[0];

$res = sql_query("SELECT COUNT(*) FROM peers") or sqlerr(__FILE__, __LINE__);
$n = mysql_fetch_row($res);
$n_peers = $n[0];

$uporder = $_GET['uporder'] ?? '';
$catorder = $_GET["catorder"] ?? '';
if (!in_array($uporder, array('uploader', 'lastul', 'torrents', 'peers'), true))
	$uporder = '';
if (!in_array($catorder, array('category', 'lastul', 'torrents', 'peers'), true))
	$catorder = '';

print("<h1 class=\"stats-title\">Stats</h1>\n");
print("<div class=\"stats-summary\"><div><span>Total Torrents</span><b>".number_format($n_tor)."</b></div><div><span>Total Peers</span><b>".number_format($n_peers)."</b></div></div>\n");

if ($uporder == "lastul")
	$orderby = "last DESC, name";
elseif ($uporder == "torrents")
	$orderby = "n_t DESC, name";
elseif ($uporder == "peers")
	$orderby = "n_p DESC, name";
else
	$orderby = "name";

$query = "SELECT u.id, u.username AS name, MAX(t.added) AS last, COUNT(DISTINCT t.id) AS n_t, COUNT(p.id) as n_p
	FROM users as u LEFT JOIN torrents as t ON u.id = t.owner LEFT JOIN peers as p ON t.id = p.torrent WHERE u.class = 3
	GROUP BY u.id UNION SELECT u.id, u.username AS name, MAX(t.added) AS last, COUNT(DISTINCT t.id) AS n_t, COUNT(p.id) as n_p
	FROM users as u LEFT JOIN torrents as t ON u.id = t.owner LEFT JOIN peers as p ON t.id = p.torrent WHERE u.class > 3
	GROUP BY u.id ORDER BY $orderby";

$res = sql_query($query) or sqlerr(__FILE__, __LINE__);

if (mysql_num_rows($res) == 0)
	stdmsg("Sorry...", "No uploaders.");
else
{
	print("<div class=\"stats-section stats-uploaders\">\n");
	begin_frame("Uploader Activity", True);
	begin_table();
	print("<tr class=\"stats-head-row\">\n
	<td class=colhead><a href=\"" . $_SERVER['PHP_SELF'] . "?uporder=uploader&catorder=$catorder\" class=colheadlink>Uploader</a></td>\n
	<td class=colhead><a href=\"" . $_SERVER['PHP_SELF'] . "?uporder=lastul&catorder=$catorder\" class=colheadlink>Last Upload</a></td>\n
	<td class=colhead><a href=\"" . $_SERVER['PHP_SELF'] . "?uporder=torrents&catorder=$catorder\" class=colheadlink>Torrents</a></td>\n
	<td class=\"colhead stats-static-head\">Perc.</td>\n
	<td class=colhead><a href=\"" . $_SERVER['PHP_SELF'] . "?uporder=peers&catorder=$catorder\" class=colheadlink>Peers</a></td>\n
	<td class=\"colhead stats-static-head\">Perc.</td>\n
	</tr>\n");
	while ($uper = mysql_fetch_array($res))
	{
		print("<tr class=\"stats-data-row\"><td class=\"stats-user\" data-label=\"Uploader\">" . get_username($uper['id']) . "</td>\n");
		print("<td class=\"stats-last\" data-label=\"Last Upload\" " . ($uper['last']?(">".$uper['last']." (".get_elapsed_time(strtotime($uper['last']))." ago)"):"align=\"center\">---") . "</td>\n");
		print("<td data-label=\"Torrents\" align=\"right\">" . $uper['n_t'] . "</td>\n");
		print("<td data-label=\"Torrent %\" align=\"right\">" . ($n_tor > 0?number_format(100 * $uper['n_t']/$n_tor,1)."%":"---") . "</td>\n");
		print("<td data-label=\"Peers\" align=\"right\">" . $uper['n_p']."</td>\n");
		print("<td data-label=\"Peer %\" align=\"right\">" . ($n_peers > 0?number_format(100 * $uper['n_p']/$n_peers,1)."%":"---") . "</td></tr>\n");
	}
	end_table();
	end_frame();
	print("</div>\n");
}

if ($n_tor == 0)
	stdmsg("Sorry...", "No categories defined!");
else
{
  if ($catorder == "lastul")
		$orderby = "last DESC, c.name";
	elseif ($catorder == "torrents")
		$orderby = "n_t DESC, c.name";
	elseif ($catorder == "peers")
		$orderby = "n_p DESC, name";
	else
		$orderby = "c.name";

  $res = sql_query("SELECT c.name, MAX(t.added) AS last, COUNT(DISTINCT t.id) AS n_t, COUNT(p.id) AS n_p
	FROM categories as c LEFT JOIN torrents as t ON t.category = c.id LEFT JOIN peers as p
	ON t.id = p.torrent GROUP BY c.id ORDER BY $orderby") or sqlerr(__FILE__, __LINE__);

	print("<div class=\"stats-section stats-categories\">\n");
	begin_frame("Category Activity", True);
	begin_table();
	print("<tr class=\"stats-head-row\"><td class=colhead><a href=\"" . $_SERVER['PHP_SELF'] . "?uporder=$uporder&catorder=category\" class=colheadlink>Category</a></td>
	<td class=colhead><a href=\"" . $_SERVER['PHP_SELF'] . "?uporder=$uporder&catorder=lastul\" class=colheadlink>Last Upload</a></td>
	<td class=colhead><a href=\"" . $_SERVER['PHP_SELF'] . "?uporder=$uporder&catorder=torrents\" class=colheadlink>Torrents</a></td>
	<td class=\"colhead stats-static-head\">Perc.</td>
	<td class=colhead><a href=\"" . $_SERVER['PHP_SELF'] . "?uporder=$uporder&catorder=peers\" class=colheadlink>Peers</a></td>
	<td class=\"colhead stats-static-head\">Perc.</td></tr>\n");
	while ($cat = mysql_fetch_array($res))
	{
		print("<tr class=\"stats-data-row\"><td class=\"rowhead stats-name\" data-label=\"Category\">" . htmlspecialchars($cat['name']) . "</td>");
		print("<td class=\"stats-last\" data-label=\"Last Upload\" " . ($cat['last']?(">".$cat['last']." (".get_elapsed_time(strtotime($cat['last']))." ago)"):"align=\"center\">---") ."</td>");
		print("<td data-label=\"Torrents\" align=\"right\">" . $cat['n_t'] . "</td>");
		print("<td data-label=\"Torrent %\" align=\"right\">" . number_format(100 * $cat['n_t']/$n_tor,1) . "%</td>");
		print("<td data-label=\"Peers\" align=\"right\">" . $cat['n_p'] . "</td>");
		print("<td data-label=\"Peer %\" align=\"right\">" . ($n_peers > 0?number_format(100 * $cat['n_p']/$n_peers,1)."%":"---") . "</td></tr>\n");
	}
	end_table();
	end_frame();
	print("</div>\n");
}

print("</div>\n");
end_main_frame();
stdfoot();
die;
?>
