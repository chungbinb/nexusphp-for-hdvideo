<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;

/**
 * 斗地主 — MVP step 1: lobby + tables + invite + wait-for-3.
 * Live room state in Redis (polled). Card dealing / bidding / playing / scoring
 * come in later steps.
 */
const DDZ_SEATS = 3;
const DDZ_BASE_OPTIONS = [100, 500, 1000, 5000];
const DDZ_JOIN_BALANCE_FACTOR = 16; // 进桌需余额 >= 底分 * 此值（防穿仓门槛）
const DDZ_ROOM_TTL = 7200;          // 房间无操作 2 小时自动回收

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
            // 满 3 人：暂标记 ready（发牌/叫地主在下一版本实现），并从大厅移除。
            $room['status'] = 'ready';
            ddz_redis()->sRem(ddz_lobby_key(), $id);
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
        $room['seats'][$seat] = null;
        $room['updated_at'] = TIMENOW;
        if (ddz_player_count($room) === 0) {
            ddz_redis()->del(ddz_room_key($id));
            ddz_redis()->sRem(ddz_lobby_key(), $id);
            return "";
        }
        $room['status'] = 'waiting';
        ddz_redis()->sAdd(ddz_lobby_key(), $id);
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
            if ($room && $room['status'] === 'waiting' && ddz_player_count($room) < DDZ_SEATS) {
                ddz_redis()->sAdd(ddz_lobby_key(), $id);
                $tables[] = $room;
            }
            continue;
        }
        $tables[] = $room;
    }
    usort($tables, fn($a, $b) => $a['created_at'] <=> $b['created_at']);
    return $tables;
}

// ---- Polling endpoint (JSON) ----
if (($_GET['ajax'] ?? '') === 'poll') {
    header('Content-Type: application/json');
    $id = (int)($_GET['table'] ?? 0);
    $room = ddz_room_get($id);
    if (!$room) {
        echo json_encode(['ok' => false, 'gone' => true]);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'status' => $room['status'],
        'base' => $room['base'],
        'count' => ddz_player_count($room),
        'mySeat' => ddz_seat_of($room, $CURUSER['id']),
        'seats' => array_map(fn($s) => $s ? ['username' => $s['username']] : null, $room['seats']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
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
.ddz-wrap { max-width: 880px; margin: 0 auto; }
.ddz-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.ddz-title { font-size: 24px; font-weight: 800; }
.ddz-badge { font-size: 12px; font-weight: 700; color: #e67e22; background: rgba(230,126,34,.12); padding: 2px 8px; border-radius: 999px; vertical-align: middle; }
.ddz-balance { font-size: 14px; font-weight: 700; }
.ddz-muted { color: #6f7f95; }
.ddz-message { padding: 10px 12px; border-radius: 6px; margin-bottom: 14px; font-weight: 700; background: rgba(220,60,70,.14); color: #c02432; }
.ddz-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 16px; margin-bottom: 14px; background: rgba(30,60,100,.06); }
.ddz-section-title { font-size: 18px; font-weight: 800; margin: 8px 0 12px; }
.ddz-table-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 10px 12px; border: 1px solid rgba(120,150,190,.26); border-radius: 8px; margin-bottom: 8px; }
.ddz-table-row b { font-size: 16px; }
.ddz-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.ddz-form select, .ddz-form input { padding: 8px; }
.ddz-btn { padding: 8px 16px; font-weight: 700; cursor: pointer; border-radius: 6px; border: 1px solid #2ecc71; background: #2ecc71; color: #fff; text-decoration: none; display: inline-block; }
.ddz-btn.ghost { background: transparent; color: #2ecc71; }
.ddz-btn.danger { background: transparent; color: #c0392b; border-color: #c0392b; }
.ddz-seats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 14px 0; }
.ddz-seat { border: 1px solid rgba(120,150,190,.34); border-radius: 10px; padding: 18px 10px; text-align: center; background: rgba(0,0,0,.03); min-height: 90px; display: flex; flex-direction: column; justify-content: center; gap: 6px; }
.ddz-seat.filled { border-color: #2ecc71; background: rgba(46,204,113,.08); }
.ddz-seat .who { font-weight: 800; font-size: 16px; }
.ddz-seat .role { font-size: 12px; color: #6f7f95; }
.ddz-status { font-weight: 800; margin: 10px 0; }
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

    <?php if ($room) { // ----- 桌内视图 / 受邀加入 ----- ?>
        <div class="ddz-panel">
            <div class="ddz-section-title">桌 #<?php echo (int)$room['id'] ?>　底分 <?php echo (int)$room['base'] ?>　电影票</div>
            <div class="ddz-status" id="ddzStatus"></div>
            <div class="ddz-seats" id="ddzSeats"></div>
            <?php if ($inRoom) { ?>
                <form method="post" action="/games/ddz/" onsubmit="return confirm('确认离开桌子？');" style="display:inline-block">
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
        <script>
        (function () {
            var tableId = <?php echo (int)$room['id'] ?>;
            var seatsEl = document.getElementById('ddzSeats');
            var statusEl = document.getElementById('ddzStatus');
            function render(d) {
                var html = '';
                for (var i = 0; i < 3; i++) {
                    var s = d.seats[i];
                    html += '<div class="ddz-seat' + (s ? ' filled' : '') + '">'
                        + '<div class="who">' + (s ? escapeHtml(s.username) : '空位') + '</div>'
                        + '<div class="role">' + (s ? ('座位 ' + (i + 1)) : '等待加入…') + '</div>'
                        + '</div>';
                }
                seatsEl.innerHTML = html;
                if (d.status === 'ready' || d.count >= 3) {
                    statusEl.textContent = '✅ 已满 3 人，即将开始发牌（发牌/叫地主/出牌功能开发中）';
                } else {
                    statusEl.textContent = '⏳ 等待玩家加入… ' + d.count + ' / 3';
                }
            }
            function escapeHtml(s) { return (s || '').replace(/[&<>"]/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]); }); }
            function poll() {
                fetch('/games/ddz/?ajax=poll&table=' + tableId, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.ok) { statusEl.textContent = '该桌已解散。'; seatsEl.innerHTML = ''; return; }
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

    <?php } else { // ----- 大厅 ----- ?>
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
