<?php
/**
 * Shared leaderboard helpers for the game hall (游戏大厅总榜) and individual games.
 * Every game logs seedbonus changes to bonus_logs with business_type 13 (游戏/抽奖),
 * so a hall-wide ranking aggregates that table; per-game boards can scope by the
 * comment prefix (e.g. '[刮刮乐]') or use a game's own records table.
 */

const GAME_LB_BUSINESS_TYPE = 13;

function game_lb_run($sql)
{
    $rows = [];
    $res = sql_query($sql);
    if ($res) {
        while ($r = mysql_fetch_assoc($res)) {
            $rows[] = $r;
        }
    }
    return $rows;
}

/**
 * Aggregate bonus_logs into a leaderboard.
 *  $mode: 'profit' = SUM(value) net | 'active' = COUNT(*) | 'win' = SUM(value>0)
 *  $commentLike: optional comment prefix to scope to one game (null = whole hall)
 */
function game_lb_bonus($mode, $commentLike = null, $limit = 10)
{
    $where = '`bl`.`business_type` = ' . GAME_LB_BUSINESS_TYPE . ' AND `bl`.`uid` > 0';
    if ($commentLike !== null) {
        $where .= ' AND `bl`.`comment` LIKE ' . sqlesc($commentLike . '%');
    }
    if ($mode === 'active') {
        $metric = 'COUNT(*)';
    } elseif ($mode === 'win') {
        $metric = 'SUM(CASE WHEN `bl`.`value` > 0 THEN `bl`.`value` ELSE 0 END)';
    } else {
        $metric = 'SUM(`bl`.`value`)';
    }
    $sql = "SELECT `bl`.`uid` AS uid, `u`.`username` AS username, $metric AS amt, COUNT(*) AS cnt
            FROM `bonus_logs` `bl`
            INNER JOIN `users` `u` ON `u`.`id` = `bl`.`uid`
            WHERE $where
            GROUP BY `bl`.`uid`, `u`.`username`
            ORDER BY amt DESC
            LIMIT " . (int)$limit;
    return game_lb_run($sql);
}

function game_lb_money($v)
{
    $v = (float)$v;
    return ($v < 0 ? '-' : '') . number_format(abs(round($v)));
}

function game_lb_user_cell($row)
{
    $uid = (int)($row['uid'] ?? 0);
    $name = htmlspecialchars((string)($row['username'] ?? ('用户#' . $uid)));
    return '<a href="/userdetails.php?id=' . $uid . '">' . $name . '</a>';
}

/**
 * Render one compact leaderboard card.
 *  $valueFn($row) => display string for the value column.
 *  $valueClassFn($row) => optional css class (glb-pos / glb-neg) for the value cell.
 */
function game_lb_table($title, $rows, $valueLabel, $valueFn, $valueClassFn = null)
{
    ob_start(); ?>
    <div class="glb-card">
        <div class="glb-card-title"><?php echo $title ?></div>
        <table class="glb-table">
            <tr><th>#</th><th>用户</th><th><?php echo htmlspecialchars($valueLabel) ?></th></tr>
            <?php if (!$rows) { ?>
                <tr><td colspan="3" class="glb-empty">暂无数据</td></tr>
            <?php } else { $i = 0; foreach ($rows as $r) { $i++;
                $cls = $valueClassFn ? $valueClassFn($r) : '';
            ?>
                <tr>
                    <td class="glb-rank glb-rank-<?php echo $i ?>"><?php echo $i ?></td>
                    <td><?php echo game_lb_user_cell($r) ?></td>
                    <td class="<?php echo $cls ?>"><?php echo $valueFn($r) ?></td>
                </tr>
            <?php } } ?>
        </table>
    </div>
    <?php return ob_get_clean();
}

function game_lb_css()
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return '<style>
    .glb-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
    @media(max-width:760px){.glb-grid{grid-template-columns:1fr}}
    .glb-card{border:1px solid rgba(120,150,190,.3);border-radius:8px;overflow:hidden}
    .glb-card-title{padding:10px 12px;font-weight:800;background:rgba(120,150,190,.16)}
    .glb-table{width:100%;border-collapse:collapse}
    .glb-table th,.glb-table td{padding:7px 10px;text-align:left;border-top:1px solid rgba(120,150,190,.16);font-size:13px}
    .glb-table th{color:#8aa0b6;font-weight:700}
    .glb-table th:first-child,.glb-table td:first-child{width:34px;text-align:center}
    .glb-table th:last-child,.glb-table td:last-child{text-align:right;white-space:nowrap}
    .glb-rank{font-weight:800;color:#8aa0b6}
    .glb-rank-1{color:#e9b949}.glb-rank-2{color:#9fb0c2}.glb-rank-3{color:#c08457}
    .glb-pos{color:#16a34a;font-weight:700}.glb-neg{color:#dc2626;font-weight:700}
    .glb-empty{text-align:center;color:#8aa0b6;padding:16px}
    </style>';
}
