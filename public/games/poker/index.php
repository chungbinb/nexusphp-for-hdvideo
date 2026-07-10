<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;
require_once "../../../include/game_control.php";
require_once __DIR__ . '/engine.php';
game_guard('poker');

const POKER_SEATS = 4;
const POKER_BASE_OPTIONS = [100, 500, 1000, 5000];
const POKER_STACK_FACTOR = 20;
const POKER_ROOM_TTL = 604800; // 未完牌局保留 7 天，避免刷新或临时离开丢失托管筹码
const POKER_TURN_SECONDS = 30;
const POKER_BOT_THINK_SECONDS = 3;
const POKER_MATCH_WAIT = 10;
const POKER_RAISE_CAP = 3;
const POKER_BUSINESS_TYPE = 104;
const POKER_ESCROW_BUSINESS_TYPE = 112; // 托管资金流水不进入游戏总榜，最终净结果单独记 104
const POKER_BOT_NAMES = ['阿德', '小荷', '老K', '安娜', '石头', '星河', '北辰', '薇薇'];

function poker_redis()
{
    return \Nexus\Database\NexusDB::redis();
}

function poker_room_key($id) { return 'poker:room:' . (int)$id; }
function poker_lobby_key() { return 'poker:lobby'; }
function poker_user_room_key($uid) { return 'poker:user-room:' . (int)$uid; }
function poker_legacy_game_key($uid) { return 'poker:game:' . (int)$uid; }
function poker_lock_key($id) { return 'poker:lock:' . (int)$id; }

function poker_room_get($id)
{
    $json = poker_redis()->get(poker_room_key($id));
    return $json ? json_decode($json, true) : null;
}

function poker_seat_of($room, $uid)
{
    foreach (($room['players'] ?? []) as $seat => $player) {
        if ($player && empty($player['bot']) && (int)($player['uid'] ?? 0) === (int)$uid) return (int)$seat;
    }
    return -1;
}

function poker_player_count($room)
{
    return count(array_filter($room['players'] ?? [], fn($player) => (bool)$player));
}

function poker_room_put($room)
{
    $redis = poker_redis();
    $redis->setex(poker_room_key($room['id']), POKER_ROOM_TTL, json_encode($room, JSON_UNESCAPED_UNICODE));
    $departed = array_map('intval', $room['departed'] ?? []);
    foreach (($room['players'] ?? []) as $player) {
        if ($player && empty($player['bot']) && (int)($player['uid'] ?? 0) > 0 && !in_array((int)$player['uid'], $departed, true)) {
            $redis->setex(poker_user_room_key($player['uid']), POKER_ROOM_TTL, (int)$room['id']);
        }
    }
}

function poker_current_room_id($uid)
{
    $redis = poker_redis();
    $id = (int)$redis->get(poker_user_room_key($uid));
    if ($id > 0) {
        $room = poker_room_get($id);
        if ($room && poker_seat_of($room, $uid) >= 0) return $id;
        $redis->del(poker_user_room_key($uid));
    }
    // Migrate an in-progress single-player room from the first public version.
    $legacyJson = $redis->get(poker_legacy_game_key($uid));
    if ($legacyJson) {
        $legacy = json_decode($legacyJson, true);
        if ($legacy && !empty($legacy['players'])) {
            $id = (int)$redis->incr('poker:next-id');
            $legacy['id'] = $id;
            $legacy['owner'] = (int)$uid;
            $legacy['mode'] = 'legacy';
            $legacy['invite'] = bin2hex(random_bytes(6));
            poker_room_put($legacy);
            $redis->del(poker_legacy_game_key($uid));
            return $id;
        }
    }
    return 0;
}

function poker_lock($id, $ttl = 8)
{
    $token = bin2hex(random_bytes(8));
    for ($i = 0; $i < 25; $i++) {
        if (poker_redis()->set(poker_lock_key($id), $token, ['nx', 'ex' => $ttl])) return $token;
        usleep(60000);
    }
    return false;
}

function poker_unlock($id, $token)
{
    $redis = poker_redis();
    if ($redis->get(poker_lock_key($id)) === $token) $redis->del(poker_lock_key($id));
}

function poker_ensure_tables()
{
    static $done = false;
    if ($done) return;
    @sql_query("
        CREATE TABLE IF NOT EXISTS `hdvideo_poker_results` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `hand_id` varchar(32) NOT NULL,
            `uid` int unsigned NOT NULL,
            `base` int unsigned NOT NULL,
            `result` enum('win','lose','push') NOT NULL,
            `hand_name` varchar(32) NOT NULL DEFAULT '',
            `delta` decimal(20,1) NOT NULL DEFAULT '0.0',
            `pot` decimal(20,1) NOT NULL DEFAULT '0.0',
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_hand_uid` (`hand_id`,`uid`),
            KEY `idx_uid` (`uid`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function poker_wallet_balance($uid)
{
    $res = sql_query('SELECT `seedbonus` FROM `users` WHERE `id` = ' . (int)$uid) or sqlerr(__FILE__, __LINE__);
    $row = mysql_fetch_assoc($res);
    return $row ? (float)$row['seedbonus'] : 0.0;
}

function poker_real_player($player)
{
    return $player && empty($player['bot']) && (int)($player['uid'] ?? 0) > 0;
}

/** 开局时一次性锁定所有真人余额，确保不会出现部分玩家已扣款、牌局却未开始。 */
function poker_reserve_room_buyins(&$room, $handId)
{
    $amount = (float)((int)$room['base'] * POKER_STACK_FACTOR);
    $realSeats = [];
    foreach ($room['players'] as $seat => $player) {
        if (poker_real_player($player)) $realSeats[(int)$player['uid']] = (int)$seat;
    }
    ksort($realSeats, SORT_NUMERIC);
    $now = date('Y-m-d H:i:s');
    sql_query('START TRANSACTION') or sqlerr(__FILE__, __LINE__);
    try {
        $balances = [];
        foreach ($realSeats as $uid => $seat) {
            $res = sql_query('SELECT `seedbonus` FROM `users` WHERE `id` = ' . (int)$uid . ' FOR UPDATE') or sqlerr(__FILE__, __LINE__);
            $row = mysql_fetch_assoc($res);
            $old = $row ? (float)$row['seedbonus'] : 0;
            if ($old < $amount) {
                sql_query('ROLLBACK');
                return ($room['players'][$seat]['username'] ?? '有玩家') . '的电影票余额不足，暂时无法开局。';
            }
            $balances[$uid] = $old;
        }
        foreach ($realSeats as $uid => $seat) {
            $old = $balances[$uid];
            $new = $old - $amount;
            sql_query('UPDATE `users` SET `seedbonus` = `seedbonus` - ' . sqlesc(number_format($amount, 1, '.', '')) . ' WHERE `id` = ' . (int)$uid) or sqlerr(__FILE__, __LINE__);
            sql_query(sprintf(
                "INSERT INTO `bonus_logs` (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
                POKER_ESCROW_BUSINESS_TYPE, (int)$uid, sqlesc(number_format($old, 1, '.', '')),
                sqlesc(number_format(-$amount, 1, '.', '')), sqlesc(number_format($new, 1, '.', '')),
                sqlesc('[德州扑克托管] 手牌 ' . $handId . ' 带入筹码'), sqlesc($now), sqlesc($now)
            )) or sqlerr(__FILE__, __LINE__);
            $room['players'][$seat]['escrow'] = $amount;
            $room['players'][$seat]['balance_before'] = $old;
            $room['players'][$seat]['wallet_after_reserve'] = $new;
        }
        sql_query('COMMIT') or sqlerr(__FILE__, __LINE__);
        foreach (array_keys($realSeats) as $uid) clear_user_cache((int)$uid);
        return '';
    } catch (Throwable $e) {
        sql_query('ROLLBACK');
        throw $e;
    }
}

function poker_active_seats($game)
{
    $out = [];
    foreach ($game['players'] as $seat => $player) {
        if (($player['status'] ?? '') === 'active') $out[] = (int)$seat;
    }
    return $out;
}

function poker_next_active($game, $seat)
{
    for ($i = 1; $i <= POKER_SEATS; $i++) {
        $next = ((int)$seat + $i) % POKER_SEATS;
        if (($game['players'][$next]['status'] ?? '') === 'active') return $next;
    }
    return -1;
}

function poker_set_turn(&$game, $seat)
{
    $seat = (int)$seat;
    $game['turn'] = $seat;
    if ($seat >= 0 && !empty($game['players'][$seat]['bot'])) {
        $game['bot_ready_at'] = TIMENOW + POKER_BOT_THINK_SECONDS - 1;
        $game['deadline'] = TIMENOW + POKER_BOT_THINK_SECONDS;
    } else {
        unset($game['bot_ready_at']);
        $game['deadline'] = TIMENOW + POKER_TURN_SECONDS;
    }
}

function poker_add_log(&$game, $text)
{
    $game['logs'][] = ['at' => TIMENOW, 'text' => $text];
    if (count($game['logs']) > 16) $game['logs'] = array_slice($game['logs'], -16);
}

function poker_commit(&$game, $seat, $amount)
{
    $amount = max(0, (int)$amount);
    $amount = min($amount, (int)$game['players'][$seat]['stack']);
    $game['players'][$seat]['stack'] -= $amount;
    $game['players'][$seat]['contrib'] += $amount;
    $game['players'][$seat]['round_bet'] += $amount;
    $game['pot'] += $amount;
    return $amount;
}

function poker_validate_base($base)
{
    global $CURUSER;
    $base = (int)$base;
    if (!in_array($base, POKER_BASE_OPTIONS, true)) return '请选择有效的底注。';
    $need = $base * POKER_STACK_FACTOR;
    if ((float)$CURUSER['seedbonus'] < $need) return "余额不足，该牌桌需至少 {$need} 张电影票。";
    return '';
}

function poker_user_entry()
{
    global $CURUSER;
    return ['uid' => (int)$CURUSER['id'], 'username' => (string)$CURUSER['username'], 'avatar' => (string)($CURUSER['avatar'] ?? ''), 'bot' => false];
}

function poker_new_waiting_room($base, $mode)
{
    global $CURUSER;
    $id = (int)poker_redis()->incr('poker:next-id');
    return [
        'id' => $id, 'base' => (int)$base, 'status' => 'waiting', 'stage' => 'waiting',
        'owner' => (int)$CURUSER['id'], 'mode' => $mode, 'invite' => bin2hex(random_bytes(6)),
        'players' => [poker_user_entry(), null, null, null], 'logs' => [],
        'created_at' => TIMENOW, 'updated_at' => TIMENOW,
        'mm_deadline' => $mode === 'match' ? TIMENOW + POKER_MATCH_WAIT : 0,
    ];
}

function poker_fill_room_bots(&$room)
{
    $names = POKER_BOT_NAMES;
    shuffle($names);
    $bi = 0;
    foreach ($room['players'] as $seat => $player) {
        if (!$player) {
            $room['players'][$seat] = ['uid' => -((int)$seat + 1), 'username' => '机器人·' . $names[$bi++], 'avatar' => '', 'bot' => true];
        }
    }
}

function poker_start_room(&$room)
{
    if (($room['status'] ?? '') !== 'waiting') return '牌局已经开始。';
    if (poker_player_count($room) !== POKER_SEATS) return '人数不足，暂时不能开局。';
    $stack = (int)$room['base'] * POKER_STACK_FACTOR;
    $handId = bin2hex(random_bytes(8));
    $reserveError = poker_reserve_room_buyins($room, $handId);
    if ($reserveError !== '') return $reserveError;

    $deck = range(0, 51);
    shuffle($deck);
    foreach ($room['players'] as &$player) {
        $player['cards'] = [array_pop($deck), array_pop($deck)];
        $player['stack'] = $stack;
        $player['contrib'] = 0;
        $player['round_bet'] = 0;
        $player['status'] = 'active';
        $player['acted'] = false;
    }
    unset($player);
    $dealer = random_int(0, POKER_SEATS - 1);
    $room['hand_id'] = $handId;
    $room['token'] = bin2hex(random_bytes(16));
    $room['status'] = 'playing';
    $room['stage'] = 'preflop';
    $room['dealer'] = $dealer;
    $room['small_blind'] = ($dealer + 1) % POKER_SEATS;
    $room['big_blind'] = ($dealer + 2) % POKER_SEATS;
    $room['deck'] = $deck;
    $room['community'] = [];
    $room['pot'] = 0;
    $room['current_bet'] = (int)$room['base'];
    $room['raise_count'] = 0;
    $room['turn'] = -1;
    $room['deadline'] = 0;
    $room['logs'] = [];
    $room['started_at'] = TIMENOW;
    unset($room['start_error']);
    poker_commit($room, $room['small_blind'], intdiv((int)$room['base'], 2));
    poker_commit($room, $room['big_blind'], (int)$room['base']);
    poker_add_log($room, $room['players'][$room['small_blind']]['username'] . ' 下小盲注 ' . intdiv((int)$room['base'], 2));
    poker_add_log($room, $room['players'][$room['big_blind']]['username'] . ' 下大盲注 ' . (int)$room['base']);
    poker_set_turn($room, ($room['big_blind'] + 1) % POKER_SEATS);
    poker_redis()->sRem(poker_lobby_key(), $room['id']);
    return '';
}

function poker_create_friend_room($base)
{
    global $CURUSER;
    $error = poker_validate_base($base);
    if ($error !== '') return [0, $error];
    $current = poker_current_room_id((int)$CURUSER['id']);
    if ($current) return [$current, ''];
    $room = poker_new_waiting_room((int)$base, 'friend');
    poker_room_put($room);
    poker_redis()->sAdd(poker_lobby_key(), $room['id']);
    return [(int)$room['id'], ''];
}

function poker_join_room($id, $invite = '', $matchJoin = false)
{
    global $CURUSER;
    $id = (int)$id;
    $current = poker_current_room_id((int)$CURUSER['id']);
    if ($current) return [$current, $current === $id ? '' : '你已经在另一张牌桌中。'];
    $lock = poker_lock($id);
    if (!$lock) return [0, '操作繁忙，请稍后重试。'];
    try {
        $room = poker_room_get($id);
        if (!$room) return [0, '牌桌不存在或已解散。'];
        if (($room['status'] ?? '') !== 'waiting') return [0, '该牌桌已经开局。'];
        if (!$matchJoin && ($room['mode'] ?? '') === 'friend' && !hash_equals((string)($room['invite'] ?? ''), (string)$invite)) return [0, '好友桌邀请链接已失效。'];
        if (poker_player_count($room) >= POKER_SEATS) return [0, '该牌桌已满。'];
        $need = (int)$room['base'] * POKER_STACK_FACTOR;
        if ((float)$CURUSER['seedbonus'] < $need) return [0, "余额不足，该牌桌需至少 {$need} 张电影票。"];
        foreach ($room['players'] as $seat => $player) {
            if (!$player) { $room['players'][$seat] = poker_user_entry(); break; }
        }
        $room['updated_at'] = TIMENOW;
        if (poker_player_count($room) === POKER_SEATS) {
            $startError = poker_start_room($room);
            if ($startError !== '') { $room['start_error'] = $startError; poker_room_put($room); return [$id, '']; }
        }
        poker_room_put($room);
        return [$id, ''];
    } finally {
        poker_unlock($id, $lock);
    }
}

function poker_matchmake($base)
{
    global $CURUSER;
    $error = poker_validate_base($base);
    if ($error !== '') return [0, $error];
    $current = poker_current_room_id((int)$CURUSER['id']);
    if ($current) return [$current, ''];
    $candidates = [];
    foreach ((poker_redis()->sMembers(poker_lobby_key()) ?: []) as $id) {
        $room = poker_room_get($id);
        if (!$room) { poker_redis()->sRem(poker_lobby_key(), $id); continue; }
        if (($room['mode'] ?? '') === 'match' && ($room['status'] ?? '') === 'waiting' && (int)$room['base'] === (int)$base && poker_player_count($room) < POKER_SEATS) $candidates[] = $room;
    }
    usort($candidates, fn($a, $b) => (int)$a['created_at'] <=> (int)$b['created_at']);
    foreach ($candidates as $room) {
        [$id, $joinError] = poker_join_room($room['id'], '', true);
        if ($id && $joinError === '') return [$id, ''];
    }
    $room = poker_new_waiting_room((int)$base, 'match');
    poker_room_put($room);
    poker_redis()->sAdd(poker_lobby_key(), $room['id']);
    return [(int)$room['id'], ''];
}

function poker_fill_and_start($id, $uid, $ownerOnly = true)
{
    $lock = poker_lock($id);
    if (!$lock) return '操作繁忙，请稍后重试。';
    try {
        $room = poker_room_get($id);
        if (!$room || ($room['status'] ?? '') !== 'waiting') return '牌桌已不存在或已经开局。';
        if ($ownerOnly && (int)$room['owner'] !== (int)$uid) return '只有房主可以提前补机器人。';
        poker_fill_room_bots($room);
        $error = poker_start_room($room);
        if ($error !== '') { $room['start_error'] = $error; poker_room_put($room); return $error; }
        poker_room_put($room);
        return '';
    } finally {
        poker_unlock($id, $lock);
    }
}

function poker_leave_current_room($uid)
{
    $id = poker_current_room_id($uid);
    if (!$id) return '';
    $lock = poker_lock($id);
    if (!$lock) return '操作繁忙，请稍后重试。';
    try {
        $room = poker_room_get($id);
        if (!$room) { poker_redis()->del(poker_user_room_key($uid)); return ''; }
        if (($room['status'] ?? '') === 'playing') return '牌局进行中，不能离开。';
        $seat = poker_seat_of($room, $uid);
        poker_redis()->del(poker_user_room_key($uid));
        if (($room['status'] ?? '') === 'finished') {
            $room['departed'][] = (int)$uid;
            $room['departed'] = array_values(array_unique(array_map('intval', $room['departed'])));
            $hasViewer = false;
            foreach ($room['players'] as $player) {
                if (poker_real_player($player) && (int)poker_redis()->get(poker_user_room_key($player['uid'])) === $id) { $hasViewer = true; break; }
            }
            if (!$hasViewer) poker_redis()->del(poker_room_key($id));
            else poker_room_put($room);
            return '';
        }
        if ($seat >= 0) $room['players'][$seat] = null;
        if (poker_player_count($room) === 0) {
            poker_redis()->del(poker_room_key($id));
            poker_redis()->sRem(poker_lobby_key(), $id);
            return '';
        }
        if ((int)$room['owner'] === (int)$uid) {
            foreach ($room['players'] as $player) if (poker_real_player($player)) { $room['owner'] = (int)$player['uid']; break; }
        }
        $room['updated_at'] = TIMENOW;
        poker_room_put($room);
        return '';
    } finally {
        poker_unlock($id, $lock);
    }
}

function poker_betting_complete($game)
{
    $active = poker_active_seats($game);
    if (count($active) <= 1) return true;
    foreach ($active as $seat) {
        $player = $game['players'][$seat];
        if (empty($player['acted']) || (int)$player['round_bet'] !== (int)$game['current_bet']) return false;
    }
    return true;
}

function poker_stage_label($stage)
{
    return ['preflop' => '翻牌前', 'flop' => '翻牌圈', 'turn' => '转牌圈', 'river' => '河牌圈', 'showdown' => '摊牌'][$stage] ?? '';
}

function poker_settle(&$game)
{
    if (($game['status'] ?? '') === 'finished') return;
    $active = poker_active_seats($game);
    $winners = [];
    $scores = [];
    if (count($active) === 1) {
        $winners = $active;
        $game['finish_reason'] = '其余玩家均已弃牌';
    } else {
        $best = null;
        foreach ($active as $seat) {
            $score = poker_evaluate(array_merge($game['players'][$seat]['cards'], $game['community']));
            $scores[$seat] = $score;
            $cmp = $best === null ? 1 : poker_compare_scores($score, $best);
            if ($cmp > 0) { $best = $score; $winners = [$seat]; }
            elseif ($cmp === 0) $winners[] = $seat;
        }
        $game['finish_reason'] = count($winners) > 1 ? '平分底池' : poker_score_name($best) . ' 获胜';
    }

    $share = count($winners) ? round((float)$game['pot'] / count($winners), 1) : 0;
    $deltas = [];
    foreach ($game['players'] as $seat => $player) {
        $payout = in_array((int)$seat, $winners, true) ? $share : 0;
        $deltas[$seat] = round($payout - (float)$player['contrib'], 1);
        $game['players'][$seat]['score'] = $scores[$seat] ?? null;
        $game['players'][$seat]['hand_name'] = isset($scores[$seat]) ? poker_score_name($scores[$seat]) : '';
    }
    $realSeats = [];
    foreach ($game['players'] as $seat => $player) {
        if (!poker_real_player($player)) continue;
        $escrow = (float)($player['escrow'] ?? ($game['escrow'] ?? ((int)$game['base'] * POKER_STACK_FACTOR)));
        $credit = (float)$player['stack'] + (in_array((int)$seat, $winners, true) ? $share : 0);
        $delta = round($credit - $escrow, 1);
        $deltas[$seat] = $delta;
        $realSeats[(int)$player['uid']] = ['seat' => (int)$seat, 'credit' => $credit, 'delta' => $delta, 'escrow' => $escrow];
    }
    ksort($realSeats, SORT_NUMERIC);
    poker_ensure_tables();
    $now = date('Y-m-d H:i:s');
    sql_query('START TRANSACTION') or sqlerr(__FILE__, __LINE__);
    try {
        $wallets = [];
        foreach ($realSeats as $uid => $info) {
            $res = sql_query('SELECT `seedbonus` FROM `users` WHERE `id` = ' . (int)$uid . ' FOR UPDATE') or sqlerr(__FILE__, __LINE__);
            $row = mysql_fetch_assoc($res);
            $wallets[$uid] = $row ? (float)$row['seedbonus'] : 0;
        }
        foreach ($realSeats as $uid => $info) {
            $seat = $info['seat'];
            $old = $wallets[$uid];
            $new = $old + $info['credit'];
            $delta = $info['delta'];
            $result = $delta > 0 ? 'win' : ($delta < 0 ? 'lose' : 'push');
            $handName = $game['players'][$seat]['hand_name'] ?: (($game['players'][$seat]['status'] ?? '') === 'folded' ? '弃牌' : '未摊牌');
            sql_query(sprintf(
                "INSERT INTO `hdvideo_poker_results` (`hand_id`,`uid`,`base`,`result`,`hand_name`,`delta`,`pot`,`created_at`) VALUES (%s,%d,%d,%s,%s,%s,%s,%s)",
                sqlesc($game['hand_id']), (int)$uid, (int)$game['base'], sqlesc($result), sqlesc($handName),
                sqlesc(number_format($delta, 1, '.', '')), sqlesc(number_format((float)$game['pot'], 1, '.', '')), sqlesc($now)
            )) or sqlerr(__FILE__, __LINE__);
            sql_query('UPDATE `users` SET `seedbonus` = `seedbonus` + ' . sqlesc(number_format($info['credit'], 1, '.', '')) . ' WHERE `id` = ' . (int)$uid) or sqlerr(__FILE__, __LINE__);
            sql_query(sprintf(
                "INSERT INTO `bonus_logs` (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
                POKER_ESCROW_BUSINESS_TYPE, (int)$uid, sqlesc(number_format($old, 1, '.', '')),
                sqlesc(number_format($info['credit'], 1, '.', '')), sqlesc(number_format($new, 1, '.', '')),
                sqlesc('[德州扑克托管] 手牌 ' . $game['hand_id'] . ' 退回筹码与底池'), sqlesc($now), sqlesc($now)
            )) or sqlerr(__FILE__, __LINE__);
            // 游戏总榜只统计一条“整手净结果”，托管进出不影响活跃/中奖次数。
            $summaryOld = (float)($game['players'][$seat]['balance_before'] ?? ($game['balance_before'] ?? $old + $info['escrow']));
            $comment = '[德州扑克] ' . $handName . ' ' . ($delta > 0 ? '赢' : ($delta < 0 ? '输' : '平')) . ' ' . abs($delta) . '，底池 ' . $game['pot'];
            sql_query(sprintf(
                "INSERT INTO `bonus_logs` (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
                POKER_BUSINESS_TYPE, (int)$uid, sqlesc(number_format($summaryOld, 1, '.', '')),
                sqlesc(number_format($delta, 1, '.', '')), sqlesc(number_format($summaryOld + $delta, 1, '.', '')),
                sqlesc($comment), sqlesc($now), sqlesc($now)
            )) or sqlerr(__FILE__, __LINE__);
            $game['players'][$seat]['wallet_after_settle'] = $new;
        }
        sql_query('COMMIT') or sqlerr(__FILE__, __LINE__);
        foreach (array_keys($realSeats) as $uid) clear_user_cache((int)$uid);
    } catch (Throwable $e) {
        sql_query('ROLLBACK');
        throw $e;
    }
    $game['deltas'] = $deltas;
    $game['winners'] = $winners;
    $game['share'] = $share;
    $game['status'] = 'finished';
    $game['stage'] = 'showdown';
    $game['turn'] = -1;
    $game['finished_at'] = TIMENOW;
    poker_add_log($game, $game['finish_reason']);
}

function poker_advance_stage(&$game)
{
    if (count(poker_active_seats($game)) <= 1) { poker_settle($game); return; }
    $next = ['preflop' => 'flop', 'flop' => 'turn', 'turn' => 'river', 'river' => 'showdown'][$game['stage']] ?? 'showdown';
    if ($next === 'showdown') { poker_settle($game); return; }
    $draw = $next === 'flop' ? 3 : 1;
    for ($i = 0; $i < $draw; $i++) $game['community'][] = array_pop($game['deck']);
    $game['stage'] = $next;
    $game['current_bet'] = 0;
    $game['raise_count'] = 0;
    foreach ($game['players'] as &$player) {
        $player['round_bet'] = 0;
        $player['acted'] = $player['status'] !== 'active';
    }
    unset($player);
    poker_set_turn($game, poker_next_active($game, $game['dealer']));
    poker_add_log($game, '进入' . poker_stage_label($next));
}

function poker_apply_action(&$game, $seat, $action)
{
    $seat = (int)$seat;
    if (($game['status'] ?? '') !== 'playing') return '牌局已经结束。';
    if ((int)$game['turn'] !== $seat || ($game['players'][$seat]['status'] ?? '') !== 'active') return '还没轮到你行动。';
    $player =& $game['players'][$seat];
    $toCall = max(0, (int)$game['current_bet'] - (int)$player['round_bet']);
    $name = $player['username'];
    if ($action === 'fold') {
        $player['status'] = 'folded';
        $player['acted'] = true;
        poker_add_log($game, $name . ' 弃牌');
    } elseif ($action === 'check') {
        if ($toCall !== 0) return '当前有下注，不能看牌。';
        $player['acted'] = true;
        poker_add_log($game, $name . ' 看牌');
    } elseif ($action === 'call') {
        if ($toCall <= 0) return '当前无需跟注。';
        if ((int)$player['stack'] < $toCall) return '筹码不足以跟注。';
        poker_commit($game, $seat, $toCall);
        $player['acted'] = true;
        poker_add_log($game, $name . ' 跟注 ' . $toCall);
    } elseif ($action === 'raise') {
        if ((int)$game['raise_count'] >= POKER_RAISE_CAP) return '本轮已达到加注上限。';
        $increment = in_array($game['stage'], ['turn', 'river'], true) ? (int)$game['base'] * 2 : (int)$game['base'];
        $target = (int)$game['current_bet'] + $increment;
        $need = $target - (int)$player['round_bet'];
        if ((int)$player['stack'] < $need) return '筹码不足以加注。';
        poker_commit($game, $seat, $need);
        $game['current_bet'] = $target;
        $game['raise_count']++;
        foreach ($game['players'] as $i => &$other) {
            if ($i !== $seat && ($other['status'] ?? '') === 'active') $other['acted'] = false;
        }
        unset($other);
        $player['acted'] = true;
        poker_add_log($game, $name . ' 加注至 ' . $target);
    } else {
        return '未知操作。';
    }
    unset($player);

    if (count(poker_active_seats($game)) <= 1) poker_settle($game);
    elseif (poker_betting_complete($game)) poker_advance_stage($game);
    else {
        poker_set_turn($game, poker_next_active($game, $seat));
    }
    return '';
}

function poker_bot_strength($game, $seat)
{
    $cards = $game['players'][$seat]['cards'];
    if ($game['stage'] === 'preflop') return poker_preflop_strength($cards);
    $score = poker_evaluate(array_merge($cards, $game['community']));
    $category = (int)$score[0];
    $kicker = (int)($score[1] ?? 2);
    return min(0.99, 0.12 + $category * 0.105 + ($kicker - 2) / 12 * 0.08);
}

function poker_bot_action($game, $seat)
{
    $player = $game['players'][$seat];
    $toCall = max(0, (int)$game['current_bet'] - (int)$player['round_bet']);
    $strength = poker_bot_strength($game, $seat);
    $noise = random_int(-12, 12) / 100;
    $confidence = max(0, min(1, $strength + $noise));
    $canRaise = (int)$game['raise_count'] < POKER_RAISE_CAP;
    $increment = in_array($game['stage'], ['turn', 'river'], true) ? (int)$game['base'] * 2 : (int)$game['base'];
    $raiseNeed = (int)$game['current_bet'] + $increment - (int)$player['round_bet'];
    if ($toCall === 0) {
        if ($canRaise && $raiseNeed <= (int)$player['stack'] && $confidence > 0.66 && random_int(1, 100) <= 48) return 'raise';
        return 'check';
    }
    $pressure = $toCall / max(1, (int)$player['stack'] + $toCall);
    if ($confidence < 0.26 + $pressure * 1.8 && random_int(1, 100) <= 82) return 'fold';
    if ($canRaise && $raiseNeed <= (int)$player['stack'] && $confidence > 0.76 && random_int(1, 100) <= 42) return 'raise';
    return $toCall <= (int)$player['stack'] ? 'call' : 'fold';
}

function poker_drive_bots(&$game)
{
    if (($game['status'] ?? '') !== 'playing') return;
    $seat = (int)$game['turn'];
    if ($seat < 0 || empty($game['players'][$seat]['bot'])) return;
    if (TIMENOW < (int)($game['bot_ready_at'] ?? 0)) return;
    $action = poker_bot_action($game, $seat);
    $error = poker_apply_action($game, $seat, $action);
    if ($error !== '') {
        $fallback = max(0, (int)$game['current_bet'] - (int)$game['players'][$seat]['round_bet']) === 0 ? 'check' : 'fold';
        poker_apply_action($game, $seat, $fallback);
    }
}

function poker_mutate_current($callback)
{
    global $CURUSER;
    $uid = (int)$CURUSER['id'];
    $id = poker_current_room_id($uid);
    if (!$id) return [null, '牌桌不存在，请重新加入。'];
    $lock = poker_lock($id);
    if (!$lock) return [null, '操作繁忙，请稍后重试。'];
    try {
        $room = poker_room_get($id);
        if (!$room) return [null, '牌桌不存在，请重新加入。'];
        $seat = poker_seat_of($room, $uid);
        if ($seat < 0) return [null, '你已不在这张牌桌。'];
        $error = $callback($room, $seat);
        poker_room_put($room);
        return [$room, (string)$error];
    } finally {
        poker_unlock($id, $lock);
    }
}

function poker_public_state($room, $viewerUid)
{
    global $BASEURL;
    if (!$room) return ['ok' => true, 'game' => null];
    $viewerSeat = poker_seat_of($room, $viewerUid);
    if ($viewerSeat < 0) return ['ok' => true, 'game' => null];
    $waiting = ($room['status'] ?? '') === 'waiting';
    $finished = ($room['status'] ?? '') === 'finished';
    $toView = fn($actual) => $actual < 0 ? -1 : (((int)$actual - $viewerSeat + POKER_SEATS) % POKER_SEATS);
    $players = [];
    $deltas = [];
    for ($viewSeat = 0; $viewSeat < POKER_SEATS; $viewSeat++) {
        $actualSeat = ($viewerSeat + $viewSeat) % POKER_SEATS;
        $player = $room['players'][$actualSeat] ?? null;
        if (!$player) {
            $players[] = ['username' => '等待加入', 'avatar' => '', 'bot' => false, 'stack' => 0, 'contrib' => 0, 'roundBet' => 0, 'status' => 'empty', 'cards' => [], 'handName' => ''];
            $deltas[] = 0;
            continue;
        }
        $showCards = !$waiting && ($actualSeat === $viewerSeat || ($finished && ($player['status'] ?? '') === 'active'));
        $cards = [];
        if ($showCards) foreach (($player['cards'] ?? []) as $card) $cards[] = ['id' => $card, 'label' => poker_card_label($card), 'red' => poker_card_is_red($card)];
        $players[] = [
            'username' => $player['username'], 'avatar' => $player['avatar'] ?? '', 'bot' => (bool)($player['bot'] ?? false),
            'stack' => (int)($player['stack'] ?? 0), 'contrib' => (int)($player['contrib'] ?? 0), 'roundBet' => (int)($player['round_bet'] ?? 0),
            'status' => $player['status'] ?? ($waiting ? 'waiting' : 'active'), 'cards' => $cards, 'handName' => $player['hand_name'] ?? '',
        ];
        $deltas[] = (float)($room['deltas'][$actualSeat] ?? 0);
    }
    $community = [];
    foreach (($room['community'] ?? []) as $card) $community[] = ['id' => $card, 'label' => poker_card_label($card), 'red' => poker_card_is_red($card)];
    $toCall = 0;
    $canRaise = false;
    $raiseTo = 0;
    $viewer = $room['players'][$viewerSeat];
    if (!$waiting && !$finished && ($viewer['status'] ?? '') === 'active') {
        $toCall = max(0, (int)$room['current_bet'] - (int)$viewer['round_bet']);
        $inc = in_array($room['stage'], ['turn', 'river'], true) ? (int)$room['base'] * 2 : (int)$room['base'];
        $raiseTo = (int)$room['current_bet'] + $inc;
        $canRaise = (int)$room['turn'] === $viewerSeat && (int)$room['raise_count'] < POKER_RAISE_CAP
            && (int)$viewer['stack'] >= $raiseTo - (int)$viewer['round_bet'];
    }
    $winners = [];
    foreach (($room['winners'] ?? []) as $actualSeat) $winners[] = $toView((int)$actualSeat);
    $inviteUrl = get_protocol_prefix() . $BASEURL . '/games/poker/?table=' . (int)$room['id'] . '&invite=' . rawurlencode((string)($room['invite'] ?? ''));
    $game = [
        'roomId' => (int)$room['id'], 'handId' => $room['hand_id'] ?? ('waiting-' . $room['id']),
        'status' => $room['status'], 'stage' => $room['stage'] ?? 'waiting', 'stageLabel' => $waiting ? '等待入座' : poker_stage_label($room['stage']),
        'mode' => $room['mode'] ?? 'legacy', 'base' => (int)$room['base'], 'pot' => (int)($room['pot'] ?? 0),
        'dealer' => $waiting ? -1 : $toView((int)$room['dealer']), 'smallBlind' => $waiting ? -1 : $toView((int)$room['small_blind']), 'bigBlind' => $waiting ? -1 : $toView((int)$room['big_blind']),
        'turn' => $waiting ? -1 : $toView((int)($room['turn'] ?? -1)), 'timeLeft' => (!$waiting && !$finished) ? max(0, (int)$room['deadline'] - TIMENOW) : 0,
        'players' => $players, 'playerCount' => poker_player_count($room), 'community' => $community,
        'toCall' => $toCall, 'canRaise' => $canRaise, 'raiseTo' => $raiseTo,
        'raiseCount' => (int)($room['raise_count'] ?? 0), 'raiseCap' => POKER_RAISE_CAP,
        'logs' => array_values($room['logs'] ?? []), 'token' => $room['token'] ?? '',
        'winners' => $winners, 'deltas' => $deltas, 'finishReason' => $room['finish_reason'] ?? '', 'share' => $room['share'] ?? 0,
        'isOwner' => (int)$room['owner'] === (int)$viewerUid, 'inviteUrl' => $inviteUrl,
        'queueLeft' => $waiting && ($room['mode'] ?? '') === 'match' ? max(0, (int)($room['mm_deadline'] ?? TIMENOW) - TIMENOW) : 0,
        'startError' => $room['start_error'] ?? '',
    ];
    $response = ['ok' => true, 'game' => $game];
    if (isset($viewer['wallet_after_settle'])) $response['wallet'] = (float)$viewer['wallet_after_settle'];
    elseif (isset($viewer['wallet_after_reserve'])) $response['wallet'] = (float)$viewer['wallet_after_reserve'];
    return $response;
}

function poker_stats($uid)
{
    poker_ensure_tables();
    $res = sql_query("SELECT COUNT(*) games, SUM(result='win') wins, SUM(result='lose') losses, COALESCE(SUM(delta),0) net, COALESCE(MAX(delta),0) best FROM hdvideo_poker_results WHERE uid=" . (int)$uid) or sqlerr(__FILE__, __LINE__);
    return mysql_fetch_assoc($res) ?: ['games' => 0, 'wins' => 0, 'losses' => 0, 'net' => 0, 'best' => 0];
}

function poker_rankings()
{
    poker_ensure_tables();
    $res = sql_query("SELECT r.uid,u.username,COUNT(*) games,SUM(r.result='win') wins,SUM(r.delta) net FROM hdvideo_poker_results r INNER JOIN users u ON u.id=r.uid GROUP BY r.uid,u.username ORDER BY net DESC LIMIT 8") or sqlerr(__FILE__, __LINE__);
    $rows = [];
    while ($row = mysql_fetch_assoc($res)) $rows[] = $row;
    return $rows;
}

// JSON poll drives matchmaking, bot turns and safe timeout actions for every real player.
if (($_GET['ajax'] ?? '') === 'poll') {
    header('Content-Type: application/json');
    $id = poker_current_room_id((int)$CURUSER['id']);
    if (!$id) { echo json_encode(['ok' => true, 'game' => null], JSON_UNESCAPED_UNICODE); exit; }
    [$game, $error] = poker_mutate_current(function (&$game) {
        if (($game['status'] ?? '') === 'waiting' && ($game['mode'] ?? '') === 'match' && TIMENOW >= (int)($game['mm_deadline'] ?? 0)) {
            poker_fill_room_bots($game);
            $startError = poker_start_room($game);
            if ($startError !== '') {
                foreach ($game['players'] as $seat => $player) if ($player && !empty($player['bot'])) $game['players'][$seat] = null;
                $game['start_error'] = $startError;
                $game['mm_deadline'] = TIMENOW + POKER_MATCH_WAIT;
            }
        }
        if (($game['status'] ?? '') !== 'playing') return '';
        $turn = (int)$game['turn'];
        if ($turn >= 0 && empty($game['players'][$turn]['bot']) && TIMENOW >= (int)$game['deadline']) {
            $toCall = max(0, (int)$game['current_bet'] - (int)$game['players'][$turn]['round_bet']);
            $name = $game['players'][$turn]['username'];
            poker_apply_action($game, $turn, $toCall === 0 ? 'check' : 'fold');
            poker_add_log($game, $name . ' 已超时，系统自动行动');
        }
        poker_drive_bots($game);
        return '';
    });
    if ($error !== '') { echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE); exit; }
    $response = poker_public_state($game, (int)$CURUSER['id']);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = (string)($_POST['action'] ?? '');
    $uid = (int)$CURUSER['id'];
    $game = null;
    $error = '';
    if ($action === 'create') {
        [$id, $error] = poker_create_friend_room($_POST['base'] ?? 0);
        if ($id) $game = poker_room_get($id);
    } elseif ($action === 'match') {
        [$id, $error] = poker_matchmake($_POST['base'] ?? 0);
        if ($id) $game = poker_room_get($id);
    } elseif ($action === 'join') {
        [$id, $error] = poker_join_room($_POST['table'] ?? 0, (string)($_POST['invite'] ?? ''), false);
        if ($id) $game = poker_room_get($id);
    } elseif ($action === 'fill_bots') {
        $id = poker_current_room_id($uid);
        $error = $id ? poker_fill_and_start($id, $uid, true) : '牌桌不存在。';
        if ($id) $game = poker_room_get($id);
    } elseif ($action === 'leave' || $action === 'reset') {
        $error = poker_leave_current_room($uid);
    } elseif ($action === 'rematch') {
        $id = poker_current_room_id($uid);
        $old = $id ? poker_room_get($id) : null;
        if (!$old || ($old['status'] ?? '') !== 'finished') $error = '本局尚未结束。';
        else {
            $base = (int)$old['base'];
            $error = poker_leave_current_room($uid);
            if ($error === '') { [$newId, $error] = poker_matchmake($base); if ($newId) $game = poker_room_get($newId); }
        }
    } elseif (in_array($action, ['fold', 'check', 'call', 'raise'], true)) {
        [$game, $error] = poker_mutate_current(function (&$room, $seat) use ($action) {
            if (!hash_equals((string)($room['token'] ?? ''), (string)($_POST['token'] ?? ''))) return '页面凭证已失效，请刷新重试。';
            $result = poker_apply_action($room, $seat, $action);
            if ($result === '') poker_drive_bots($room);
            return $result;
        });
    } else $error = '未知操作。';
    if ($error !== '') { echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE); exit; }
    $response = poker_public_state($game, $uid);
    if (!isset($response['wallet'])) $response['wallet'] = poker_wallet_balance($uid);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

$stats = poker_stats((int)$CURUSER['id']);
$rankings = poker_rankings();
$balance = (float)$CURUSER['seedbonus'];
$inviteTableId = max(0, (int)($_GET['table'] ?? 0));
$inviteCode = (string)($_GET['invite'] ?? '');
stdhead('德州扑克');
?>
<style>
:root {
    --pk-bg: #071019;
    --pk-panel: #101d29;
    --pk-panel-2: #162737;
    --pk-line: rgba(151, 181, 207, .18);
    --pk-text: #edf6ff;
    --pk-muted: #91a8bd;
    --pk-gold: #f1c566;
    --pk-green: #18a66c;
    --pk-red: #e55c65;
}
body.page-games-poker-index-php:not(.inframe), body.game-page:not(.inframe) { background: var(--pk-bg) !important; }
.pk-page { min-height: calc(100vh - 88px); padding: 14px 18px 34px; color: var(--pk-text); background: radial-gradient(circle at 50% -20%, rgba(26,95,105,.28), transparent 38%); }
.pk-top { max-width: 1420px; margin: 0 auto 12px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.pk-brand { display: flex; align-items: center; gap: 12px; min-width: 0; }
.pk-back, .pk-icon-btn { display: inline-grid; place-items: center; width: 42px; height: 42px; border: 1px solid var(--pk-line); border-radius: 12px; background: rgba(17,34,47,.82); color: var(--pk-text) !important; text-decoration: none !important; cursor: pointer; transition: border-color .2s, background .2s; }
.pk-back:hover, .pk-icon-btn:hover { background: #1a3042; border-color: rgba(241,197,102,.48); }
.pk-back:focus-visible, .pk-stake:focus-visible, .pk-primary:focus-visible, .pk-action:focus-visible, .pk-result-actions button:focus-visible { outline: 3px solid #83d7ff; outline-offset: 2px; }
.pk-brand h1 { margin: 0; color: #fff; font-size: clamp(21px,2vw,29px); line-height: 1.05; letter-spacing: .02em; }
.pk-brand p { margin: 5px 0 0; color: var(--pk-muted); font-size: 13px; }
.pk-balance { flex: 0 0 auto; padding: 10px 15px; border: 1px solid rgba(241,197,102,.26); border-radius: 12px; background: rgba(34,31,23,.75); color: var(--pk-muted); }
.pk-balance b { margin-left: 7px; color: var(--pk-gold); font-size: 17px; }
.pk-layout { max-width: 1420px; margin: 0 auto; display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 16px; align-items: start; }
.pk-main { min-width: 0; }
.pk-board { position: relative; min-height: 690px; overflow: hidden; border: 1px solid rgba(101,153,174,.28); border-radius: 24px; background: radial-gradient(circle at 50% 38%, rgba(25,107,78,.35), transparent 37%), linear-gradient(145deg,#0b1e2a,#071019 68%); box-shadow: 0 28px 80px rgba(0,0,0,.34); }
.pk-board::before { content: ""; position: absolute; inset: 20px; border: 1px solid rgba(255,255,255,.035); border-radius: 18px; pointer-events: none; }
.pk-hud { position: absolute; z-index: 5; left: 50%; top: 24px; transform: translateX(-50%); display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 8px; width: min(92%, 680px); }
.pk-pill { padding: 7px 11px; border: 1px solid var(--pk-line); border-radius: 999px; background: rgba(5,14,21,.76); color: var(--pk-muted); font-size: 12px; backdrop-filter: blur(8px); }
.pk-pill b { color: #fff; }
.pk-table { position: absolute; left: 50%; top: 50%; width: min(78%, 790px); aspect-ratio: 1.72; transform: translate(-50%,-51%); border: 18px solid #17212a; border-radius: 50%; background: radial-gradient(ellipse at 50% 43%, #14724f 0,#0d593e 58%,#083c2b 100%); box-shadow: inset 0 0 0 3px #b68b43, inset 0 0 48px rgba(0,0,0,.46), 0 18px 40px rgba(0,0,0,.45), 0 0 0 4px #050a0e; }
.pk-table::after { content: "HDVIDEO HOLD'EM"; position: absolute; left: 50%; top: 67%; transform: translate(-50%,-50%); color: rgba(230,244,234,.13); font-size: clamp(12px,1.7vw,20px); font-weight: 900; letter-spacing: .24em; white-space: nowrap; }
.pk-community { position: absolute; z-index: 3; left: 50%; top: 45%; transform: translate(-50%,-50%); display: flex; gap: clamp(5px,.8vw,10px); }
.pk-card { position: relative; display: inline-flex; flex: 0 0 auto; width: clamp(48px,5.2vw,72px); aspect-ratio: .7; border: 1px solid rgba(15,23,30,.58); border-radius: 8px; background: linear-gradient(145deg,#fff,#e9edf0); color: #111923; box-shadow: 0 5px 13px rgba(0,0,0,.32); font-family: Arial, sans-serif; user-select: none; }
.pk-card.red { color: #d23d48; }
.pk-card .rank { position: absolute; left: 7px; top: 5px; font-size: clamp(15px,1.8vw,23px); font-weight: 800; line-height: 1; }
.pk-card .suit { position: absolute; left: 7px; top: 26px; font-size: clamp(14px,1.7vw,21px); line-height: 1; }
.pk-card .big-suit { margin: auto; font-size: clamp(24px,3vw,42px); opacity: .9; }
.pk-card.back { border-color: #c49849; background: repeating-linear-gradient(45deg,rgba(238,197,109,.22) 0 3px,transparent 3px 7px), radial-gradient(circle,#172f46,#071a2b); box-shadow: inset 0 0 0 3px #0b1a27, inset 0 0 0 5px rgba(241,197,102,.62), 0 5px 13px rgba(0,0,0,.32); }
.pk-card.empty { border: 1px dashed rgba(220,240,228,.22); background: rgba(4,40,29,.27); box-shadow: none; }
.pk-seat { position: absolute; z-index: 6; width: 174px; min-height: 78px; padding: 9px 10px; border: 1px solid var(--pk-line); border-radius: 14px; background: rgba(7,17,25,.92); box-shadow: 0 8px 20px rgba(0,0,0,.25); transition: border-color .2s, box-shadow .2s; }
.pk-seat.is-turn { border-color: var(--pk-gold); box-shadow: 0 0 0 3px rgba(241,197,102,.13), 0 8px 20px rgba(0,0,0,.3); }
.pk-seat.is-folded { opacity: .56; filter: saturate(.6); }
.pk-seat[data-seat="0"] { left: 50%; bottom: 24px; transform: translateX(-50%); }
.pk-seat[data-seat="1"] { left: 23px; top: 51%; transform: translateY(-50%); }
.pk-seat[data-seat="2"] { left: 50%; top: 72px; transform: translateX(-50%); }
.pk-seat[data-seat="3"] { right: 23px; top: 51%; transform: translateY(-50%); }
.pk-seat-head { display: flex; align-items: center; gap: 8px; }
.pk-avatar { display: grid; place-items: center; width: 34px; height: 34px; overflow: hidden; border-radius: 50%; background: linear-gradient(145deg,#31516a,#172b3d); color: #fff; font-weight: 800; }
.pk-avatar img { width: 100%; height: 100%; object-fit: cover; }
.pk-who { min-width: 0; flex: 1; }
.pk-name { overflow: hidden; color: #fff; font-weight: 800; text-overflow: ellipsis; white-space: nowrap; }
.pk-stack { margin-top: 2px; color: var(--pk-gold); font-size: 12px; }
.pk-timer { display: none; width: 34px; height: 34px; box-sizing: border-box; border: 2px solid var(--pk-gold); border-radius: 50%; background: #2b2415; color: #ffe4a5; text-align: center; font-size: 13px; font-weight: 950; line-height: 30px; box-shadow: 0 0 0 3px rgba(241,197,102,.1); }
.pk-seat.is-turn .pk-timer { display: block; }
.pk-seat.is-turn .pk-name::after { content: " · 行动中"; color: var(--pk-gold); font-size: 10px; }
.pk-timer.urgent { border-color: #ff6f78; background: #422026; color: #fff; box-shadow: 0 0 0 3px rgba(229,92,101,.14); }
.pk-hole { position: absolute; display: flex; gap: 4px; }
.pk-hole .pk-card { width: 39px; border-radius: 6px; }
.pk-hole .pk-card .rank { left: 4px; top: 3px; font-size: 13px; }
.pk-hole .pk-card .suit { left: 4px; top: 17px; font-size: 12px; }
.pk-hole .pk-card .big-suit { font-size: 20px; }
.pk-seat[data-seat="0"] .pk-hole { left: 50%; bottom: 88px; transform: translateX(-50%); }
.pk-seat[data-seat="1"] .pk-hole { left: 184px; top: 50%; transform: translateY(-50%); }
.pk-seat[data-seat="2"] .pk-hole { left: 50%; top: 88px; transform: translateX(-50%); }
.pk-seat[data-seat="3"] .pk-hole { right: 184px; top: 50%; transform: translateY(-50%); }
.pk-badges { position: absolute; right: 7px; top: -11px; display: flex; gap: 4px; }
.pk-marker { display: grid; place-items: center; min-width: 23px; height: 23px; padding: 0 4px; border: 2px solid #d5aa56; border-radius: 50%; background: #f4d47d; color: #2c220e; font-size: 10px; font-weight: 950; box-sizing: border-box; }
.pk-bet { position: absolute; left: 50%; bottom: -24px; transform: translateX(-50%); color: #c8d6e2; font-size: 11px; white-space: nowrap; }
.pk-hand-name { position: absolute; left: 50%; top: -24px; transform: translateX(-50%); color: var(--pk-gold); font-size: 11px; font-weight: 800; white-space: nowrap; }
.pk-start { position: absolute; z-index: 15; inset: 0; display: grid; place-items: center; padding: 20px; background: rgba(3,11,17,.56); backdrop-filter: blur(5px); }
.pk-start-card { width: min(560px,92%); padding: 27px; border: 1px solid rgba(241,197,102,.28); border-radius: 20px; background: linear-gradient(145deg,rgba(21,38,50,.97),rgba(9,21,31,.98)); box-shadow: 0 24px 70px rgba(0,0,0,.42); }
.pk-start-card h2 { margin: 0; color: #fff; font-size: 25px; }
.pk-start-card > p { margin: 9px 0 20px; color: var(--pk-muted); line-height: 1.6; }
.pk-modes { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; margin-bottom: 17px; }
.pk-mode-input { position: absolute; inline-size: 1px; block-size: 1px; opacity: 0; pointer-events: none; }
.pk-mode { display: block; min-height: 74px; padding: 12px 13px; border: 1px solid var(--pk-line); border-radius: 12px; background: rgba(8,20,29,.78); color: #fff; cursor: pointer; transition: border-color .2s,background .2s,transform .2s; }
.pk-mode b { display: block; font-size: 14px; }.pk-mode small { display: block; margin-top: 5px; color: var(--pk-muted); line-height: 1.4; }
.pk-mode-input:checked + .pk-mode { border-color: var(--pk-gold); background: rgba(241,197,102,.11); box-shadow: inset 0 0 0 1px rgba(241,197,102,.14); }
.pk-mode-input:focus-visible + .pk-mode { outline: 3px solid #83d7ff; outline-offset: 2px; }
.pk-invite-notice { display: none; margin: 0 0 16px; padding: 11px 13px; border: 1px solid rgba(89,217,157,.3); border-radius: 11px; background: rgba(89,217,157,.08); color: #ccebdd; font-size: 13px; }
.pk-stakes { position: relative; display: grid; grid-template-columns: repeat(4,1fr); gap: 9px; }
.pk-stake-input { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
.pk-stake { position: relative; display: grid; place-content: center; min-height: 70px; padding: 8px; border: 1px solid var(--pk-line); border-radius: 12px; background: #122330; color: var(--pk-text); text-align: center; cursor: pointer; transition: border-color .2s, background .2s, box-shadow .2s; }
.pk-stake:hover { border-color: rgba(241,197,102,.62); background: #1d2d31; }
.pk-stake-input:checked + .pk-stake { border-color: var(--pk-gold); background: #263321; box-shadow: inset 0 0 0 2px rgba(241,197,102,.2), 0 0 0 2px rgba(241,197,102,.12); }
.pk-stake-input:focus-visible + .pk-stake { outline: 3px solid #83d7ff; outline-offset: 2px; }
.pk-stake-input:checked + .pk-stake::after { content: ""; position: absolute; right: 9px; top: 8px; width: 9px; height: 5px; border-left: 2px solid var(--pk-gold); border-bottom: 2px solid var(--pk-gold); transform: rotate(-45deg); }
.pk-stake b, .pk-stake small { display: block; }
.pk-stake b { color: var(--pk-gold); font-size: 18px; }
.pk-stake small { margin-top: 4px; color: var(--pk-muted); }
.pk-stake-choice { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 12px; padding: 9px 11px; border: 1px solid rgba(241,197,102,.2); border-radius: 10px; background: rgba(241,197,102,.07); color: var(--pk-muted); font-size: 12px; }
.pk-stake-choice b { color: var(--pk-gold); font-size: 14px; }
.pk-primary { width: 100%; min-height: 48px; margin-top: 14px; border: 0; border-radius: 12px; background: linear-gradient(135deg,#d5a94e,#f1d07f); color: #1b170d; font-size: 16px; font-weight: 900; cursor: pointer; box-shadow: 0 8px 24px rgba(211,167,73,.23); }
.pk-waiting { position: absolute; z-index: 14; inset: 0; display: none; place-items: center; padding: 20px; background: rgba(3,11,17,.5); backdrop-filter: blur(4px); }
.pk-waiting-card { width: min(500px,92%); padding: 25px; border: 1px solid rgba(89,217,157,.28); border-radius: 20px; background: linear-gradient(145deg,rgba(18,43,48,.98),rgba(8,22,31,.98)); box-shadow: 0 24px 70px rgba(0,0,0,.42); text-align: center; }
.pk-waiting-card h2 { margin: 0; color: #fff; font-size: 25px; }.pk-waiting-card > p { margin: 9px 0 17px; color: var(--pk-muted); line-height: 1.6; }
.pk-waiting-seats { display: flex; justify-content: center; gap: 10px; margin: 17px 0; }
.pk-waiting-dot { display: grid; place-items: center; width: 42px; height: 42px; border: 1px dashed rgba(145,168,189,.42); border-radius: 50%; color: var(--pk-muted); font-size: 12px; }
.pk-waiting-dot.full { border-style: solid; border-color: #59d99d; background: rgba(89,217,157,.14); color: #dffff0; }
.pk-invite-box { display: none; grid-template-columns: 1fr auto; gap: 8px; margin-top: 14px; }
.pk-invite-box input { min-width: 0; height: 43px; padding: 0 11px; border: 1px solid var(--pk-line); border-radius: 10px; background: #07131c; color: #c6d7e5; }
.pk-secondary { min-height: 43px; padding: 0 15px; border: 1px solid var(--pk-line); border-radius: 10px; background: #183044; color: #fff; font-weight: 800; cursor: pointer; }
.pk-waiting-actions { display: flex; justify-content: center; gap: 9px; margin-top: 15px; }.pk-waiting-actions .pk-primary { width: auto; min-width: 150px; margin: 0; padding: 0 17px; }
.pk-actions { display: flex; align-items: stretch; justify-content: center; gap: 9px; min-height: 52px; margin-top: 12px; }
.pk-action { min-width: 116px; min-height: 48px; padding: 9px 16px; border: 1px solid var(--pk-line); border-radius: 12px; background: var(--pk-panel-2); color: #fff; font-size: 15px; font-weight: 850; cursor: pointer; transition: background .2s,border-color .2s; }
.pk-action:hover { border-color: rgba(255,255,255,.38); background: #20394d; }
.pk-action.fold { color: #ffabb0; }
.pk-action.raise { border-color: rgba(241,197,102,.38); background: #3a321e; color: #ffe2a1; }
.pk-action:disabled { opacity: .38; cursor: not-allowed; filter: saturate(.45); box-shadow: none; }
.pk-action:disabled:hover { border-color: var(--pk-line); background: var(--pk-panel-2); }
.pk-action.fold:disabled:hover { color: #ffabb0; }
.pk-action.raise:disabled:hover { border-color: rgba(241,197,102,.38); background: #3a321e; }
.pk-wait { display: flex; align-items: center; color: var(--pk-muted); }
.pk-wait b { margin-left: 5px; color: #fff; }
.pk-side { display: flex; flex-direction: column; gap: 12px; }
.pk-panel { overflow: hidden; border: 1px solid var(--pk-line); border-radius: 16px; background: rgba(15,29,41,.9); }
.pk-panel h2 { margin: 0; padding: 13px 15px; border-bottom: 1px solid var(--pk-line); color: #fff; font-size: 15px; }
.pk-stat-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 1px; background: var(--pk-line); }
.pk-stat { padding: 13px; background: #101e2a; color: var(--pk-muted); font-size: 12px; }
.pk-stat b { display: block; margin-top: 4px; color: #fff; font-size: 18px; }
.pk-log { max-height: 210px; overflow: auto; padding: 8px 14px; }
.pk-log-row { display: flex; gap: 8px; padding: 7px 0; border-bottom: 1px solid rgba(151,181,207,.09); color: #bed0df; font-size: 12px; line-height: 1.4; }
.pk-log-row::before { content: ""; flex: 0 0 auto; width: 5px; height: 5px; margin-top: 6px; border-radius: 50%; background: #4b7895; }
.pk-rank { width: 100%; border-collapse: collapse; color: #c8d7e4; font-size: 12px; }
.pk-rank th, .pk-rank td { padding: 8px 10px; border-bottom: 1px solid rgba(151,181,207,.08); text-align: left; }
.pk-rank th { color: var(--pk-muted); font-weight: 600; }
.pk-pos { color: #59d99d; }.pk-neg { color: #ff7c85; }
.pk-rules { padding: 12px 15px; color: var(--pk-muted); font-size: 12px; line-height: 1.7; }
.pk-rules strong { color: #e8f2fa; }
.pk-result { position: fixed; z-index: 999; inset: 0; display: none; place-items: center; padding: 18px; background: rgba(2,8,13,.72); backdrop-filter: blur(8px); }
.pk-result.show { display: grid; }
.pk-result-card { width: min(490px,100%); max-height: 90vh; overflow: auto; padding: 26px; border: 1px solid rgba(241,197,102,.3); border-radius: 20px; background: #101e2a; box-shadow: 0 30px 80px rgba(0,0,0,.55); }
.pk-result-title { margin: 0; text-align: center; color: #fff; font-size: 28px; }
.pk-result-reason { margin: 7px 0 18px; text-align: center; color: var(--pk-gold); }
.pk-result-row { display: grid; grid-template-columns: 1fr auto; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--pk-line); }
.pk-result-row b { color: #fff; }.pk-result-row small { display: block; margin-top: 3px; color: var(--pk-muted); }
.pk-result-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; margin-top: 17px; }
.pk-result-actions button { min-height: 46px; border: 1px solid var(--pk-line); border-radius: 11px; background: #172c3d; color: #fff; font-weight: 800; cursor: pointer; }
.pk-result-actions .again { grid-column: 1 / -1; border-color: rgba(241,197,102,.4); background: #d7ad55; color: #1d170b; }
.pk-toast { position: fixed; z-index: 1000; left: 50%; bottom: 28px; transform: translate(-50%,20px); padding: 10px 15px; border: 1px solid rgba(255,255,255,.16); border-radius: 10px; background: #172637; color: #fff; opacity: 0; pointer-events: none; transition: opacity .2s,transform .2s; }
.pk-toast.show { opacity: 1; transform: translate(-50%,0); }
.pk-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); }
@media (max-width: 1080px) { .pk-layout { grid-template-columns: 1fr; }.pk-side { display:grid;grid-template-columns:repeat(2,1fr);}.pk-panel.log-panel { grid-column:1/-1; } }
@media (max-width: 760px) {
    .pk-page { padding: 8px 8px 24px; }.pk-top { margin-bottom:8px; }.pk-brand p { display:none; }.pk-balance { padding:8px 10px;font-size:11px; }.pk-balance b { font-size:14px; }
    .pk-board { min-height: 620px; border-radius:18px; }.pk-board::before { inset:8px; }.pk-table { top:47%;width:82%;border-width:11px; }
    .pk-seat { width:136px;min-height:67px;padding:7px; }.pk-avatar { width:29px;height:29px; }.pk-name { font-size:12px; }.pk-stack { font-size:11px; }
    .pk-seat[data-seat="0"] { bottom:18px; }.pk-seat[data-seat="2"] { top:64px; }.pk-seat[data-seat="1"] { left:8px;top:44%; }.pk-seat[data-seat="3"] { right:8px;top:44%; }
    .pk-seat[data-seat="0"] .pk-hole { bottom:77px; }.pk-seat[data-seat="2"] .pk-hole { top:77px; }
    .pk-seat[data-seat="1"] .pk-hole, .pk-seat[data-seat="3"] .pk-hole { left:50%;right:auto;top:-57px;transform:translateX(-50%); }
    .pk-hole .pk-card { width:34px; }.pk-community { top:46%; }.pk-card { width:clamp(42px,12vw,58px); }
    .pk-actions { gap:6px;overflow-x:auto;justify-content:flex-start;padding:0 2px; }.pk-action { min-width:94px;min-height:46px;padding:8px 10px;font-size:13px; }
    .pk-side { grid-template-columns:1fr; }.pk-panel.log-panel { grid-column:auto; }.pk-stakes { grid-template-columns:repeat(2,1fr); }.pk-modes { grid-template-columns:1fr; }
}
@media (max-width: 430px) {
    .pk-board { min-height:570px; }.pk-hud { top:14px; }.pk-pill { padding:5px 8px; }.pk-table { top:45%;width:91%; }
    .pk-seat { width:116px; }.pk-seat[data-seat="2"] { top:49px; }.pk-seat[data-seat="1"],.pk-seat[data-seat="3"] { top:41%; }
    .pk-hole .pk-card { width:30px; }
    .pk-community { top:44%;gap:3px; }.pk-card { width:40px;border-radius:6px; }.pk-card .rank { left:4px;top:3px;font-size:14px; }.pk-card .suit { left:4px;top:19px;font-size:13px; }.pk-card .big-suit { font-size:23px; }
    .pk-start-card,.pk-waiting-card { padding:20px; }.pk-result-card { padding:20px; }.pk-invite-box { grid-template-columns:1fr; }.pk-waiting-actions { flex-direction:column; }.pk-waiting-actions .pk-primary { width:100%; }
}
@media (prefers-reduced-motion: reduce) { *,*::before,*::after { scroll-behavior:auto!important;transition-duration:.01ms!important;animation-duration:.01ms!important;animation-iteration-count:1!important; } }
</style>

<main class="pk-page">
    <header class="pk-top">
        <div class="pk-brand">
            <a class="pk-back" href="/games/" aria-label="返回游戏大厅">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 18l-6-6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
            <div><h1>德州扑克</h1><p>四人固定限注桌 · 服务端洗牌 · 电影票结算</p></div>
        </div>
        <div class="pk-balance">电影票余额 <b id="pkBalance"><?php echo number_format($balance, 0) ?></b></div>
    </header>

    <div class="pk-layout">
        <section class="pk-main" aria-label="德州扑克牌桌">
            <div class="pk-board" id="pkBoard">
                <div class="pk-hud"><span class="pk-pill" id="pkStage">等待开局</span><span class="pk-pill" id="pkTurn">等待行动</span><span class="pk-pill">底池 <b id="pkPot">0</b></span></div>
                <div class="pk-table"><div class="pk-community" id="pkCommunity"></div></div>
                <?php for ($i = 0; $i < POKER_SEATS; $i++) { ?>
                <article class="pk-seat" data-seat="<?php echo $i ?>" id="pkSeat<?php echo $i ?>">
                    <div class="pk-badges" id="pkBadges<?php echo $i ?>"></div>
                    <div class="pk-hand-name" id="pkHandName<?php echo $i ?>"></div>
                    <div class="pk-seat-head">
                        <span class="pk-avatar" id="pkAvatar<?php echo $i ?>">?</span>
                        <span class="pk-who"><span class="pk-name" id="pkName<?php echo $i ?>">等待中</span><span class="pk-stack" id="pkStack<?php echo $i ?>">0</span></span>
                        <span class="pk-timer" id="pkTimer<?php echo $i ?>">0</span>
                    </div>
                    <div class="pk-hole" id="pkHole<?php echo $i ?>"></div>
                    <div class="pk-bet" id="pkBet<?php echo $i ?>"></div>
                </article>
                <?php } ?>
                <div class="pk-start" id="pkStart">
                    <div class="pk-start-card">
                        <h2 id="pkStartTitle">加入德州牌桌</h2>
                        <p>先选择好友桌或快速排队，再选择底注。排队超过 <?php echo POKER_MATCH_WAIT ?> 秒未满员会自动补机器人，真正开局时才托管带入筹码。</p>
                        <div class="pk-invite-notice" id="pkInviteNotice">好友邀请已识别，点击下方按钮即可加入同一张牌桌。</div>
                        <div class="pk-modes" id="pkModes" role="radiogroup" aria-label="加入方式">
                            <input class="pk-mode-input" type="radio" name="poker_mode" id="pkModeMatch" value="match" checked>
                            <label class="pk-mode" for="pkModeMatch"><b>快速排队</b><small>优先匹配真人，<?php echo POKER_MATCH_WAIT ?> 秒未满自动补机器人</small></label>
                            <input class="pk-mode-input" type="radio" name="poker_mode" id="pkModeFriend" value="friend">
                            <label class="pk-mode" for="pkModeFriend"><b>好友一起</b><small>创建专属邀请链接，好友入座后由房主开局</small></label>
                        </div>
                        <div class="pk-stakes" id="pkStakes" role="radiogroup" aria-label="牌桌底注">
                            <?php foreach (POKER_BASE_OPTIONS as $i => $base) { ?>
                            <input class="pk-stake-input" type="radio" name="poker_base" id="pkBase<?php echo $base ?>" value="<?php echo $base ?>" data-buyin="<?php echo $base * POKER_STACK_FACTOR ?>" <?php echo $i === 0 ? 'checked' : '' ?>>
                            <label class="pk-stake" for="pkBase<?php echo $base ?>"><b><?php echo number_format($base) ?></b><small>需 <?php echo number_format($base * POKER_STACK_FACTOR) ?> 票</small></label>
                            <?php } ?>
                        </div>
                        <div class="pk-stake-choice" aria-live="polite"><span>已选择底注 <b id="pkSelectedBase"><?php echo number_format(POKER_BASE_OPTIONS[0]) ?></b></span><span>带入 <b id="pkSelectedBuyin"><?php echo number_format(POKER_BASE_OPTIONS[0] * POKER_STACK_FACTOR) ?></b> 票</span></div>
                        <button type="button" class="pk-primary" id="pkStartBtn">以 <?php echo number_format(POKER_BASE_OPTIONS[0]) ?> 底注开始快速排队</button>
                    </div>
                </div>
                <div class="pk-waiting" id="pkWaiting">
                    <div class="pk-waiting-card">
                        <h2 id="pkWaitingTitle">正在寻找牌友</h2>
                        <p id="pkWaitingText">已加入 1/4，稍候会自动补机器人开局。</p>
                        <div class="pk-waiting-seats" id="pkWaitingSeats" aria-label="牌桌入座人数"></div>
                        <div class="pk-invite-box" id="pkInviteBox"><input id="pkInviteUrl" type="text" readonly aria-label="好友桌邀请链接"><button type="button" class="pk-secondary" id="pkCopyInvite">复制邀请</button></div>
                        <div class="pk-waiting-actions"><button type="button" class="pk-secondary" id="pkLeaveQueue">退出牌桌</button><button type="button" class="pk-primary" id="pkFillBots">补机器人并开局</button></div>
                    </div>
                </div>
            </div>
            <div class="pk-actions" id="pkActions"><span class="pk-wait">请选择底注开始牌局</span></div>
        </section>

        <aside class="pk-side">
            <section class="pk-panel">
                <h2>我的战绩</h2>
                <div class="pk-stat-grid">
                    <div class="pk-stat">总场次<b><?php echo number_format((int)$stats['games']) ?></b></div>
                    <div class="pk-stat">胜场<b><?php echo number_format((int)$stats['wins']) ?></b></div>
                    <div class="pk-stat">净盈亏<b class="<?php echo (float)$stats['net'] >= 0 ? 'pk-pos' : 'pk-neg' ?>"><?php echo ((float)$stats['net'] > 0 ? '+' : '') . number_format((float)$stats['net'], 0) ?></b></div>
                    <div class="pk-stat">单场最佳<b class="pk-pos">+<?php echo number_format(max(0, (float)$stats['best']), 0) ?></b></div>
                </div>
            </section>
            <section class="pk-panel log-panel"><h2>牌局动态</h2><div class="pk-log" id="pkLog"><div class="pk-log-row">选择底注，开始你的第一手牌</div></div></section>
            <section class="pk-panel">
                <h2>德州排行榜</h2>
                <table class="pk-rank"><thead><tr><th>玩家</th><th>胜场</th><th>净盈亏</th></tr></thead><tbody>
                <?php if (!$rankings) { ?><tr><td colspan="3">还没有战绩</td></tr><?php } ?>
                <?php foreach ($rankings as $row) { $net = (float)$row['net']; ?><tr><td><?php echo htmlspecialchars($row['username']) ?></td><td><?php echo number_format((int)$row['wins']) ?></td><td class="<?php echo $net >= 0 ? 'pk-pos' : 'pk-neg' ?>"><?php echo ($net > 0 ? '+' : '') . number_format($net, 0) ?></td></tr><?php } ?>
                </tbody></table>
            </section>
            <section class="pk-panel"><h2>快速规则</h2><div class="pk-rules"><strong>牌型由大到小：</strong>同花顺、四条、葫芦、同花、顺子、三条、两对、一对、高牌。使用两张底牌和五张公共牌中的最佳五张。<br><strong>快捷键：</strong>C 看牌/跟注，R 加注，F 弃牌。</div></section>
        </aside>
    </div>
</main>

<div class="pk-result" id="pkResult" role="dialog" aria-modal="true" aria-labelledby="pkResultTitle">
    <div class="pk-result-card"><h2 class="pk-result-title" id="pkResultTitle">本局结果</h2><div class="pk-result-reason" id="pkResultReason"></div><div id="pkResultRows"></div><div class="pk-result-actions"><button type="button" id="pkResultExit">返回大厅</button><button type="button" id="pkChangeStake">更换底注</button><button type="button" class="again" id="pkAgain">同底注再来一局</button></div></div>
</div>
<div class="pk-toast" id="pkToast" role="status"></div><div class="pk-sr" id="pkLive" aria-live="polite"></div>

<script>
(function () {
    'use strict';
    var state = null, token = '', selectedBase = <?php echo POKER_BASE_OPTIONS[0] ?>, selectedMode = 'match', busy = false, stateReceivedAt = Date.now(), lastAnnouncedTurn = '';
    var balance = <?php echo json_encode($balance) ?>, settledHand = '', lastStartError = '';
    var inviteTableId = <?php echo json_encode($inviteTableId) ?>, inviteCode = <?php echo json_encode($inviteCode) ?>;
    var $ = function (id) { return document.getElementById(id); };
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
    function number(v) { return Math.round(Number(v || 0)).toLocaleString(); }
    function toast(text) { var el=$('pkToast');el.textContent=text;el.classList.add('show');clearTimeout(el._t);el._t=setTimeout(function(){el.classList.remove('show');},1800); }
    function card(c, hidden, empty) {
        if (empty) return '<span class="pk-card empty" aria-hidden="true"></span>';
        if (hidden) return '<span class="pk-card back" aria-label="未公开的底牌"></span>';
        var label=c.label||'', suit=label.charAt(0), rank=label.slice(1);
        return '<span class="pk-card'+(c.red?' red':'')+'" aria-label="'+esc(label)+'"><span class="rank">'+esc(rank)+'</span><span class="suit">'+esc(suit)+'</span><span class="big-suit">'+esc(suit)+'</span></span>';
    }
    function avatar(p) {
        if (p.avatar) return '<img src="'+esc(p.avatar)+'" alt="">';
        return esc((p.username||'?').slice(0,1));
    }
    function post(action, extra) {
        if (busy) return Promise.resolve(null);
        busy=true;
        if(state)renderActions();
        var body='action='+encodeURIComponent(action)+'&token='+encodeURIComponent(token)+(extra||'');
        return fetch('/games/poker/',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
            .then(function(r){return r.json();}).then(function(data){if(!data.ok){toast(data.error||'操作失败');return null;}renderResponse(data);return data;})
            .catch(function(){toast('网络开小差了，请重试');return null;}).finally(function(){busy=false;if(state)renderActions();});
    }
    function renderResponse(data) { if(typeof data.wallet==='number'){balance=Number(data.wallet);$('pkBalance').textContent=number(balance);}state=data.game||null;token=state?state.token:'';stateReceivedAt=Date.now();render(); }
    function liveTimeLeft(){return state&&state.status==='playing'?Math.max(0,Number(state.timeLeft||0)-Math.floor((Date.now()-stateReceivedAt)/1000)):0;}
    function liveQueueLeft(){return state&&state.status==='waiting'?Math.max(0,Number(state.queueLeft||0)-Math.floor((Date.now()-stateReceivedAt)/1000)):0;}
    function renderClock(){
        for(var t=0;t<4;t++){$('pkTimer'+t).textContent='';$('pkTimer'+t).classList.remove('urgent');}
        if(!state){$('pkTurn').textContent='等待行动';return;}
        if(state.status==='waiting'){$('pkTurn').textContent=state.mode==='match'?'等待玩家 · '+liveQueueLeft()+' 秒后补机器人':'好友桌等待加入';if(state.mode==='match')$('pkWaitingText').textContent='已加入 '+state.playerCount+'/4，'+liveQueueLeft()+' 秒后自动补机器人开局。';return;}
        if(state.status!=='playing'){$('pkTurn').textContent='牌局结束';return;}
        var left=liveTimeLeft(), actor=state.players[state.turn], name=actor?actor.username:'等待';
        $('pkTurn').textContent='轮到 '+name+' · '+left+' 秒';
        var turnKey=state.handId+'-'+state.stage+'-'+state.turn;
        if(turnKey!==lastAnnouncedTurn){lastAnnouncedTurn=turnKey;$('pkLive').textContent='轮到 '+name+' 行动';}
        for(var i=0;i<4;i++){var timer=$('pkTimer'+i);timer.textContent=state.turn===i?left:'';timer.classList.toggle('urgent',state.turn===i&&left<=5);timer.setAttribute('aria-label',state.turn===i?name+' 剩余 '+left+' 秒':'');}
    }
    function renderSeats() {
        for(var i=0;i<4;i++){
            var box=$('pkSeat'+i), p=state.players[i];
            box.classList.toggle('is-turn',state.status==='playing'&&state.turn===i);
            box.classList.toggle('is-folded',p.status==='folded');
            $('pkAvatar'+i).innerHTML=avatar(p);$('pkName'+i).textContent=p.username;$('pkStack'+i).textContent=state.status==='waiting'?(p.status==='empty'?'空座':'已入座'):(number(p.stack)+' 筹码');
            var badges='';if(state.dealer===i)badges+='<span class="pk-marker" title="庄家按钮">D</span>';if(state.smallBlind===i)badges+='<span class="pk-marker" title="小盲注">SB</span>';if(state.bigBlind===i)badges+='<span class="pk-marker" title="大盲注">BB</span>';$('pkBadges'+i).innerHTML=badges;
            $('pkBet'+i).textContent=p.roundBet>0?'本轮 '+number(p.roundBet):(p.status==='folded'?'已弃牌':'');
            $('pkHandName'+i).textContent=p.handName||'';
            if(state.status==='waiting'){$('pkHole'+i).innerHTML='';}
            else if(p.cards&&p.cards.length){$('pkHole'+i).innerHTML=p.cards.map(function(c){return card(c);}).join('');}
            else {$('pkHole'+i).innerHTML=card(null,true)+card(null,true);}
        }
    }
    function renderCommunity() { var html='';for(var i=0;i<5;i++)html+=state.community[i]?card(state.community[i]):card(null,false,true);$('pkCommunity').innerHTML=html; }
    function renderActions() {
        var el=$('pkActions');
        if(!state){el.innerHTML='<span class="pk-wait">选择加入方式和底注，进入排队大厅</span>';return;}
        if(state.status==='waiting'){el.innerHTML='<span class="pk-wait">已在牌桌等待区 · '+state.playerCount+'/4 人</span>';return;}
        if(state.status==='finished'){el.innerHTML='<span class="pk-wait">牌局已结束 · <b>'+esc(state.finishReason)+'</b></span>';return;}
        var myTurn=state.turn===0&&state.players[0]&&state.players[0].status==='active'&&!busy;
        var disabled=myTurn?'':' disabled aria-disabled="true"';
        var callText=state.toCall>0?'跟注 '+number(state.toCall):'看牌';
        el.innerHTML='<button class="pk-action fold" data-action="fold"'+disabled+'>弃牌 <small>F</small></button>'+
            '<button class="pk-action" data-action="'+(state.toCall>0?'call':'check')+'"'+disabled+'>'+callText+' <small>C</small></button>'+
            '<button class="pk-action raise" data-action="raise"'+(myTurn&&state.canRaise?'':' disabled aria-disabled="true"')+'>加注至 '+number(state.raiseTo)+' <small>R</small></button>';
        el.querySelectorAll('button[data-action]').forEach(function(btn){btn.addEventListener('click',function(){post(btn.getAttribute('data-action'));});});
    }
    function renderLog(){var logs=state.logs||[];$('pkLog').innerHTML=logs.slice().reverse().map(function(row){return '<div class="pk-log-row">'+esc(row.text)+'</div>';}).join('')||'<div class="pk-log-row">牌局开始</div>';}
    function renderResult(){
        if(state.status!=='finished'){$('pkResult').classList.remove('show');return;}
        if(settledHand!==state.handId){settledHand=state.handId;}
        var my=Number((state.deltas||[])[0]||0);$('pkResultTitle').textContent=my>0?'本局胜利':(my<0?'本局失利':'本局平手');$('pkResultReason').textContent=state.finishReason+' · 底池 '+number(state.pot);
        var winners=state.winners||[];$('pkResultRows').innerHTML=state.players.map(function(p,i){var d=Number((state.deltas||[])[i]||0);return '<div class="pk-result-row"><span><b>'+esc(p.username)+(winners.indexOf(i)>=0?' · 获胜':'')+'</b><small>'+esc(p.handName||(p.status==='folded'?'弃牌':'未摊牌'))+'</small></span><strong class="'+(d>=0?'pk-pos':'pk-neg')+'">'+(d>0?'+':'')+number(d)+'</strong></div>';}).join('');
        $('pkResult').classList.add('show');$('pkLive').textContent=$('pkResultTitle').textContent+'，'+state.finishReason;
    }
    function renderWaiting(){
        var waiting=state&&state.status==='waiting';$('pkWaiting').style.display=waiting?'grid':'none';if(!waiting)return;
        var isFriend=state.mode==='friend';$('pkWaitingTitle').textContent=isFriend?'好友桌已创建':'正在寻找牌友';
        $('pkWaitingText').textContent=isFriend?('已加入 '+state.playerCount+'/4，把邀请链接发给好友；房主也可以随时补机器人开局。'):('已加入 '+state.playerCount+'/4，'+liveQueueLeft()+' 秒后自动补机器人开局。');
        var dots='';for(var i=0;i<4;i++)dots+='<span class="pk-waiting-dot '+(i<state.playerCount?'full':'')+'">'+(i<state.playerCount?'已入座':'空位')+'</span>';$('pkWaitingSeats').innerHTML=dots;
        $('pkInviteBox').style.display=isFriend?'grid':'none';$('pkInviteUrl').value=state.inviteUrl||'';
        $('pkFillBots').style.display=isFriend&&state.isOwner?'inline-block':'none';
        if(state.startError&&state.startError!==lastStartError){lastStartError=state.startError;toast(state.startError);}
    }
    function render(){
        $('pkStart').style.display=state?'none':'grid';renderWaiting();
        if(!state){$('pkStage').textContent='等待开局';$('pkTurn').textContent='等待行动';$('pkPot').textContent='0';renderActions();return;}
        $('pkStage').textContent=state.status==='waiting'?(state.mode==='friend'?'好友桌 · 底注 '+number(state.base):'快速排队 · 底注 '+number(state.base)):(state.stageLabel+' · 加注 '+state.raiseCount+'/'+state.raiseCap);$('pkPot').textContent=number(state.pot);
        renderSeats();renderCommunity();renderActions();renderLog();renderResult();renderClock();
    }
    function poll(){fetch('/games/poker/?ajax=poll',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(data){if(data.ok)renderResponse(data);}).catch(function(){});}
    function updateChoice(){var checked=document.querySelector('input[name="poker_base"]:checked'),mode=document.querySelector('input[name="poker_mode"]:checked');if(checked){selectedBase=Number(checked.value);$('pkSelectedBase').textContent=number(selectedBase);$('pkSelectedBuyin').textContent=number(checked.getAttribute('data-buyin'));}if(mode)selectedMode=mode.value;if(inviteTableId){$('pkStartBtn').textContent='加入好友牌桌';return;}$('pkStartBtn').textContent='以 '+number(selectedBase)+' 底注'+(selectedMode==='friend'?'创建好友桌':'开始快速排队');}
    $('pkModes').addEventListener('change',updateChoice);$('pkStakes').addEventListener('change',updateChoice);
    $('pkStartBtn').addEventListener('click',function(){updateChoice();if(inviteTableId)post('join','&table='+inviteTableId+'&invite='+encodeURIComponent(inviteCode));else post(selectedMode==='friend'?'create':'match','&base='+selectedBase);});
    $('pkLeaveQueue').addEventListener('click',function(){post('leave');});
    $('pkFillBots').addEventListener('click',function(){post('fill_bots');});
    $('pkCopyInvite').addEventListener('click',function(){var value=$('pkInviteUrl').value;if(navigator.clipboard&&window.isSecureContext)navigator.clipboard.writeText(value).then(function(){toast('邀请链接已复制');});else{$('pkInviteUrl').select();document.execCommand('copy');toast('邀请链接已复制');}});
    $('pkResultExit').addEventListener('click',function(){location.href='/games/';});
    $('pkChangeStake').addEventListener('click',function(){$('pkResult').classList.remove('show');post('reset');});
    $('pkAgain').addEventListener('click',function(){$('pkResult').classList.remove('show');post('rematch');});
    document.addEventListener('keydown',function(e){if(!state||state.status!=='playing'||state.turn!==0||e.ctrlKey||e.metaKey||e.altKey)return;var k=e.key.toLowerCase();if(k==='f')post('fold');else if(k==='c')post(state.toCall>0?'call':'check');else if(k==='r'&&state.canRaise)post('raise');});
    if(inviteTableId){$('pkInviteNotice').style.display='block';$('pkModes').style.display='none';$('pkStakes').style.display='none';document.querySelector('.pk-stake-choice').style.display='none';$('pkStartTitle').textContent='加入好友牌桌';}
    updateChoice();
    poll();setInterval(poll,1200);setInterval(renderClock,250);
})();
</script>
<?php stdfoot(); ?>
