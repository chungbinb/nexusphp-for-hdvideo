<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;
require_once "../../../include/game_control.php";
game_guard('ddz');

/**
 * 斗地主 — MVP step 3: full play.
 * lobby + tables + invite + wait-for-3 + deal + 叫地主 + 出牌(牌型校验) + 判胜负 + 电影票结算 + 再来一局.
 * Turn timer / leaderboards come later. Live state in Redis (polled), lock-guarded mutations.
 */
const DDZ_SEATS = 3;
const DDZ_BASE_OPTIONS = [100, 500, 1000, 5000];
const DDZ_JOIN_BALANCE_FACTOR = 16;
const DDZ_ROOM_TTL = 7200;
const DDZ_MULT_CAP = 1024;
const DDZ_BUSINESS_TYPE = 103; // 斗地主（历史记录为 13）
const DDZ_TURN_SECONDS = 30; // 回合限时
const DDZ_MATCH_WAIT = 10;   // 匹配等待：超过则补机器人开局
const DDZ_BOT_NAMES = ['小赵', '小钱', '小孙', '小李', '小周', '小吴', '小郑', '小王'];

function ddz_set_deadline(&$room)
{
    $room['deadline'] = TIMENOW + DDZ_TURN_SECONDS;
}

function ddz_redis()
{
    return \Nexus\Database\NexusDB::redis();
}
function ddz_room_key($id) { return "ddz:room:" . (int)$id; }
function ddz_lobby_key() { return "ddz:lobby"; }
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
function ddz_card_rank($c)
{
    if ($c >= 52) {
        return $c - 52 + 13; // 13=小王, 14=大王
    }
    return $c % 13; // 0..12 (0='3' ... 11='A', 12='2')
}
function ddz_card_red($c)
{
    if ($c == 53) return true;
    if ($c == 52) return false;
    $suit = intdiv($c, 13);
    return $suit === 1 || $suit === 3;
}
function ddz_card_label($c)
{
    if ($c == 52) return '小王';
    if ($c == 53) return '大王';
    $ranks = ['3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A', '2'];
    $suits = ['♠', '♥', '♣', '♦'];
    return $suits[intdiv($c, 13)] . $ranks[$c % 13];
}
function ddz_sort_hand(&$hand)
{
    usort($hand, function ($a, $b) {
        $ra = ddz_card_rank($a);
        $rb = ddz_card_rank($b);
        if ($ra !== $rb) return $rb - $ra;
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

/**
 * Classify a set of card ids into a play. Returns ['type','n','main'] or null.
 * main = comparison rank (higher beats lower for the same type+n).
 */
function ddz_classify($cards)
{
    $n = count($cards);
    if ($n === 0) return null;
    $ranks = array_map('ddz_card_rank', $cards);
    $counts = array_count_values($ranks);
    if ($n === 2 && isset($counts[13]) && isset($counts[14])) {
        return ['type' => 'rocket', 'n' => 2, 'main' => 100];
    }
    if ($n === 1) return ['type' => 'single', 'n' => 1, 'main' => $ranks[0]];
    if ($n === 2 && count($counts) === 1) return ['type' => 'pair', 'n' => 2, 'main' => $ranks[0]];
    if ($n === 3 && count($counts) === 1) return ['type' => 'triple', 'n' => 3, 'main' => $ranks[0]];
    if ($n === 4 && count($counts) === 1) return ['type' => 'bomb', 'n' => 4, 'main' => $ranks[0]];
    if ($n === 4) {
        $vals = array_values($counts);
        if (in_array(3, $vals, true) && in_array(1, $vals, true)) {
            return ['type' => 'triple1', 'n' => 4, 'main' => array_search(3, $counts)];
        }
    }
    if ($n === 5) {
        if (count($counts) === 2 && in_array(3, array_values($counts), true) && in_array(2, array_values($counts), true)) {
            return ['type' => 'triple2', 'n' => 5, 'main' => array_search(3, $counts)];
        }
    }
    $hasJoker = isset($counts[13]) || isset($counts[14]);
    $distinct = array_keys($counts);
    sort($distinct);
    $maxRank = $distinct ? max($distinct) : -1;
    $consecutive = function ($arr) {
        for ($i = 1; $i < count($arr); $i++) {
            if ($arr[$i] !== $arr[$i - 1] + 1) return false;
        }
        return $arr && $arr[count($arr) - 1] <= 11; // no '2'(12)/jokers
    };

    if (!$hasJoker && $n >= 5 && count($counts) === $n) {
        return ($consecutive($distinct)) ? ['type' => 'straight', 'n' => $n, 'main' => $maxRank] : null;
    }
    if (!$hasJoker && $n >= 6 && $n % 2 === 0) {
        $allPairs = true;
        foreach ($counts as $c) {
            if ($c !== 2) { $allPairs = false; break; }
        }
        if ($allPairs && $consecutive($distinct)) {
            return ['type' => 'pairs', 'n' => $n, 'main' => $maxRank];
        }
    }
    // airplane (consecutive triples) +/- wings
    if (!$hasJoker) {
        $tripleRanks = [];
        foreach ($counts as $r => $c) {
            if ($c >= 3) $tripleRanks[] = $r;
        }
        sort($tripleRanks);
        if (count($tripleRanks) >= 2) {
            $allThree = true;
            foreach ($tripleRanks as $r) {
                if ($counts[$r] !== 3) { $allThree = false; break; }
            }
            if ($allThree && $consecutive($tripleRanks)) {
                $k = count($tripleRanks);
                $maxTriple = max($tripleRanks);
                $wing = $n - 3 * $k;
                if ($wing === 0) return ['type' => 'plane', 'n' => $n, 'main' => $maxTriple];
                if ($wing === $k) {
                    foreach ($counts as $r => $c) {
                        if (!in_array($r, $tripleRanks, true) && $c >= 3) return null;
                    }
                    return ['type' => 'plane1', 'n' => $n, 'main' => $maxTriple];
                }
                if ($wing === 2 * $k) {
                    $pairs = 0;
                    foreach ($counts as $r => $c) {
                        if (in_array($r, $tripleRanks, true)) continue;
                        if ($c !== 2) return null;
                        $pairs++;
                    }
                    if ($pairs === $k) return ['type' => 'plane2', 'n' => $n, 'main' => $maxTriple];
                }
                return null;
            }
        }
    }
    // four + two
    $quad = null;
    foreach ($counts as $r => $c) {
        if ($c === 4) { $quad = $r; break; }
    }
    if ($quad !== null) {
        if ($n === 6) return ['type' => 'four2', 'n' => 6, 'main' => $quad];
        if ($n === 8) {
            $pairs = 0;
            foreach ($counts as $r => $c) {
                if ($r === $quad) continue;
                if ($c !== 2) return null;
                $pairs++;
            }
            if ($pairs === 2) return ['type' => 'four2p', 'n' => 8, 'main' => $quad];
        }
    }
    return null;
}

function ddz_can_beat($play, $last)
{
    if (!$play) return false;
    if ($play['type'] === 'rocket') return true;
    if ($last === null) return true;
    if ($play['type'] === 'bomb') {
        if ($last['type'] === 'rocket') return false;
        if ($last['type'] === 'bomb') return $play['main'] > $last['main'];
        return true;
    }
    if ($last['type'] === 'bomb' || $last['type'] === 'rocket') return false;
    return $play['type'] === $last['type'] && $play['n'] === $last['n'] && $play['main'] > $last['main'];
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
    $room['lastPlay'] = null;
    $room['lastSeat'] = -1;
    $room['plays'] = [0, 0, 0];
    $room['bombs'] = 0;
    $room['winner'] = -1;
    $room['status'] = 'bidding';
    $room['updated_at'] = TIMENOW;
    ddz_set_deadline($room);
}

function ddz_set_landlord(&$room, $seat)
{
    $room['landlord'] = $seat;
    $room['multiplier'] = max(1, (int)$room['bid']['high']);
    $room['hands'][$seat] = array_merge($room['hands'][$seat], $room['bottom']);
    ddz_sort_hand($room['hands'][$seat]);
    $room['status'] = 'playing';
    $room['turn'] = $seat;
    $room['lastPlay'] = null;
    $room['lastSeat'] = -1;
    $room['updated_at'] = TIMENOW;
    ddz_set_deadline($room);
}

function ddz_settle($room, $landlordWon, $mult)
{
    $base = (int)$room['base'];
    $landlord = (int)$room['landlord'];
    $amt = $base * $mult;
    $uids = [];
    $isBot = [];
    foreach ($room['seats'] as $i => $s) {
        $uids[$i] = $s ? (int)$s['uid'] : 0;
        $isBot[$i] = $s ? !empty($s['bot']) : true;
    }
    $now = date('Y-m-d H:i:s');
    $deltas = [0, 0, 0];
    ddz_ensure_tables();
    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        // 仅对真人按 uid 顺序加锁取余额；机器人视作余额无限（不入库、不影响经济外的真人收益）
        $real = [];
        for ($i = 0; $i < 3; $i++) {
            if (!$isBot[$i] && $uids[$i] > 0) $real[] = $i;
        }
        usort($real, fn($a, $b) => $uids[$a] <=> $uids[$b]);
        $bal = [];
        foreach ($real as $seat) {
            $res = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = " . $uids[$seat] . " FOR UPDATE") or sqlerr(__FILE__, __LINE__);
            $row = mysql_fetch_assoc($res);
            $bal[$seat] = $row ? (float)$row['seedbonus'] : 0;
        }
        for ($i = 0; $i < 3; $i++) {
            if (!isset($bal[$i])) $bal[$i] = (float)$amt * 4 + 1e9; // 机器人虚拟余额
        }
        if ($landlordWon) {
            $collected = 0;
            for ($i = 0; $i < 3; $i++) {
                if ($i === $landlord) continue;
                $loss = min($amt, $bal[$i]);
                $deltas[$i] = -$loss;
                $collected += $loss;
            }
            $deltas[$landlord] = $collected;
        } else {
            $pay = min(2 * $amt, $bal[$landlord]);
            $deltas[$landlord] = -$pay;
            $half = $pay / 2;
            for ($i = 0; $i < 3; $i++) {
                if ($i !== $landlord) $deltas[$i] = $half;
            }
        }
        for ($i = 0; $i < 3; $i++) {
            $d = round($deltas[$i], 1);
            $deltas[$i] = $d;
            if ($isBot[$i] || $uids[$i] <= 0) continue; // 机器人不入库、不计战绩
            $uid = $uids[$i];
            $roleKey = ($i === $landlord) ? 'landlord' : 'farmer';
            $isWin = $landlordWon ? ($i === $landlord ? 1 : 0) : ($i !== $landlord ? 1 : 0);
            sql_query(sprintf(
                "INSERT INTO `hdvideo_ddz_results` (`room_id`,`uid`,`role`,`is_winner`,`delta`,`base`,`multiplier`,`created_at`) VALUES (%d,%d,%s,%d,%s,%d,%d,%s)",
                (int)$room['id'], $uid, sqlesc($roleKey), $isWin, sqlesc(number_format($d, 1, '.', '')), $base, $mult, sqlesc($now)
            )) or sqlerr(__FILE__, __LINE__);
            if ($d == 0) continue;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(number_format($d, 1, '.', '')) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            $role = ($i === $landlord) ? '地主' : '农民';
            $comment = "[斗地主] 桌#{$room['id']} {$role} " . ($d > 0 ? '赢' : '输') . " " . abs($d) . "（倍数{$mult}）";
            sql_query(sprintf(
                "INSERT INTO bonus_logs (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
                DDZ_BUSINESS_TYPE, $uid,
                sqlesc(number_format($bal[$i], 1, '.', '')),
                sqlesc(number_format($d, 1, '.', '')),
                sqlesc(number_format($bal[$i] + $d, 1, '.', '')),
                sqlesc($comment), sqlesc($now), sqlesc($now)
            )) or sqlerr(__FILE__, __LINE__);
            clear_user_cache($uid);
        }
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        throw $e;
    }
    return ['deltas' => $deltas, 'amt' => $amt];
}

function ddz_finish(&$room, $winnerSeat)
{
    $landlord = (int)$room['landlord'];
    $landlordWon = ($winnerSeat === $landlord);
    $mult = max(1, (int)$room['multiplier']);
    $bombs = (int)($room['bombs'] ?? 0);
    $mult *= (1 << min($bombs, 16));
    $plays = $room['plays'] ?? [0, 0, 0];
    $spring = false;
    if ($landlordWon) {
        $allZero = true;
        for ($i = 0; $i < 3; $i++) {
            if ($i !== $landlord && (int)($plays[$i] ?? 0) > 0) $allZero = false;
        }
        if ($allZero) { $spring = true; $mult *= 2; }
    } else {
        if ((int)($plays[$landlord] ?? 0) <= 1) { $spring = true; $mult *= 2; }
    }
    $mult = min($mult, DDZ_MULT_CAP);
    $room['status'] = 'finished';
    $room['winner'] = $winnerSeat;
    $room['landlordWon'] = $landlordWon;
    $room['finalMult'] = $mult;
    $room['spring'] = $spring;
    $room['settled'] = ddz_settle($room, $landlordWon, $mult);
    $room['updated_at'] = TIMENOW;
    ddz_redis()->sRem(ddz_lobby_key(), $room['id']);
}

function ddz_create_table($base)
{
    global $CURUSER;
    $base = (int)$base;
    if (!in_array($base, DDZ_BASE_OPTIONS, true)) return [null, "底分无效。"];
    $need = $base * DDZ_JOIN_BALANCE_FACTOR;
    if ((float)$CURUSER['seedbonus'] < $need) return [null, "余额不足，该底分桌需至少 {$need} 张电影票。"];
    $id = (int)ddz_redis()->incr("ddz:next_id");
    $room = [
        'id' => $id, 'base' => $base, 'status' => 'waiting', 'owner' => (int)$CURUSER['id'],
        'seats' => [['uid' => (int)$CURUSER['id'], 'username' => $CURUSER['username'], 'avatar' => (string)($CURUSER['avatar'] ?? '')], null, null],
        'created_at' => TIMENOW, 'updated_at' => TIMENOW,
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
    if (!$token) return [null, "操作繁忙，请重试。"];
    try {
        $room = ddz_room_get($id);
        if (!$room) return [null, "桌子不存在或已解散。"];
        if (ddz_seat_of($room, $CURUSER['id']) >= 0) return [$id, ""];
        if ($room['status'] !== 'waiting') return [null, "该桌已开始，无法加入。"];
        if (ddz_player_count($room) >= DDZ_SEATS) return [null, "该桌已满。"];
        $need = $room['base'] * DDZ_JOIN_BALANCE_FACTOR;
        if ((float)$CURUSER['seedbonus'] < $need) return [null, "余额不足，该底分桌需至少 {$need} 张电影票。"];
        foreach ($room['seats'] as $i => $s) {
            if (!$s) { $room['seats'][$i] = ['uid' => (int)$CURUSER['id'], 'username' => $CURUSER['username'], 'avatar' => (string)($CURUSER['avatar'] ?? '')]; break; }
        }
        $room['updated_at'] = TIMENOW;
        if (ddz_player_count($room) >= DDZ_SEATS) {
            ddz_redis()->sRem(ddz_lobby_key(), $id);
            ddz_start_game($room);
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
    if (!$token) return "操作繁忙，请重试。";
    try {
        $room = ddz_room_get($id);
        if (!$room) return "";
        $seat = ddz_seat_of($room, $CURUSER['id']);
        if ($seat < 0) return "";
        if ($room['status'] === 'playing' || $room['status'] === 'bidding') {
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
        // finished or waiting -> back to waiting
        $room['status'] = 'waiting';
        ddz_redis()->sAdd(ddz_lobby_key(), $id);
        ddz_room_put($room);
        return "";
    } finally {
        ddz_unlock($id, $token);
    }
}

function ddz_do_bid(&$room, $seat, $score)
{
    $score = (int)$score;
    if ($score < 0 || $score > 3) return "无效叫分。";
    if ($score > 0 && $score <= (int)$room['bid']['high']) return "叫分必须高于当前最高分。";
    $room['bid']['bids'][$seat] = $score;
    if ($score > 0) { $room['bid']['high'] = $score; $room['bid']['highSeat'] = $seat; }
    $room['bid']['acted']++;
    $room['updated_at'] = TIMENOW;
    if ($score === 3 || $room['bid']['acted'] >= 3) {
        if ((int)$room['bid']['highSeat'] >= 0) {
            ddz_set_landlord($room, (int)$room['bid']['highSeat']);
        } else {
            ddz_start_game($room);
        }
    } else {
        $room['bid']['turn'] = ((int)$room['bid']['turn'] + 1) % 3;
        ddz_set_deadline($room);
    }
    return "";
}

function ddz_bid($id, $score)
{
    global $CURUSER;
    $id = (int)$id;
    $token = ddz_lock($id);
    if (!$token) return "操作繁忙，请重试。";
    try {
        $room = ddz_room_get($id);
        if (!$room || $room['status'] !== 'bidding') return "现在不能叫分。";
        $seat = ddz_seat_of($room, $CURUSER['id']);
        if ($seat < 0) return "你不在这桌。";
        if ($seat !== (int)$room['bid']['turn']) return "还没轮到你叫分。";
        $err = ddz_do_bid($room, $seat, $score);
        if ($err === '') ddz_room_put($room);
        return $err;
    } finally {
        ddz_unlock($id, $token);
    }
}

function ddz_do_play(&$room, $seat, $cardIds)
{
    $cardIds = array_values(array_unique(array_map('intval', $cardIds)));
    if (!$cardIds) return "请选择要出的牌。";
    $handCopy = $room['hands'][$seat];
    foreach ($cardIds as $c) {
        $k = array_search($c, $handCopy, true);
        if ($k === false) return "选牌不在你手里。";
        unset($handCopy[$k]);
    }
    $play = ddz_classify($cardIds);
    if (!$play) return "牌型不合法。";
    $last = $room['lastPlay'] ?? null;
    $lastSeat = (int)($room['lastSeat'] ?? -1);
    $leading = ($last === null) || ($lastSeat === $seat);
    if (!$leading && !ddz_can_beat($play, $last)) return "管不上，请换牌或过牌。";
    $room['hands'][$seat] = array_values($handCopy);
    $play['cards'] = $cardIds;
    $room['lastPlay'] = $play;
    $room['lastSeat'] = $seat;
    $room['plays'][$seat] = (int)($room['plays'][$seat] ?? 0) + 1;
    if ($play['type'] === 'bomb' || $play['type'] === 'rocket') {
        $room['bombs'] = (int)($room['bombs'] ?? 0) + 1;
    }
    $room['updated_at'] = TIMENOW;
    if (count($room['hands'][$seat]) === 0) {
        ddz_finish($room, $seat);
    } else {
        $room['turn'] = ((int)$room['turn'] + 1) % 3;
        ddz_set_deadline($room);
    }
    return "";
}

function ddz_play($id, $cardIds)
{
    global $CURUSER;
    $id = (int)$id;
    if (!is_array($cardIds)) $cardIds = [];
    $token = ddz_lock($id);
    if (!$token) return "操作繁忙，请重试。";
    try {
        $room = ddz_room_get($id);
        if (!$room || $room['status'] !== 'playing') return "现在不能出牌。";
        $seat = ddz_seat_of($room, $CURUSER['id']);
        if ($seat < 0) return "你不在这桌。";
        if ($seat !== (int)$room['turn']) return "还没轮到你。";
        $err = ddz_do_play($room, $seat, $cardIds);
        if ($err === '') ddz_room_put($room);
        return $err;
    } finally {
        ddz_unlock($id, $token);
    }
}

function ddz_do_pass(&$room, $seat)
{
    $lastSeat = (int)($room['lastSeat'] ?? -1);
    if (($room['lastPlay'] ?? null) === null || $lastSeat === $seat) return "你是首出/上家无人压，必须出牌。";
    $room['turn'] = ((int)$room['turn'] + 1) % 3;
    $room['updated_at'] = TIMENOW;
    ddz_set_deadline($room);
    return "";
}

function ddz_pass($id)
{
    global $CURUSER;
    $id = (int)$id;
    $token = ddz_lock($id);
    if (!$token) return "操作繁忙，请重试。";
    try {
        $room = ddz_room_get($id);
        if (!$room || $room['status'] !== 'playing') return "现在不能过牌。";
        $seat = ddz_seat_of($room, $CURUSER['id']);
        if ($seat < 0) return "你不在这桌。";
        if ($seat !== (int)$room['turn']) return "还没轮到你。";
        $err = ddz_do_pass($room, $seat);
        if ($err === '') ddz_room_put($room);
        return $err;
    } finally {
        ddz_unlock($id, $token);
    }
}

/**
 * Auto-action for the current player on turn timeout (no bots): bidding -> 不叫;
 * playing -> 过牌 if possible, else play the smallest single card.
 */
function ddz_auto_action(&$room)
{
    if ($room['status'] === 'bidding') {
        ddz_do_bid($room, (int)$room['bid']['turn'], 0);
        return;
    }
    if ($room['status'] === 'playing') {
        $seat = (int)$room['turn'];
        $lastSeat = (int)($room['lastSeat'] ?? -1);
        $leading = (($room['lastPlay'] ?? null) === null) || ($lastSeat === $seat);
        if ($leading) {
            $hand = $room['hands'][$seat];
            $card = $hand[count($hand) - 1]; // sorted high->low, last = smallest
            ddz_do_play($room, $seat, [$card]);
        } else {
            ddz_do_pass($room, $seat);
        }
    }
}

function ddz_handle_timeout($id)
{
    $token = ddz_lock($id, 5);
    if (!$token) return;
    try {
        $room = ddz_room_get($id);
        if (!$room || !in_array($room['status'], ['bidding', 'playing'], true)) return;
        if (!isset($room['deadline']) || TIMENOW < (int)$room['deadline']) return; // recheck inside lock
        ddz_auto_action($room);
        ddz_room_put($room);
    } finally {
        ddz_unlock($id, $token);
    }
}

function ddz_replay($id)
{
    global $CURUSER;
    $id = (int)$id;
    $token = ddz_lock($id);
    if (!$token) return "操作繁忙，请重试。";
    try {
        $room = ddz_room_get($id);
        if (!$room) return "桌子不存在。";
        if (ddz_seat_of($room, $CURUSER['id']) < 0) return "你不在这桌。";
        if ($room['status'] !== 'finished') return "本局未结束。";
        if (ddz_player_count($room) < DDZ_SEATS) return "人数不足，无法再来一局。";
        ddz_start_game($room);
        ddz_room_put($room);
        return "";
    } finally {
        ddz_unlock($id, $token);
    }
}

// ---------- 匹配 + 机器人 ----------
function ddz_seat_is_bot($room, $seat)
{
    $s = $room['seats'][$seat] ?? null;
    return $s && !empty($s['bot']);
}

/**
 * 经典场匹配：优先加入同底分、有空位的匹配桌；没有则新建一张匹配桌（10 秒后补机器人）。
 */
function ddz_matchmake($base)
{
    global $CURUSER;
    $base = (int)$base;
    if (!in_array($base, DDZ_BASE_OPTIONS, true)) return [null, "底分无效。"];
    $need = $base * DDZ_JOIN_BALANCE_FACTOR;
    if ((float)$CURUSER['seedbonus'] < $need) return [null, "余额不足，该底分桌需至少 {$need} 张电影票。"];

    // 已在某张桌则直接回去
    $ids = ddz_redis()->sMembers(ddz_lobby_key()) ?: [];
    $cands = [];
    foreach ($ids as $id) {
        $r = ddz_room_get($id);
        if (!$r) { ddz_redis()->sRem(ddz_lobby_key(), $id); continue; }
        if (ddz_seat_of($r, $CURUSER['id']) >= 0 && $r['status'] === 'waiting') return [(int)$r['id'], ""];
        if (!empty($r['mm']) && $r['status'] === 'waiting' && (int)$r['base'] === $base
            && ddz_player_count($r) < DDZ_SEATS) {
            $cands[] = $r;
        }
    }
    usort($cands, fn($a, $b) => $a['created_at'] <=> $b['created_at']);
    foreach ($cands as $c) {
        [$jid, $jerr] = ddz_join_table($c['id']);
        if ($jid) return [$jid, ""];
    }

    // 新建匹配桌
    $id = (int)ddz_redis()->incr("ddz:next_id");
    $room = [
        'id' => $id, 'base' => $base, 'status' => 'waiting', 'owner' => (int)$CURUSER['id'],
        'mm' => true, 'field' => 'classic', 'mm_deadline' => TIMENOW + DDZ_MATCH_WAIT,
        'seats' => [['uid' => (int)$CURUSER['id'], 'username' => $CURUSER['username'], 'avatar' => (string)($CURUSER['avatar'] ?? '')], null, null],
        'created_at' => TIMENOW, 'updated_at' => TIMENOW,
    ];
    ddz_room_put($room);
    ddz_redis()->sAdd(ddz_lobby_key(), $id);
    return [$id, ""];
}

function ddz_fill_bots_and_start($id)
{
    $token = ddz_lock($id);
    if (!$token) return;
    try {
        $room = ddz_room_get($id);
        if (!$room || $room['status'] !== 'waiting') return;
        if (ddz_player_count($room) >= DDZ_SEATS) return;
        $names = DDZ_BOT_NAMES;
        shuffle($names);
        $bi = 0;
        foreach ($room['seats'] as $i => $s) {
            if (!$s) {
                $room['seats'][$i] = ['uid' => -($i + 1), 'username' => '机器人·' . $names[$bi % count($names)], 'bot' => true];
                $bi++;
            }
        }
        ddz_redis()->sRem(ddz_lobby_key(), $id);
        ddz_start_game($room);
        ddz_room_put($room);
    } finally {
        ddz_unlock($id, $token);
    }
}

/** 机器人行动一步（叫分或出牌）。每次轮询驱动一步，形成自然节奏。 */
function ddz_bot_step($id)
{
    $token = ddz_lock($id, 5);
    if (!$token) return;
    try {
        $room = ddz_room_get($id);
        if (!$room) return;
        if ($room['status'] === 'bidding') {
            $seat = (int)$room['bid']['turn'];
            if (!ddz_seat_is_bot($room, $seat)) return;
            ddz_bot_bid($room, $seat);
            ddz_room_put($room);
        } elseif ($room['status'] === 'playing') {
            $seat = (int)$room['turn'];
            if (!ddz_seat_is_bot($room, $seat)) return;
            ddz_bot_play($room, $seat);
            ddz_room_put($room);
        }
    } finally {
        ddz_unlock($id, $token);
    }
}

function ddz_bot_bid(&$room, $seat)
{
    $hand = $room['hands'][$seat];
    $counts = array_count_values(array_map('ddz_card_rank', $hand));
    $score = 0;
    $hasS = isset($counts[13]); $hasB = isset($counts[14]);
    if ($hasS && $hasB) $score += 7; else { if ($hasB) $score += 3.5; if ($hasS) $score += 2.5; }
    foreach ($counts as $r => $c) {
        if ($c == 4) $score += 6;          // 炸弹
        if ($r == 12) $score += $c * 1.6;  // 2
        if ($r == 11) $score += $c * 0.8;  // A
    }
    $high = (int)$room['bid']['high'];
    $want = $score >= 9 ? 3 : ($score >= 5.5 ? 2 : ($score >= 3 ? 1 : 0));
    if ($want > 0 && $want <= $high) $want = 0; // 不能压过则不叫
    ddz_do_bid($room, $seat, $want);
}

function ddz_bot_play(&$room, $seat)
{
    $hand = $room['hands'][$seat];
    $last = $room['lastPlay'] ?? null;
    $lastSeat = (int)($room['lastSeat'] ?? -1);
    $landlord = (int)$room['landlord'];
    $leading = ($last === null) || ($lastSeat === $seat);

    if ($leading) {
        ddz_do_play($room, $seat, ddz_bot_lead($hand));
        return;
    }
    // 队友（双农民）之间不互压
    $iAmFarmer = ($seat !== $landlord);
    $lastIsFarmer = ($lastSeat >= 0 && $lastSeat !== $landlord);
    if ($iAmFarmer && $lastIsFarmer) { ddz_do_pass($room, $seat); return; }

    $minOpp = 99;
    foreach ($room['hands'] as $i => $h) {
        if ($i !== $seat) $minOpp = min($minOpp, count($h));
    }
    $ids = ddz_find_beat($hand, $last, false);
    if (!$ids && $minOpp <= 2) $ids = ddz_find_beat($hand, $last, true); // 对手快走完才动炸弹
    if ($ids) ddz_do_play($room, $seat, $ids); else ddz_do_pass($room, $seat);
}

function ddz_bot_lead($hand)
{
    if (ddz_classify($hand)) return $hand;     // 整手能一次走完就直接走完
    return [$hand[count($hand) - 1]];           // 否则甩最小单张（已按大→小排序）
}

function ddz_pick_extra($byRank, $used, $k)
{
    $usedSet = array_flip(array_map('intval', $used));
    $res = [];
    foreach ($byRank as $r => $cs) {
        if ($r >= 13) continue;
        if (count($cs) == 4) continue; // 不拆炸弹
        foreach ($cs as $c) {
            if (isset($usedSet[$c])) continue;
            $res[] = $c;
            if (count($res) >= $k) return array_slice($res, 0, $k);
        }
    }
    return count($res) >= $k ? array_slice($res, 0, $k) : null;
}

function ddz_pick_pair_extra($byRank, $used)
{
    $usedRank = ddz_card_rank($used[0]);
    foreach ($byRank as $r => $cs) {
        if ($r == $usedRank || $r >= 13) continue;
        if (count($cs) >= 2 && count($cs) != 4) return array_slice($cs, 0, 2);
    }
    return null;
}

function ddz_pick_straight($byRank, $len, $main, $per)
{
    for ($start = 0; $start + $len - 1 <= 11; $start++) {
        $top = $start + $len - 1;
        if ($top <= $main) continue;
        $ok = true; $cards = [];
        for ($r = $start; $r <= $top; $r++) {
            if (!isset($byRank[$r]) || count($byRank[$r]) < $per) { $ok = false; break; }
            $cards = array_merge($cards, array_slice($byRank[$r], 0, $per));
        }
        if ($ok) return $cards;
    }
    return null;
}

/** 找出能压制 $last 的最小牌组（card id 数组），找不到返回 null。$allowBomb 控制是否动炸弹/火箭。 */
function ddz_find_beat($hand, $last, $allowBomb)
{
    $type = $last['type']; $n = (int)$last['n']; $main = $last['main'];
    $byRank = [];
    foreach ($hand as $c) { $byRank[ddz_card_rank($c)][] = $c; }
    ksort($byRank);
    $hasJ13 = isset($byRank[13]); $hasJ14 = isset($byRank[14]);

    $pick = function ($k, $minRank) use ($byRank) {
        foreach ($byRank as $r => $cs) {
            if ($r <= $minRank || $r >= 13) continue;
            if (count($cs) == 4) continue; // 保留炸弹
            if (count($cs) >= $k) return array_slice($cs, 0, $k);
        }
        return null;
    };

    $found = null;
    if ($type === 'single') {
        $found = $pick(1, $main);
        if (!$found && $main < 13 && $hasJ13) $found = [$byRank[13][0]];
    } elseif ($type === 'pair') {
        $found = $pick(2, $main);
    } elseif ($type === 'triple') {
        $found = $pick(3, $main);
    } elseif ($type === 'triple1') {
        $t = $pick(3, $main);
        if ($t) { $e = ddz_pick_extra($byRank, $t, 1); if ($e) $found = array_merge($t, $e); }
    } elseif ($type === 'triple2') {
        $t = $pick(3, $main);
        if ($t) { $e = ddz_pick_pair_extra($byRank, $t); if ($e) $found = array_merge($t, $e); }
    } elseif ($type === 'straight') {
        $found = ddz_pick_straight($byRank, $n, $main, 1);
    } elseif ($type === 'pairs') {
        $found = ddz_pick_straight($byRank, intdiv($n, 2), $main, 2);
    } elseif ($type === 'four2') {
        foreach ($byRank as $r => $cs) {
            if ($r > $main && count($cs) == 4) {
                $e = ddz_pick_extra($byRank, $cs, 2);
                if ($e) { $found = array_merge($cs, $e); break; }
            }
        }
    } elseif ($type === 'bomb') {
        foreach ($byRank as $r => $cs) { if ($r > $main && count($cs) == 4) { $found = $cs; break; } }
        if (!$found && $hasJ13 && $hasJ14) $found = [$byRank[13][0], $byRank[14][0]];
        return $found;
    } elseif ($type === 'rocket') {
        return null;
    }
    if ($found) return $found;
    if (!$allowBomb) return null;
    foreach ($byRank as $r => $cs) { if (count($cs) == 4) return $cs; } // 炸弹压杂牌
    if ($hasJ13 && $hasJ14) return [$byRank[13][0], $byRank[14][0]];     // 火箭
    return null;
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

function ddz_ensure_tables()
{
    static $done = false;
    if ($done) return;
    @sql_query("
        CREATE TABLE IF NOT EXISTS `hdvideo_ddz_results` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `room_id` int unsigned NOT NULL,
            `uid` int unsigned NOT NULL,
            `role` enum('landlord','farmer') NOT NULL,
            `is_winner` tinyint(1) NOT NULL DEFAULT 0,
            `delta` decimal(20,1) NOT NULL DEFAULT '0.0',
            `base` int unsigned NOT NULL DEFAULT 0,
            `multiplier` int unsigned NOT NULL DEFAULT 1,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_uid` (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function ddz_points($v, $signed = false)
{
    $v = (float)$v;
    $s = ($signed && $v > 0) ? '+' : '';
    return $s . number_format(round($v), 0);
}

function ddz_leaderboard($orderBy, $limit, $minGames, $havingExtra = '')
{
    ddz_ensure_tables();
    $extra = $havingExtra !== '' ? " AND ($havingExtra)" : '';
    $sql = "SELECT r.`uid`, u.`username`,
            COUNT(*) AS games,
            SUM(r.`is_winner`) AS wins,
            SUM(CASE WHEN r.`role` = 'landlord' THEN 1 ELSE 0 END) AS lord_games,
            SUM(r.`delta`) AS net
        FROM `hdvideo_ddz_results` r INNER JOIN `users` u ON u.`id` = r.`uid`
        GROUP BY r.`uid`, u.`username`
        HAVING games >= " . (int)$minGames . $extra . "
        ORDER BY $orderBy
        LIMIT " . (int)$limit;
    $res = sql_query($sql) or sqlerr(__FILE__, __LINE__);
    $rows = [];
    while ($r = mysql_fetch_assoc($res)) $rows[] = $r;
    return $rows;
}

function ddz_my_stats($uid)
{
    ddz_ensure_tables();
    $res = sql_query("SELECT COUNT(*) AS games, SUM(`is_winner`) AS wins, SUM(`delta`) AS net,
            SUM(CASE WHEN `role`='landlord' THEN 1 ELSE 0 END) AS lord
        FROM `hdvideo_ddz_results` WHERE `uid` = " . (int)$uid) or sqlerr(__FILE__, __LINE__);
    $r = mysql_fetch_assoc($res);
    $games = (int)($r['games'] ?? 0);
    $wins = (int)($r['wins'] ?? 0);
    return ['games' => $games, 'wins' => $wins, 'losses' => $games - $wins, 'net' => (float)($r['net'] ?? 0), 'lord' => (int)($r['lord'] ?? 0)];
}

function ddz_subnav($active)
{
    $items = ['' => '大厅', 'ranking' => '战绩榜', 'pnl' => '盈亏榜'];
    $out = '<div style="display:flex;flex-wrap:wrap;gap:2px;margin-bottom:16px;border-bottom:1px solid rgba(120,150,190,.3)">';
    foreach ($items as $k => $label) {
        $url = $k === '' ? '/games/ddz/' : '/games/ddz/?view=' . $k;
        $color = $k === $active ? '#2ecc71' : '#6f7f95';
        $border = $k === $active ? '3px solid #2ecc71' : '3px solid transparent';
        $out .= '<a href="' . $url . '" style="padding:9px 14px;font-weight:700;text-decoration:none;color:' . $color . ';border-bottom:' . $border . '">' . $label . '</a>';
    }
    return $out . '</div>';
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
    // 匹配超时：补机器人开局
    if ($room['status'] === 'waiting' && !empty($room['mm']) && isset($room['mm_deadline'])
        && TIMENOW >= (int)$room['mm_deadline'] && ddz_player_count($room) < DDZ_SEATS) {
        ddz_fill_bots_and_start($id);
        $room = ddz_room_get($id);
        if (!$room) { echo json_encode(['ok' => false, 'gone' => true]); exit; }
    }
    if (in_array($room['status'], ['bidding', 'playing'], true) && isset($room['deadline']) && TIMENOW >= (int)$room['deadline']) {
        ddz_handle_timeout($id);
        $room = ddz_room_get($id);
        if (!$room) {
            echo json_encode(['ok' => false, 'gone' => true]);
            exit;
        }
    }
    // 轮到机器人则驱动一步（每次轮询一步，节奏自然）
    if (in_array($room['status'], ['bidding', 'playing'], true)) {
        $turnSeat = $room['status'] === 'bidding' ? (int)$room['bid']['turn'] : (int)$room['turn'];
        if (ddz_seat_is_bot($room, $turnSeat)) {
            ddz_bot_step($id);
            $room = ddz_room_get($id);
            if (!$room) { echo json_encode(['ok' => false, 'gone' => true]); exit; }
        }
    }
    $mySeat = ddz_seat_of($room, $CURUSER['id']);
    $out = [
        'ok' => true, 'status' => $room['status'], 'base' => (int)$room['base'],
        'mySeat' => $mySeat, 'count' => ddz_player_count($room), 'seats' => [],
        'mm' => !empty($room['mm']),
        'mmLeft' => (!empty($room['mm']) && isset($room['mm_deadline'])) ? max(0, (int)$room['mm_deadline'] - TIMENOW) : null,
    ];
    foreach ($room['seats'] as $i => $s) {
        $out['seats'][$i] = $s ? ['username' => $s['username'], 'avatar' => (string)($s['avatar'] ?? ''), 'bot' => !empty($s['bot']), 'cards' => isset($room['hands'][$i]) ? count($room['hands'][$i]) : 0] : null;
    }
    if (in_array($room['status'], ['bidding', 'playing', 'finished'], true)) {
        $out['bid'] = $room['bid'] ?? null;
        $out['landlord'] = (int)($room['landlord'] ?? -1);
        $out['multiplier'] = (int)($room['multiplier'] ?? 1);
        $out['turn'] = (int)($room['turn'] ?? -1);
        $out['lastSeat'] = (int)($room['lastSeat'] ?? -1);
        $out['timeLeft'] = (in_array($room['status'], ['bidding', 'playing'], true) && isset($room['deadline'])) ? max(0, (int)$room['deadline'] - TIMENOW) : null;
        if ($mySeat >= 0 && isset($room['hands'][$mySeat])) {
            $out['myHand'] = array_map(fn($c) => ['id' => $c, 'label' => ddz_card_label($c), 'red' => ddz_card_red($c)], $room['hands'][$mySeat]);
        }
        $lp = $room['lastPlay'] ?? null;
        $out['lastPlay'] = ($lp && isset($lp['cards'])) ? [
            'seat' => (int)($room['lastSeat'] ?? -1),
            'cards' => array_map(fn($c) => ['label' => ddz_card_label($c), 'red' => ddz_card_red($c)], $lp['cards']),
        ] : null;
        if ($room['status'] !== 'bidding' && isset($room['bottom'])) {
            $out['bottom'] = array_map(fn($c) => ['label' => ddz_card_label($c), 'red' => ddz_card_red($c)], $room['bottom']);
        }
        if ($room['status'] === 'finished') {
            $out['winner'] = (int)($room['winner'] ?? -1);
            $out['landlordWon'] = (bool)($room['landlordWon'] ?? false);
            $out['finalMult'] = (int)($room['finalMult'] ?? 1);
            $out['spring'] = (bool)($room['spring'] ?? false);
            $out['deltas'] = $room['settled']['deltas'] ?? [0, 0, 0];
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 提示：返回一手建议出牌 ----
if (($_GET['ajax'] ?? '') === 'hint') {
    header('Content-Type: application/json');
    $id = (int)($_GET['table'] ?? 0);
    $room = ddz_room_get($id);
    $seat = $room ? ddz_seat_of($room, $CURUSER['id']) : -1;
    if (!$room || $room['status'] !== 'playing' || $seat < 0 || (int)$room['turn'] !== $seat) {
        echo json_encode(['ok' => false, 'cards' => []]);
        exit;
    }
    $hand = $room['hands'][$seat];
    $last = $room['lastPlay'] ?? null;
    $lastSeat = (int)($room['lastSeat'] ?? -1);
    $leading = ($last === null) || ($lastSeat === $seat);
    if ($leading) {
        $cards = [$hand[count($hand) - 1]]; // 最小单张
    } else {
        $cards = ddz_find_beat($hand, $last, true) ?: [];
    }
    echo json_encode(['ok' => (bool)$cards, 'cards' => array_values(array_map('intval', $cards))], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ajaxActions = ['bid', 'play', 'pass', 'replay'];
    if (in_array($action, $ajaxActions, true)) {
        header('Content-Type: application/json');
        if ($action === 'bid') {
            $err = ddz_bid($_POST['table'] ?? 0, $_POST['score'] ?? -1);
        } elseif ($action === 'play') {
            $cards = $_POST['cards'] ?? '';
            $cardIds = $cards === '' ? [] : array_map('intval', explode(',', $cards));
            $err = ddz_play($_POST['table'] ?? 0, $cardIds);
        } elseif ($action === 'pass') {
            $err = ddz_pass($_POST['table'] ?? 0);
        } else {
            $err = ddz_replay($_POST['table'] ?? 0);
        }
        echo json_encode(['ok' => $err === '', 'error' => $err], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $error = '';
    $goTable = 0;
    if ($action === 'create') {
        [$id, $error] = ddz_create_table($_POST['base'] ?? 0);
        if ($id) $goTable = $id;
    } elseif ($action === 'match') {
        [$id, $error] = ddz_matchmake($_POST['base'] ?? 0);
        if ($id) $goTable = $id;
    } elseif ($action === 'join') {
        [$id, $error] = ddz_join_table($_POST['table'] ?? 0);
        if ($id) $goTable = $id;
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
$view = $_GET['view'] ?? '';
$tableId = (int)($_GET['table'] ?? 0);
$room = $tableId ? ddz_room_get($tableId) : null;
$mySeat = $room ? ddz_seat_of($room, $CURUSER['id']) : -1;
$inRoom = $mySeat >= 0;

// 大厅（未进桌、非子页、非轮询）走独立的全屏「游戏中心」式页面（电脑+手机自适应）。
if ($view === '' && $tableId === 0 && empty($_GET['ajax'])) {
    require __DIR__ . '/lobby.php';
    exit;
}

// 进桌（等待/匹配/叫分/出牌/结算）走全屏「欢乐斗地主」式牌桌（电脑+手机自适应）。
if ($tableId && $room && $view === '' && empty($_GET['ajax'])) {
    require __DIR__ . '/table.php';
    exit;
}

stdhead("斗地主");
echo game_back_link();
?>
<style>
.ddz-wrap { max-width: 940px; margin: 0 auto; }
.ddz-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.ddz-title { font-size: 24px; font-weight: 800; }
.ddz-badge { font-size: 12px; font-weight: 700; color: #e67e22; background: rgba(230,126,34,.12); padding: 2px 8px; border-radius: 999px; vertical-align: middle; }
.ddz-balance { font-size: 14px; font-weight: 700; }
.ddz-muted { color: #6f7f95; }
.ddz-message { padding: 10px 12px; border-radius: 6px; margin-bottom: 14px; font-weight: 700; background: rgba(220,60,70,.14); color: #c02432; }
.ddz-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 16px; margin-bottom: 14px; background: rgba(30,60,100,.06); }
.ddz-section-title { font-size: 18px; font-weight: 800; margin: 8px 0 12px; }
.ddz-table-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 10px 12px; border: 1px solid rgba(120,150,190,.26); border-radius: 8px; margin-bottom: 8px; }
.ddz-table { width: 100%; border-collapse: collapse; }
.ddz-table th, .ddz-table td { padding: 8px; border: 1px solid rgba(120,150,190,.26); text-align: center; }
.ddz-pos { color: #16a34a; font-weight: 700; }
.ddz-neg { color: #dc2626; font-weight: 700; }
.ddz-mystat { display: flex; gap: 16px; flex-wrap: wrap; padding: 10px 14px; background: rgba(0,0,0,.04); border-radius: 6px; margin-bottom: 14px; font-weight: 700; }
.ddz-tab2 { display: inline-block; padding: 6px 14px; border: 1px solid rgba(120,150,190,.45); border-radius: 999px; cursor: pointer; font-weight: 700; background: rgba(0,0,0,.03); margin-right: 8px; }
.ddz-tab2.is-active { background: #2ecc71; color: #fff; border-color: #2ecc71; }
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
.ddz-center { min-height: 56px; padding: 8px; margin: 8px 0; border-radius: 8px; background: rgba(0,0,0,.04); display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ddz-card { display: inline-flex; align-items: center; justify-content: center; min-width: 30px; height: 40px; padding: 0 4px; border: 1px solid #c9ced6; border-radius: 5px; background: #fff; color: #222; font-weight: 800; font-size: 14px; box-shadow: 0 1px 2px rgba(0,0,0,.12); }
.ddz-card.red { color: #d62828; }
.ddz-hand { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px; }
.ddz-hand .ddz-card { cursor: pointer; transition: transform .08s ease; }
.ddz-hand .ddz-card.sel { transform: translateY(-10px); border-color: #2ecc71; box-shadow: 0 4px 8px rgba(46,204,113,.4); }
.ddz-actions { margin: 12px 0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.ddz-result { font-size: 17px; font-weight: 800; padding: 10px 12px; border-radius: 8px; background: rgba(46,204,113,.14); margin: 10px 0; }
.ddz-invite { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 10px; }
.ddz-invite input { padding: 8px; width: 320px; max-width: 100%; }
</style>
<div class="ddz-wrap">
    <div class="ddz-head">
        <div>
            <div class="ddz-title">斗地主 <span class="ddz-badge">内测中 v0.1</span></div>
            <div class="ddz-muted">三人对战，满 3 人或匹配超时补机器人开局。用电影票计分（结算时扣）。</div>
        </div>
        <div class="ddz-balance">我的电影票：<?php echo number_format((float)$CURUSER['seedbonus'], 1) ?> 张</div>
    </div>

    <?php if ($error) { ?><div class="ddz-message"><?php echo htmlspecialchars($error) ?></div><?php } ?>

    <?php if ($view === 'ranking') {
        $rows = ddz_leaderboard('games DESC, net DESC', 100, 1);
    ?>
        <?php echo ddz_subnav('ranking'); ?>
        <div class="ddz-section-title">战绩榜（按局数）</div>
        <div class="ddz-panel">
            <table class="ddz-table">
                <tr><th>排名</th><th>玩家</th><th>局数</th><th>胜</th><th>负</th><th>胜率</th><th>当地主</th><th>净盈亏</th></tr>
                <?php foreach ($rows as $i => $r) {
                    $g = (int)$r['games']; $w = (int)$r['wins']; $l = $g - $w;
                    $rate = $g > 0 ? round($w * 100 / $g) . '%' : '-';
                ?>
                    <tr>
                        <td>#<?php echo $i + 1 ?></td>
                        <td><a href="userdetails.php?id=<?php echo (int)$r['uid'] ?>"><?php echo htmlspecialchars($r['username']) ?></a></td>
                        <td><?php echo $g ?></td>
                        <td><?php echo $w ?></td>
                        <td><?php echo $l ?></td>
                        <td><?php echo $rate ?></td>
                        <td><?php echo (int)$r['lord_games'] ?></td>
                        <td class="<?php echo (float)$r['net'] >= 0 ? 'ddz-pos' : 'ddz-neg' ?>"><?php echo ddz_points($r['net'], true) ?></td>
                    </tr>
                <?php } ?>
                <?php if (!$rows) { ?><tr><td colspan="8" class="ddz-muted">暂无战绩。</td></tr><?php } ?>
            </table>
        </div>
    <?php } elseif ($view === 'pnl') {
        $win = ddz_leaderboard('net DESC', 50, 1, 'net > 0');
        $lose = ddz_leaderboard('net ASC', 50, 1, 'net < 0');
        $my = ddz_my_stats((int)$CURUSER['id']);
    ?>
        <?php echo ddz_subnav('pnl'); ?>
        <div class="ddz-section-title">盈亏榜</div>
        <div class="ddz-mystat">
            <span>我的战绩</span>
            <span>局数：<?php echo $my['games'] ?></span>
            <span>胜：<?php echo $my['wins'] ?></span>
            <span>负：<?php echo $my['losses'] ?></span>
            <span>净：<span class="<?php echo $my['net'] >= 0 ? 'ddz-pos' : 'ddz-neg' ?>"><?php echo ddz_points($my['net'], true) ?></span></span>
        </div>
        <div id="ddzPnlTabs" style="margin-bottom:12px">
            <span class="ddz-tab2 is-active" data-pnl="win">🏆 胜榜·总盈利</span>
            <span class="ddz-tab2" data-pnl="lose">💸 负榜·总亏损</span>
        </div>
        <div class="ddz-panel">
            <table class="ddz-table" id="ddzPnlWin">
                <tr><th>排名</th><th>玩家</th><th>胜场</th><th>总盈利</th></tr>
                <?php foreach ($win as $i => $r) { ?>
                    <tr><td>#<?php echo $i + 1 ?></td><td><a href="userdetails.php?id=<?php echo (int)$r['uid'] ?>"><?php echo htmlspecialchars($r['username']) ?></a></td><td><?php echo (int)$r['wins'] ?></td><td class="ddz-pos"><?php echo ddz_points($r['net'], true) ?></td></tr>
                <?php } ?>
                <?php if (!$win) { ?><tr><td colspan="4" class="ddz-muted">暂无。</td></tr><?php } ?>
            </table>
            <table class="ddz-table" id="ddzPnlLose" style="display:none">
                <tr><th>排名</th><th>玩家</th><th>胜场</th><th>总亏损</th></tr>
                <?php foreach ($lose as $i => $r) { ?>
                    <tr><td>#<?php echo $i + 1 ?></td><td><a href="userdetails.php?id=<?php echo (int)$r['uid'] ?>"><?php echo htmlspecialchars($r['username']) ?></a></td><td><?php echo (int)$r['wins'] ?></td><td class="ddz-neg"><?php echo ddz_points($r['net'], true) ?></td></tr>
                <?php } ?>
                <?php if (!$lose) { ?><tr><td colspan="4" class="ddz-muted">暂无。</td></tr><?php } ?>
            </table>
        </div>
        <script>
        (function () {
            var t = document.getElementById('ddzPnlTabs');
            t.addEventListener('click', function (e) {
                var b = e.target.closest('.ddz-tab2');
                if (!b) { return; }
                t.querySelectorAll('.ddz-tab2').forEach(function (x) { x.classList.remove('is-active'); });
                b.classList.add('is-active');
                var w = b.getAttribute('data-pnl');
                document.getElementById('ddzPnlWin').style.display = w === 'win' ? '' : 'none';
                document.getElementById('ddzPnlLose').style.display = w === 'lose' ? '' : 'none';
            });
        })();
        </script>
    <?php } elseif ($room) { ?>
        <div class="ddz-panel">
            <div class="ddz-section-title">桌 #<?php echo (int)$room['id'] ?>　底分 <?php echo (int)$room['base'] ?> 电影票　<span id="ddzMult" class="ddz-muted"></span></div>
            <div class="ddz-status" id="ddzStatus">加载中…</div>
            <div class="ddz-seats" id="ddzSeats"></div>
            <div class="ddz-center" id="ddzCenter" style="display:none"></div>
            <div id="ddzResult"></div>
            <div id="ddzActions" class="ddz-actions" style="display:none"></div>
            <div id="ddzMyHandWrap" style="display:none">
                <div class="ddz-muted">我的手牌（<span id="ddzMyCount">0</span> 张）— 点牌选择</div>
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
            var centerEl = document.getElementById('ddzCenter');
            var resultEl = document.getElementById('ddzResult');
            var actEl = document.getElementById('ddzActions');
            var handWrap = document.getElementById('ddzMyHandWrap');
            var handEl = document.getElementById('ddzMyHand');
            var myCountEl = document.getElementById('ddzMyCount');
            var multEl = document.getElementById('ddzMult');
            var busy = false, handKey = '', selected = {};
            function esc(s) { return (s || '').replace(/[&<>"]/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]); }); }
            function cardHtml(c) { return '<span class="ddz-card' + (c.red ? ' red' : '') + '">' + esc(c.label) + '</span>'; }
            function bidLabel(v) { return v === null ? '' : (v === 0 ? '不叫' : (v + '分')); }
            function post(action, extra) {
                if (busy) { return; }
                busy = true;
                var body = 'action=' + action + '&table=' + tableId + (extra || '');
                fetch('/games/ddz/', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (j) { if (!j.ok && j.error) { alert(j.error); } poll(); })
                    .catch(function () {}).finally(function () { busy = false; });
            }
            function renderHand(d) {
                var key = (d.myHand || []).map(function (c) { return c.id; }).join(',');
                if (key === handKey) { return; } // unchanged -> keep selection
                handKey = key;
                selected = {};
                if (!d.myHand || !d.myHand.length) { handWrap.style.display = 'none'; handEl.innerHTML = ''; return; }
                handEl.innerHTML = d.myHand.map(function (c) {
                    return '<span class="ddz-card' + (c.red ? ' red' : '') + '" data-id="' + c.id + '">' + esc(c.label) + '</span>';
                }).join('');
                myCountEl.textContent = d.myHand.length;
                handWrap.style.display = '';
                handEl.querySelectorAll('.ddz-card').forEach(function (el) {
                    el.addEventListener('click', function () {
                        var id = el.getAttribute('data-id');
                        if (selected[id]) { delete selected[id]; el.classList.remove('sel'); }
                        else { selected[id] = 1; el.classList.add('sel'); }
                    });
                });
            }
            function render(d) {
                var lord = d.landlord != null ? d.landlord : -1, turn = d.turn != null ? d.turn : -1;
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
                        if (d.status === 'finished' && d.deltas) { meta = (d.deltas[i] >= 0 ? '+' : '') + d.deltas[i]; }
                        html += '<div class="meta">' + meta + '</div>';
                    } else { html += '<div class="meta">等待加入…</div>'; }
                    html += '</div>';
                }
                seatsEl.innerHTML = html;

                multEl.textContent = (d.status === 'playing' || d.status === 'finished') ? ('倍数 ' + (d.finalMult || d.multiplier || 1)) : '';

                // center (last play / bottom)
                if (d.status === 'playing' || d.status === 'finished') {
                    var c = '';
                    if (d.lastPlay && d.lastPlay.cards) {
                        var who = d.seats[d.lastPlay.seat] ? d.seats[d.lastPlay.seat].username : '';
                        c = '<span class="ddz-muted">' + esc(who) + ' 出：</span>' + d.lastPlay.cards.map(cardHtml).join('');
                    } else if (d.bottom) {
                        c = '<span class="ddz-muted">地主底牌：</span>' + d.bottom.map(cardHtml).join('');
                    }
                    centerEl.innerHTML = c; centerEl.style.display = c ? '' : 'none';
                } else { centerEl.style.display = 'none'; }

                // status line
                var tl = (d.timeLeft != null) ? ('（剩 ' + d.timeLeft + ' 秒）') : '';
                if (d.status === 'waiting') {
                    if (d.mm) {
                        statusEl.textContent = '🎮 匹配中… ' + d.count + ' / 3' + (d.mmLeft > 0 ? '（' + d.mmLeft + ' 秒后补满机器人）' : '（正在加入机器人…）');
                    } else { statusEl.textContent = '⏳ 等待玩家加入… ' + d.count + ' / 3'; }
                }
                else if (d.status === 'bidding') {
                    var nm = (d.bid && d.seats[d.bid.turn]) ? d.seats[d.bid.turn].username : '';
                    statusEl.textContent = '🎯 叫地主中…轮到 ' + esc(nm) + '（最高 ' + (d.bid ? d.bid.high : 0) + ' 分）' + tl;
                } else if (d.status === 'playing') {
                    var tn = d.seats[turn] ? d.seats[turn].username : '';
                    statusEl.textContent = '🃏 轮到 ' + esc(tn) + ' 出牌 ' + tl;
                } else if (d.status === 'finished') { statusEl.textContent = '本局结束'; }

                // result banner
                if (d.status === 'finished') {
                    resultEl.innerHTML = '<div class="ddz-result">🎉 ' + (d.landlordWon ? '地主赢' : '农民赢') + (d.spring ? '（春天）' : '') + ' · 倍数 ' + d.finalMult + '</div>';
                } else { resultEl.innerHTML = ''; }

                // action area
                actEl.innerHTML = ''; actEl.style.display = 'none';
                if (d.mySeat >= 0) {
                    if (d.status === 'bidding' && d.mySeat === bidTurn) {
                        var high = d.bid.high, h = '<span class="ddz-muted">轮到你叫分：</span><button class="ddz-btn ghost" data-bid="0">不叫</button>';
                        [1, 2, 3].forEach(function (v) { h += '<button class="ddz-btn" data-bid="' + v + '"' + (v <= high ? ' disabled' : '') + '>' + v + ' 分</button>'; });
                        actEl.innerHTML = h; actEl.style.display = '';
                        actEl.querySelectorAll('button[data-bid]').forEach(function (b) { b.addEventListener('click', function () { post('bid', '&score=' + b.getAttribute('data-bid')); }); });
                    } else if (d.status === 'playing' && d.mySeat === turn) {
                        var leading = (!d.lastPlay) || (d.lastPlay.seat === d.mySeat);
                        actEl.innerHTML = '<button class="ddz-btn" id="ddzPlay">出牌</button><button class="ddz-btn ghost" id="ddzPass"' + (leading ? ' disabled' : '') + '>过牌（不出）</button>';
                        actEl.style.display = '';
                        document.getElementById('ddzPlay').addEventListener('click', function () {
                            var ids = Object.keys(selected);
                            if (!ids.length) { alert('请先点选要出的牌。'); return; }
                            post('play', '&cards=' + ids.join(','));
                        });
                        var pb = document.getElementById('ddzPass');
                        if (!leading) { pb.addEventListener('click', function () { post('pass', ''); }); }
                    } else if (d.status === 'finished') {
                        actEl.innerHTML = '<button class="ddz-btn" id="ddzReplay">再来一局</button>';
                        actEl.style.display = '';
                        document.getElementById('ddzReplay').addEventListener('click', function () { post('replay', ''); });
                    }
                }

                renderHand(d);
            }
            function poll() {
                fetch('/games/ddz/?ajax=poll&table=' + tableId, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.ok) { statusEl.textContent = '该桌已解散。'; seatsEl.innerHTML = ''; actEl.style.display = 'none'; handWrap.style.display = 'none'; centerEl.style.display = 'none'; return; }
                        render(d);
                    }).catch(function () {});
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
        <?php echo ddz_subnav(''); ?>
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
