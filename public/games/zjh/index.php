<?php
$zjhRuntimeOnly = defined('ZJH_RUNTIME_ONLY') && ZJH_RUNTIME_ONLY;
require dirname(__DIR__, 3) . '/include/bittorrent.php';
dbconn(false, !$zjhRuntimeOnly);
if (!$zjhRuntimeOnly) {
    loggedinorreturn();
    parked();
    $GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
    $GLOBALS['nexus_hide_top_banner'] = true;
}
require_once dirname(__DIR__, 3) . '/include/game_control.php';
require_once __DIR__ . '/engine.php';
if (!$zjhRuntimeOnly) game_guard('zjh');

const ZJH_MIN_SEATS = 3;
const ZJH_MAX_SEATS = 10;
const ZJH_BASES = [100, 500, 1000, 5000];
const ZJH_STACK_FACTOR = 20;
const ZJH_TURN_SECONDS = 30;
const ZJH_BOT_THINK_SECONDS = 2;
const ZJH_RAISE_CAP = 5;
const ZJH_MAX_ACTIONS = 100;
const ZJH_ROOM_TTL = 604800;
const ZJH_BUSINESS_TYPE = 113;
const ZJH_ESCROW_BUSINESS_TYPE = 114;
const ZJH_DEADLINE_KEY = 'zjh:room-deadlines';

function zjh_redis() { return \Nexus\Database\NexusDB::redis(); }
function zjh_now() { return time(); }
function zjh_room_key($id) { return 'zjh:room:' . (int)$id; }
function zjh_user_key($uid) { return 'zjh:user-room:' . (int)$uid; }
function zjh_lock_key($id) { return 'zjh:lock:' . (int)$id; }
function zjh_lobby_key($base, $seats) { return 'zjh:lobby:' . (int)$base . ':' . (int)$seats; }

function zjh_seat_count($room)
{
    $count = (int)($room['seat_count'] ?? count($room['players'] ?? []));
    return max(ZJH_MIN_SEATS, min(ZJH_MAX_SEATS, $count ?: ZJH_MIN_SEATS));
}

function zjh_validate_seats($seats)
{
    $seats = (int)$seats;
    return $seats >= ZJH_MIN_SEATS && $seats <= ZJH_MAX_SEATS ? $seats : 0;
}

function zjh_room_get($id)
{
    $raw = zjh_redis()->get(zjh_room_key($id));
    return $raw ? json_decode($raw, true) : null;
}

function zjh_seat_of($room, $uid)
{
    foreach (($room['players'] ?? []) as $seat => $player) {
        if ($player && empty($player['bot']) && (int)$player['uid'] === (int)$uid) return (int)$seat;
    }
    return -1;
}

function zjh_room_put($room)
{
    $redis = zjh_redis();
    $redis->setex(zjh_room_key($room['id']), ZJH_ROOM_TTL, json_encode($room, JSON_UNESCAPED_UNICODE));
    if (($room['status'] ?? '') === 'playing') {
        $turn = (int)($room['turn'] ?? -1);
        $player = $room['players'][$turn] ?? [];
        $due = !empty($player['bot']) ? (int)($room['bot_ready_at'] ?? $room['deadline'] ?? 0) : (int)($room['deadline'] ?? 0);
        if ($due > 0) $redis->zAdd(ZJH_DEADLINE_KEY, $due, (string)$room['id']);
    } else {
        $redis->zRem(ZJH_DEADLINE_KEY, (string)$room['id']);
    }
    foreach (($room['players'] ?? []) as $player) {
        if ($player && empty($player['bot']) && empty($player['departed'])) $redis->setex(zjh_user_key($player['uid']), ZJH_ROOM_TTL, (int)$room['id']);
    }
}

function zjh_current_room_id($uid)
{
    $redis = zjh_redis();
    $id = (int)$redis->get(zjh_user_key($uid));
    if ($id > 0) {
        $room = zjh_room_get($id);
        if ($room && zjh_seat_of($room, $uid) >= 0) return $id;
        $redis->del(zjh_user_key($uid));
    }
    return 0;
}

function zjh_lock($id)
{
    $token = bin2hex(random_bytes(8));
    for ($i = 0; $i < 25; $i++) {
        if (zjh_redis()->set(zjh_lock_key($id), $token, ['nx', 'ex' => 8])) return $token;
        usleep(60000);
    }
    return false;
}

function zjh_unlock($id, $token)
{
    $redis = zjh_redis();
    if ($redis->get(zjh_lock_key($id)) === $token) $redis->del(zjh_lock_key($id));
}

function zjh_ensure_tables()
{
    static $done = false;
    if ($done) return;
    @sql_query("CREATE TABLE IF NOT EXISTS `hdvideo_zjh_results` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `hand_id` varchar(32) NOT NULL,
        `uid` int unsigned NOT NULL,
        `base` int unsigned NOT NULL,
        `result` enum('win','lose') NOT NULL,
        `hand_name` varchar(32) NOT NULL DEFAULT '',
        `delta` decimal(20,1) NOT NULL DEFAULT '0.0',
        `pot` decimal(20,1) NOT NULL DEFAULT '0.0',
        `created_at` datetime NOT NULL,
        PRIMARY KEY (`id`), UNIQUE KEY `uk_hand_uid` (`hand_id`,`uid`),
        KEY `idx_uid` (`uid`), KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function zjh_wallet($uid)
{
    $res = sql_query('SELECT `seedbonus` FROM `users` WHERE `id`=' . (int)$uid) or sqlerr(__FILE__, __LINE__);
    $row = mysql_fetch_assoc($res);
    return $row ? (float)$row['seedbonus'] : 0;
}

function zjh_log(&$room, $message)
{
    $room['logs'][] = ['time' => date('H:i:s'), 'text' => $message];
    if (count($room['logs']) > 16) $room['logs'] = array_slice($room['logs'], -16);
}

function zjh_player_from_current()
{
    global $CURUSER;
    return ['uid'=>(int)$CURUSER['id'], 'username'=>$CURUSER['username'], 'status'=>'waiting', 'seen'=>false, 'revealed'=>false, 'cards'=>[], 'stack'=>0, 'contrib'=>0];
}

function zjh_bot_difficulty()
{
    $difficulty = (string)(game_control_get('zjh')['bot_difficulty'] ?? 'simple');
    return in_array($difficulty, ['simple', 'hard', 'hell'], true) ? $difficulty : 'simple';
}

function zjh_difficulty_label($difficulty)
{
    return ['simple'=>'简单', 'hard'=>'困难', 'hell'=>'地狱'][$difficulty] ?? '简单';
}

function zjh_bot_player($seat, $difficulty)
{
    $names = ['阿策', '小金', '牌九', '星仔', '十三', '老千', '红桃', '黑桃', '方块', '梅花'];
    return [
        'uid'=>0, 'username'=>'机器人·' . $names[(int)$seat % count($names)],
        'bot'=>true, 'difficulty'=>$difficulty, 'status'=>'waiting', 'seen'=>false, 'revealed'=>false,
        'cards'=>[], 'stack'=>0, 'contrib'=>0, 'escrow'=>0,
    ];
}

function zjh_new_room($base, $mode, $seats)
{
    global $CURUSER;
    $base = (int)$base;
    if (!in_array($base, ZJH_BASES, true)) return [0, '请选择有效底注。'];
    $seats = zjh_validate_seats($seats);
    if (!$seats) return [0, '请选择 3 至 10 人的牌桌。'];
    if (zjh_current_room_id((int)$CURUSER['id'])) return [zjh_current_room_id((int)$CURUSER['id']), ''];
    $id = (int)zjh_redis()->incr('zjh:room-sequence') + 100000;
    $room = [
        'id'=>$id, 'invite'=>strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)), 'mode'=>$mode,
        'owner'=>(int)$CURUSER['id'], 'base'=>$base, 'seat_count'=>$seats, 'status'=>'waiting',
        'players'=>array_replace(array_fill(0, $seats, null), [0=>zjh_player_from_current()]),
        'created_at'=>zjh_now(), 'logs'=>[], 'token'=>bin2hex(random_bytes(16)), 'ready'=>[],
        'bot_difficulty'=>zjh_bot_difficulty()
    ];
    zjh_log($room, $CURUSER['username'] . ' 已入座，等待玩家或补机器人');
    zjh_room_put($room);
    return [$id, ''];
}

function zjh_add_current_player(&$room)
{
    global $CURUSER;
    if (($room['status'] ?? '') !== 'waiting') return '牌局已经开始。';
    if (zjh_seat_of($room, (int)$CURUSER['id']) >= 0) return '';
    foreach ($room['players'] as $seat => $player) {
        if (!$player) {
            $room['players'][$seat] = zjh_player_from_current();
            zjh_log($room, $CURUSER['username'] . ' 已入座');
            return '';
        }
    }
    return '牌桌已满。';
}

function zjh_fill_bots(&$room)
{
    if (($room['status'] ?? '') !== 'waiting') return '牌局已经开始。';
    $difficulty = zjh_bot_difficulty();
    $room['bot_difficulty'] = $difficulty;
    foreach ($room['players'] as $seat => $player) {
        if (!$player) $room['players'][$seat] = zjh_bot_player($seat, $difficulty);
    }
    zjh_log($room, '已按“' . zjh_difficulty_label($difficulty) . '”难度补齐机器人');
    return zjh_start($room);
}

function zjh_reserve(&$room, $handId)
{
    $amount = (float)((int)$room['base'] * ZJH_STACK_FACTOR);
    $uids = [];
    foreach ($room['players'] as $seat => $player) {
        if ($player && empty($player['bot']) && (int)$player['uid'] > 0) $uids[(int)$player['uid']] = (int)$seat;
    }
    ksort($uids, SORT_NUMERIC);
    $now = date('Y-m-d H:i:s');
    sql_query('START TRANSACTION') or sqlerr(__FILE__, __LINE__);
    try {
        $balances = [];
        foreach ($uids as $uid => $seat) {
            $res = sql_query('SELECT `seedbonus` FROM `users` WHERE `id`=' . $uid . ' FOR UPDATE') or sqlerr(__FILE__, __LINE__);
            $row = mysql_fetch_assoc($res); $balances[$uid] = $row ? (float)$row['seedbonus'] : 0;
            if ($balances[$uid] < $amount) { sql_query('ROLLBACK'); return $room['players'][$seat]['username'] . ' 的电影票不足 ' . number_format($amount) . '。'; }
        }
        foreach ($uids as $uid => $seat) {
            $old = $balances[$uid]; $new = $old - $amount;
            sql_query('UPDATE `users` SET `seedbonus`=`seedbonus`-' . sqlesc(number_format($amount, 1, '.', '')) . ' WHERE `id`=' . $uid) or sqlerr(__FILE__, __LINE__);
            sql_query(sprintf("INSERT INTO `bonus_logs` (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
                ZJH_ESCROW_BUSINESS_TYPE, $uid, sqlesc($old), sqlesc(-$amount), sqlesc($new), sqlesc('[炸金花托管] 手牌 ' . $handId . ' 带入筹码'), sqlesc($now), sqlesc($now))) or sqlerr(__FILE__, __LINE__);
            $room['players'][$seat]['escrow'] = $amount;
            $room['players'][$seat]['balance_before'] = $old;
            $room['players'][$seat]['wallet_after_reserve'] = $new;
        }
        sql_query('COMMIT') or sqlerr(__FILE__, __LINE__);
        foreach (array_keys($uids) as $uid) clear_user_cache($uid);
        return '';
    } catch (Throwable $e) { sql_query('ROLLBACK'); throw $e; }
}

function zjh_commit(&$room, $seat, $amount)
{
    $amount = (int)$amount;
    $room['players'][$seat]['stack'] -= $amount;
    $room['players'][$seat]['contrib'] += $amount;
    $room['pot'] += $amount;
}

function zjh_start(&$room)
{
    $seatCount = zjh_seat_count($room);
    if (($room['status'] ?? '') !== 'waiting') return '';
    if (count(array_filter($room['players'])) !== $seatCount) return '等待玩家入座，或选择补机器人开局。';
    $realCount = count(array_filter($room['players'], fn($player) => $player && empty($player['bot']) && (int)$player['uid'] > 0));
    if ($realCount < 1) return '牌桌至少需要一位真人玩家。';
    $handId = bin2hex(random_bytes(8));
    $error = zjh_reserve($room, $handId);
    if ($error !== '') { $room['start_error'] = $error; return $error; }
    $deck = range(0, 51); shuffle($deck);
    $stack = (int)$room['base'] * ZJH_STACK_FACTOR;
    $room['pot'] = 0;
    foreach ($room['players'] as $seat => &$player) {
        $player['cards'] = [array_pop($deck), array_pop($deck), array_pop($deck)];
        $player['stack'] = $stack; $player['contrib'] = 0; $player['seen'] = false; $player['revealed'] = false; $player['status'] = 'active';
        zjh_commit($room, $seat, (int)$room['base']);
    }
    unset($player);
    $room['hand_id'] = $handId; $room['status'] = 'playing'; $room['dealer'] = random_int(0, $seatCount - 1);
    $room['turn'] = ($room['dealer'] + 1) % $seatCount;
    $room['current_bet'] = (int)$room['base']; $room['raise_count'] = 0; $room['action_count'] = 0;
    $room['token'] = bin2hex(random_bytes(16)); $room['logs'] = []; unset($room['start_error'], $room['showdown_pending']);
    $botCount = $seatCount - $realCount;
    zjh_log($room, '牌局开始：' . $realCount . ' 位真人、' . $botCount . ' 位机器人，每席投入底注 ' . $room['base']);
    zjh_log($room, $room['players'][$room['turn']]['username'] . ' 先行动');
    zjh_set_turn($room, $room['turn']);
    return '';
}

function zjh_active($room)
{
    $out = [];
    foreach ($room['players'] as $seat => $player) if (($player['status'] ?? '') === 'active') $out[] = (int)$seat;
    return $out;
}

function zjh_next_active($room, $seat)
{
    $seatCount = zjh_seat_count($room);
    for ($i=1; $i<=$seatCount; $i++) { $next = ((int)$seat + $i) % $seatCount; if (($room['players'][$next]['status'] ?? '') === 'active') return $next; }
    return -1;
}

function zjh_set_turn(&$room, $seat)
{
    $room['turn'] = (int)$seat;
    if ($seat >= 0 && !empty($room['players'][$seat]['bot'])) {
        $room['bot_ready_at'] = zjh_now() + ZJH_BOT_THINK_SECONDS;
        $room['deadline'] = zjh_now() + ZJH_BOT_THINK_SECONDS + 3;
    } else {
        unset($room['bot_ready_at']);
        $room['deadline'] = zjh_now() + ZJH_TURN_SECONDS;
    }
}

function zjh_best_seat(&$room, $seats)
{
    $winner = (int)$seats[0]; $best = zjh_evaluate($room['players'][$winner]['cards']);
    foreach (array_slice($seats, 1) as $seat) {
        $score = zjh_evaluate($room['players'][$seat]['cards']);
        if (zjh_compare_scores($score, $best) > 0) { $winner = (int)$seat; $best = $score; }
    }
    return $winner;
}

function zjh_settle(&$room, $winner, $reason)
{
    if (($room['status'] ?? '') === 'finished') return;
    zjh_ensure_tables();
    $winner = (int)$winner; $room['winner'] = $winner; $room['finish_reason'] = $reason;
    $now = date('Y-m-d H:i:s');
    foreach ($room['players'] as $seat => &$player) {
        $player['hand_name'] = zjh_score_name(zjh_evaluate($player['cards']));
        if (!empty($player['bot'])) $player['delta'] = 0;
    }
    unset($player);
    $uids = [];
    foreach ($room['players'] as $seat => $player) {
        if (empty($player['bot']) && (int)$player['uid'] > 0) $uids[(int)$player['uid']] = (int)$seat;
    }
    ksort($uids, SORT_NUMERIC);
    sql_query('START TRANSACTION') or sqlerr(__FILE__, __LINE__);
    try {
        foreach ($uids as $uid => $seat) {
            $player =& $room['players'][$seat];
            $payout = (float)$player['stack'] + ($seat === $winner ? (float)$room['pot'] : 0);
            $delta = round($payout - (float)$player['escrow'], 1);
            $handName = $player['hand_name'];
            $res = sql_query('SELECT `seedbonus` FROM `users` WHERE `id`=' . $uid . ' FOR UPDATE') or sqlerr(__FILE__, __LINE__);
            $row = mysql_fetch_assoc($res); $old = (float)$row['seedbonus']; $new = $old + $payout;
            sql_query('UPDATE `users` SET `seedbonus`=`seedbonus`+' . sqlesc(number_format($payout, 1, '.', '')) . ' WHERE `id`=' . $uid) or sqlerr(__FILE__, __LINE__);
            sql_query(sprintf("INSERT INTO `bonus_logs` (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
                ZJH_ESCROW_BUSINESS_TYPE, $uid, sqlesc($old), sqlesc($payout), sqlesc($new), sqlesc('[炸金花托管] 手牌 ' . $room['hand_id'] . ' 退回筹码与底池'), sqlesc($now), sqlesc($now))) or sqlerr(__FILE__, __LINE__);
            $summaryOld = (float)$player['balance_before'];
            sql_query(sprintf("INSERT INTO `bonus_logs` (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
                ZJH_BUSINESS_TYPE, $uid, sqlesc($summaryOld), sqlesc($delta), sqlesc($summaryOld + $delta), sqlesc('[炸金花] ' . $handName . ($seat === $winner ? ' 获胜' : ' 落败') . '，底池 ' . $room['pot']), sqlesc($now), sqlesc($now))) or sqlerr(__FILE__, __LINE__);
            sql_query(sprintf("INSERT IGNORE INTO `hdvideo_zjh_results` (`hand_id`,`uid`,`base`,`result`,`hand_name`,`delta`,`pot`,`created_at`) VALUES (%s,%d,%d,%s,%s,%s,%s,%s)",
                sqlesc($room['hand_id']), $uid, (int)$room['base'], sqlesc($seat === $winner ? 'win' : 'lose'), sqlesc($handName), sqlesc($delta), sqlesc($room['pot']), sqlesc($now))) or sqlerr(__FILE__, __LINE__);
            $player['hand_name'] = $handName; $player['delta'] = $delta; $player['wallet_after_settle'] = $new;
            unset($player);
        }
        sql_query('COMMIT') or sqlerr(__FILE__, __LINE__);
        foreach (array_keys($uids) as $uid) clear_user_cache($uid);
    } catch (Throwable $e) { sql_query('ROLLBACK'); throw $e; }
    $room['status'] = 'finished'; $room['turn'] = -1; $room['finished_at'] = zjh_now(); $room['ready'] = [];
    zjh_log($room, $room['players'][$winner]['username'] . ' 获胜：' . $reason);
}

function zjh_force_capped_showdown(&$room, $seat = null)
{
    if (($room['status'] ?? '') !== 'playing' || !empty($room['showdown_pending'])) return false;
    $active = zjh_active($room);
    if (count($active) < 2) return false;
    $seat = $seat === null ? (int)($room['turn'] ?? -1) : (int)$seat;
    $player = $room['players'][$seat] ?? [];
    if (($player['status'] ?? '') !== 'active' || !zjh_requires_showdown($player['stack'] ?? 0, $room['current_bet'] ?? $room['base'], !empty($player['seen']))) return false;
    zjh_settle($room, zjh_best_seat($room, $active), '有玩家达到带入筹码上限，系统自动亮牌');
    return true;
}

function zjh_reveal_and_advance(&$room, $seat, $reason)
{
    if (($room['status'] ?? '') !== 'playing') return '牌局已经结束。';
    $seat = (int)$seat;
    $player =& $room['players'][$seat];
    if (($player['status'] ?? '') !== 'active') return '该玩家已不在牌局中。';
    if (!empty($player['revealed'])) return '';
    $room['showdown_pending'] = true;
    $player['seen'] = true;
    $player['revealed'] = true;
    zjh_log($room, $player['username'] . ' ' . $reason . '，已亮牌等待其他玩家');
    unset($player);

    $active = zjh_active($room);
    $waiting = zjh_unrevealed_active_seats($room['players']);
    if (!$waiting) {
        zjh_settle($room, zjh_best_seat($room, $active), '所有仍在局玩家均已亮牌');
        return '';
    }
    $seatCount = zjh_seat_count($room);
    $next = -1;
    for ($i = 1; $i <= $seatCount; $i++) {
        $candidate = ($seat + $i) % $seatCount;
        if (in_array($candidate, $waiting, true)) { $next = $candidate; break; }
    }
    if ($next < 0) {
        zjh_settle($room, zjh_best_seat($room, $active), '所有仍在局玩家均已亮牌');
        return '';
    }
    zjh_set_turn($room, $next);
    return '';
}

function zjh_advance(&$room, $seat)
{
    $active = zjh_active($room);
    if (count($active) === 1) { zjh_settle($room, $active[0], '其余玩家均已弃牌'); return; }
    if ((int)$room['action_count'] >= ZJH_MAX_ACTIONS) { $winner = zjh_best_seat($room, $active); zjh_settle($room, $winner, '达到操作上限，强制亮牌'); return; }
    $next = zjh_next_active($room, $seat);
    if ($next < 0 || zjh_force_capped_showdown($room, $next)) return;
    zjh_set_turn($room, $next);
}

function zjh_apply(&$room, $seat, $action, $targetSeat = null)
{
    if (($room['status'] ?? '') !== 'playing') return '牌局尚未开始或已经结束。';
    $seat = (int)$seat; $player =& $room['players'][$seat];
    if ($action === 'reveal') {
        if (empty($room['showdown_pending'])) return '当前尚未进入亮牌阶段。';
        if ((int)$room['turn'] !== $seat || ($player['status'] ?? '') !== 'active' || !empty($player['revealed'])) return '还没轮到你亮牌。';
        unset($player);
        return zjh_reveal_and_advance($room, $seat, '主动亮牌');
    }
    if (!empty($room['showdown_pending'])) return '当前正在逐个亮牌，请等待轮到你。';
    if ($action === 'peek') {
        if (($player['status'] ?? '') !== 'active') return '你已经弃牌。';
        if (!$player['seen']) { $player['seen'] = true; zjh_log($room, $player['username'] . ' 已看牌'); }
        return '';
    }
    if ((int)$room['turn'] !== $seat || ($player['status'] ?? '') !== 'active') return '还没轮到你行动。';
    $unit = zjh_action_cost($room['current_bet'], !empty($player['seen']));
    if ($action === 'fold') {
        $player['status'] = 'folded'; zjh_log($room, $player['username'] . ' 弃牌');
    } elseif ($action === 'call') {
        if ((int)$player['stack'] < $unit) return '桌面筹码不足以跟注。';
        zjh_commit($room, $seat, $unit); zjh_log($room, $player['username'] . ' 跟注 ' . $unit);
    } elseif ($action === 'raise') {
        if ((int)$room['raise_count'] >= ZJH_RAISE_CAP) return '本局已达到加注上限。';
        $room['current_bet'] += (int)$room['base']; $unit = (int)$room['current_bet'] * ($player['seen'] ? 2 : 1);
        if ((int)$player['stack'] < $unit) { $room['current_bet'] -= (int)$room['base']; return '桌面筹码不足以加注。'; }
        zjh_commit($room, $seat, $unit); $room['raise_count']++; zjh_log($room, $player['username'] . ' 加注，投入 ' . $unit);
    } elseif ($action === 'compare') {
        $opponent = $targetSeat === null ? zjh_next_active($room, $seat) : (int)$targetSeat;
        if ($opponent < 0 || $opponent === $seat || ($room['players'][$opponent]['status'] ?? '') !== 'active') return '请选择仍在牌局中的比牌对象。';
        if ((int)$player['stack'] < $unit) return '桌面筹码不足以比牌。';
        zjh_commit($room, $seat, $unit);
        $cmp = zjh_compare_scores(zjh_evaluate($player['cards']), zjh_evaluate($room['players'][$opponent]['cards']));
        $loser = $cmp > 0 ? $opponent : $seat; // 同牌时发起者负
        $room['players'][$loser]['status'] = 'compared';
        zjh_log($room, $player['username'] . ' 与 ' . $room['players'][$opponent]['username'] . ' 比牌，' . $room['players'][$loser]['username'] . ' 落败');
    } elseif ($action === 'compare_all') {
        $opponents = array_values(array_filter(zjh_active($room), fn($opponent) => (int)$opponent !== $seat));
        if (!$opponents) return '没有可以全比的对手。';
        $totalCost = $unit * count($opponents);
        if ((int)$player['stack'] < $totalCost) return '桌面筹码不足以全比，需要 ' . $totalCost . '。';
        zjh_commit($room, $seat, $totalCost);
        $opponentHands = array_map(fn($opponent) => $room['players'][$opponent]['cards'], $opponents);
        $lostIndex = zjh_compare_all_outcome($player['cards'], $opponentHands);
        $lostTo = $lostIndex === -1 ? null : (int)$opponents[$lostIndex];
        if ($lostTo !== null) {
            $player['status'] = 'compared';
            zjh_log($room, $player['username'] . ' 发起全比，不敌 ' . $room['players'][$lostTo]['username'] . '，全比落败');
        } else {
            foreach ($opponents as $opponent) $room['players'][$opponent]['status'] = 'compared';
            zjh_log($room, $player['username'] . ' 发起全比并击败其余 ' . count($opponents) . ' 位玩家');
        }
    } else return '未知操作。';
    unset($player); $room['action_count']++;
    zjh_advance($room, $seat);
    return '';
}

function zjh_bot_strength($room, $seat)
{
    $score = zjh_evaluate($room['players'][$seat]['cards']);
    $categoryBase = [0=>0.08, 1=>0.34, 2=>0.52, 3=>0.66, 4=>0.86, 5=>0.98];
    return min(1, ($categoryBase[$score[0]] ?? 0.08) + ((int)($score[1] ?? 2) - 2) / 180);
}

function zjh_bot_action(&$room, $seat)
{
    $player =& $room['players'][$seat];
    $difficulty = $player['difficulty'] ?? $room['bot_difficulty'] ?? 'simple';
    if (!$player['seen'] && random_int(1, 100) <= ($difficulty === 'simple' ? 35 : ($difficulty === 'hard' ? 68 : 88))) {
        $player['seen'] = true;
        zjh_log($room, $player['username'] . ' 已看牌');
    }
    $strength = zjh_bot_strength($room, $seat);
    $roll = random_int(1, 100);
    $canRaise = (int)$room['raise_count'] < ZJH_RAISE_CAP;
    $activeCount = count(zjh_active($room));
    $cost = (int)$room['current_bet'] * ($player['seen'] ? 2 : 1);
    $pressure = $cost / max(1, (int)$player['stack'] + $cost);

    if ($difficulty === 'hard') {
        $strength = max(0, min(1, $strength + random_int(-14, 14) / 100));
    }
    return zjh_bot_decide($difficulty, $strength, $pressure, $canRaise, $activeCount, $roll,
        (int)$player['stack'] >= $cost + (int)$room['base'] * 2);
}

function zjh_drive_bot(&$room)
{
    if (($room['status'] ?? '') !== 'playing') return;
    $seat = (int)$room['turn'];
    if ($seat < 0 || empty($room['players'][$seat]['bot']) || zjh_now() < (int)($room['bot_ready_at'] ?? 0)) return;
    if (!empty($room['showdown_pending'])) {
        zjh_reveal_and_advance($room, $seat, '自动亮牌');
        return;
    }
    $action = zjh_bot_action($room, $seat);
    $error = zjh_apply($room, $seat, $action);
    if ($error !== '' && ($room['status'] ?? '') === 'playing') {
        $fallback = zjh_apply($room, $seat, 'call');
        if ($fallback !== '' && ($room['status'] ?? '') === 'playing') {
            $active = zjh_active($room);
            zjh_settle($room, zjh_best_seat($room, $active), '机器人无法继续投入，系统自动亮牌');
        }
    }
}

function zjh_mutate($callback)
{
    global $CURUSER;
    $id = zjh_current_room_id((int)$CURUSER['id']);
    if (!$id) return [null, '牌桌不存在，请重新加入。'];
    $token = zjh_lock($id); if (!$token) return [null, '操作繁忙，请稍后重试。'];
    try {
        $room = zjh_room_get($id); if (!$room) return [null, '牌桌不存在。'];
        $seat = zjh_seat_of($room, (int)$CURUSER['id']); if ($seat < 0) return [null, '你已不在牌桌。'];
        $error = $callback($room, $seat); zjh_room_put($room); return [$room, (string)$error];
    } finally { zjh_unlock($id, $token); }
}

function zjh_join($id, $invite = '', $matching = false)
{
    global $CURUSER;
    if (zjh_current_room_id((int)$CURUSER['id'])) return [zjh_current_room_id((int)$CURUSER['id']), ''];
    $id = (int)$id; $token = zjh_lock($id); if (!$token) return [0, '牌桌繁忙，请重试。'];
    try {
        $room = zjh_room_get($id); if (!$room) return [0, '牌桌不存在。'];
        if (!$matching && ($room['mode'] ?? '') === 'friend' && !hash_equals((string)$room['invite'], strtoupper(trim($invite)))) return [0, '好友口令不正确。'];
        $error = zjh_add_current_player($room);
        if ($error === '' && count(array_filter($room['players'])) === zjh_seat_count($room)) zjh_start($room);
        zjh_room_put($room); return [$error === '' ? $id : 0, $error];
    } finally { zjh_unlock($id, $token); }
}

function zjh_match($base, $seats)
{
    global $CURUSER;
    $current = zjh_current_room_id((int)$CURUSER['id']);
    if ($current) return [$current, ''];
    $base = (int)$base;
    if (!in_array($base, ZJH_BASES, true)) return [0, '请选择有效底注。'];
    $seats = zjh_validate_seats($seats);
    if (!$seats) return [0, '请选择 3 至 10 人的牌桌。'];
    $redis = zjh_redis();
    for ($i=0; $i<12; $i++) {
        $id = (int)$redis->lpop(zjh_lobby_key($base, $seats)); if (!$id) break;
        [$joined, $error] = zjh_join($id, '', true);
        if ($joined) {
            $room = zjh_room_get($joined);
            if (($room['status'] ?? '') === 'waiting') $redis->rpush(zjh_lobby_key($base, $seats), $joined);
            return [$joined, ''];
        }
    }
    [$id, $error] = zjh_new_room($base, 'match', $seats);
    if ($id && $error === '') $redis->rpush(zjh_lobby_key($base, $seats), $id);
    return [$id, $error];
}

function zjh_leave($uid)
{
    $id = zjh_current_room_id($uid); if (!$id) return '';
    $token = zjh_lock($id); if (!$token) return '牌桌繁忙，请稍后重试。';
    try {
        $room = zjh_room_get($id); if (!$room) { zjh_redis()->del(zjh_user_key($uid)); return ''; }
        if (($room['status'] ?? '') === 'playing') return '牌局进行中不能离桌，请先弃牌并等待结算。';
        $seat = zjh_seat_of($room, $uid); if ($seat >= 0) $room['players'][$seat] = null;
        zjh_redis()->del(zjh_user_key($uid)); zjh_log($room, '一位玩家离开了牌桌'); zjh_room_put($room);
        if (($room['mode'] ?? '') === 'match' && count(array_filter($room['players'])) > 0) {
            zjh_redis()->rpush(zjh_lobby_key($room['base'], zjh_seat_count($room)), $id);
        }
        return '';
    } finally { zjh_unlock($id, $token); }
}

function zjh_public($room, $uid)
{
    global $BASEURL;
    if (!$room) return ['ok'=>true, 'game'=>null, 'wallet'=>zjh_wallet($uid)];
    $viewer = zjh_seat_of($room, $uid); if ($viewer < 0) return ['ok'=>true, 'game'=>null];
    $seatCount = zjh_seat_count($room);
    $finished = ($room['status'] ?? '') === 'finished'; $players = [];
    for ($view=0; $view<$seatCount; $view++) {
        $actual = ($viewer + $view) % $seatCount; $player = $room['players'][$actual] ?? null;
        if (!$player) { $players[] = ['username'=>'等待玩家', 'status'=>'empty', 'cards'=>[], 'stack'=>0, 'seen'=>false, 'delta'=>0, 'bot'=>false]; continue; }
        $show = $finished || !empty($player['revealed']) || ($actual === $viewer && !empty($player['seen'])); $cards = [];
        if ($show) foreach (($player['cards'] ?? []) as $card) $cards[] = zjh_card_view($card);
        $players[] = ['username'=>$player['username'], 'status'=>$player['status'], 'cards'=>$cards, 'stack'=>(int)$player['stack'],
            'seen'=>(bool)$player['seen'], 'revealed'=>(bool)($player['revealed'] ?? false), 'handName'=>$show && count($cards) === 3 ? zjh_score_name(zjh_evaluate($player['cards'])) : '',
            'delta'=>(float)($player['delta'] ?? 0), 'bot'=>(bool)($player['bot'] ?? false),
            'difficulty'=>!empty($player['bot']) ? zjh_difficulty_label($player['difficulty'] ?? 'simple') : ''];
    }
    $turn = (int)($room['turn'] ?? -1); $turnView = $turn < 0 ? -1 : (($turn - $viewer + $seatCount) % $seatCount);
    $me = $room['players'][$viewer]; $unit = zjh_action_cost($room['current_bet'] ?? $room['base'], !empty($me['seen']));
    $compareTargets = [];
    foreach (empty($room['showdown_pending']) ? zjh_active($room) : [] as $actualSeat) {
        if ($actualSeat === $viewer) continue;
        $compareTargets[] = [
            'seat'=>(($actualSeat - $viewer + $seatCount) % $seatCount),
            'username'=>$room['players'][$actualSeat]['username'],
            'bot'=>(bool)($room['players'][$actualSeat]['bot'] ?? false),
        ];
    }
    $inviteUrl = get_protocol_prefix() . $BASEURL . '/games/zjh/?table=' . (int)$room['id'] . '&invite=' . rawurlencode($room['invite']);
    return ['ok'=>true, 'wallet'=>(float)($me['wallet_after_settle'] ?? $me['wallet_after_reserve'] ?? zjh_wallet($uid)), 'game'=>[
        'roomId'=>(int)$room['id'], 'invite'=>$room['invite'], 'inviteUrl'=>$inviteUrl, 'mode'=>$room['mode'], 'base'=>(int)$room['base'],
        'status'=>$room['status'], 'seatCount'=>$seatCount, 'playerCount'=>count(array_filter($room['players'])), 'players'=>$players, 'pot'=>(int)($room['pot'] ?? 0),
        'tableCap'=>(int)$room['base'] * ZJH_STACK_FACTOR * $seatCount,
        'turn'=>$turnView, 'timeLeft'=>$room['status']==='playing' ? max(0, (int)$room['deadline']-zjh_now()) : 0,
        'currentBet'=>(int)($room['current_bet'] ?? $room['base']), 'callCost'=>$unit, 'raiseCount'=>(int)($room['raise_count'] ?? 0),
        'canAct'=>$room['status']==='playing' && $turn===$viewer && ($me['status'] ?? '')==='active' && empty($me['revealed']),
        'canPeek'=>$room['status']==='playing' && empty($room['showdown_pending']) && ($me['status'] ?? '')==='active' && empty($me['seen']), 'token'=>$room['token'],
        'compareTargets'=>$compareTargets, 'compareAllCost'=>$unit * count($compareTargets),
        'logs'=>array_values($room['logs'] ?? []), 'winner'=>$finished ? (((int)$room['winner']-$viewer+$seatCount)%$seatCount) : -1,
        'finishReason'=>$room['finish_reason'] ?? '', 'startError'=>$room['start_error'] ?? '',
        'isOwner'=>(int)$room['owner']===(int)$uid, 'botDifficulty'=>zjh_difficulty_label($room['bot_difficulty'] ?? 'simple'),
        'showdownPending'=>!empty($room['showdown_pending'])
    ]];
}

function zjh_timeout(&$room)
{
    if (($room['status'] ?? '') !== 'playing' || zjh_now() < (int)$room['deadline']) return;
    $seat = (int)$room['turn'];
    zjh_reveal_and_advance($room, $seat, '读秒结束自动亮牌');
}

function zjh_rebuild_deadlines()
{
    $redis = zjh_redis();
    $redis->del(ZJH_DEADLINE_KEY);
    $count = 0;
    foreach ((array)$redis->keys('zjh:room:*') as $key) {
        $raw = $redis->get($key);
        $room = $raw ? json_decode($raw, true) : null;
        if (!$room || ($room['status'] ?? '') !== 'playing') continue;
        zjh_room_put($room);
        $count++;
    }
    return $count;
}

function zjh_process_due_rooms($limit = 100)
{
    $redis = zjh_redis();
    $ids = array_slice((array)$redis->zRangeByScore(ZJH_DEADLINE_KEY, '-inf', (string)zjh_now()), 0, max(1, (int)$limit));
    $processed = 0;
    foreach ($ids as $rawId) {
        $id = (int)$rawId;
        if ($id <= 0) { $redis->zRem(ZJH_DEADLINE_KEY, (string)$rawId); continue; }
        $token = zjh_lock($id);
        if (!$token) continue;
        try {
            $room = zjh_room_get($id);
            if (!$room) { $redis->zRem(ZJH_DEADLINE_KEY, (string)$id); continue; }
            zjh_force_capped_showdown($room);
            zjh_drive_bot($room);
            zjh_timeout($room);
            zjh_room_put($room);
            $processed++;
        } catch (Throwable $e) {
            error_log('[ZJH_WORKER] room ' . $id . ': ' . $e->getMessage());
            $redis->zAdd(ZJH_DEADLINE_KEY, zjh_now() + 1, (string)$id);
        } finally {
            zjh_unlock($id, $token);
        }
    }
    return $processed;
}

if ($zjhRuntimeOnly) return;

function zjh_stats($uid)
{
    zjh_ensure_tables();
    $res = sql_query("SELECT COUNT(*) games,SUM(result='win') wins,COALESCE(SUM(delta),0) net,COALESCE(MAX(delta),0) best FROM hdvideo_zjh_results WHERE uid=".(int)$uid) or sqlerr(__FILE__, __LINE__);
    return mysql_fetch_assoc($res) ?: ['games'=>0,'wins'=>0,'net'=>0,'best'=>0];
}

function zjh_rankings()
{
    zjh_ensure_tables(); $rows=[];
    $res = sql_query("SELECT r.uid,u.username,COUNT(*) games,SUM(r.result='win') wins,SUM(r.delta) net FROM hdvideo_zjh_results r INNER JOIN users u ON u.id=r.uid GROUP BY r.uid,u.username ORDER BY net DESC LIMIT 8") or sqlerr(__FILE__, __LINE__);
    while ($row=mysql_fetch_assoc($res)) $rows[]=$row; return $rows;
}

if (($_GET['ajax'] ?? '') === 'poll') {
    header('Content-Type: application/json');
    [$room,$error] = zjh_mutate(function (&$room) {
        if (($room['status'] ?? '') === 'waiting' && count(array_filter($room['players'])) === zjh_seat_count($room)) zjh_start($room);
        zjh_force_capped_showdown($room);
        zjh_drive_bot($room);
        zjh_timeout($room);
        return '';
    });
    if ($error !== '' && !$room) { echo json_encode(['ok'=>true,'game'=>null,'wallet'=>zjh_wallet((int)$GLOBALS['CURUSER']['id'])], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(zjh_public($room, (int)$CURUSER['id']), JSON_UNESCAPED_UNICODE); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json'); $action=(string)($_POST['action'] ?? ''); $room=null; $error=''; $uid=(int)$CURUSER['id'];
    if ($action==='match') { [$id,$error]=zjh_match($_POST['base'] ?? 0, $_POST['seats'] ?? 0); if ($id) $room=zjh_room_get($id); }
    elseif ($action==='create') { [$id,$error]=zjh_new_room($_POST['base'] ?? 0, 'friend', $_POST['seats'] ?? 0); if ($id) $room=zjh_room_get($id); }
    elseif ($action==='join') { [$id,$error]=zjh_join($_POST['table'] ?? 0, $_POST['invite'] ?? ''); if ($id) $room=zjh_room_get($id); }
    elseif ($action==='leave') $error=zjh_leave($uid);
    elseif ($action==='fill_bots') {
        [$room,$error]=zjh_mutate(function (&$room) use ($uid) {
            if ((int)$room['owner'] !== $uid) return '只有牌桌创建者可以补机器人。';
            return zjh_fill_bots($room);
        });
    }
    elseif ($action==='rematch') {
        [$room,$error]=zjh_mutate(function (&$room,$seat) use ($uid) {
            if (($room['status'] ?? '')!=='finished') return '本局尚未结束。';
            $room['ready'][(string)$uid]=true; zjh_log($room, $room['players'][$seat]['username'].' 已准备下一局');
            $realCount = count(array_filter($room['players'], fn($player) => $player && empty($player['bot'])));
            if (count($room['ready']) >= $realCount) {
                $room['status']='waiting';
                foreach ($room['players'] as &$p) { $p['status']='waiting'; $p['seen']=false; $p['revealed']=false; }
                unset($p);
                zjh_start($room);
            }
            return '';
        });
    } elseif (in_array($action, ['peek','call','raise','fold','compare','compare_all','reveal'], true)) {
        [$room,$error]=zjh_mutate(function (&$room,$seat) use ($action) {
            if (!hash_equals((string)($room['token'] ?? ''), (string)($_POST['token'] ?? ''))) return '页面凭证已失效，请刷新。';
            $targetSeat = null;
            if ($action === 'compare') {
                $targetView = (int)($_POST['target'] ?? -1);
                $seatCount = zjh_seat_count($room);
                if ($targetView <= 0 || $targetView >= $seatCount) return '请选择有效的比牌对象。';
                $targetSeat = ($seat + $targetView) % $seatCount;
            }
            return zjh_apply($room,$seat,$action,$targetSeat);
        });
    } else $error='未知操作。';
    $response=$room ? zjh_public($room,$uid) : ['ok'=>$error==='','game'=>null,'wallet'=>zjh_wallet($uid)];
    if ($error!=='') { $response['ok']=false; $response['error']=$error; }
    echo json_encode($response, JSON_UNESCAPED_UNICODE); exit;
}

if (!zjh_current_room_id((int)$CURUSER['id']) && isset($_GET['table'], $_GET['invite'])) zjh_join($_GET['table'], $_GET['invite']);
$roomId=zjh_current_room_id((int)$CURUSER['id']); $initial=zjh_public($roomId ? zjh_room_get($roomId) : null, (int)$CURUSER['id']);
$stats=zjh_stats((int)$CURUSER['id']); $rankings=zjh_rankings();
stdhead('炸金花');
?>
<style>
:root{--z-gold:#e3b45a;--z-gold2:#ffda85;--z-ink:#130d0b;--z-panel:#24130f;--z-red:#8c1d25;--z-line:rgba(255,218,133,.22)}
.zjh-page{max-width:1180px;margin:18px auto 40px;color:#f8ead0;font-family:Inter,"Microsoft YaHei",sans-serif}.zjh-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}.zjh-top h1{margin:0;color:var(--theme-page-text,var(--z-gold2))!important;font-size:28px}.zjh-top h1 small{color:var(--bili-primary,var(--z-gold))}.zjh-top a{color:var(--theme-link,var(--z-gold));text-decoration:none}.zjh-wallet{padding:9px 14px;border:1px solid var(--z-line);border-radius:10px;background:#1b100d}.zjh-table{position:relative;min-height:650px;border:1px solid var(--z-line);border-radius:30px;background:radial-gradient(ellipse at center,#194e38 0,#123528 55%,#28150f 56%,#120b09 82%);box-shadow:0 26px 70px rgba(0,0,0,.38),inset 0 0 0 10px rgba(227,180,90,.08);overflow:hidden}.zjh-brand{position:absolute;left:50%;top:45%;transform:translate(-50%,-50%);text-align:center;color:rgba(255,218,133,.82)}.zjh-brand strong{font-size:34px;letter-spacing:8px}.zjh-pot{margin-top:8px;padding:7px 14px;border:1px solid var(--z-line);border-radius:99px;background:rgba(15,9,7,.65)}.z-seat{position:absolute;width:270px;padding:13px;border:1px solid var(--z-line);border-radius:16px;background:linear-gradient(145deg,rgba(32,18,14,.97),rgba(15,10,9,.96));box-shadow:0 10px 30px rgba(0,0,0,.35)}.z-seat.me{left:50%;bottom:96px;transform:translateX(-50%)}.z-seat.left{left:30px;top:72px}.z-seat.right{right:30px;top:72px}.z-seat.is-turn{border-color:var(--z-gold2);box-shadow:0 0 0 2px rgba(227,180,90,.2),0 10px 30px #000}.z-seat-head{display:flex;justify-content:space-between;gap:10px}.z-name{font-weight:800;color:#fff}.z-stack{color:var(--z-gold)}.z-status{font-size:12px;color:#c7bba9;margin-top:5px}.z-cards{display:flex;gap:7px;margin-top:9px;min-height:72px}.z-card{width:48px;height:66px;border-radius:6px;background:#f7f1e6;color:#18100c;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:18px;font-weight:900;box-shadow:0 3px 8px #000}.z-card.red{color:#b9222e}.z-card.back{background:repeating-linear-gradient(45deg,#7d1e27,#7d1e27 5px,#d3a149 5px,#d3a149 7px);border:2px solid #f6d795}.z-clock{position:absolute;right:10px;bottom:10px;width:38px;height:38px;border-radius:50%;display:grid;place-items:center;border:3px solid var(--z-gold);color:var(--z-gold2);font-weight:900}.z-actions{position:absolute;left:50%;bottom:22px;transform:translateX(-50%);display:flex;gap:8px;z-index:5}.z-btn{border:1px solid var(--z-line);border-radius:10px;background:#392019;color:#f8ead0;padding:11px 17px;font-weight:800;cursor:pointer}.z-btn.primary{background:linear-gradient(135deg,#bc7d25,#e5b95d);color:#21130b}.z-btn.danger{background:#761d24}.z-btn:disabled{opacity:.38;cursor:not-allowed}.z-overlay{position:absolute;inset:0;background:rgba(10,7,6,.84);backdrop-filter:blur(8px);display:grid;place-items:center;z-index:10}.z-dialog{width:min(650px,calc(100% - 30px));padding:28px;border:1px solid var(--z-line);border-radius:20px;background:linear-gradient(145deg,#2b1712,#160e0c);text-align:center}.zjh-page .z-dialog h2{color:var(--z-gold2)!important;background:transparent!important;border:0!important;box-shadow:none!important;font-size:28px;margin:0 0 8px}.z-base-grid,.z-mode-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin:18px 0}.z-mode-grid{grid-template-columns:1fr 1fr}.z-choice{padding:15px 8px;border:1px solid var(--z-line);border-radius:10px;background:#1b100d;color:#f8ead0;cursor:pointer}.zjh-page .z-choice.active{border-color:#fff!important;box-shadow:0 0 0 3px var(--bili-primary,var(--z-gold));filter:saturate(.78) brightness(.84)}.z-wait-code{font-size:30px;letter-spacing:6px;color:var(--z-gold2);font-weight:900;margin:15px}.z-log{position:absolute;left:25px;bottom:22px;width:260px;max-height:82px;overflow:auto;font-size:12px;color:#d9cbb7}.z-result{margin-top:12px;color:#cdbfa9}.z-info{display:grid;grid-template-columns:1.5fr 1fr;gap:14px;margin-top:14px}.z-panel{border:1px solid var(--z-line);border-radius:14px;background:#1b100d;padding:18px}.z-panel h3{margin:0 0 14px;color:var(--z-gold2)}.z-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.z-stat{padding:12px;border-radius:10px;background:#291813;text-align:center}.z-stat b{display:block;font-size:20px;color:var(--z-gold)}.z-rank{width:100%;border-collapse:collapse}.z-rank td,.z-rank th{padding:8px;border-bottom:1px solid var(--z-line);text-align:left}.z-rule{font-size:13px;line-height:1.8;color:#d7c8b5}.z-toast{position:fixed;left:50%;top:80px;transform:translateX(-50%);padding:11px 18px;border-radius:10px;background:#8c1d25;color:#fff;z-index:100;display:none}
.zjh-table{min-height:760px}.z-dialog.setup{width:min(760px,calc(100% - 30px))}.z-option-label{text-align:left;margin:15px 0 8px;color:#f8ead0;font-weight:800}.z-option-label small{color:#bda98c;font-weight:500}.z-number-grid{display:grid;grid-template-columns:repeat(8,1fr);gap:8px}.z-choice{position:relative;min-height:58px;transition:border-color .2s,box-shadow .2s,background-color .2s,filter .2s}.z-choice:hover{box-shadow:0 8px 20px rgba(0,0,0,.24)}.z-choice:focus-visible{outline:3px solid var(--z-gold2);outline-offset:3px}.z-choice-check{position:absolute;right:8px;top:7px;width:22px;height:22px;border-radius:50%;display:none;place-items:center;background:#fff;color:#5a2035;font-weight:1000;line-height:1}.zjh-page .z-choice.active{border:3px solid #fff!important;box-shadow:0 0 0 4px var(--bili-primary,var(--z-gold)),0 10px 24px rgba(0,0,0,.32)!important;filter:brightness(.72) saturate(1.1)!important;font-weight:900}.z-choice.active .z-choice-check{display:grid}.z-selection-summary{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:18px 0 14px;padding:12px 15px;border:1px solid var(--z-gold);border-radius:10px;background:rgba(227,180,90,.1);text-align:left}.z-selection-summary b{color:var(--z-gold2)}.z-seat.dynamic{width:230px;transform:translate(-50%,-50%);transition:left .25s,top .25s,border-color .2s,box-shadow .2s;z-index:2}.z-seat.dynamic.compact{width:175px;padding:9px}.z-seat.dynamic.dense{width:135px;padding:7px}.z-seat.dynamic.compact .z-card{width:36px;height:51px;font-size:14px}.z-seat.dynamic.dense .z-card{width:28px;height:42px;font-size:11px}.z-seat.dynamic.compact .z-cards,.z-seat.dynamic.dense .z-cards{min-height:44px;gap:4px;margin-top:5px}.z-seat.dynamic.dense .z-name,.z-seat.dynamic.dense .z-stack{font-size:11px}.z-seat.dynamic.dense .z-status{font-size:10px}.z-seat.dynamic.dense .z-clock{width:30px;height:30px;font-size:11px}.z-compare-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin:16px 0;max-height:330px;overflow:auto}.z-compare-target{text-align:left;display:flex;justify-content:space-between;align-items:center}.z-compare-all{width:100%;margin-top:4px}.z-result{max-height:320px;overflow:auto}
@media(max-width:760px){.zjh-page{margin:8px}.zjh-top h1{font-size:21px}.zjh-table{min-height:700px;border-radius:18px}.z-seat.dynamic{width:145px;padding:7px}.z-seat.dynamic.compact,.z-seat.dynamic.dense{width:104px;padding:5px}.z-seat.dynamic .z-card{width:27px!important;height:40px!important;font-size:10px!important}.z-seat.dynamic .z-cards{min-height:40px;gap:3px}.z-seat.dynamic .z-name,.z-seat.dynamic .z-stack{font-size:10px}.z-seat.dynamic .z-status{font-size:9px}.zjh-brand{top:43%}.zjh-brand strong{font-size:21px}.z-actions{bottom:12px;width:96%;justify-content:center;flex-wrap:wrap}.z-btn{padding:9px 11px}.z-log{display:none}.z-info{grid-template-columns:1fr}.z-stats{grid-template-columns:repeat(2,1fr)}.z-base-grid,.z-number-grid{grid-template-columns:repeat(4,1fr)}.z-mode-grid{grid-template-columns:1fr}.z-selection-summary{align-items:flex-start;flex-direction:column}.z-compare-grid{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;transition:none!important}}
</style>
<div class="zjh-page">
 <div class="zjh-top"><div><a href="/games/">返回游戏列表</a><h1>炸金花 <small>内测中 v0.1</small></h1></div><div class="zjh-wallet">电影票 <b id="wallet"><?php echo number_format($initial['wallet'],1) ?></b></div></div>
 <main class="zjh-table" id="table">
  <div class="zjh-brand"><strong>炸金花</strong><div class="zjh-pot">底池 <b id="pot">0</b> / <span id="tableCap">0</span> · 当前注 <b id="currentBet">0</b></div></div>
  <div id="seats"></div><div class="z-log" id="logs"></div><div class="z-actions" id="actions"></div><div id="overlay"></div><div id="comparePicker"></div>
 </main>
 <section class="z-info">
  <div class="z-panel"><h3>我的战绩</h3><div class="z-stats"><div class="z-stat"><b><?php echo (int)$stats['games'] ?></b>总局数</div><div class="z-stat"><b><?php echo (int)$stats['wins'] ?></b>胜局</div><div class="z-stat"><b><?php echo number_format((float)$stats['net'],1) ?></b>净盈亏</div><div class="z-stat"><b><?php echo number_format((float)$stats['best'],1) ?></b>单局最佳</div></div><p class="z-rule">每桌可设置 3–10 席，真人优先，也可由创建者补机器人。每席带入底注的 20 倍，单人最高 100,000、全桌最高 1,000,000 电影票。操作超时的玩家先自动亮牌，其他仍在局玩家随后依次亮牌，全部亮完才统一结算；筹码不足以继续行动时直接自动亮牌。指定比牌支付一次比牌费；全比按对手人数支付费用，必须击败全部在局玩家才算成功。牌型从大到小：豹子、同花顺、金花、顺子、对子、单张。A23 为最小顺子，平牌时发起者落败。</p></div>
  <div class="z-panel"><h3>真人排行榜</h3><table class="z-rank"><tr><th>玩家</th><th>胜局</th><th>净盈亏</th></tr><?php foreach($rankings as $row){ ?><tr><td><?php echo htmlspecialchars($row['username']) ?></td><td><?php echo (int)$row['wins'] ?></td><td><?php echo number_format((float)$row['net'],1) ?></td></tr><?php } ?></table></div>
 </section>
</div><div class="z-toast" id="toast"></div>
<script>
let state=<?php echo json_encode($initial, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
let chosenBase=100, chosenMode='match', chosenSeats=3, busy=false, compareOpen=false;
const zjhEndpoint=location.origin+location.pathname;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money=n=>Number(n||0).toLocaleString();
const checkMark='<span class="z-choice-check" aria-hidden="true">✓</span>';

function toast(s){const e=document.getElementById('toast');e.textContent=s;e.style.display='block';setTimeout(()=>e.style.display='none',2600)}
function cards(p){
 if(p.cards&&p.cards.length)return p.cards.map(c=>`<span class="z-card ${c.red?'red':''}"><span>${esc(c.rank)}</span><span>${esc(c.suit)}</span></span>`).join('');
 if(p.status==='empty')return '';
 return '<span class="z-card back"></span><span class="z-card back"></span><span class="z-card back"></span>';
}
function seatPosition(index,count){
 const angle=Math.PI/2+(index*Math.PI*2/count), rx=count>=8?44:(count>=5?42:38), ry=count>=8?32:30;
 return {x:50+rx*Math.cos(angle),y:43+ry*Math.sin(angle)};
}
function optionButton(label,sub,active,click){
 return `<button type="button" class="z-choice ${active?'active':''}" aria-pressed="${active}" onclick="${click}">${checkMark}<strong>${label}</strong>${sub?`<br><small>${sub}</small>`:''}</button>`;
}
function setupHtml(){
 const mode=chosenMode==='match'?'快速排队':'好友一起';
 return `<div class="z-overlay"><div class="z-dialog setup"><h2>加入炸金花牌桌</h2><p>真人优先，等待时可由创建者补机器人立即开局。</p>
 <div class="z-option-label">加入方式</div><div class="z-mode-grid">
 ${optionButton('快速排队','匹配相同底注与人数的玩家',chosenMode==='match',"chosenMode='match';render()")}
 ${optionButton('好友一起','创建专属邀请牌桌',chosenMode==='friend',"chosenMode='friend';render()")}</div>
 <div class="z-option-label">牌桌人数 <small>最低 3 人，最多 10 人</small></div><div class="z-number-grid">
 ${[3,4,5,6,7,8,9,10].map(v=>optionButton(v+' 人','',chosenSeats===v,`chosenSeats=${v};render()`)).join('')}</div>
 <div class="z-option-label">牌桌底注</div><div class="z-base-grid">
 ${[100,500,1000,5000].map(v=>optionButton(money(v),'带入 '+money(v*20),chosenBase===v,`chosenBase=${v};render()`)).join('')}</div>
 <div class="z-selection-summary"><span>已选择：<b>${mode} · ${chosenSeats} 人桌 · ${money(chosenBase)} 底注</b></span><span>每位带入 <b>${money(chosenBase*20)}</b> · 全桌上限 <b>${money(chosenBase*20*chosenSeats)}</b> 电影票</span></div>
 <button class="z-btn primary" onclick="send(chosenMode==='match'?'match':'create',{base:chosenBase,seats:chosenSeats})">${chosenMode==='match'?'加入 '+chosenSeats+' 人桌排队':'创建 '+chosenSeats+' 人好友牌桌'}</button></div></div>`;
}
function compareHtml(g){
 if(!compareOpen||!g.canAct||!g.compareTargets?.length)return '';
 return `<div class="z-overlay"><div class="z-dialog"><h2>选择比牌方式</h2><p>指定比牌支付 ${money(g.callCost)}；全比按对手人数逐份支付。</p>
 <div class="z-compare-grid">${g.compareTargets.map(t=>`<button class="z-btn z-compare-target" onclick="send('compare',{target:${t.seat}})"><span>${esc(t.username)}${t.bot?'（机器人）':''}</span><b>${money(g.callCost)}</b></button>`).join('')}</div>
 <button class="z-btn primary z-compare-all" onclick="send('compare_all')">全比 ${g.compareTargets.length} 人 · ${money(g.compareAllCost)}</button><br><button class="z-btn" onclick="compareOpen=false;render()">取消</button></div></div>`;
}
function render(){
 const g=state.game;
 document.getElementById('wallet').textContent=Number(state.wallet||0).toLocaleString(undefined,{minimumFractionDigits:1,maximumFractionDigits:1});
 const comparePicker=document.getElementById('comparePicker');
 if(!g){
  compareOpen=false;document.getElementById('pot').textContent='0';document.getElementById('tableCap').textContent='0';document.getElementById('currentBet').textContent='0';document.getElementById('seats').innerHTML='';document.getElementById('logs').innerHTML='';document.getElementById('actions').innerHTML='';document.getElementById('overlay').innerHTML=setupHtml();comparePicker.innerHTML='';return;
 }
 document.getElementById('pot').textContent=money(g.pot);document.getElementById('tableCap').textContent=money(g.tableCap);document.getElementById('currentBet').textContent=money(g.currentBet||g.base);
 const count=g.seatCount||g.players.length;
 document.getElementById('seats').innerHTML=g.players.map((p,i)=>{const pos=seatPosition(i,count),density=count>=8?'dense':(count>=5?'compact':'');return `<article class="z-seat dynamic ${density} ${g.turn===i?'is-turn':''}" style="left:${pos.x.toFixed(2)}%;top:${pos.y.toFixed(2)}%"><div class="z-seat-head"><span class="z-name">${esc(p.username)}${p.bot?` <small>机器人·${esc(p.difficulty)}</small>`:''}</span><span class="z-stack">${money(p.stack)}</span></div><div class="z-status">${p.status==='empty'?'等待入座':p.status==='folded'?'已弃牌':p.status==='compared'?'比牌落败':p.revealed?'已亮牌 · '+esc(p.handName||''):p.handName||(p.seen?'已看牌':'暗牌')}${g.status==='finished'&&!p.bot?` · ${p.delta>=0?'+':''}${money(p.delta)}`:''}</div><div class="z-cards">${cards(p)}</div>${g.turn===i&&g.status==='playing'?`<span class="z-clock">${g.timeLeft}</span>`:''}</article>`}).join('');
 document.getElementById('logs').innerHTML=(g.logs||[]).slice().reverse().map(x=>`<div>${esc(x.time)} ${esc(x.text)}</div>`).join('');
 if(g.status==='playing')document.getElementById('actions').innerHTML=g.showdownPending?(g.canAct?`<button class="z-btn primary" onclick="send('reveal')" ${busy?'disabled':''}>亮牌</button>`:'<span class="z-btn" aria-live="polite">等待其他玩家依次亮牌</span>'):`<button class="z-btn" onclick="send('peek')" ${!g.canPeek||busy?'disabled':''}>看牌</button><button class="z-btn primary" onclick="send('call')" ${!g.canAct||busy?'disabled':''}>跟注 ${money(g.callCost)}</button><button class="z-btn" onclick="send('raise')" ${!g.canAct||g.raiseCount>=5||busy?'disabled':''}>加注</button><button class="z-btn" onclick="compareOpen=true;render()" ${!g.canAct||!g.compareTargets?.length||busy?'disabled':''}>选择比牌</button><button class="z-btn danger" onclick="send('fold')" ${!g.canAct||busy?'disabled':''}>弃牌</button>`;else document.getElementById('actions').innerHTML='';
 if(g.status==='waiting'){
  compareOpen=false;document.getElementById('overlay').innerHTML=`<div class="z-overlay"><div class="z-dialog"><h2>等待玩家入座 ${g.playerCount}/${g.seatCount}</h2><p>牌桌 ${g.roomId} · ${g.seatCount} 人桌 · 底注 ${money(g.base)} · 机器人难度 ${esc(g.botDifficulty)}</p>${g.mode==='friend'?`<div class="z-wait-code">${esc(g.invite)}</div><button class="z-btn primary" onclick="navigator.clipboard.writeText('${esc(g.inviteUrl)}').then(()=>toast('邀请链接已复制'))">复制好友邀请链接</button>`:'<p>系统正在匹配相同底注与人数的玩家，可继续等待真人。</p>'}${g.startError?`<p class="z-result">${esc(g.startError)}</p>`:''}<br>${g.isOwner&&g.playerCount<g.seatCount?'<button class="z-btn primary" onclick="send(\'fill_bots\')">补机器人立即开局</button> ':''}<button class="z-btn" onclick="send('leave')">离开牌桌</button></div></div>`;
 }else if(g.status==='finished'){
  compareOpen=false;const w=g.players[g.winner];document.getElementById('overlay').innerHTML=`<div class="z-overlay"><div class="z-dialog"><h2>${esc(w?w.username:'赢家')} 获胜</h2><p>${esc(g.finishReason)}</p><div class="z-result">${g.players.filter(p=>p.status!=='empty').map(p=>`${esc(p.username)}：${esc(p.handName)} ${p.bot?'':(p.delta>=0?'+':'')+money(p.delta)}`).join('<br>')}</div><br><button class="z-btn primary" onclick="send('rematch')">准备下一局</button><button class="z-btn" onclick="send('leave')">离开牌桌</button></div></div>`;
 }else document.getElementById('overlay').innerHTML='';
 comparePicker.innerHTML=compareHtml(g);
}
async function send(action,extra={}){if(busy)return;busy=true;compareOpen=false;render();try{const fd=new FormData();fd.append('action',action);if(state.game)fd.append('token',state.game.token||'');Object.entries(extra).forEach(([k,v])=>fd.append(k,v));const r=await fetch(zjhEndpoint,{method:'POST',body:fd,credentials:'same-origin'});const j=await r.json();if(!j.ok)toast(j.error||'操作失败');else state=j;render()}catch(e){toast('网络异常，请重试')}finally{busy=false;render()}}
async function poll(){try{const r=await fetch(zjhEndpoint+'?ajax=poll',{credentials:'same-origin',cache:'no-store'});const j=await r.json();if(j.ok){state=j;render()}}catch(e){}}
render();setInterval(poll,2000);
</script>
<?php stdfoot(); ?>
