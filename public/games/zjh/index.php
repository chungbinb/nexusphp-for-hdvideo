<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;
require_once "../../../include/game_control.php";
require_once __DIR__ . '/engine.php';
game_guard('zjh');

const ZJH_SEATS = 3;
const ZJH_BASES = [100, 500, 1000, 5000];
const ZJH_STACK_FACTOR = 20;
const ZJH_TURN_SECONDS = 30;
const ZJH_RAISE_CAP = 5;
const ZJH_MAX_ACTIONS = 30;
const ZJH_ROOM_TTL = 604800;
const ZJH_BUSINESS_TYPE = 113;
const ZJH_ESCROW_BUSINESS_TYPE = 114;

function zjh_redis() { return \Nexus\Database\NexusDB::redis(); }
function zjh_room_key($id) { return 'zjh:room:' . (int)$id; }
function zjh_user_key($uid) { return 'zjh:user-room:' . (int)$uid; }
function zjh_lock_key($id) { return 'zjh:lock:' . (int)$id; }
function zjh_lobby_key($base) { return 'zjh:lobby:' . (int)$base; }

function zjh_room_get($id)
{
    $raw = zjh_redis()->get(zjh_room_key($id));
    return $raw ? json_decode($raw, true) : null;
}

function zjh_seat_of($room, $uid)
{
    foreach (($room['players'] ?? []) as $seat => $player) {
        if ($player && (int)$player['uid'] === (int)$uid) return (int)$seat;
    }
    return -1;
}

function zjh_room_put($room)
{
    $redis = zjh_redis();
    $redis->setex(zjh_room_key($room['id']), ZJH_ROOM_TTL, json_encode($room, JSON_UNESCAPED_UNICODE));
    foreach (($room['players'] ?? []) as $player) {
        if ($player && empty($player['departed'])) $redis->setex(zjh_user_key($player['uid']), ZJH_ROOM_TTL, (int)$room['id']);
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
    return ['uid'=>(int)$CURUSER['id'], 'username'=>$CURUSER['username'], 'status'=>'waiting', 'seen'=>false, 'cards'=>[], 'stack'=>0, 'contrib'=>0];
}

function zjh_new_room($base, $mode)
{
    global $CURUSER;
    $base = (int)$base;
    if (!in_array($base, ZJH_BASES, true)) return [0, '请选择有效底注。'];
    if (zjh_current_room_id((int)$CURUSER['id'])) return [zjh_current_room_id((int)$CURUSER['id']), ''];
    $id = (int)zjh_redis()->incr('zjh:room-sequence') + 100000;
    $room = [
        'id'=>$id, 'invite'=>strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)), 'mode'=>$mode,
        'owner'=>(int)$CURUSER['id'], 'base'=>$base, 'status'=>'waiting', 'players'=>[zjh_player_from_current(), null, null],
        'created_at'=>TIMENOW, 'logs'=>[], 'token'=>bin2hex(random_bytes(16)), 'ready'=>[]
    ];
    zjh_log($room, $CURUSER['username'] . ' 已入座，等待真人玩家');
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

function zjh_reserve(&$room, $handId)
{
    $amount = (float)((int)$room['base'] * ZJH_STACK_FACTOR);
    $uids = [];
    foreach ($room['players'] as $seat => $player) $uids[(int)$player['uid']] = (int)$seat;
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
    if (($room['status'] ?? '') !== 'waiting') return '';
    if (count(array_filter($room['players'])) !== ZJH_SEATS) return '等待三位真人玩家入座。';
    foreach ($room['players'] as $player) if (!$player || (int)$player['uid'] <= 0) return '炸金花仅支持三位真人玩家。';
    $handId = bin2hex(random_bytes(8));
    $error = zjh_reserve($room, $handId);
    if ($error !== '') { $room['start_error'] = $error; return $error; }
    $deck = range(0, 51); shuffle($deck);
    $stack = (int)$room['base'] * ZJH_STACK_FACTOR;
    $room['pot'] = 0;
    foreach ($room['players'] as $seat => &$player) {
        $player['cards'] = [array_pop($deck), array_pop($deck), array_pop($deck)];
        $player['stack'] = $stack; $player['contrib'] = 0; $player['seen'] = false; $player['status'] = 'active';
        zjh_commit($room, $seat, (int)$room['base']);
    }
    unset($player);
    $room['hand_id'] = $handId; $room['status'] = 'playing'; $room['dealer'] = random_int(0, ZJH_SEATS - 1);
    $room['turn'] = ($room['dealer'] + 1) % ZJH_SEATS; $room['deadline'] = TIMENOW + ZJH_TURN_SECONDS;
    $room['current_bet'] = (int)$room['base']; $room['raise_count'] = 0; $room['action_count'] = 0;
    $room['token'] = bin2hex(random_bytes(16)); $room['logs'] = []; unset($room['start_error']);
    zjh_log($room, '三位真人已就位，每人投入底注 ' . $room['base']);
    zjh_log($room, $room['players'][$room['turn']]['username'] . ' 先行动');
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
    for ($i=1; $i<=ZJH_SEATS; $i++) { $next = ((int)$seat + $i) % ZJH_SEATS; if (($room['players'][$next]['status'] ?? '') === 'active') return $next; }
    return -1;
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
    $uids = []; foreach ($room['players'] as $seat => $player) $uids[(int)$player['uid']] = (int)$seat;
    ksort($uids, SORT_NUMERIC);
    sql_query('START TRANSACTION') or sqlerr(__FILE__, __LINE__);
    try {
        foreach ($uids as $uid => $seat) {
            $player =& $room['players'][$seat];
            $payout = (float)$player['stack'] + ($seat === $winner ? (float)$room['pot'] : 0);
            $delta = round($payout - (float)$player['escrow'], 1);
            $score = zjh_evaluate($player['cards']); $handName = zjh_score_name($score);
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
    $room['status'] = 'finished'; $room['turn'] = -1; $room['finished_at'] = TIMENOW; $room['ready'] = [];
    zjh_log($room, $room['players'][$winner]['username'] . ' 获胜：' . $reason);
}

function zjh_advance(&$room, $seat)
{
    $active = zjh_active($room);
    if (count($active) === 1) { zjh_settle($room, $active[0], '其余玩家均已弃牌'); return; }
    if ((int)$room['action_count'] >= ZJH_MAX_ACTIONS) { $winner = zjh_best_seat($room, $active); zjh_settle($room, $winner, '达到操作上限，强制亮牌'); return; }
    $room['turn'] = zjh_next_active($room, $seat); $room['deadline'] = TIMENOW + ZJH_TURN_SECONDS;
}

function zjh_apply(&$room, $seat, $action)
{
    if (($room['status'] ?? '') !== 'playing') return '牌局尚未开始或已经结束。';
    $seat = (int)$seat; $player =& $room['players'][$seat];
    if ($action === 'peek') {
        if (($player['status'] ?? '') !== 'active') return '你已经弃牌。';
        if (!$player['seen']) { $player['seen'] = true; zjh_log($room, $player['username'] . ' 已看牌'); }
        return '';
    }
    if ((int)$room['turn'] !== $seat || ($player['status'] ?? '') !== 'active') return '还没轮到你行动。';
    $unit = (int)$room['current_bet'] * ($player['seen'] ? 2 : 1);
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
        $opponent = zjh_next_active($room, $seat);
        if ($opponent < 0) return '没有可以比牌的对手。';
        if ((int)$player['stack'] < $unit) return '桌面筹码不足以比牌。';
        zjh_commit($room, $seat, $unit);
        $cmp = zjh_compare_scores(zjh_evaluate($player['cards']), zjh_evaluate($room['players'][$opponent]['cards']));
        $loser = $cmp > 0 ? $opponent : $seat; // 同牌时发起者负
        $room['players'][$loser]['status'] = 'compared';
        zjh_log($room, $player['username'] . ' 与 ' . $room['players'][$opponent]['username'] . ' 比牌，' . $room['players'][$loser]['username'] . ' 落败');
    } else return '未知操作。';
    unset($player); $room['action_count']++;
    zjh_advance($room, $seat);
    return '';
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
        if ($error === '' && count(array_filter($room['players'])) === ZJH_SEATS) zjh_start($room);
        zjh_room_put($room); return [$error === '' ? $id : 0, $error];
    } finally { zjh_unlock($id, $token); }
}

function zjh_match($base)
{
    global $CURUSER;
    $current = zjh_current_room_id((int)$CURUSER['id']);
    if ($current) return [$current, ''];
    $base = (int)$base;
    if (!in_array($base, ZJH_BASES, true)) return [0, '请选择有效底注。'];
    $redis = zjh_redis();
    for ($i=0; $i<12; $i++) {
        $id = (int)$redis->lpop(zjh_lobby_key($base)); if (!$id) break;
        [$joined, $error] = zjh_join($id, '', true);
        if ($joined) {
            $room = zjh_room_get($joined);
            if (($room['status'] ?? '') === 'waiting') $redis->rpush(zjh_lobby_key($base), $joined);
            return [$joined, ''];
        }
    }
    [$id, $error] = zjh_new_room($base, 'match');
    if ($id && $error === '') $redis->rpush(zjh_lobby_key($base), $id);
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
        if (($room['mode'] ?? '') === 'match' && count(array_filter($room['players'])) > 0) zjh_redis()->rpush(zjh_lobby_key($room['base']), $id);
        return '';
    } finally { zjh_unlock($id, $token); }
}

function zjh_public($room, $uid)
{
    global $BASEURL;
    if (!$room) return ['ok'=>true, 'game'=>null, 'wallet'=>zjh_wallet($uid)];
    $viewer = zjh_seat_of($room, $uid); if ($viewer < 0) return ['ok'=>true, 'game'=>null];
    $finished = ($room['status'] ?? '') === 'finished'; $players = [];
    for ($view=0; $view<ZJH_SEATS; $view++) {
        $actual = ($viewer + $view) % ZJH_SEATS; $player = $room['players'][$actual] ?? null;
        if (!$player) { $players[] = ['username'=>'等待真人', 'status'=>'empty', 'cards'=>[], 'stack'=>0, 'seen'=>false, 'delta'=>0]; continue; }
        $show = $finished || ($actual === $viewer && !empty($player['seen'])); $cards = [];
        if ($show) foreach (($player['cards'] ?? []) as $card) $cards[] = zjh_card_view($card);
        $players[] = ['username'=>$player['username'], 'status'=>$player['status'], 'cards'=>$cards, 'stack'=>(int)$player['stack'],
            'seen'=>(bool)$player['seen'], 'handName'=>$show && count($cards) === 3 ? zjh_score_name(zjh_evaluate($player['cards'])) : '', 'delta'=>(float)($player['delta'] ?? 0)];
    }
    $turn = (int)($room['turn'] ?? -1); $turnView = $turn < 0 ? -1 : (($turn - $viewer + ZJH_SEATS) % ZJH_SEATS);
    $me = $room['players'][$viewer]; $unit = (int)($room['current_bet'] ?? $room['base']) * (!empty($me['seen']) ? 2 : 1);
    $inviteUrl = get_protocol_prefix() . $BASEURL . '/games/zjh/?table=' . (int)$room['id'] . '&invite=' . rawurlencode($room['invite']);
    return ['ok'=>true, 'wallet'=>(float)($me['wallet_after_settle'] ?? $me['wallet_after_reserve'] ?? zjh_wallet($uid)), 'game'=>[
        'roomId'=>(int)$room['id'], 'invite'=>$room['invite'], 'inviteUrl'=>$inviteUrl, 'mode'=>$room['mode'], 'base'=>(int)$room['base'],
        'status'=>$room['status'], 'playerCount'=>count(array_filter($room['players'])), 'players'=>$players, 'pot'=>(int)($room['pot'] ?? 0),
        'turn'=>$turnView, 'timeLeft'=>$room['status']==='playing' ? max(0, (int)$room['deadline']-TIMENOW) : 0,
        'currentBet'=>(int)($room['current_bet'] ?? $room['base']), 'callCost'=>$unit, 'raiseCount'=>(int)($room['raise_count'] ?? 0),
        'canAct'=>$room['status']==='playing' && $turn===$viewer && ($me['status'] ?? '')==='active',
        'canPeek'=>$room['status']==='playing' && ($me['status'] ?? '')==='active' && empty($me['seen']), 'token'=>$room['token'],
        'logs'=>array_values($room['logs'] ?? []), 'winner'=>$finished ? (((int)$room['winner']-$viewer+ZJH_SEATS)%ZJH_SEATS) : -1,
        'finishReason'=>$room['finish_reason'] ?? '', 'startError'=>$room['start_error'] ?? ''
    ]];
}

function zjh_timeout(&$room)
{
    if (($room['status'] ?? '') !== 'playing' || TIMENOW < (int)$room['deadline']) return;
    $seat = (int)$room['turn']; $name = $room['players'][$seat]['username'];
    zjh_apply($room, $seat, 'fold'); zjh_log($room, $name . ' 读秒结束，系统自动弃牌');
}

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
        if (($room['status'] ?? '') === 'waiting' && count(array_filter($room['players'])) === ZJH_SEATS) zjh_start($room);
        zjh_timeout($room);
        return '';
    });
    if ($error !== '' && !$room) { echo json_encode(['ok'=>true,'game'=>null,'wallet'=>zjh_wallet((int)$GLOBALS['CURUSER']['id'])], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(zjh_public($room, (int)$CURUSER['id']), JSON_UNESCAPED_UNICODE); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json'); $action=(string)($_POST['action'] ?? ''); $room=null; $error=''; $uid=(int)$CURUSER['id'];
    if ($action==='match') { [$id,$error]=zjh_match($_POST['base'] ?? 0); if ($id) $room=zjh_room_get($id); }
    elseif ($action==='create') { [$id,$error]=zjh_new_room($_POST['base'] ?? 0, 'friend'); if ($id) $room=zjh_room_get($id); }
    elseif ($action==='join') { [$id,$error]=zjh_join($_POST['table'] ?? 0, $_POST['invite'] ?? ''); if ($id) $room=zjh_room_get($id); }
    elseif ($action==='leave') $error=zjh_leave($uid);
    elseif ($action==='rematch') {
        [$room,$error]=zjh_mutate(function (&$room,$seat) use ($uid) {
            if (($room['status'] ?? '')!=='finished') return '本局尚未结束。';
            $room['ready'][(string)$uid]=true; zjh_log($room, $room['players'][$seat]['username'].' 已准备下一局');
            if (count($room['ready'])===ZJH_SEATS) { $room['status']='waiting'; foreach ($room['players'] as &$p) $p['status']='waiting'; unset($p); zjh_start($room); }
            return '';
        });
    } elseif (in_array($action, ['peek','call','raise','fold','compare'], true)) {
        [$room,$error]=zjh_mutate(function (&$room,$seat) use ($action) {
            if (!hash_equals((string)($room['token'] ?? ''), (string)($_POST['token'] ?? ''))) return '页面凭证已失效，请刷新。';
            return zjh_apply($room,$seat,$action);
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
.zjh-page{max-width:1180px;margin:18px auto 40px;color:#f8ead0;font-family:Inter,"Microsoft YaHei",sans-serif}.zjh-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}.zjh-top h1{margin:0;color:var(--z-gold2);font-size:28px}.zjh-top a{color:var(--z-gold);text-decoration:none}.zjh-wallet{padding:9px 14px;border:1px solid var(--z-line);border-radius:10px;background:#1b100d}.zjh-table{position:relative;min-height:650px;border:1px solid var(--z-line);border-radius:30px;background:radial-gradient(ellipse at center,#194e38 0,#123528 55%,#28150f 56%,#120b09 82%);box-shadow:0 26px 70px rgba(0,0,0,.38),inset 0 0 0 10px rgba(227,180,90,.08);overflow:hidden}.zjh-brand{position:absolute;left:50%;top:45%;transform:translate(-50%,-50%);text-align:center;color:rgba(255,218,133,.82)}.zjh-brand strong{font-size:34px;letter-spacing:8px}.zjh-pot{margin-top:8px;padding:7px 14px;border:1px solid var(--z-line);border-radius:99px;background:rgba(15,9,7,.65)}.z-seat{position:absolute;width:270px;padding:13px;border:1px solid var(--z-line);border-radius:16px;background:linear-gradient(145deg,rgba(32,18,14,.97),rgba(15,10,9,.96));box-shadow:0 10px 30px rgba(0,0,0,.35)}.z-seat.me{left:50%;bottom:96px;transform:translateX(-50%)}.z-seat.left{left:30px;top:72px}.z-seat.right{right:30px;top:72px}.z-seat.is-turn{border-color:var(--z-gold2);box-shadow:0 0 0 2px rgba(227,180,90,.2),0 10px 30px #000}.z-seat-head{display:flex;justify-content:space-between;gap:10px}.z-name{font-weight:800;color:#fff}.z-stack{color:var(--z-gold)}.z-status{font-size:12px;color:#c7bba9;margin-top:5px}.z-cards{display:flex;gap:7px;margin-top:9px;min-height:72px}.z-card{width:48px;height:66px;border-radius:6px;background:#f7f1e6;color:#18100c;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:18px;font-weight:900;box-shadow:0 3px 8px #000}.z-card.red{color:#b9222e}.z-card.back{background:repeating-linear-gradient(45deg,#7d1e27,#7d1e27 5px,#d3a149 5px,#d3a149 7px);border:2px solid #f6d795}.z-clock{position:absolute;right:10px;bottom:10px;width:38px;height:38px;border-radius:50%;display:grid;place-items:center;border:3px solid var(--z-gold);color:var(--z-gold2);font-weight:900}.z-actions{position:absolute;left:50%;bottom:22px;transform:translateX(-50%);display:flex;gap:8px;z-index:5}.z-btn{border:1px solid var(--z-line);border-radius:10px;background:#392019;color:#f8ead0;padding:11px 17px;font-weight:800;cursor:pointer}.z-btn.primary{background:linear-gradient(135deg,#bc7d25,#e5b95d);color:#21130b}.z-btn.danger{background:#761d24}.z-btn:disabled{opacity:.38;cursor:not-allowed}.z-overlay{position:absolute;inset:0;background:rgba(10,7,6,.84);backdrop-filter:blur(8px);display:grid;place-items:center;z-index:10}.z-dialog{width:min(650px,calc(100% - 30px));padding:28px;border:1px solid var(--z-line);border-radius:20px;background:linear-gradient(145deg,#2b1712,#160e0c);text-align:center}.z-dialog h2{color:var(--z-gold2);font-size:28px;margin:0 0 8px}.z-base-grid,.z-mode-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin:18px 0}.z-mode-grid{grid-template-columns:1fr 1fr}.z-choice{padding:15px 8px;border:1px solid var(--z-line);border-radius:10px;background:#1b100d;color:#f8ead0;cursor:pointer}.z-choice.active{border-color:var(--z-gold2);color:var(--z-gold2);background:#352015}.z-wait-code{font-size:30px;letter-spacing:6px;color:var(--z-gold2);font-weight:900;margin:15px}.z-log{position:absolute;left:25px;bottom:22px;width:260px;max-height:82px;overflow:auto;font-size:12px;color:#d9cbb7}.z-result{margin-top:12px;color:#cdbfa9}.z-info{display:grid;grid-template-columns:1.5fr 1fr;gap:14px;margin-top:14px}.z-panel{border:1px solid var(--z-line);border-radius:14px;background:#1b100d;padding:18px}.z-panel h3{margin:0 0 14px;color:var(--z-gold2)}.z-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.z-stat{padding:12px;border-radius:10px;background:#291813;text-align:center}.z-stat b{display:block;font-size:20px;color:var(--z-gold)}.z-rank{width:100%;border-collapse:collapse}.z-rank td,.z-rank th{padding:8px;border-bottom:1px solid var(--z-line);text-align:left}.z-rule{font-size:13px;line-height:1.8;color:#d7c8b5}.z-toast{position:fixed;left:50%;top:80px;transform:translateX(-50%);padding:11px 18px;border-radius:10px;background:#8c1d25;color:#fff;z-index:100;display:none}
@media(max-width:760px){.zjh-page{margin:8px}.zjh-top h1{font-size:21px}.zjh-table{min-height:720px;border-radius:18px}.z-seat{width:42%;padding:9px}.z-seat.left{left:8px;top:62px}.z-seat.right{right:8px;top:62px}.z-seat.me{width:65%;bottom:130px}.z-card{width:38px;height:54px}.zjh-brand{top:46%}.zjh-brand strong{font-size:24px}.z-actions{bottom:18px;width:96%;justify-content:center;flex-wrap:wrap}.z-btn{padding:9px 12px}.z-log{display:none}.z-info{grid-template-columns:1fr}.z-stats{grid-template-columns:repeat(2,1fr)}.z-base-grid{grid-template-columns:repeat(2,1fr)}}
@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;transition:none!important}}
</style>
<div class="zjh-page">
 <div class="zjh-top"><div><a href="/games/">返回游戏列表</a><h1>炸金花 <small>内测中 v0.1</small></h1></div><div class="zjh-wallet">电影票 <b id="wallet"><?php echo number_format($initial['wallet'],1) ?></b></div></div>
 <main class="zjh-table" id="table">
  <div class="zjh-brand"><strong>炸金花</strong><div class="zjh-pot">底池 <b id="pot">0</b> · 当前注 <b id="currentBet">0</b></div></div>
  <div id="seats"></div><div class="z-log" id="logs"></div><div class="z-actions" id="actions"></div><div id="overlay"></div>
 </main>
 <section class="z-info">
  <div class="z-panel"><h3>我的战绩</h3><div class="z-stats"><div class="z-stat"><b><?php echo (int)$stats['games'] ?></b>总局数</div><div class="z-stat"><b><?php echo (int)$stats['wins'] ?></b>胜局</div><div class="z-stat"><b><?php echo number_format((float)$stats['net'],1) ?></b>净盈亏</div><div class="z-stat"><b><?php echo number_format((float)$stats['best'],1) ?></b>单局最佳</div></div><p class="z-rule">三人真人开局。牌型从大到小：豹子、同花顺、金花、顺子、对子、单张。A23 为最小顺子；完全相同牌型比牌时，发起比牌者落败。看牌后跟注、加注与比牌费用翻倍，每回合 30 秒。</p></div>
  <div class="z-panel"><h3>真人排行榜</h3><table class="z-rank"><tr><th>玩家</th><th>胜局</th><th>净盈亏</th></tr><?php foreach($rankings as $row){ ?><tr><td><?php echo htmlspecialchars($row['username']) ?></td><td><?php echo (int)$row['wins'] ?></td><td><?php echo number_format((float)$row['net'],1) ?></td></tr><?php } ?></table></div>
 </section>
</div><div class="z-toast" id="toast"></div>
<script>
let state=<?php echo json_encode($initial, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>, chosenBase=100, chosenMode='match', busy=false;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function toast(s){const e=document.getElementById('toast');e.textContent=s;e.style.display='block';setTimeout(()=>e.style.display='none',2600)}
function cards(p){if(p.cards&&p.cards.length)return p.cards.map(c=>`<span class="z-card ${c.red?'red':''}"><span>${esc(c.rank)}</span><span>${esc(c.suit)}</span></span>`).join('');if(p.status==='empty')return '';return '<span class="z-card back"></span><span class="z-card back"></span><span class="z-card back"></span>'}
function render(){const g=state.game;document.getElementById('wallet').textContent=Number(state.wallet||0).toLocaleString(undefined,{minimumFractionDigits:1,maximumFractionDigits:1});
 if(!g){document.getElementById('pot').textContent='0';document.getElementById('currentBet').textContent='0';document.getElementById('seats').innerHTML='';document.getElementById('logs').innerHTML='';document.getElementById('actions').innerHTML='';document.getElementById('overlay').innerHTML=`<div class="z-overlay"><div class="z-dialog"><h2>加入炸金花牌桌</h2><p>三位真人玩家才会开局，不加入机器人。</p><div class="z-mode-grid"><button class="z-choice ${chosenMode==='match'?'active':''}" onclick="chosenMode='match';render()">快速排队<br><small>匹配相同底注真人</small></button><button class="z-choice ${chosenMode==='friend'?'active':''}" onclick="chosenMode='friend';render()">好友一起<br><small>创建专属邀请牌桌</small></button></div><div class="z-base-grid">${[100,500,1000,5000].map(v=>`<button class="z-choice ${chosenBase===v?'active':''}" onclick="chosenBase=${v};render()">${v.toLocaleString()}<br><small>带入 ${(v*20).toLocaleString()}</small></button>`).join('')}</div><button class="z-btn primary" onclick="send(chosenMode==='match'?'match':'create',{base:chosenBase})">${chosenMode==='match'?'加入真人排队':'创建好友牌桌'}</button></div></div>`;return}
 document.getElementById('pot').textContent=Number(g.pot||0).toLocaleString();document.getElementById('currentBet').textContent=Number(g.currentBet||g.base).toLocaleString();
 document.getElementById('seats').innerHTML=g.players.map((p,i)=>`<article class="z-seat ${i===0?'me':i===1?'left':'right'} ${g.turn===i?'is-turn':''}"><div class="z-seat-head"><span class="z-name">${esc(p.username)}</span><span class="z-stack">${Number(p.stack||0).toLocaleString()}</span></div><div class="z-status">${p.status==='empty'?'等待入座':p.status==='folded'?'已弃牌':p.status==='compared'?'比牌落败':p.handName|| (p.seen?'已看牌':'暗牌')}${g.status==='finished'?` · ${p.delta>=0?'+':''}${Number(p.delta).toLocaleString()}`:''}</div><div class="z-cards">${cards(p)}</div>${g.turn===i&&g.status==='playing'?`<span class="z-clock">${g.timeLeft}</span>`:''}</article>`).join('');
 document.getElementById('logs').innerHTML=(g.logs||[]).slice().reverse().map(x=>`<div>${esc(x.time)} ${esc(x.text)}</div>`).join('');
 if(g.status==='playing')document.getElementById('actions').innerHTML=`<button class="z-btn" onclick="send('peek')" ${!g.canPeek||busy?'disabled':''}>看牌</button><button class="z-btn primary" onclick="send('call')" ${!g.canAct||busy?'disabled':''}>跟注 ${Number(g.callCost).toLocaleString()}</button><button class="z-btn" onclick="send('raise')" ${!g.canAct||g.raiseCount>=5||busy?'disabled':''}>加注</button><button class="z-btn" onclick="send('compare')" ${!g.canAct||busy?'disabled':''}>比牌</button><button class="z-btn danger" onclick="send('fold')" ${!g.canAct||busy?'disabled':''}>弃牌</button>`;else document.getElementById('actions').innerHTML='';
 if(g.status==='waiting')document.getElementById('overlay').innerHTML=`<div class="z-overlay"><div class="z-dialog"><h2>等待真人入座 ${g.playerCount}/3</h2><p>牌桌 ${g.roomId} · 底注 ${Number(g.base).toLocaleString()}</p>${g.mode==='friend'?`<div class="z-wait-code">${esc(g.invite)}</div><button class="z-btn primary" onclick="navigator.clipboard.writeText('${esc(g.inviteUrl)}').then(()=>toast('邀请链接已复制'))">复制好友邀请链接</button>`:'<p>系统正在匹配相同底注的真人玩家，请保持页面开启。</p>'}${g.startError?`<p class="z-result">${esc(g.startError)}</p>`:''}<br><button class="z-btn" onclick="send('leave')">离开牌桌</button></div></div>`;
 else if(g.status==='finished'){const w=g.players[g.winner];document.getElementById('overlay').innerHTML=`<div class="z-overlay"><div class="z-dialog"><h2>${esc(w?w.username:'赢家')} 获胜</h2><p>${esc(g.finishReason)}</p><div class="z-result">${g.players.map(p=>`${esc(p.username)}：${esc(p.handName)} ${p.delta>=0?'+':''}${Number(p.delta).toLocaleString()}`).join('<br>')}</div><br><button class="z-btn primary" onclick="send('rematch')">准备下一局</button><button class="z-btn" onclick="send('leave')">离开牌桌</button></div></div>`}else document.getElementById('overlay').innerHTML='';
}
async function send(action,extra={}){if(busy)return;busy=true;render();try{const fd=new FormData();fd.append('action',action);if(state.game)fd.append('token',state.game.token||'');Object.entries(extra).forEach(([k,v])=>fd.append(k,v));const r=await fetch(location.pathname,{method:'POST',body:fd,credentials:'same-origin'});const j=await r.json();if(!j.ok)toast(j.error||'操作失败');else state=j;render()}catch(e){toast('网络异常，请重试')}finally{busy=false;render()}}
async function poll(){try{const r=await fetch(location.pathname+'?ajax=poll',{credentials:'same-origin',cache:'no-store'});const j=await r.json();if(j.ok){state=j;render()}}catch(e){}}
render();setInterval(poll,2000);
</script>
<?php stdfoot(); ?>
