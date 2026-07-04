<?php
/* $Id: mysql_stats.php,v 1.0 2005/06/20 22:52:24 CoLdFuSiOn Exp $ */
// vim: expandtab sw=4 ts=4 sts=4:


require "../include/bittorrent.php";
dbconn();
loggedinorreturn();
/**
 * Checks if the user is allowed to do what he tries to...
 */
if (get_user_class() < UC_SYSOP)
	stderr("Error", "Permission denied.");

$GLOBALS["byteUnits"] = array('Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB');

$day_of_week = array('Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat');
$month = array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec');
// See http://www.php.net/manual/en/function.strftime.php to define the
// variable below
$datefmt = '%B %d, %Y at %I:%M %p';
$timespanfmt = '%s days, %s hours, %s minutes and %s seconds';
////////////////// FUNCTION LIST /////////////////////////
    /**
     * Formats $value to byte view
     *
     * @param    double   the value to format
     * @param    integer  the sensitiveness
     * @param    integer  the number of decimals to retain
     *
     * @return   array    the formatted value and its unit
     *
     * @access  public
     *
     * @author   staybyte
     * @version  1.0 - 20 July 2005
     */
    function formatByteDown($value, $limes = 6, $comma = 0)
    {
        $dh           = pow(10, $comma);
        $li           = pow(10, $limes);
        $return_value = $value;
        $unit         = $GLOBALS['byteUnits'][0];

        for ( $d = 6, $ex = 15; $d >= 1; $d--, $ex-=3 ) {
            if (isset($GLOBALS['byteUnits'][$d]) && $value >= $li * pow(10, $ex)) {
                $value = round($value / ( pow(1024, $d) / $dh) ) /$dh;
                $unit = $GLOBALS['byteUnits'][$d];
                break 1;
            } // end if
        } // end for

        if ($unit != $GLOBALS['byteUnits'][0]) {
            $return_value = number_format($value, $comma, '.', ',');
        } else {
            $return_value = number_format($value, 0, '.', ',');
        }

        return array($return_value, $unit);
    } // end of the 'formatByteDown' function

    /**
     * Returns a given timespan value in a readable format.
     *
     * @param  int     the timespan
     *
     * @return string  the formatted value
     */
    function timespanFormat($seconds)
    {
        $return_string = '';
        $days = floor($seconds / 86400);
        if ($days > 0) {
            $seconds -= $days * 86400;
        }
        $hours = floor($seconds / 3600);
        if ($days > 0 || $hours > 0) {
            $seconds -= $hours * 3600;
        }
        $minutes = floor($seconds / 60);
        if ($days > 0 || $hours > 0 || $minutes > 0) {
            $seconds -= $minutes * 60;
        }
        return (string)$days." Days ". (string)$hours." Hours ". (string)$minutes." Minutes ". (string)$seconds." Seconds ";
    }


   /**
     * Writes localised date
     *
     * @param   string   the current timestamp
     *
     * @return  string   the formatted date
     *
     * @access  public
     */
    function localisedDate($timestamp = -1, $format = '')
    {
        global $datefmt, $month, $day_of_week;

        if ($format == '') {
            $format = $datefmt;
        }

        if ($timestamp == -1) {
            $timestamp = time();
        }

        $date = preg_replace('@%[aA]@', $day_of_week[(int)strftime('%w', $timestamp)], $format);
        $date = preg_replace('@%[bB]@', $month[(int)strftime('%m', $timestamp)-1], $date);

        return strftime($date, $timestamp);
    } // end of the 'localisedDate()' function
    
////////////////////// END FUNCTION LIST /////////////////////////////////////


stdhead("Stats");

/**
 * Displays the sub-page heading
 */
echo '<h1 align=center>' . "\n"
   . '    Mysql Server Status'  . "\n"
   . '</h1>' . "\n";





/**
 * Sends the query and buffers the result
 */
$res = @sql_query('SHOW STATUS') or Die(mysql_error());
	while ($row = mysql_fetch_row($res)) {
		$serverStatus[$row[0]] = $row[1];
	}
@mysql_free_result($res);
unset($res);
unset($row);


/**
 * Displays the page
 */
//Uptime calculation
$res = @sql_query('SELECT UNIX_TIMESTAMP() - ' . $serverStatus['Uptime']);
$row = mysql_fetch_row($res);
$uptime = max(1, (int)($serverStatus['Uptime'] ?? 1));
$uptimeText = timespanFormat((int)($serverStatus['Uptime'] ?? 0));
$startedAt = localisedDate((int)$row[0]);
$isMobileMysqlStats = function_exists('mobile_is') && mobile_is();
mysql_free_result($res);
unset($res);
unset($row);

//Get query statistics
$queryStats = array();
$tmp_array = $serverStatus;
	foreach($tmp_array AS $name => $value) {
		if (substr($name, 0, 4) == 'Com_') {
			$queryStats[str_replace('_', ' ', substr($name, 4))] = $value;
			unset($serverStatus[$name]);
		}
	}
unset($tmp_array);

$byteText = function ($value) {
    return join(' ', formatByteDown((float)$value));
};
$perHourText = function ($value) use ($uptime) {
    return number_format(((float)$value * 3600 / $uptime), 2, '.', ',');
};
$percentText = function ($value, $total) {
    return ((float)$total > 0) ? number_format(((float)$value * 100 / (float)$total), 2, '.', ',') . ' %' : '---';
};

$bytesReceived = (float)($serverStatus['Bytes_received'] ?? 0);
$bytesSent = (float)($serverStatus['Bytes_sent'] ?? 0);
$connections = (float)($serverStatus['Connections'] ?? 0);
$questions = (float)($serverStatus['Questions'] ?? 0);
$queryDenominator = max(1, $questions - $connections);

$trafficRows = array(
    array('Received', $byteText($bytesReceived), $byteText($bytesReceived * 3600 / $uptime)),
    array('Sent', $byteText($bytesSent), $byteText($bytesSent * 3600 / $uptime)),
    array('Total', $byteText($bytesReceived + $bytesSent), $byteText(($bytesReceived + $bytesSent) * 3600 / $uptime)),
);
$connectionRows = array(
    array('Failed Attempts', number_format((float)($serverStatus['Aborted_connects'] ?? 0), 0, '.', ','), $perHourText($serverStatus['Aborted_connects'] ?? 0), $percentText($serverStatus['Aborted_connects'] ?? 0, $connections)),
    array('Aborted Clients', number_format((float)($serverStatus['Aborted_clients'] ?? 0), 0, '.', ','), $perHourText($serverStatus['Aborted_clients'] ?? 0), $percentText($serverStatus['Aborted_clients'] ?? 0, $connections)),
    array('Total', number_format($connections, 0, '.', ','), $perHourText($connections), number_format(100, 2, '.', ',') . ' %'),
);
$queryOverview = array(
    array('Total', number_format($questions, 0, '.', ',')),
    array('Per Hour', $perHourText($questions)),
    array('Per Minute', number_format(($questions * 60 / $uptime), 2, '.', ',')),
    array('Per Second', number_format(($questions / $uptime), 2, '.', ',')),
);
$queryRows = array();
foreach ($queryStats as $name => $value) {
    $queryRows[] = array(
        htmlspecialchars($name),
        number_format((float)$value, 0, '.', ','),
        $perHourText($value),
        $percentText($value, $queryDenominator),
    );
}

$moreStatus = $serverStatus;
unset($moreStatus['Aborted_clients']);
unset($moreStatus['Aborted_connects']);
unset($moreStatus['Bytes_received']);
unset($moreStatus['Bytes_sent']);
unset($moreStatus['Connections']);
unset($moreStatus['Questions']);
unset($moreStatus['Uptime']);

if ($isMobileMysqlStats) {
?>
<section class="mysql-stats-mobile">
    <div class="mysql-stats-hero">
        <span>Server Uptime</span>
        <strong><?php echo htmlspecialchars($uptimeText); ?></strong>
        <p>Started up on <?php echo htmlspecialchars($startedAt); ?></p>
    </div>

    <section class="mysql-stats-section">
        <h2>Server Traffic</h2>
        <p>Network traffic statistics since this MySQL server started.</p>
        <div class="mysql-stats-card">
            <h3>Traffic</h3>
            <?php foreach ($trafficRows as $trafficRow) { ?>
                <div class="mysql-stats-row">
                    <span><?php echo $trafficRow[0]; ?></span>
                    <b><?php echo $trafficRow[1]; ?></b>
                    <em><?php echo $trafficRow[2]; ?>/h</em>
                </div>
            <?php } ?>
        </div>
        <div class="mysql-stats-card">
            <h3>Connections</h3>
            <?php foreach ($connectionRows as $connectionRow) { ?>
                <div class="mysql-stats-row mysql-stats-row-four">
                    <span><?php echo $connectionRow[0]; ?></span>
                    <b><?php echo $connectionRow[1]; ?></b>
                    <em><?php echo $connectionRow[2]; ?>/h</em>
                    <i><?php echo $connectionRow[3]; ?></i>
                </div>
            <?php } ?>
        </div>
    </section>

    <section class="mysql-stats-section">
        <h2>Query Statistics</h2>
        <p>Since startup, <?php echo number_format($questions, 0, '.', ','); ?> queries have been sent to the server.</p>
        <div class="mysql-stats-overview">
            <?php foreach ($queryOverview as $overviewRow) { ?>
                <div><span><?php echo $overviewRow[0]; ?></span><b><?php echo $overviewRow[1]; ?></b></div>
            <?php } ?>
        </div>
        <div class="mysql-stats-query-list">
            <?php foreach ($queryRows as $queryRow) { ?>
                <div class="mysql-stats-query-item">
                    <b><?php echo $queryRow[0]; ?></b>
                    <span><?php echo $queryRow[1]; ?></span>
                    <em><?php echo $queryRow[2]; ?>/h</em>
                    <i><?php echo $queryRow[3]; ?></i>
                </div>
            <?php } ?>
        </div>
    </section>

    <?php if (!empty($moreStatus)) { ?>
        <details class="mysql-stats-more">
            <summary>More status variables</summary>
            <div class="mysql-stats-var-list">
                <?php foreach($moreStatus AS $name => $value) { ?>
                    <div><span><?php echo htmlspecialchars(str_replace('_', ' ', $name)); ?></span><b><?php echo htmlspecialchars($value); ?></b></div>
                <?php } ?>
            </div>
        </details>
    <?php } ?>
</section>
<?php
} else {
?>
<table id="torrenttable" border="1"><tr><td>
<?php print("This MySQL server has been running for ". $uptimeText .". It started up on ". $startedAt) . "\n"; ?>
</td></tr></table>

<ul>
    <li>
        <b>Server traffic:</b> These tables show the network traffic statistics of this MySQL server since its startup
        <br />
        <table border="0">
            <tr>
                <td valign="top">
                    <table id="torrenttable" border="0">
                        <tr>
                            <th colspan="2" bgcolor="lightgrey">&nbsp;Traffic&nbsp;</th>
                            <th bgcolor="lightgrey">&nbsp;&nbsp;Per Hour&nbsp;</th>
                        </tr>
                        <?php foreach ($trafficRows as $trafficRow) { ?>
                            <tr>
                                <td bgcolor="#EFF3FF">&nbsp;<?php echo $trafficRow[0]; ?>&nbsp;</td>
                                <td bgcolor="#EFF3FF" align="right">&nbsp;<?php echo $trafficRow[1]; ?>&nbsp;</td>
                                <td bgcolor="#EFF3FF" align="right">&nbsp;<?php echo $trafficRow[2]; ?>&nbsp;</td>
                            </tr>
                        <?php } ?>
                    </table>
                </td>
                <td valign="top">
                    <table id="torrenttable" border="0">
                        <tr>
                            <th colspan="2" bgcolor="lightgrey">&nbsp;Connections&nbsp;</th>
                            <th bgcolor="lightgrey">&nbsp;&oslash;&nbsp;Per Hour&nbsp;</th>
                            <th bgcolor="lightgrey">&nbsp;%&nbsp;</th>
                        </tr>
                        <?php foreach ($connectionRows as $connectionRow) { ?>
                            <tr>
                                <td bgcolor="#EFF3FF">&nbsp;<?php echo $connectionRow[0]; ?>&nbsp;</td>
                                <td bgcolor="#EFF3FF" align="right">&nbsp;<?php echo $connectionRow[1]; ?>&nbsp;</td>
                                <td bgcolor="#EFF3FF" align="right">&nbsp;<?php echo $connectionRow[2]; ?>&nbsp;</td>
                                <td bgcolor="#EFF3FF" align="right">&nbsp;<?php echo $connectionRow[3]; ?>&nbsp;</td>
                            </tr>
                        <?php } ?>
                    </table>
                </td>
            </tr>
        </table>
    </li>
    <br />
    <li>
        <?php print("<b>Query Statistics:</b> Since it's start up, ". number_format($questions, 0, '.', ',')." queries have been sent to the server.\n"); ?>
        <table border="0">
            <tr>
                <td colspan="2">
                    <br />
                    <table id="torrenttable" border="0" align="right">
                        <tr>
                            <?php foreach ($queryOverview as $overviewRow) { ?>
                                <th bgcolor="lightgrey">&nbsp;<?php echo $overviewRow[0]; ?>&nbsp;</th>
                            <?php } ?>
                        </tr>
                        <tr>
                            <?php foreach ($queryOverview as $overviewRow) { ?>
                                <td bgcolor="#EFF3FF" align="right">&nbsp;<?php echo $overviewRow[1]; ?>&nbsp;</td>
                            <?php } ?>
                        </tr>
                    </table>
                </td>
            </tr>
            <tr>
                <?php
                $queryColumns = array_chunk($queryRows, max(1, (int)ceil(count($queryRows) / 2)));
                foreach ($queryColumns as $queryColumn) {
                ?>
                <td valign="top">
                    <table id="torrenttable" border="0">
                        <tr>
                            <th colspan="2" bgcolor="lightgrey">&nbsp;Query&nbsp;Type&nbsp;</th>
                            <th bgcolor="lightgrey">&nbsp;&oslash;&nbsp;Per&nbsp;Hour&nbsp;</th>
                            <th bgcolor="lightgrey">&nbsp;%&nbsp;</th>
                        </tr>
                        <?php foreach ($queryColumn as $queryRow) { ?>
                            <tr>
                                <td bgcolor="#EFF3FF">&nbsp;<?php echo $queryRow[0]; ?>&nbsp;</td>
                                <td bgcolor="#EFF3FF" align="right">&nbsp;<?php echo $queryRow[1]; ?>&nbsp;</td>
                                <td bgcolor="#EFF3FF" align="right">&nbsp;<?php echo $queryRow[2]; ?>&nbsp;</td>
                                <td bgcolor="#EFF3FF" align="right">&nbsp;<?php echo $queryRow[3]; ?>&nbsp;</td>
                            </tr>
                        <?php } ?>
                    </table>
                </td>
                <?php } ?>
            </tr>
        </table>
    </li>
    <?php if (!empty($moreStatus)) { ?>
    <br />
    <li>
        <b>More status variables</b><br />
        <table border="0">
            <tr>
                <?php
                $statusColumns = array_chunk($moreStatus, max(1, (int)ceil(count($moreStatus) / 3)), true);
                foreach($statusColumns as $statusColumn) {
                ?>
                <td valign="top">
                    <table id="torrenttable" border="0">
                        <tr>
                            <th bgcolor="lightgrey">&nbsp;Variable&nbsp;</th>
                            <th bgcolor="lightgrey">&nbsp;Value&nbsp;</th>
                        </tr>
                        <?php foreach($statusColumn AS $name => $value) { ?>
                        <tr>
                            <td bgcolor="#EFF3FF">&nbsp;<?php echo htmlspecialchars(str_replace('_', ' ', $name)); ?>&nbsp;</td>
                            <td bgcolor="#EFF3FF" align="right">&nbsp;<?php echo htmlspecialchars($value); ?>&nbsp;</td>
                        </tr>
                        <?php } ?>
                    </table>
                </td>
                <?php } ?>
            </tr>
        </table>
    </li>
    <?php } ?>
</ul>
<?php
}
?>
<?php
stdfoot();
