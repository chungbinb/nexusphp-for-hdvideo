<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;

/**
 * 斗地主 — MVP step 2: lobby + tables + invite + wait-for-3 + deal + bidding (叫地主).
 * Card play / scoring / turn-timer come in later steps.
 * Live room state in Redis (polled every ~1.5s). Mutations are lock-guarded.
 */
const DDZ_SEATS = 3;
const DDZ_BASE_OPTIONS = [100, 500, 1000, 5000];
const DDZ_JOIN_BALANCE_FACTOR = 16;
const DDZ_ROOM_TTL = 7200;

function ddz_redis()
{
    return \Nexus\Database\NexusDB::redis();
}

function ddz_room_key($id)
{
    return "ddz:room:" . (int)$id;
}

function ddz_lobby_key()
{
    return "ddz:lobby";
}

function ddz_room_get($id)
{
    $j = ddz_redis()->get(ddz_room_key($id));
    return $j ? json_decode($j, true) : null;
}

function ddz_room_put($room)
{
    ddz_redis()->setex(ddz_room_key($room['id']), DDZ_ROOM_TTL, json_encode($room, JSON_UNESCAPED_UNICODE));
}

function ddz_lock($id, $ttl = 5)
{
    $token = bin2hex(random_bytes(8));
    for ($i = 0; $i < 25; $i++) {
        if (ddz_redis()->set("ddz:lock:" . (int)$id, $token, ['nx', 'ex' => $ttl])) {
            return $token;
        }
        usleep(80000);
    }
    return false;
}

function ddz_unlock($id, $token)
{
    $r = ddz_redis();
    if ($r->get("ddz:lock:" . (int)$id) === $token) {
        $r->del("ddz:lock:" . (int)$id);
    }
}

function ddz_seat_of($room, $uid)
{
    foreach ($room['seats'] as $i => $s) {
        if ($s && (int)$s['uid'] === (int)$uid) {
            return $i;
        }
    }
    return -1;
}

function ddz_player_count($room)
{
    $n = 0;
    foreach ($room['seats'] as $s) {
        if ($s) {
            $n++;
        }
    }
    return $n;
}

// ---------- card engine ----------
// card id 0..51 = standard 52 (rank = id%13 over [3,4,..,K,A,2], suit = id/13);
// 52 = 小王, 53 = 大王.
function ddz_card_rank($c)
{
    if ($c >= 52) {
        return $c - 52 + 13; // 13=小王, 14=大王
    }
    return $c % 13; // 0..12  (12 = '2')
}

function ddz_card_red($c)
{
    if ($c == 53) {
        return true;   // 大王 red
    }
    if ($c == 52) {
        return false;  // 小王 black
    }
    $suit = intdiv($c, 13);
    return $suit === 1 || $suit === 3; // ♥ ♦
}

function ddz_card_label($c)
{
    if ($c == 52) {
        return '小王';
    }
    if ($c == 53) {
        return '大王';
    }
    $ranks = ['3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A', '2'];
    $suits = ['♠', '♥', '♣', '♦'];
    return $suits[intdiv($c, 13)] . $ranks[$c % 13];
}

function ddz_sort_hand(&$hand)
{
    usort($hand, function ($a, $b) {
        $ra = ddz_card_rank($a);
        $rb = ddz_card_rank($b);
        if ($ra !== $rb) {
            return $rb - $ra; // high to low
        }
        return $a - $b;
    });
}

function ddz_deal()
{
    $deck = range(0, 53);
    shuffle($deck);
    $hands = [array_slice($deck, 0, 17), array_slice($deck, 17, 17), array_slice($deck, 34, 17)];
    $bottom = array_slice($deck, 51, 3);
    foreach ($hands as &$h) {
        ddz_sort_hand($h);
    }
    unset($h);
    return [$hands, $bottom];
}

function ddz_start_game(&$room)
{
    [$hands, $bottom] = ddz_deal();
    $room['hands'] = $hands;
    $room['bottom'] = $bottom;
    $first = mt_rand(0, 2);
    $room['bid'] = ['turn' => $first, 'first' => $first, 'high' => 0, 'highSeat' => -1, 'acted' => 0, 'bids' => [null, null, null]];
    $room['landlord'] = -1;
    $room['multiplier'] = 1;
    $room['turn'] = -1;
    $room['status'] = 'bidding';
    $room['updated_at'] = TIMENOW;
}

function ddz_set_landlord(&$room, $seat)
{
    $room['landlord'] = $seat;
    $room['multiplier'] = max(1, (int)$room['bid']['high']);
    $room['hands'][$seat] = array_merge($room['hands'][$seat], $room['bottom']);
    ddz_sort_hand($room['hands'][$seat]);
    $room['status'] = 'playing';
    $room['turn'] = $seat; // landlord leads
    $room['lastPlay'] = null;
    $room['updated_at'] = TIMENOW;
}

function ddz_create_table($base)
{
    global $CURUSER;
    $base = (int)$base;
    if (!in_array($base, DDZ_BASE_OPTIONS, true)) {
        return [null, "底分无效。"];
    }
    $need = $base * DDZ_JOIN_BALANCE_FACTOR;
    if ((float)$CURUSER['seedbonus'] < $need) {
        return [null, "余额不足，该底分桌需至少 {$need} 张电影票。"];
    }
    $id = (int)ddz_redis()->incr("ddz:next_id");
    $room = [
        'id' => $id,
        'base' => $base,
        'status' => 'waiting',
        'owner' => (int)$CURUSER['id'],
        'seats' => [
            ['uid' => (int)$CURUSER['id'], 'username' => $CURUSER['username']],
            null,
            null,
        ],
        'created_at' => TIMENOW,
        'updated_at' => TIMENOW,
    ];
    ddz_room_put($room);
    ddz_redis()->sAdd(ddz_lobby_key(), $id);
    return [$id, ""];
}

function ddz_join_table($id)
{
    global $CURUSER;
    $id = (int)$id;
    $token = ddz_lock($id);
    if (!$token) {
        return [null, "操作繁忙，请重试。"];
    }
    try {
        $room = ddz_room_get($id);
        if (!$room) {
            return [null, "桌子不存在或已解散。"];
        }
        if (ddz_seat_of($room, $CURUSER['id']) >= 0) {
            return [$id, ""];
        }
        if ($room['status'] !== 'waiting') {
            return [null, "该桌已开始，无法加入。"];
        }
        if (ddz_player_count($room) >= DDZ_SEATS) {
            return [null, "该桌已满。"];
        }
        $need = $room['base'] * DDZ_JOIN_BALANCE_FACTOR;
        if ((float)$CURUSER['seedbonus'] < $need) {
            return [null, "余额不足，该底分桌需至少 {$need} 张电影票。"];
        }
        foreach ($room['seats'] as $i => $s) {
            if (!$s) {
                $room['seats'][$i] = ['uid' => (int)$CURUSER['id'], 'username' => $CURUSER['username']];
                break;
            }
        }
        $room['updated_at'] = TIMENOW;
        if (ddz_player_count($room) >= DDZ_SEATS) {
            ddz_redis()->sRem(ddz_lobby_key(), $id);
            ddz_start_game($room); // 满 3 人：发牌 + 进入叫地主
        }
        ddz_room_put($room);
        return [$id, ""];
    } finally {
        ddz_unlock($id, $token);
    }
}

function ddz_leave_table($id)
{
    global $CURUSER;
    $id = (int)$id;
    $token = ddz_lock($id);
    if (!$token) {
        return "操作繁忙，请重试。";
    }
    try {
        $room = ddz_room_get($id);
        if (!$room) {
            return "";
        }
        $seat = ddz_seat_of($room, $CURUSER['id']);
        if ($seat < 0) {
            return "";
        }
        // 游戏已开始（叫地主/出牌）时，一人离开则整桌解散（暂无机器人托管）。
        if ($room['status'] !== 'waiting') {
            ddz_redis()->del(ddz_room_key($id));
            ddz_redis()->sRem(ddz_lobby_key(), $id);
            return "";
        }
        $room['seats'][$seat] = null;
        $room['updated_at'] = TIMENOW;
        if (ddz_player_count($room) === 0) {
            ddz_redis()->del(ddz_room_key($id));
            ddz_redis()->sRem(ddz_lobby_key(), $id);
            return "";
        }
        ddz_redis()->sAdd(ddz_lobby_key(), $id);
        ddz_room_put($room);
        return "";
    } finally {
        ddz_unlock($id, $token);
    }
}

function ddz_bid($id, $score)
{
    global $CURUSER;
    $id = (int)$id;
    $score = (int)$score;
    if ($score < 0 || $score > 3) {
        return "无效叫分。";
    }
    $token = ddz_lock($id);
    if (!$token) {
        return "操作繁忙，请重试。";
    }
    try {
        $room = ddz_room_get($id);
        if (!$room || $room['status'] !== 'bidding') {
            return "现在不能叫分。";
        }
        $seat = ddz_seat_of($room, $CURUSER['id']);
        if ($seat < 0) {
            return "你不在这桌。";
        }
        if ($seat !== (int)$room['bid']['turn']) {
            return "还没轮到你叫分。";
        }
        if ($score > 0 && $score <= (int)$room['bid']['high']) {
            return "叫分必须高于当前最高分。";
        }
        $room['bid']['bids'][$seat] = $score;
        if ($score > 0) {
            $room['bid']['high'] = $score;
            $room['bid']['highSeat'] = $seat;
        }
        $room['bid']['acted']++;
        $room['updated_at'] = TIMENOW;
        if ($score === 3 || $room['bid']['acted'] >= 3) {
            if ((int)$room['bid']['highSeat'] >= 0) {
                ddz_set_landlord($room, (int)$room['bid']['highSeat']);
            } else {
                ddz_start_game($room); // 全部不叫 -> 重新发牌
            }
        } else {
            $room['bid']['turn'] = ((int)$room['bid']['turn'] + 1) % 3;
        }
        ddz_room_put($room);
        return "";
    } finally {
        ddz_unlock($id, $token);
    }
}

function ddz_list_tables()
{
    $ids = ddz_redis()->sMembers(ddz_lobby_key()) ?: [];
    $tables = [];
    foreach ($ids as $id) {
        $room = ddz_room_get($id);
        if (!$room || $room['status'] !== 'waiting' || ddz_player_count($room) >= DDZ_SEATS) {
            ddz_redis()->sRem(ddz_lobby_key(), $id);
            continue;
        }
        $tables[] = $room;
    }
    usort($tables, fn($a, $b) => $a['created_at'] <=> $b['created_at']);
    return $tables;
}

// ---- Polling endpoint (per-viewer JSON) ----
if (($_GET['ajax'] ?? '') === 'poll') {
    header('Content-Type: application/json');
    $id = (int)($_GET['table'] ?? 0);
    $room = ddz_room_get($id);
    if (!$room) {
        echo json_encode(['ok' => false, 'gone' => true]);
        exit;
    }
    $mySeat = ddz_seat_of($room, $CURUSER['id']);
    $out = [
        'ok' => true,
        'status' => $room['status'],
        'base' => (int)$room['base'],
        'mySeat' => $mySeat,
        'count' => ddz_player_count($room),
        'seats' => [],
    ];
    foreach ($room['seats'] as $i => $s) {
        $out['seats'][$i] = $s ? [
            'username' => $s['username'],
            'cards' => isset($room['hands'][$i]) ? count($room['hands'][$i]) : 0,
        ] : null;
    }
    if (in_array($room['status'], ['bidding', 'playing'], true)) {
        $out['bid'] = $room['bid'] ?? null;
        $out['landlord'] = (int)($room['landlord'] ?? -1);
        $out['multiplier'] = (int)($room['multiplier'] ?? 1);
        $out['turn'] = (int)($room['turn'] ?? -1);
        if ($mySeat >= 0 && isset($room['hands'][$mySeat])) {
            $out['myHand'] = array_map(fn($c) => ['id' => $c, 'label' => ddz_card_label($c), 'red' => ddz_card_red($c)], $room['hands'][$mySeat]);
        }
        if ($room['status'] === 'playing' && isset($room['bottom'])) {
            $out['bottom'] = array_map(fn($c) => ['label' => ddz_card_label($c), 'red' => ddz_card_red($c)], $room['bottom']);
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'bid') { // AJAX
        header('Content-Type: application/json');
        $err = ddz_bid($_POST['table'] ?? 0, $_POST['score'] ?? -1);
        echo json_encode(['ok' => $err === '', 'error' => $err], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $error = '';
    $goTable = 0;
    if ($action === 'create') {
        [$id, $error] = ddz_create_table($_POST['base'] ?? 0);
        if ($id) {
            $goTable = $id;
        }
    } elseif ($action === 'join') {
        [$id, $error] = ddz_join_table($_POST['table'] ?? 0);
        if ($id) {
            $goTable = $id;
        }
    } elseif ($action === 'leave') {
        $error = ddz_leave_table($_POST['table'] ?? 0);
    }
    if ($error !== '') {
        header('Location: /games/ddz/?error=' . urlencode($error));
        exit;
    }
    header('Location: /games/ddz/' . ($goTable ? '?table=' . $goTable : ''));
    exit;
}

$error = trim((string)($_GET['error'] ?? ''));
$tableId = (int)($_GET['table'] ?? 0);
$room = $tableId ? ddz_room_get($tableId) : null;
$mySeat = $room ? ddz_seat_of($room, $CURUSER['id']) : -1;
$inRoom = $mySeat >= 0;

stdhead("斗地主");
?>
<style>
.ddz-wrap { max-width: 920px; margin: 0 auto; }
.ddz-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.ddz-title { font-size: 24px; font-weight: 800; }
.ddz-badge { font-size: 12px; font-weight: 700; color: #e67e22; background: rgba(230,126,34,.12); padding: 2px 8px; border-radius: 999px; vertical-align: middle; }
.ddz-balance { font-size: 14px; font-weight: 700; }
.ddz-muted { color: #6f7f95; }
.ddz-message { padding: 10px 12px; border-radius: 6px; margin-bottom: 14px; font-weight: 700; background: rgba(220,60,70,.14); color: #c02432; }
.ddz-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 16px; margin-bottom: 14px; background: rgba(30,60,100,.06); }
.ddz-section-title { font-size: 18px; font-weight: 800; margin: 8px 0 12px; }
.ddz-table-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 10px 12px; border: 1px solid rgba(120,150,190,.26); border-radius: 8px; margin-bottom: 8px; }
.ddz-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.ddz-form select, .ddz-form input { padding: 8px; }
.ddz-btn { padding: 8px 16px; font-weight: 700; cursor: pointer; border-radius: 6px; border: 1px solid #2ecc71; background: #2ecc71; color: #fff; text-decoration: none; display: inline-block; }
.ddz-btn.ghost { background: transparent; color: #2ecc71; }
.ddz-btn.danger { background: transparent; color: #c0392b; border-color: #c0392b; }
.ddz-btn:disabled { opacity: .45; cursor: not-allowed; }
.ddz-seats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 12px 0; }
.ddz-seat { border: 1px solid rgba(120,150,190,.34); border-radius: 10px; padding: 12px 10px; text-align: center; background: rgba(0,0,0,.03); min-height: 92px; }
.ddz-seat.filled { border-color: #2ecc71; background: rgba(46,204,113,.08); }
.ddz-seat.turn { border-color: #e67e22; box-shadow: 0 0 0 2px rgba(230,126,34,.35); }
.ddz-seat .who { font-weight: 800; font-size: 15px; }
.ddz-seat .meta { font-size: 12px; color: #6f7f95; margin-top: 4px; }
.ddz-seat .lord { color: #c0392b; font-weight: 800; }
.ddz-status { font-weight: 800; margin: 8px 0; }
.ddz-bottom { display: inline-flex; gap: 4px; margin-left: 8px; vertical-align: middle; }
.ddz-card { display: inline-flex; align-items: center; justify-content: center; min-width: 30px; height: 40px; padding: 0 4px; border: 1px solid #c9ced6; border-radius: 5px; background: #fff; color: #222; font-weight: 800; font-size: 14px; box-shadow: 0 1px 2px rgba(0,0,0,.12); }
.ddz-card.red { color: #d62828; }
.ddz-hand { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px; }
.ddz-bid-area { margin: 12px 0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.ddz-invite { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 10px; }
.ddz-invite input { padding: 8px; width: 320px; max-width: 100%; }
</style>
<div class="ddz-wrap">
    <div class="ddz-head">
        <div>
            <div class="ddz-title">斗地主 <span class="ddz-badge">开发中 v0.1</span></div>
            <div class="ddz-muted">三人真人对战，满 3 人开局，不加机器人。用电影票计分。</div>
        </div>
        <div class="ddz-balance">我的电影票：<?php echo number_format((float)$CURUSER['seedbonus'], 1) ?> 张</div>
    </div>

    <?php if ($error) { ?><div class="ddz-message"><?php echo htmlspecialchars($error) ?></div><?php } ?>

    <?php if ($room) { ?>
        <div class="ddz-panel">
            <div class="ddz-section-title">桌 #<?php echo (int)$room['id'] ?>　底分 <?php echo (int)$room['base'] ?> 电影票　<span id="ddzMult" class="ddz-muted"></span></div>
            <div class="ddz-status" id="ddzStatus">加载中…</div>
            <div class="ddz-seats" id="ddzSeats"></div>
            <div id="ddzBidArea" class="ddz-bid-area" style="display:none"></div>
            <div id="ddzMyHandWrap" style="display:none">
                <div class="ddz-muted">我的手牌（<span id="ddzMyCount">0</span> 张）</div>
                <div class="ddz-hand" id="ddzMyHand"></div>
            </div>
            <div style="margin-top:14px">
                <?php if ($inRoom) { ?>
                    <form method="post" action="/games/ddz/" onsubmit="return confirm('确认离开？游戏进行中离开会解散整桌。');" style="display:inline-block">
                        <input type="hidden" name="action" value="leave">
                        <input type="hidden" name="table" value="<?php echo (int)$room['id'] ?>">
                        <button type="submit" class="ddz-btn danger">离开桌子</button>
                    </form>
                    <a class="ddz-btn ghost" href="/games/ddz/">返回大厅</a>
                    <div class="ddz-invite">
                        <span class="ddz-muted">邀请好友：</span>
                        <input type="text" id="ddzInviteLink" readonly value="<?php echo htmlspecialchars(get_protocol_prefix() . $BASEURL . '/games/ddz/?table=' . (int)$room['id']) ?>">
                        <button type="button" class="ddz-btn ghost" onclick="ddzCopyInvite()">复制链接</button>
                    </div>
                <?php } elseif ($room['status'] === 'waiting' && ddz_player_count($room) < DDZ_SEATS) { ?>
                    <form method="post" action="/games/ddz/">
                        <input type="hidden" name="action" value="join">
                        <input type="hidden" name="table" value="<?php echo (int)$room['id'] ?>">
                        <button type="submit" class="ddz-btn">加入这桌</button>
                        <a class="ddz-btn ghost" href="/games/ddz/">返回大厅</a>
                    </form>
                <?php } else { ?>
                    <div class="ddz-muted">该桌已满或已开始。</div>
                    <a class="ddz-btn ghost" href="/games/ddz/">返回大厅</a>
                <?php } ?>
            </div>
        </div>
        <script>
        (function () {
            var tableId = <?php echo (int)$room['id'] ?>;
            var seatsEl = document.getElementById('ddzSeats');
            var statusEl = document.getElementById('ddzStatus');
            var bidEl = document.getElementById('ddzBidArea');
            var handWrap = document.getElementById('ddzMyHandWrap');
            var handEl = document.getElementById('ddzMyHand');
            var myCountEl = document.getElementById('ddzMyCount');
            var multEl = document.getElementById('ddzMult');
            var busy = false;
            function esc(s) { return (s || '').replace(/[&<>"]/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]); }); }
            function cardHtml(c) { return '<span class="ddz-card' + (c.red ? ' red' : '') + '">' + esc(c.label) + '</span>'; }
            function bidLabel(v) { return v === null ? '' : (v === 0 ? '不叫' : (v + '分')); }
            function render(d) {
                var lord = (typeof d.landlord !== 'undefined') ? d.landlord : -1;
                var turn = (typeof d.turn !== 'undefined') ? d.turn : -1;
                var bidTurn = d.bid ? d.bid.turn : -1;
                var html = '';
                for (var i = 0; i < 3; i++) {
                    var s = d.seats[i];
                    var hot = (d.status === 'bidding' && i === bidTurn) || (d.status === 'playing' && i === turn);
                    html += '<div class="ddz-seat' + (s ? ' filled' : '') + (hot ? ' turn' : '') + '">';
                    html += '<div class="who">' + (s ? esc(s.username) : '空位') + (i === lord ? ' <span class="lord">[地主]</span>' : '') + '</div>';
                    if (s) {
                        var meta = '剩 ' + s.cards + ' 张';
                        if (d.status === 'bidding' && d.bid && d.bid.bids[i] !== null) { meta = bidLabel(d.bid.bids[i]); }
                        html += '<div class="meta">' + meta + '</div>';
                    } else {
                        html += '<div class="meta">等待加入…</div>';
                    }
                    html += '</div>';
                }
                seatsEl.innerHTML = html;

                if (d.status === 'waiting') {
                    statusEl.textContent = '⏳ 等待玩家加入… ' + d.count + ' / 3';
                    multEl.textContent = '';
                } else if (d.status === 'bidding') {
                    var name = (d.bid && d.seats[d.bid.turn]) ? d.seats[d.bid.turn].username : '';
                    statusEl.textContent = '🎯 叫地主中…当前轮到 ' + esc(name) + '（最高 ' + (d.bid ? d.bid.high : 0) + ' 分）';
                    multEl.textContent = '';
                } else if (d.status === 'playing') {
                    var bottom = (d.bottom || []).map(cardHtml).join('');
                    var tn = d.seats[turn] ? d.seats[turn].username : '';
                    statusEl.innerHTML = '🃏 地主底牌：<span class="ddz-bottom">' + bottom + '</span>　轮到 <b>' + esc(tn) + '</b> 出牌（出牌功能开发中）';
                    multEl.textContent = '倍数 ' + (d.multiplier || 1);
                }

                // bid buttons (only when it's my turn during bidding)
                if (d.status === 'bidding' && d.mySeat >= 0 && d.mySeat === bidTurn) {
                    var high = d.bid.high;
                    var btns = '<span class="ddz-muted">轮到你叫分：</span>';
                    btns += '<button class="ddz-btn ghost" data-bid="0">不叫</button>';
                    [1, 2, 3].forEach(function (v) {
                        btns += '<button class="ddz-btn" data-bid="' + v + '"' + (v <= high ? ' disabled' : '') + '>' + v + ' 分</button>';
                    });
                    bidEl.innerHTML = btns;
                    bidEl.style.display = '';
                    bidEl.querySelectorAll('button[data-bid]').forEach(function (b) {
                        b.addEventListener('click', function () { sendBid(b.getAttribute('data-bid')); });
                    });
                } else {
                    bidEl.style.display = 'none';
                    bidEl.innerHTML = '';
                }

                // my hand
                if (d.myHand && d.myHand.length) {
                    handEl.innerHTML = d.myHand.map(cardHtml).join('');
                    myCountEl.textContent = d.myHand.length;
                    handWrap.style.display = '';
                } else {
                    handWrap.style.display = 'none';
                }
            }
            function sendBid(score) {
                if (busy) { return; }
                busy = true;
                var body = 'action=bid&table=' + tableId + '&score=' + encodeURIComponent(score);
                fetch('/games/ddz/', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (j) { if (!j.ok && j.error) { alert(j.error); } poll(); })
                    .catch(function () {})
                    .finally(function () { busy = false; });
            }
            function poll() {
                fetch('/games/ddz/?ajax=poll&table=' + tableId, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.ok) { statusEl.textContent = '该桌已解散。'; seatsEl.innerHTML = ''; bidEl.style.display = 'none'; handWrap.style.display = 'none'; return; }
                        render(d);
                    })
                    .catch(function () {});
            }
            poll();
            setInterval(poll, 1500);
        })();
        function ddzCopyInvite() {
            var el = document.getElementById('ddzInviteLink');
            el.select(); el.setSelectionRange(0, 99999);
            try { document.execCommand('copy'); } catch (e) {}
            if (navigator.clipboard) { navigator.clipboard.writeText(el.value).catch(function () {}); }
        }
        </script>

    <?php } else { ?>
        <div class="ddz-panel">
            <div class="ddz-section-title">创建桌子</div>
            <form class="ddz-form" method="post" action="/games/ddz/">
                <input type="hidden" name="action" value="create">
                <label>底分
                    <select name="base">
                        <?php foreach (DDZ_BASE_OPTIONS as $b) { ?><option value="<?php echo $b ?>"><?php echo $b ?> 电影票</option><?php } ?>
                    </select>
                </label>
                <button type="submit" class="ddz-btn">创建并入座</button>
                <span class="ddz-muted">入座需余额 ≥ 底分 × <?php echo DDZ_JOIN_BALANCE_FACTOR ?>（防穿仓）。</span>
            </form>
        </div>

        <div class="ddz-section-title">等待中的桌子 <a class="ddz-btn ghost" style="font-size:13px;padding:4px 10px" href="/games/ddz/">刷新</a></div>
        <?php $tables = ddz_list_tables(); ?>
        <?php if (!$tables) { ?>
            <div class="ddz-panel ddz-muted">暂无等待中的桌子，创建一个吧。</div>
        <?php } ?>
        <?php foreach ($tables as $t) { ?>
            <div class="ddz-table-row">
                <b>桌 #<?php echo (int)$t['id'] ?></b>
                <span>底分 <?php echo (int)$t['base'] ?> 电影票</span>
                <span class="ddz-muted"><?php echo ddz_player_count($t) ?> / 3 人</span>
                <span class="ddz-muted">座上：<?php
                    $names = [];
                    foreach ($t['seats'] as $s) { if ($s) { $names[] = htmlspecialchars($s['username']); } }
                    echo implode('、', $names);
                ?></span>
                <form method="post" action="/games/ddz/" style="margin-left:auto">
                    <input type="hidden" name="action" value="join">
                    <input type="hidden" name="table" value="<?php echo (int)$t['id'] ?>">
                    <button type="submit" class="ddz-btn">加入</button>
                </form>
            </div>
        <?php } ?>
    <?php } ?>
</div>
<?php
stdfoot();
