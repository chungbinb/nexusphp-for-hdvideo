<?php
/**
 * Shared leaderboard helpers for the game hall (游戏大厅总榜) and individual games.
 * Every game logs seedbonus changes to bonus_logs with business_type 13 (游戏/抽奖),
 * so a hall-wide ranking aggregates that table; per-game boards can scope by the
 * comment prefix (e.g. '[刮刮乐]') or use a game's own records table.
 */

// 历史记录都记在 13（幸运大转盘），新记录每个游戏用各自的业务类型（101-111）。
// 总榜与按 comment 过滤的单游戏榜都需要同时统计旧的 13 和新的各游戏类型。
const GAME_LB_BUSINESS_TYPE = 13;
const GAME_LB_BUSINESS_TYPE_SET = '13,101,102,103,104,105,106,107,108,109,110,111';

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
function game_lb_bonus($mode, $commentLike = null, $limit = 10, $order = 'DESC')
{
    $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
    $where = '`bl`.`business_type` IN (' . GAME_LB_BUSINESS_TYPE_SET . ') AND `bl`.`uid` > 0';
    if ($commentLike !== null) {
        $where .= ' AND `bl`.`comment` LIKE ' . sqlesc($commentLike . '%');
    }
    // The `value` column's sign is logged inconsistently across games (some log
    // bet deductions as a positive amount). The true signed delta is always the
    // real balance change new_total_value - old_total_value, so use that.
    $delta = '(`bl`.`new_total_value` - `bl`.`old_total_value`)';
    if ($mode === 'active') {
        $metric = 'COUNT(*)';
    } elseif ($mode === 'wincount') {
        $metric = "SUM(CASE WHEN $delta > 0 THEN 1 ELSE 0 END)";
    } elseif ($mode === 'win') {
        $metric = "SUM(CASE WHEN $delta > 0 THEN $delta ELSE 0 END)";
    } else {
        $metric = "SUM($delta)";
    }
    $sql = "SELECT `bl`.`uid` AS uid, `u`.`username` AS username, $metric AS amt, COUNT(*) AS cnt
            FROM `bonus_logs` `bl`
            INNER JOIN `users` `u` ON `u`.`id` = `bl`.`uid`
            WHERE $where
            GROUP BY `bl`.`uid`, `u`.`username`
            ORDER BY amt $order
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
function game_lb_table_body($rows, $valueLabel, $valueFn, $valueClassFn, $dir, $hidden)
{
    ob_start(); ?>
    <table class="glb-table glb-body" data-dir="<?php echo $dir ?>"<?php echo $hidden ? ' style="display:none"' : '' ?>>
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
    <?php return ob_get_clean();
}

/**
 * Render one compact leaderboard card.
 *  $valueFn($row) => display string for the value column.
 *  $valueClassFn($row) => optional css class (glb-pos / glb-neg) for the value cell.
 *  $reverseRows => optional second dataset (e.g. ascending / biggest losers). When given,
 *                  a 高→低 / 低→高 toggle is shown and both tables are rendered (one hidden).
 */
function game_lb_table($title, $rows, $valueLabel, $valueFn, $valueClassFn = null, $reverseRows = null)
{
    $hasToggle = is_array($reverseRows);
    ob_start(); ?>
    <div class="glb-card">
        <div class="glb-card-title">
            <span><?php echo $title ?></span>
            <?php if ($hasToggle) { ?>
                <button type="button" class="glb-dir" data-dir="desc" title="切换升序/降序">↓</button>
            <?php } ?>
        </div>
        <?php
        echo game_lb_table_body($rows, $valueLabel, $valueFn, $valueClassFn, 'desc', false);
        if ($hasToggle) {
            echo game_lb_table_body($reverseRows, $valueLabel, $valueFn, $valueClassFn, 'asc', true);
        }
        ?>
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
    .glb-card-title{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;font-weight:800;background:rgba(120,150,190,.16)}
    .glb-dir{appearance:none;-webkit-appearance:none;font-size:16px;font-weight:800;border:0!important;background:transparent!important;box-shadow:none!important;border-radius:0!important;color:#8aa0b6;cursor:pointer;line-height:1;flex:none;padding:0}
    .glb-dir:hover{color:#2980b9;background:transparent!important}
    .glb-table{width:100%;border-collapse:collapse}
    .glb-table th,.glb-table td{padding:7px 10px;text-align:left;border-top:1px solid rgba(120,150,190,.16);font-size:13px}
    .glb-table th{color:#8aa0b6;font-weight:700}
    .glb-table th:first-child,.glb-table td:first-child{width:34px;text-align:center}
    .glb-table th:last-child,.glb-table td:last-child{text-align:right;white-space:nowrap}
    .glb-rank{font-weight:800;color:#8aa0b6}
    .glb-rank-1{color:#e9b949}.glb-rank-2{color:#9fb0c2}.glb-rank-3{color:#c08457}
    .glb-pos{color:#16a34a;font-weight:700}.glb-neg{color:#dc2626;font-weight:700}
    .glb-empty{text-align:center;color:#8aa0b6;padding:16px}
    </style>
    <script>
    document.addEventListener("click", function (e) {
        var btn = e.target.closest ? e.target.closest(".glb-dir") : null;
        if (!btn) return;
        var card = btn.closest(".glb-card"); if (!card) return;
        var dir = btn.getAttribute("data-dir") === "desc" ? "asc" : "desc";
        btn.setAttribute("data-dir", dir);
        btn.textContent = dir === "desc" ? "↓" : "↑";
        card.querySelectorAll(".glb-body").forEach(function (t) { t.style.display = t.getAttribute("data-dir") === dir ? "" : "none"; });
    });
    </script>';
}
