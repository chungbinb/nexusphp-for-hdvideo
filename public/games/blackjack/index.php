<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;
require_once "../../../include/game_control.php";
game_guard('blackjack');
require_once "../../../include/game_leaderboard.php";

/**
 * 二十一点 (Blackjack) — single player vs dealer, bet 电影票.
 * Server-authoritative: the deck/hands live in a DB row per user; the client only
 * issues actions (deal/hit/stand/double). Dealer stands on 17, blackjack pays 3:2.
 */
const BJ_BUSINESS_TYPE = 107; // 二十一点（历史记录为 13）
const BJ_GAME_TABLE = 'hdvideo_blackjack_games';
const BJ_RESULT_TABLE = 'hdvideo_blackjack_results';
const BJ_CHIPS = [100, 500, 1000, 5000, 10000];

function bj_money($v)
{
    return number_format((float)$v, 1, '.', '');
}

function bj_ensure_tables()
{
    static $done = false;
    if ($done) return;
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . BJ_GAME_TABLE . "` (
            `uid` int unsigned NOT NULL,
            `bet` bigint NOT NULL DEFAULT 0,
            `deck` text,
            `player` text,
            `dealer` text,
            `status` varchar(10) NOT NULL DEFAULT 'done',
            `outcome` varchar(16) NOT NULL DEFAULT '',
            `doubled` tinyint(1) NOT NULL DEFAULT 0,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . BJ_RESULT_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `uid` int unsigned NOT NULL,
            `bet` bigint NOT NULL DEFAULT 0,
            `delta` decimal(20,1) NOT NULL DEFAULT '0.0',
            `outcome` varchar(16) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_uid` (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

// --- card engine (cards are ints 0..51; rank=c%13 [0=A..12=K], suit=c/13) ---
function bj_card_value($c)
{
    $r = $c % 13;
    if ($r === 0) return 11;       // Ace (soft)
    if ($r >= 9) return 10;        // 10/J/Q/K
    return $r + 1;                 // 2..9
}
function bj_hand_value($cards)
{
    $sum = 0; $aces = 0;
    foreach ($cards as $c) {
        $sum += bj_card_value($c);
        if (($c % 13) === 0) $aces++;
    }
    while ($sum > 21 && $aces > 0) { $sum -= 10; $aces--; }
    return $sum;
}
function bj_is_blackjack($cards)
{
    return count($cards) === 2 && bj_hand_value($cards) === 21;
}
function bj_rank_label($c)
{
    $m = ['A', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K'];
    return $m[$c % 13];
}
function bj_suit_symbol($c)
{
    return ['♠', '♥', '♦', '♣'][intdiv($c, 13)];
}
function bj_card_json($c)
{
    $s = intdiv($c, 13);
    return ['r' => bj_rank_label($c), 's' => bj_suit_symbol($c), 'red' => ($s === 1 || $s === 2)];
}

function bj_load_game($uid)
{
    $res = sql_query("SELECT * FROM `" . BJ_GAME_TABLE . "` WHERE `uid` = " . (int)$uid . " FOR UPDATE");
    $row = $res ? mysql_fetch_assoc($res) : null;
    if (!$row) return null;
    return [
        'bet' => (int)$row['bet'],
        'deck' => json_decode($row['deck'] ?? '[]', true) ?: [],
        'player' => json_decode($row['player'] ?? '[]', true) ?: [],
        'dealer' => json_decode($row['dealer'] ?? '[]', true) ?: [],
        'status' => $row['status'],
        'outcome' => $row['outcome'],
        'doubled' => (int)$row['doubled'],
    ];
}

function bj_save_game($uid, $g)
{
    $now = date('Y-m-d H:i:s');
    sql_query(sprintf(
        "INSERT INTO `" . BJ_GAME_TABLE . "` (`uid`,`bet`,`deck`,`player`,`dealer`,`status`,`outcome`,`doubled`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%d,%s)
         ON DUPLICATE KEY UPDATE `bet`=VALUES(`bet`),`deck`=VALUES(`deck`),`player`=VALUES(`player`),`dealer`=VALUES(`dealer`),`status`=VALUES(`status`),`outcome`=VALUES(`outcome`),`doubled`=VALUES(`doubled`),`updated_at`=VALUES(`updated_at`)",
        (int)$uid, (int)$g['bet'], sqlesc(json_encode($g['deck'])), sqlesc(json_encode($g['player'])), sqlesc(json_encode($g['dealer'])),
        sqlesc($g['status']), sqlesc($g['outcome']), (int)$g['doubled'], sqlesc($now)
    )) or sqlerr(__FILE__, __LINE__);
}

function bj_bonus_log($uid, $old, $delta, $new, $comment)
{
    $now = date('Y-m-d H:i:s');
    sql_query(sprintf(
        "INSERT INTO bonus_logs (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
        BJ_BUSINESS_TYPE, (int)$uid, sqlesc(bj_money($old)), sqlesc(bj_money($delta)), sqlesc(bj_money($new)), sqlesc($comment), sqlesc($now), sqlesc($now)
    )) or sqlerr(__FILE__, __LINE__);
}

function bj_charge($uid, $amount, $comment)
{
    $res = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = " . (int)$uid . " FOR UPDATE") or sqlerr(__FILE__, __LINE__);
    $row = mysql_fetch_assoc($res);
    $old = (float)$row['seedbonus'];
    if ($old < $amount) return [false, $old];
    $new = $old - $amount;
    sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` - " . sqlesc(bj_money($amount)) . " WHERE `id` = " . (int)$uid) or sqlerr(__FILE__, __LINE__);
    bj_bonus_log($uid, $old, -$amount, $new, $comment);
    $GLOBALS['CURUSER']['seedbonus'] = $new;
    return [true, $new];
}

function bj_outcome_label($o)
{
    $m = ['blackjack' => '黑杰克！', 'win' => '你赢了', 'lose' => '你输了', 'push' => '平局', 'bust' => '爆牌'];
    return $m[$o] ?? $o;
}

/** Credit the payout for a finished hand, record it, mark the game done. */
function bj_settle($uid, &$g, $outcome)
{
    $bet = (int)$g['bet'];
    $totalBet = $g['doubled'] ? 2 * $bet : $bet;
    if ($outcome === 'blackjack') {
        $return = $bet + (int)floor($bet * 1.5);   // natural 3:2 (never doubled)
    } elseif ($outcome === 'win') {
        $return = 2 * $totalBet;
    } elseif ($outcome === 'push') {
        $return = $totalBet;
    } else {
        $return = 0;                                // lose / bust
    }
    $delta = $return - $totalBet;
    if ($return > 0) {
        $res = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = " . (int)$uid . " FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $old = (float)mysql_fetch_assoc($res)['seedbonus'];
        $new = $old + $return;
        sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(bj_money($return)) . " WHERE `id` = " . (int)$uid) or sqlerr(__FILE__, __LINE__);
        bj_bonus_log($uid, $old, $return, $new, "[二十一点] " . bj_outcome_label($outcome) . " 返还{$return}");
        $GLOBALS['CURUSER']['seedbonus'] = $new;
    }
    $now = date('Y-m-d H:i:s');
    sql_query(sprintf(
        "INSERT INTO `" . BJ_RESULT_TABLE . "` (`uid`,`bet`,`delta`,`outcome`,`created_at`) VALUES (%d,%d,%s,%s,%s)",
        (int)$uid, (int)$totalBet, sqlesc(bj_money($delta)), sqlesc($outcome), sqlesc($now)
    )) or sqlerr(__FILE__, __LINE__);
    $g['status'] = 'done';
    $g['outcome'] = $outcome;
    return $delta;
}

/** Build the client view of a game. Hides the dealer hole card while playing. */
function bj_view($g)
{
    $reveal = ($g['status'] === 'done');
    $player = array_map('bj_card_json', $g['player']);
    if ($reveal) {
        $dealer = array_map('bj_card_json', $g['dealer']);
        $dealerVal = bj_hand_value($g['dealer']);
    } else {
        $dealer = [bj_card_json($g['dealer'][0]), ['hidden' => true]];
        $dealerVal = null;
    }
    return [
        'player' => $player,
        'playerValue' => bj_hand_value($g['player']),
        'dealer' => $dealer,
        'dealerValue' => $dealerVal,
        'status' => $g['status'],
        'outcome' => $g['outcome'],
        'outcomeLabel' => $g['outcome'] !== '' ? bj_outcome_label($g['outcome']) : '',
        'bet' => (int)$g['bet'],
        'doubled' => (int)$g['doubled'],
        'canDouble' => (!$reveal && count($g['player']) === 2),
        'balance' => (float)$GLOBALS['CURUSER']['seedbonus'],
    ];
}

// ---------------- AJAX ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    bj_ensure_tables();
    $uid = (int)$CURUSER['id'];
    $action = $_POST['action'];

    try {
        if ($action === 'deal') {
            $bet = (int)($_POST['bet'] ?? 0);
            if ($bet < 1) { echo json_encode(['ok' => false, 'error' => '请输入有效的下注。']); exit; }
            sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
            $g = bj_load_game($uid);
            if ($g && $g['status'] === 'playing') {
                sql_query("ROLLBACK");
                echo json_encode(['ok' => false, 'error' => '你有未完成的牌局，请先打完。']); exit;
            }
            [$ok, $bal] = bj_charge($uid, $bet, "[二十一点] 下注 {$bet}");
            if (!$ok) { sql_query("ROLLBACK"); echo json_encode(['ok' => false, 'error' => '电影票不足，当前 ' . bj_money($bal) . ' 张。']); exit; }
            $deck = range(0, 51);
            shuffle($deck);
            $g = ['bet' => $bet, 'deck' => $deck, 'player' => [], 'dealer' => [], 'status' => 'playing', 'outcome' => '', 'doubled' => 0];
            $g['player'][] = array_pop($g['deck']);
            $g['dealer'][] = array_pop($g['deck']);
            $g['player'][] = array_pop($g['deck']);
            $g['dealer'][] = array_pop($g['deck']);
            $playerBJ = bj_is_blackjack($g['player']);
            $dealerBJ = bj_is_blackjack($g['dealer']);
            if ($playerBJ || $dealerBJ) {
                $outcome = $playerBJ && $dealerBJ ? 'push' : ($playerBJ ? 'blackjack' : 'lose');
                bj_settle($uid, $g, $outcome);
            }
            bj_save_game($uid, $g);
            sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
            clear_user_cache($uid);
            echo json_encode(['ok' => true] + bj_view($g), JSON_UNESCAPED_UNICODE); exit;
        }

        if ($action === 'hit' || $action === 'stand' || $action === 'double') {
            sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
            $g = bj_load_game($uid);
            if (!$g || $g['status'] !== 'playing') {
                sql_query("ROLLBACK");
                echo json_encode(['ok' => false, 'error' => '没有进行中的牌局。']); exit;
            }
            if ($action === 'hit') {
                $g['player'][] = array_pop($g['deck']);
                if (bj_hand_value($g['player']) > 21) {
                    bj_settle($uid, $g, 'bust');
                }
            } elseif ($action === 'double') {
                if (count($g['player']) !== 2) { sql_query("ROLLBACK"); echo json_encode(['ok' => false, 'error' => '只能在最初两张牌时加倍。']); exit; }
                [$ok, $bal] = bj_charge($uid, $g['bet'], "[二十一点] 加倍 {$g['bet']}");
                if (!$ok) { sql_query("ROLLBACK"); echo json_encode(['ok' => false, 'error' => '电影票不足，无法加倍。']); exit; }
                $g['doubled'] = 1;
                $g['player'][] = array_pop($g['deck']);
                if (bj_hand_value($g['player']) > 21) {
                    bj_settle($uid, $g, 'bust');
                } else {
                    while (bj_hand_value($g['dealer']) < 17) $g['dealer'][] = array_pop($g['deck']);
                    $pv = bj_hand_value($g['player']); $dv = bj_hand_value($g['dealer']);
                    $outcome = ($dv > 21 || $pv > $dv) ? 'win' : ($pv < $dv ? 'lose' : 'push');
                    bj_settle($uid, $g, $outcome);
                }
            } else { // stand
                while (bj_hand_value($g['dealer']) < 17) $g['dealer'][] = array_pop($g['deck']);
                $pv = bj_hand_value($g['player']); $dv = bj_hand_value($g['dealer']);
                $outcome = ($dv > 21 || $pv > $dv) ? 'win' : ($pv < $dv ? 'lose' : 'push');
                bj_settle($uid, $g, $outcome);
            }
            bj_save_game($uid, $g);
            sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
            clear_user_cache($uid);
            echo json_encode(['ok' => true] + bj_view($g), JSON_UNESCAPED_UNICODE); exit;
        }

        echo json_encode(['ok' => false, 'error' => '未知操作。']); exit;
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        echo json_encode(['ok' => false, 'error' => '系统错误，请重试。']); exit;
    }
}

bj_ensure_tables();
// resume an in-progress hand on load
$resInit = sql_query("SELECT * FROM `" . BJ_GAME_TABLE . "` WHERE `uid` = " . (int)$CURUSER['id']) or sqlerr(__FILE__, __LINE__);
$initRow = mysql_fetch_assoc($resInit);
$initState = null;
if ($initRow && $initRow['status'] === 'playing') {
    $initState = bj_view([
        'bet' => (int)$initRow['bet'],
        'deck' => [],
        'player' => json_decode($initRow['player'] ?? '[]', true) ?: [],
        'dealer' => json_decode($initRow['dealer'] ?? '[]', true) ?: [],
        'status' => 'playing',
        'outcome' => '',
        'doubled' => (int)$initRow['doubled'],
    ]);
}
$myRes = sql_query("SELECT * FROM `" . BJ_RESULT_TABLE . "` WHERE `uid` = " . (int)$CURUSER['id'] . " ORDER BY `id` DESC LIMIT 10") or sqlerr(__FILE__, __LINE__);
$sumRes = sql_query("SELECT COUNT(*) AS n, SUM(`delta`) AS net FROM `" . BJ_RESULT_TABLE . "` WHERE `uid` = " . (int)$CURUSER['id']) or sqlerr(__FILE__, __LINE__);
$sum = mysql_fetch_assoc($sumRes);

stdhead("二十一点");
echo game_back_link();
?>
<style>
.bj-wrap { max-width: 760px; margin: 0 auto; }
.bj-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.bj-title { font-size: 24px; font-weight: 800; }
.bj-badge { font-size: 12px; font-weight: 700; color: #1f9a52; background: rgba(31,154,82,.14); padding: 2px 8px; border-radius: 999px; vertical-align: middle; }
.bj-balance { font-size: 14px; font-weight: 700; }
.bj-muted { color: #6f7f95; }
.bj-table { border: 1px solid rgba(120,150,190,.34); border-radius: 12px; padding: 16px; margin-bottom: 14px; background: radial-gradient(circle at 50% 0, #1d6b40, #0d3a22); color: #eafff1; }
.bj-row { margin-bottom: 14px; }
.bj-row-label { font-size: 13px; opacity: .85; margin-bottom: 6px; }
.bj-cards { display: flex; flex-wrap: wrap; gap: 8px; min-height: 92px; }
.bj-card { width: 64px; height: 90px; border-radius: 8px; background: #fff; color: #1b2b3a; display: flex; flex-direction: column; justify-content: space-between; padding: 6px 8px; font-weight: 800; box-shadow: 0 2px 6px rgba(0,0,0,.3); }
.bj-card.red { color: #d8362f; }
.bj-card .bot { align-self: flex-end; transform: rotate(180deg); }
.bj-card.back { background: repeating-linear-gradient(45deg, #2b5c8a, #2b5c8a 6px, #21476b 6px, #21476b 12px); }
.bj-val { font-size: 13px; font-weight: 700; opacity: .9; margin-top: 2px; }
.bj-result { text-align: center; font-size: 20px; font-weight: 900; margin: 6px 0; min-height: 26px; }
.bj-win { color: #7CFFB0; } .bj-lose { color: #ff9a9a; } .bj-push { color: #ffe08a; }
.bj-controls { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.bj-chip { padding: 7px 12px; border: 1px solid rgba(120,150,190,.45); border-radius: 6px; cursor: pointer; font-weight: 700; background: rgba(255,255,255,.6); }
.bj-chip.sel { background: #1f9a52; color: #fff; border-color: #1f9a52; }
.bj-bet-input { width: 120px; padding: 8px; border: 1px solid rgba(120,150,190,.45); border-radius: 6px; }
.bj-btn { padding: 9px 18px; font-weight: 800; cursor: pointer; border-radius: 6px; border: 1px solid #1f9a52; background: #1f9a52; color: #fff; }
.bj-btn.alt { border-color: #3a6ea5; background: #3a6ea5; }
.bj-btn.warn { border-color: #c0883a; background: #c0883a; }
.bj-btn:disabled { opacity: .5; cursor: not-allowed; }
.bj-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 16px; margin-bottom: 14px; background: rgba(30,60,100,.06); }
.bj-tbl { width: 100%; border-collapse: collapse; }
.bj-tbl th, .bj-tbl td { padding: 8px; border: 1px solid rgba(120,150,190,.26); text-align: center; }
.bj-pos { color: #16a34a; font-weight: 700; } .bj-neg { color: #dc2626; font-weight: 700; }
</style>
<div class="bj-wrap">
    <div class="bj-head">
        <div>
            <div class="bj-title">二十一点 <span class="bj-badge">公测 1.0</span></div>
            <div class="bj-muted">点数接近 21 且不爆即胜。庄家停在 17，黑杰克(首两张 A+10)赔 1.5 倍。</div>
        </div>
        <div class="bj-balance">我的电影票：<b id="bjBal"><?php echo bj_money($CURUSER['seedbonus']) ?></b> 张</div>
    </div>

    <div class="bj-table">
        <div class="bj-row">
            <div class="bj-row-label">庄家 <span id="bjDealerVal" class="bj-val"></span></div>
            <div class="bj-cards" id="bjDealer"></div>
        </div>
        <div class="bj-result" id="bjResult"></div>
        <div class="bj-row">
            <div class="bj-row-label">玩家 <span id="bjPlayerVal" class="bj-val"></span></div>
            <div class="bj-cards" id="bjPlayer"></div>
        </div>
    </div>

    <div class="bj-panel">
        <div class="bj-controls" id="bjBetRow">
            <span class="bj-muted">下注：</span>
            <?php foreach (BJ_CHIPS as $i => $c) { ?>
                <span class="bj-chip<?php echo $i === 0 ? ' sel' : '' ?>" data-bet="<?php echo $c ?>"><?php echo number_format($c) ?></span>
            <?php } ?>
            <span class="bj-chip" data-allin="1">梭哈</span>
            <input type="number" min="1" class="bj-bet-input" id="bjBet" value="<?php echo BJ_CHIPS[0] ?>">
            <button type="button" class="bj-btn" id="bjDeal">发牌</button>
        </div>
        <div class="bj-controls" id="bjActionRow" style="display:none;margin-top:4px">
            <button type="button" class="bj-btn" id="bjHit">要牌</button>
            <button type="button" class="bj-btn alt" id="bjStand">停牌</button>
            <button type="button" class="bj-btn warn" id="bjDouble">加倍</button>
        </div>
        <div class="bj-muted" id="bjMsg" style="margin-top:10px"></div>
    </div>

    <div class="bj-panel" id="bjMine">
        <h3 style="margin:0 0 10px">我的最近战绩（共 <?php echo (int)($sum['n'] ?? 0) ?> 局，净 <span class="<?php echo (float)($sum['net'] ?? 0) >= 0 ? 'bj-pos' : 'bj-neg' ?>"><?php echo ((float)($sum['net'] ?? 0) >= 0 ? '+' : '') . number_format((float)($sum['net'] ?? 0), 0) ?></span>）</h3>
        <table class="bj-tbl">
            <tr><th>时间</th><th>下注</th><th>结果</th><th>盈亏</th></tr>
            <tbody id="bjRows">
            <?php while ($r = mysql_fetch_assoc($myRes)) { ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['created_at']) ?></td>
                    <td><?php echo (int)$r['bet'] ?></td>
                    <td><?php echo htmlspecialchars(bj_outcome_label($r['outcome'])) ?></td>
                    <td class="<?php echo (float)$r['delta'] >= 0 ? 'bj-pos' : 'bj-neg' ?>"><?php echo ((float)$r['delta'] >= 0 ? '+' : '') . bj_money($r['delta']) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>

    <?php
    $bjNet = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, SUM(`s`.`delta`) AS amt FROM `" . BJ_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
    $bjNetLow = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, SUM(`s`.`delta`) AS amt FROM `" . BJ_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt ASC LIMIT 10");
    $bjCnt = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, COUNT(*) AS amt FROM `" . BJ_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
    $bjLuck = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, MAX(`s`.`delta`) AS amt, COUNT(*) AS cnt FROM `" . BJ_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC, cnt DESC LIMIT 10");
    echo game_lb_css();
    ?>
    <div class="bj-panel" id="bjLb">
        <h3 style="margin:0 0 12px">🏆 二十一点榜单</h3>
        <div class="glb-grid">
            <?php
            echo game_lb_table('💰 盈亏榜', $bjNet, '净盈亏',
                function ($r) { return ((float)$r['amt'] >= 0 ? '+' : '') . game_lb_money($r['amt']); },
                function ($r) { return (float)$r['amt'] >= 0 ? 'glb-pos' : 'glb-neg'; }, $bjNetLow);
            echo game_lb_table('🔥 活跃榜', $bjCnt, '对局数',
                function ($r) { return number_format((int)$r['amt']) . ' 局'; });
            echo game_lb_table('🍀 手气榜', $bjLuck, '单局最高赢',
                function ($r) { return game_lb_money($r['amt']); },
                function ($r) { return (float)$r['amt'] > 0 ? 'glb-pos' : ''; });
            ?>
        </div>
    </div>
</div>
<script>
(function () {
    var busy = false, bet = <?php echo BJ_CHIPS[0] ?>;
    var betInput = document.getElementById('bjBet');
    var betRow = document.getElementById('bjBetRow'), actionRow = document.getElementById('bjActionRow');
    var dealBtn = document.getElementById('bjDeal'), hitBtn = document.getElementById('bjHit'), standBtn = document.getElementById('bjStand'), doubleBtn = document.getElementById('bjDouble');
    var msg = document.getElementById('bjMsg'), result = document.getElementById('bjResult');
    var balEl = document.getElementById('bjBal');
    var initState = <?php echo json_encode($initState, JSON_UNESCAPED_UNICODE) ?: 'null' ?>;

    function cardHtml(c) {
        if (c.hidden) return '<div class="bj-card back"></div>';
        var cls = 'bj-card' + (c.red ? ' red' : '');
        return '<div class="' + cls + '"><span class="top">' + c.r + c.s + '</span><span class="bot">' + c.r + c.s + '</span></div>';
    }
    function fmt(n) { return (Math.round(n * 10) / 10).toFixed(1); }
    function refreshPanels() {
        fetch(location.href, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                ['bjMine', 'bjLb'].forEach(function (id) {
                    var f = doc.getElementById(id), cur = document.getElementById(id);
                    if (f && cur) cur.innerHTML = f.innerHTML;
                });
            }).catch(function () {});
    }

    function render(d) {
        document.getElementById('bjPlayer').innerHTML = d.player.map(cardHtml).join('');
        document.getElementById('bjDealer').innerHTML = d.dealer.map(cardHtml).join('');
        document.getElementById('bjPlayerVal').textContent = d.playerValue != null ? ('(' + d.playerValue + ')') : '';
        document.getElementById('bjDealerVal').textContent = d.dealerValue != null ? ('(' + d.dealerValue + ')') : '';
        if (d.balance != null) balEl.textContent = fmt(d.balance);
        if (d.status === 'playing') {
            betRow.style.display = 'none';
            actionRow.style.display = 'flex';
            doubleBtn.style.display = d.canDouble ? '' : 'none';
            result.textContent = '';
        } else {
            actionRow.style.display = 'none';
            betRow.style.display = 'flex';
            dealBtn.textContent = '再来一局';
            if (d.outcomeLabel) {
                var cls = (d.outcome === 'win' || d.outcome === 'blackjack') ? 'bj-win' : (d.outcome === 'push' ? 'bj-push' : 'bj-lose');
                result.className = 'bj-result ' + cls;
                result.textContent = d.outcomeLabel;
                refreshPanels();
            }
        }
    }

    function post(action, extra) {
        if (busy) return;
        busy = true; msg.textContent = '';
        hitBtn.disabled = standBtn.disabled = doubleBtn.disabled = dealBtn.disabled = true;
        var body = 'action=' + action + (extra || '');
        fetch('/games/blackjack/', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { msg.innerHTML = '<span style="color:#dc2626">' + (d.error || '出错了') + '</span>'; return; }
                render(d);
            })
            .catch(function () { msg.innerHTML = '<span style="color:#dc2626">网络错误</span>'; })
            .finally(function () { busy = false; hitBtn.disabled = standBtn.disabled = doubleBtn.disabled = dealBtn.disabled = false; });
    }

    document.querySelectorAll('.bj-chip').forEach(function (c) {
        c.addEventListener('click', function () {
            document.querySelectorAll('.bj-chip').forEach(function (x) { x.classList.remove('sel'); });
            c.classList.add('sel');
            if (c.getAttribute('data-allin')) {
                bet = Math.max(1, Math.floor(parseFloat(balEl.textContent) || 0));
            } else {
                bet = parseInt(c.getAttribute('data-bet'), 10);
            }
            betInput.value = bet;
        });
    });
    betInput.addEventListener('input', function () {
        bet = Math.max(1, parseInt(betInput.value, 10) || 1);
        document.querySelectorAll('.bj-chip').forEach(function (x) { x.classList.remove('sel'); });
    });

    dealBtn.addEventListener('click', function () { post('deal', '&bet=' + bet); });
    hitBtn.addEventListener('click', function () { post('hit'); });
    standBtn.addEventListener('click', function () { post('stand'); });
    doubleBtn.addEventListener('click', function () { post('double'); });

    if (initState) { render(initState); msg.textContent = '继续上一局未打完的牌局'; }
})();
</script>
<?php
stdfoot();
