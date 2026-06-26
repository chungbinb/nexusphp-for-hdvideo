<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;

const GAME_SP_BUSINESS_TYPE = 13; // reuse the lucky-draw / game bonus category
const GAME_SP_MATCH_TABLE = 'hdvideo_sports_matches';
const GAME_SP_BET_TABLE = 'hdvideo_sports_bets';
const GAME_SP_CONFIG_TABLE = 'hdvideo_sports_config';
const GAME_SP_API_BASE = 'https://api.football-data.org/v4/';

function game_sp_run_schema_sql($sql)
{
    $res = @sql_query($sql);
    if (!$res) {
        do_log('[GAME_SPORTS_SCHEMA_ERROR] ' . $sql . ' :: ' . mysql_error(), 'error');
    }
    return $res;
}

function game_sp_column_exists($table, $column)
{
    $res = @sql_query("SHOW COLUMNS FROM `$table` LIKE " . sqlesc($column));
    return $res && mysql_num_rows($res) > 0;
}

function game_sp_ensure_tables()
{
    static $done = false;
    if ($done) {
        return;
    }
    game_sp_run_schema_sql("
        CREATE TABLE IF NOT EXISTS `" . GAME_SP_MATCH_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `external_id` varchar(40) NOT NULL DEFAULT '',
            `league` varchar(80) NOT NULL DEFAULT '',
            `home_team` varchar(80) NOT NULL DEFAULT '',
            `away_team` varchar(80) NOT NULL DEFAULT '',
            `match_time` datetime NOT NULL,
            `bet_deadline` datetime NOT NULL,
            `odds_home` decimal(6,2) NOT NULL DEFAULT '0.00',
            `odds_draw` decimal(6,2) NOT NULL DEFAULT '0.00',
            `odds_away` decimal(6,2) NOT NULL DEFAULT '0.00',
            `status` enum('draft','open','settled','cancelled') NOT NULL DEFAULT 'open',
            `result` enum('home','draw','away') DEFAULT NULL,
            `home_score` smallint unsigned DEFAULT NULL,
            `away_score` smallint unsigned DEFAULT NULL,
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_status_deadline` (`status`, `bet_deadline`),
            KEY `idx_external` (`external_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    game_sp_run_schema_sql("
        CREATE TABLE IF NOT EXISTS `" . GAME_SP_BET_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `match_id` int unsigned NOT NULL,
            `uid` int unsigned NOT NULL,
            `choice` enum('home','draw','away') NOT NULL,
            `odds` decimal(6,2) NOT NULL,
            `amount` decimal(20,1) NOT NULL,
            `status` enum('pending','won','lost','refunded') NOT NULL DEFAULT 'pending',
            `payout` decimal(20,1) NOT NULL DEFAULT '0.0',
            `created_at` datetime NOT NULL,
            `settled_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_match_status` (`match_id`, `status`),
            KEY `idx_uid_created` (`uid`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    game_sp_run_schema_sql("
        CREATE TABLE IF NOT EXISTS `" . GAME_SP_CONFIG_TABLE . "` (
            `k` varchar(50) NOT NULL,
            `v` text,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`k`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // In-place upgrade for tables created before fixture-import support.
    if (!game_sp_column_exists(GAME_SP_MATCH_TABLE, 'external_id')) {
        game_sp_run_schema_sql("ALTER TABLE `" . GAME_SP_MATCH_TABLE . "` MODIFY `status` enum('draft','open','settled','cancelled') NOT NULL DEFAULT 'open'");
        game_sp_run_schema_sql("ALTER TABLE `" . GAME_SP_MATCH_TABLE . "` ADD COLUMN `external_id` varchar(40) NOT NULL DEFAULT '' AFTER `id`");
        game_sp_run_schema_sql("ALTER TABLE `" . GAME_SP_MATCH_TABLE . "` ADD KEY `idx_external` (`external_id`)");
    }
    $done = true;
}

function game_sp_config_get($k, $default = '')
{
    $res = sql_query("SELECT `v` FROM `" . GAME_SP_CONFIG_TABLE . "` WHERE `k` = " . sqlesc($k) . " LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $row = mysql_fetch_assoc($res);
    return $row ? $row['v'] : $default;
}

function game_sp_config_set($k, $v)
{
    $now = date('Y-m-d H:i:s');
    sql_query("INSERT INTO `" . GAME_SP_CONFIG_TABLE . "` (`k`, `v`, `updated_at`) VALUES (" . sqlesc($k) . ", " . sqlesc($v) . ", " . sqlesc($now) . ") ON DUPLICATE KEY UPDATE `v` = VALUES(`v`), `updated_at` = VALUES(`updated_at`)") or sqlerr(__FILE__, __LINE__);
}

function game_sp_money($value)
{
    return number_format((float)$value, 1, '.', '');
}

function game_sp_is_admin()
{
    return get_user_class() >= UC_ADMINISTRATOR;
}

function game_sp_choice_label($choice)
{
    return ['home' => '主胜', 'draw' => '平局', 'away' => '客胜'][$choice] ?? $choice;
}

/**
 * Best-effort English -> Chinese for league / national-team / common club names.
 * Unknown names are returned unchanged.
 */
function game_sp_tr($name)
{
    static $map = null;
    if ($map === null) {
        $map = [
            // competitions
            'FIFA World Cup' => '世界杯', 'World Cup' => '世界杯', 'UEFA Champions League' => '欧冠',
            'Premier League' => '英超', 'Primera Division' => '西甲', 'La Liga' => '西甲',
            'Serie A' => '意甲', 'Bundesliga' => '德甲', 'Ligue 1' => '法甲', 'Eredivisie' => '荷甲',
            'Primeira Liga' => '葡超', 'Championship' => '英冠', 'Campeonato Brasileiro Série A' => '巴甲',
            'European Championship' => '欧洲杯', 'UEFA Europa League' => '欧联杯',
            // national teams
            'France' => '法国', 'Norway' => '挪威', 'Spain' => '西班牙', 'Uruguay' => '乌拉圭',
            'Senegal' => '塞内加尔', 'Iraq' => '伊拉克', 'Cape Verde Islands' => '佛得角', 'Cape Verde' => '佛得角',
            'Saudi Arabia' => '沙特阿拉伯', 'New Zealand' => '新西兰', 'Belgium' => '比利时',
            'Egypt' => '埃及', 'Iran' => '伊朗', 'Panama' => '巴拿马', 'England' => '英格兰',
            'Croatia' => '克罗地亚', 'Ghana' => '加纳', 'Colombia' => '哥伦比亚', 'Portugal' => '葡萄牙',
            'Argentina' => '阿根廷', 'Brazil' => '巴西', 'Germany' => '德国', 'Netherlands' => '荷兰',
            'Italy' => '意大利', 'Mexico' => '墨西哥', 'United States' => '美国', 'USA' => '美国',
            'Canada' => '加拿大', 'Japan' => '日本', 'South Korea' => '韩国', 'Korea Republic' => '韩国',
            'Australia' => '澳大利亚', 'Morocco' => '摩洛哥', 'Switzerland' => '瑞士', 'Denmark' => '丹麦',
            'Poland' => '波兰', 'Serbia' => '塞尔维亚', 'Wales' => '威尔士', 'Ecuador' => '厄瓜多尔',
            'Qatar' => '卡塔尔', 'Tunisia' => '突尼斯', 'Costa Rica' => '哥斯达黎加', 'Cameroon' => '喀麦隆',
            'Nigeria' => '尼日利亚', 'Algeria' => '阿尔及利亚', 'Ivory Coast' => '科特迪瓦',
            'Chile' => '智利', 'Peru' => '秘鲁', 'Paraguay' => '巴拉圭', 'Sweden' => '瑞典',
            'Austria' => '奥地利', 'Turkey' => '土耳其', 'Türkiye' => '土耳其', 'Ukraine' => '乌克兰',
            'Czech Republic' => '捷克', 'Greece' => '希腊', 'Russia' => '俄罗斯', 'Scotland' => '苏格兰',
            'Ireland' => '爱尔兰', 'Republic of Ireland' => '爱尔兰', 'China' => '中国', 'China PR' => '中国',
            'South Africa' => '南非', 'Jamaica' => '牙买加', 'Honduras' => '洪都拉斯', 'Venezuela' => '委内瑞拉',
            'Bolivia' => '玻利维亚', 'Slovakia' => '斯洛伐克', 'Slovenia' => '斯洛文尼亚', 'Hungary' => '匈牙利',
            'Romania' => '罗马尼亚', 'Uzbekistan' => '乌兹别克斯坦', 'Jordan' => '约旦', 'Mali' => '马里',
            'Burkina Faso' => '布基纳法索', 'DR Congo' => '刚果(金)', 'Angola' => '安哥拉', 'Zambia' => '赞比亚',
            // common clubs
            'Real Madrid' => '皇家马德里', 'Barcelona' => '巴塞罗那', 'Atletico Madrid' => '马德里竞技',
            'Manchester City' => '曼城', 'Manchester United' => '曼联', 'Liverpool' => '利物浦',
            'Chelsea' => '切尔西', 'Arsenal' => '阿森纳', 'Tottenham Hotspur' => '热刺',
            'Bayern Munich' => '拜仁慕尼黑', 'Borussia Dortmund' => '多特蒙德', 'Juventus' => '尤文图斯',
            'AC Milan' => 'AC米兰', 'Inter' => '国际米兰', 'Internazionale' => '国际米兰', 'Napoli' => '那不勒斯',
            'Paris Saint-Germain' => '巴黎圣日耳曼', 'PSG' => '巴黎圣日耳曼',
        ];
    }
    $n = trim((string)$name);
    return $n === '' ? $n : ($map[$n] ?? $n);
}

function game_sp_bonus_log($uid, $old, $delta, $new, $comment)
{
    $now = date('Y-m-d H:i:s');
    $fullComment = '[Sports] ' . $comment;
    sql_query(sprintf(
        "INSERT INTO bonus_logs (`business_type`, `uid`, `old_total_value`, `value`, `new_total_value`, `comment`, `created_at`, `updated_at`) VALUES (%d, %d, %s, %s, %s, %s, %s, %s)",
        GAME_SP_BUSINESS_TYPE,
        (int)$uid,
        sqlesc(game_sp_money($old)),
        sqlesc(game_sp_money($delta)),
        sqlesc(game_sp_money($new)),
        sqlesc($fullComment),
        sqlesc($now),
        sqlesc($now)
    )) or sqlerr(__FILE__, __LINE__);
}

function game_sp_parse_datetime($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    $ts -= $ts % 60; // ignore seconds, normalize to :00
    return date('Y-m-d H:i:s', $ts);
}

/**
 * Call API-Football (api-sports.io). Returns [decodedArray|null, errorString].
 */
function game_sp_api_request($path, array $params = [])
{
    $key = trim((string)game_sp_config_get('api_key', ''));
    if ($key === '') {
        return [null, '尚未设置 API token（在下方管理区填写）。'];
    }
    if (!function_exists('curl_init')) {
        return [null, '服务器未启用 cURL。'];
    }
    $url = GAME_SP_API_BASE . ltrim($path, '/');
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-Auth-Token: ' . $key],
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno) {
        return [null, "请求失败：$err"];
    }
    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        return [null, "返回解析失败（HTTP $code）。"];
    }
    // football-data.org returns {"message": "...", "errorCode": N} on errors.
    if ($code >= 400 || isset($data['errorCode'])) {
        $msg = $data['message'] ?? "HTTP $code";
        return [null, "API 错误（HTTP $code）：$msg"];
    }
    return [$data, ''];
}

function game_sp_import_fixtures($competition, $from, $to)
{
    $code = preg_replace('/[^A-Za-z0-9]/', '', (string)$competition);
    if ($code === '') {
        return [0, '联赛代码无效。'];
    }
    [$data, $err] = game_sp_api_request("competitions/$code/matches", [
        'dateFrom' => $from,
        'dateTo' => $to,
    ]);
    if ($err) {
        return [0, $err];
    }
    $now = date('Y-m-d H:i:s');
    $imported = 0;
    $finished = ['FINISHED', 'AWARDED', 'CANCELLED', 'POSTPONED', 'SUSPENDED'];
    foreach (($data['matches'] ?? []) as $fx) {
        $extId = (string)($fx['id'] ?? '');
        if ($extId === '') {
            continue;
        }
        $status = (string)($fx['status'] ?? '');
        if (in_array($status, $finished, true)) {
            continue;
        }
        $home = trim((string)($fx['homeTeam']['name'] ?? ''));
        $away = trim((string)($fx['awayTeam']['name'] ?? ''));
        $league = trim((string)($fx['competition']['name'] ?? ''));
        $ts = strtotime((string)($fx['utcDate'] ?? ''));
        if ($home === '' || $away === '' || !$ts) {
            continue;
        }
        $existsRes = sql_query("SELECT `id` FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `external_id` = " . sqlesc($extId) . " LIMIT 1") or sqlerr(__FILE__, __LINE__);
        if (mysql_fetch_assoc($existsRes)) {
            continue;
        }
        $matchTime = date('Y-m-d H:i:s', $ts - ($ts % 60));
        sql_query(sprintf(
            "INSERT INTO `" . GAME_SP_MATCH_TABLE . "` (`external_id`, `league`, `home_team`, `away_team`, `match_time`, `bet_deadline`, `status`, `created_at`, `updated_at`) VALUES (%s, %s, %s, %s, %s, %s, 'draft', %s, %s)",
            sqlesc($extId),
            sqlesc(mb_substr($league, 0, 80)),
            sqlesc(mb_substr($home, 0, 80)),
            sqlesc(mb_substr($away, 0, 80)),
            sqlesc($matchTime),
            sqlesc($matchTime),
            sqlesc($now),
            sqlesc($now)
        )) or sqlerr(__FILE__, __LINE__);
        $imported++;
    }
    return [$imported, ''];
}

/**
 * Fetch the final result of an API-imported match. Returns
 * [['result'=>home|draw|away,'home'=>score,'away'=>score], ''] or [null, errorMsg].
 */
function game_sp_fetch_result($externalId)
{
    $externalId = preg_replace('/[^0-9]/', '', (string)$externalId);
    if ($externalId === '') {
        return [null, '该比赛非 API 导入，请手动录入结果。'];
    }
    [$data, $err] = game_sp_api_request("matches/$externalId");
    if ($err) {
        return [null, $err];
    }
    $match = $data['match'] ?? $data;
    $status = (string)($match['status'] ?? '');
    if ($status !== 'FINISHED') {
        return [null, "比赛尚未结束（状态：$status），暂无法自动结算。"];
    }
    $winner = (string)($match['score']['winner'] ?? '');
    $map = ['HOME_TEAM' => 'home', 'AWAY_TEAM' => 'away', 'DRAW' => 'draw'];
    if (!isset($map[$winner])) {
        return [null, '结果数据缺失，请手动录入。'];
    }
    return [[
        'result' => $map[$winner],
        'home' => $match['score']['fullTime']['home'] ?? '',
        'away' => $match['score']['fullTime']['away'] ?? '',
    ], ''];
}

function game_sp_place_bet($matchId, $choice, $amount)
{
    global $CURUSER;
    $matchId = (int)$matchId;
    $now = date('Y-m-d H:i:s');
    $uid = (int)$CURUSER['id'];
    $amountSql = game_sp_money($amount);

    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $matchRes = sql_query("SELECT * FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `id` = $matchId FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $match = mysql_fetch_assoc($matchRes);
        if (!$match || $match['status'] !== 'open' || $match['bet_deadline'] <= $now) {
            sql_query("ROLLBACK");
            return "该比赛已截止或不可押注，请刷新。";
        }
        $oddsField = 'odds_' . $choice;
        $odds = (float)$match[$oddsField];
        if ($odds <= 1) {
            sql_query("ROLLBACK");
            return "该选项暂未开盘。";
        }

        $userRes = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = $uid FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $user = mysql_fetch_assoc($userRes);
        if (!$user) {
            sql_query("ROLLBACK");
            return "用户不存在。";
        }
        $oldBonus = (float)$user['seedbonus'];
        if ($oldBonus < $amount) {
            sql_query("ROLLBACK");
            return "电影票不足，当前只有 " . game_sp_money($oldBonus) . " 张。";
        }
        $newBonus = $oldBonus - $amount;
        sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` - " . sqlesc($amountSql) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
        sql_query(sprintf(
            "INSERT INTO `" . GAME_SP_BET_TABLE . "` (`match_id`, `uid`, `choice`, `odds`, `amount`, `created_at`) VALUES (%d, %d, %s, %s, %s, %s)",
            $matchId,
            $uid,
            sqlesc($choice),
            sqlesc(number_format($odds, 2, '.', '')),
            sqlesc($amountSql),
            sqlesc($now)
        )) or sqlerr(__FILE__, __LINE__);
        game_sp_bonus_log($uid, $oldBonus, $amount, $newBonus,
            "{$match['home_team']} vs {$match['away_team']} 押" . game_sp_choice_label($choice) . "（赔率 " . number_format($odds, 2) . "），扣除 {$amount} 张电影票");
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        clear_user_cache($uid);
        $CURUSER['seedbonus'] = $newBonus;
        return "";
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        throw $e;
    }
}

function game_sp_settle_match($matchId, $result, $homeScore, $awayScore)
{
    $matchId = (int)$matchId;
    $now = date('Y-m-d H:i:s');
    $homeScoreSql = ($homeScore === '' || $homeScore === null) ? 'NULL' : (int)$homeScore;
    $awayScoreSql = ($awayScore === '' || $awayScore === null) ? 'NULL' : (int)$awayScore;

    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $matchRes = sql_query("SELECT * FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `id` = $matchId FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $match = mysql_fetch_assoc($matchRes);
        if (!$match) {
            sql_query("ROLLBACK");
            return "比赛不存在。";
        }
        if ($match['status'] !== 'open') {
            sql_query("ROLLBACK");
            return "只有已开盘的比赛才能结算。";
        }
        sql_query("UPDATE `" . GAME_SP_MATCH_TABLE . "` SET `status` = 'settled', `result` = " . sqlesc($result) . ", `home_score` = $homeScoreSql, `away_score` = $awayScoreSql, `updated_at` = " . sqlesc($now) . " WHERE `id` = $matchId") or sqlerr(__FILE__, __LINE__);

        $winRes = sql_query("
            SELECT b.`id`, b.`uid`, b.`amount`, b.`odds`, u.`seedbonus`
            FROM `" . GAME_SP_BET_TABLE . "` b
            INNER JOIN `users` u ON u.`id` = b.`uid`
            WHERE b.`match_id` = $matchId AND b.`status` = 'pending' AND b.`choice` = " . sqlesc($result) . "
            FOR UPDATE
        ") or sqlerr(__FILE__, __LINE__);
        while ($bet = mysql_fetch_assoc($winRes)) {
            $betId = (int)$bet['id'];
            $uid = (int)$bet['uid'];
            $amount = (float)$bet['amount'];
            $odds = (float)$bet['odds'];
            $payout = round($amount * $odds, 1);
            $oldBonus = (float)$bet['seedbonus'];
            $newBonus = $oldBonus + $payout;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(game_sp_money($payout)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            sql_query("UPDATE `" . GAME_SP_BET_TABLE . "` SET `status` = 'won', `payout` = " . sqlesc(game_sp_money($payout)) . ", `settled_at` = " . sqlesc($now) . " WHERE `id` = $betId") or sqlerr(__FILE__, __LINE__);
            game_sp_bonus_log($uid, $oldBonus, $payout, $newBonus,
                "{$match['home_team']} vs {$match['away_team']} 押" . game_sp_choice_label($result) . "命中（赔率 " . number_format($odds, 2) . "）派彩 {$payout}");
            clear_user_cache($uid);
        }
        sql_query("UPDATE `" . GAME_SP_BET_TABLE . "` SET `status` = 'lost', `settled_at` = " . sqlesc($now) . " WHERE `match_id` = $matchId AND `status` = 'pending'") or sqlerr(__FILE__, __LINE__);
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        return "";
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        throw $e;
    }
}

function game_sp_cancel_match($matchId)
{
    $matchId = (int)$matchId;
    $now = date('Y-m-d H:i:s');

    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $matchRes = sql_query("SELECT * FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `id` = $matchId FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $match = mysql_fetch_assoc($matchRes);
        if (!$match) {
            sql_query("ROLLBACK");
            return "比赛不存在。";
        }
        if ($match['status'] !== 'open') {
            sql_query("ROLLBACK");
            return "只有已开盘的比赛才能取消。";
        }
        $betRes = sql_query("
            SELECT b.`id`, b.`uid`, b.`amount`, u.`seedbonus`
            FROM `" . GAME_SP_BET_TABLE . "` b
            INNER JOIN `users` u ON u.`id` = b.`uid`
            WHERE b.`match_id` = $matchId AND b.`status` = 'pending'
            FOR UPDATE
        ") or sqlerr(__FILE__, __LINE__);
        while ($bet = mysql_fetch_assoc($betRes)) {
            $betId = (int)$bet['id'];
            $uid = (int)$bet['uid'];
            $amount = (float)$bet['amount'];
            $oldBonus = (float)$bet['seedbonus'];
            $newBonus = $oldBonus + $amount;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(game_sp_money($amount)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            sql_query("UPDATE `" . GAME_SP_BET_TABLE . "` SET `status` = 'refunded', `payout` = " . sqlesc(game_sp_money($amount)) . ", `settled_at` = " . sqlesc($now) . " WHERE `id` = $betId") or sqlerr(__FILE__, __LINE__);
            game_sp_bonus_log($uid, $oldBonus, $amount, $newBonus,
                "{$match['home_team']} vs {$match['away_team']} 比赛取消，退还本金 {$amount}");
            clear_user_cache($uid);
        }
        sql_query("UPDATE `" . GAME_SP_MATCH_TABLE . "` SET `status` = 'cancelled', `updated_at` = " . sqlesc($now) . " WHERE `id` = $matchId") or sqlerr(__FILE__, __LINE__);
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        return "";
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        throw $e;
    }
}

/**
 * Validate + persist the three odds and (optional) deadline, then publish a match
 * (draft -> open). Also used to create a fully-manual match when $isNew is true.
 */
function game_sp_save_match($data, $isNew)
{
    $now = date('Y-m-d H:i:s');
    $oddsHome = (float)($data['odds_home'] ?? 0);
    $oddsDraw = (float)($data['odds_draw'] ?? 0);
    $oddsAway = (float)($data['odds_away'] ?? 0);
    foreach (['主胜' => $oddsHome, '平局' => $oddsDraw, '客胜' => $oddsAway] as $name => $o) {
        if ($o <= 1 || $o > 1000) {
            return "{$name}赔率必须大于 1 且不超过 1000。";
        }
    }

    if ($isNew) {
        $league = trim((string)($data['league'] ?? ''));
        $home = trim((string)($data['home_team'] ?? ''));
        $away = trim((string)($data['away_team'] ?? ''));
        $matchTime = game_sp_parse_datetime($data['match_time'] ?? '');
        $deadline = game_sp_parse_datetime($data['bet_deadline'] ?? '');
        if ($home === '' || $away === '') {
            return "主队和客队不能为空。";
        }
        if (mb_strlen($league) > 80 || mb_strlen($home) > 80 || mb_strlen($away) > 80) {
            return "队名或联赛名过长。";
        }
        if (!$matchTime || !$deadline) {
            return "比赛时间和截止时间必须填写且格式正确。";
        }
        if ($deadline > $matchTime) {
            return "押注截止时间不能晚于比赛开始时间。";
        }
        sql_query(sprintf(
            "INSERT INTO `" . GAME_SP_MATCH_TABLE . "` (`league`, `home_team`, `away_team`, `match_time`, `bet_deadline`, `odds_home`, `odds_draw`, `odds_away`, `status`, `created_at`, `updated_at`) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, 'open', %s, %s)",
            sqlesc($league), sqlesc($home), sqlesc($away), sqlesc($matchTime), sqlesc($deadline),
            sqlesc(number_format($oddsHome, 2, '.', '')), sqlesc(number_format($oddsDraw, 2, '.', '')), sqlesc(number_format($oddsAway, 2, '.', '')),
            sqlesc($now), sqlesc($now)
        )) or sqlerr(__FILE__, __LINE__);
        return "";
    }

    // Publish an imported draft.
    $matchId = (int)($data['match_id'] ?? 0);
    $matchRes = sql_query("SELECT * FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `id` = $matchId LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $match = mysql_fetch_assoc($matchRes);
    if (!$match || $match['status'] !== 'draft') {
        return "草稿赛事不存在或已发布。";
    }
    $deadline = game_sp_parse_datetime($data['bet_deadline'] ?? '');
    if (!$deadline) {
        $deadline = $match['bet_deadline'];
    }
    if ($deadline > $match['match_time']) {
        return "押注截止时间不能晚于比赛开始时间。";
    }
    sql_query("UPDATE `" . GAME_SP_MATCH_TABLE . "` SET `odds_home` = " . sqlesc(number_format($oddsHome, 2, '.', '')) . ", `odds_draw` = " . sqlesc(number_format($oddsDraw, 2, '.', '')) . ", `odds_away` = " . sqlesc(number_format($oddsAway, 2, '.', '')) . ", `bet_deadline` = " . sqlesc($deadline) . ", `status` = 'open', `updated_at` = " . sqlesc($now) . " WHERE `id` = $matchId") or sqlerr(__FILE__, __LINE__);
    return "";
}

function game_sp_delete_draft($matchId)
{
    $matchId = (int)$matchId;
    sql_query("DELETE FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `id` = $matchId AND `status` = 'draft'") or sqlerr(__FILE__, __LINE__);
    return "";
}

game_sp_ensure_tables();

$message = "";
$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'bet') {
        $matchId = (int)($_POST['match_id'] ?? 0);
        $choice = $_POST['choice'] ?? '';
        $amountRaw = trim((string)($_POST['amount'] ?? ''));
        if (!in_array($choice, ['home', 'draw', 'away'], true)) {
            $error = "请选择主胜、平局或客胜。";
        } elseif (!preg_match('/^[1-9][0-9]*$/', $amountRaw)) {
            $error = "押注电影票必须是正整数。";
        } elseif ((int)$amountRaw > 1000000) {
            $error = "单次押注不能超过 1000000 张电影票。";
        } else {
            $error = game_sp_place_bet($matchId, $choice, (int)$amountRaw);
            if ($error === "") {
                $message = "押注成功，已实时扣除 {$amountRaw} 张电影票。";
            }
        }
    } elseif ($action === 'create_match' && game_sp_is_admin()) {
        $error = game_sp_save_match($_POST, true);
        if ($error === "") {
            $message = "比赛已创建并开盘。";
        }
    } elseif ($action === 'publish_match' && game_sp_is_admin()) {
        $error = game_sp_save_match($_POST, false);
        if ($error === "") {
            $message = "赔率已设置，比赛已开盘。";
        }
    } elseif ($action === 'delete_draft' && game_sp_is_admin()) {
        $error = game_sp_delete_draft((int)($_POST['match_id'] ?? 0));
        if ($error === "") {
            $message = "草稿赛事已删除。";
        }
    } elseif ($action === 'settle_match' && game_sp_is_admin()) {
        $result = $_POST['result'] ?? '';
        if (!in_array($result, ['home', 'draw', 'away'], true)) {
            $error = "请选择比赛结果。";
        } else {
            $error = game_sp_settle_match((int)($_POST['match_id'] ?? 0), $result, $_POST['home_score'] ?? '', $_POST['away_score'] ?? '');
            if ($error === "") {
                $message = "比赛已结算并派彩。";
            }
        }
    } elseif ($action === 'cancel_match' && game_sp_is_admin()) {
        $error = game_sp_cancel_match((int)($_POST['match_id'] ?? 0));
        if ($error === "") {
            $message = "比赛已取消，押注已退回。";
        }
    } elseif ($action === 'save_api_key' && game_sp_is_admin()) {
        game_sp_config_set('api_key', trim((string)($_POST['api_key'] ?? '')));
        $message = "API key 已保存。";
    } elseif ($action === 'import_fixtures' && game_sp_is_admin()) {
        $competition = trim((string)($_POST['competition'] ?? ''));
        $from = game_sp_parse_datetime(($_POST['from'] ?? '') . ' 00:00:00');
        $to = game_sp_parse_datetime(($_POST['to'] ?? '') . ' 00:00:00');
        if ($competition === '' || !$from || !$to) {
            $error = "请填写联赛代码和起止日期。";
        } else {
            [$count, $err] = game_sp_import_fixtures($competition, substr($from, 0, 10), substr($to, 0, 10));
            $error = $err;
            if ($error === "") {
                $message = "导入完成，新增 {$count} 场草稿赛事（在下方“草稿赛事”里设赔率后开盘）。";
            }
        }
    } elseif ($action === 'auto_settle' && game_sp_is_admin()) {
        $matchId = (int)($_POST['match_id'] ?? 0);
        $mres = sql_query("SELECT `external_id`, `status` FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `id` = $matchId LIMIT 1") or sqlerr(__FILE__, __LINE__);
        $mrow = mysql_fetch_assoc($mres);
        if (!$mrow || $mrow['status'] !== 'open') {
            $error = "比赛不存在或不可结算。";
        } else {
            [$r, $err] = game_sp_fetch_result($mrow['external_id']);
            if ($err) {
                $error = $err;
            } else {
                $error = game_sp_settle_match($matchId, $r['result'], $r['home'], $r['away']);
                if ($error === "") {
                    $message = "已自动获取结果并结算派彩。";
                }
            }
        }
    } elseif ($action === 'auto_settle_all' && game_sp_is_admin()) {
        $nowStr = date('Y-m-d H:i:s');
        $listRes = sql_query("SELECT `id`, `external_id` FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `status` = 'open' AND `external_id` <> '' AND `match_time` <= " . sqlesc($nowStr) . " ORDER BY `match_time` ASC LIMIT 10") or sqlerr(__FILE__, __LINE__);
        $rows = [];
        while ($lrow = mysql_fetch_assoc($listRes)) {
            $rows[] = $lrow;
        }
        $settled = 0;
        $skipped = 0;
        foreach ($rows as $lrow) {
            [$r, $err] = game_sp_fetch_result($lrow['external_id']);
            if ($err) {
                $skipped++;
                continue;
            }
            $e = game_sp_settle_match((int)$lrow['id'], $r['result'], $r['home'], $r['away']);
            if ($e === "") {
                $settled++;
            } else {
                $skipped++;
            }
        }
        $message = "自动结算完成：成功 {$settled} 场，跳过 {$skipped} 场（未结束/暂无结果的稍后再试或手动录入）。每次最多处理 10 场（API 限速）。";
    } else {
        $error = "无效操作。";
    }
    $redirectParams = $error !== '' ? ['error' => $error] : ['message' => $message];
    header('Location: /games/sports/?' . http_build_query($redirectParams));
    exit;
}

$message = trim((string)($_GET['message'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));
$isAdmin = game_sp_is_admin();
$now = date('Y-m-d H:i:s');

$openRes = sql_query("SELECT * FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `status` = 'open' AND `bet_deadline` > " . sqlesc($now) . " ORDER BY `match_time` ASC, `id` ASC") or sqlerr(__FILE__, __LINE__);
$openMatches = [];
$leagues = [];
while ($m = mysql_fetch_assoc($openRes)) {
    $openMatches[] = $m;
    $lg = $m['league'] !== '' ? $m['league'] : '其他';
    $leagues[$lg] = true;
}
$leagueList = array_keys($leagues);
$resultRes = sql_query("SELECT * FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `status` IN ('settled','cancelled') ORDER BY `updated_at` DESC, `id` DESC LIMIT 15") or sqlerr(__FILE__, __LINE__);
$myBetsRes = sql_query("
    SELECT b.*, m.`home_team`, m.`away_team`, m.`league`
    FROM `" . GAME_SP_BET_TABLE . "` b
    INNER JOIN `" . GAME_SP_MATCH_TABLE . "` m ON m.`id` = b.`match_id`
    WHERE b.`uid` = " . (int)$CURUSER['id'] . " ORDER BY b.`id` DESC LIMIT 12
") or sqlerr(__FILE__, __LINE__);

$draftMatches = [];
$adminMatches = [];
if ($isAdmin) {
    $draftRes = sql_query("SELECT * FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `status` = 'draft' ORDER BY `match_time` ASC, `id` ASC") or sqlerr(__FILE__, __LINE__);
    while ($m = mysql_fetch_assoc($draftRes)) {
        $draftMatches[] = $m;
    }
    $adminRes = sql_query("SELECT * FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `status` = 'open' ORDER BY `match_time` ASC, `id` ASC") or sqlerr(__FILE__, __LINE__);
    while ($m = mysql_fetch_assoc($adminRes)) {
        $totRes = sql_query("SELECT `choice`, SUM(`amount`) AS total FROM `" . GAME_SP_BET_TABLE . "` WHERE `match_id` = " . (int)$m['id'] . " AND `status` = 'pending' GROUP BY `choice`") or sqlerr(__FILE__, __LINE__);
        $tot = ['home' => 0, 'draw' => 0, 'away' => 0];
        while ($t = mysql_fetch_assoc($totRes)) {
            $tot[$t['choice']] = (float)$t['total'];
        }
        $m['_totals'] = $tot;
        $adminMatches[] = $m;
    }
}

$betStatusLabel = ['pending' => '待开奖', 'won' => '中奖', 'lost' => '未中', 'refunded' => '已退回'];

stdhead("菠菜系统");
?>
<style>
.sp-wrap { max-width: 1000px; margin: 0 auto; }
.sp-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.sp-title { font-size: 24px; font-weight: 800; }
.sp-balance { font-size: 14px; font-weight: 700; }
.sp-muted { color: #6f7f95; }
.sp-message { padding: 10px 12px; border-radius: 6px; margin-bottom: 14px; font-weight: 700; }
.sp-message.ok { background: rgba(34,150,90,.14); color: #16834d; }
.sp-message.err { background: rgba(220,60,70,.14); color: #c02432; }
.sp-section-title { font-size: 18px; font-weight: 800; margin: 18px 0 10px; }
.sp-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.sp-tab { padding: 6px 14px; border: 1px solid rgba(120,150,190,.45); border-radius: 999px; cursor: pointer; font-weight: 700; background: rgba(0,0,0,.03); }
.sp-tab.is-active { background: #2ecc71; color: #fff; border-color: #2ecc71; }
.sp-match { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 14px; margin-bottom: 12px; background: rgba(30,60,100,.06); }
.sp-match-top { display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px; margin-bottom: 6px; }
.sp-league { font-size: 12px; color: #2f80b5; font-weight: 700; }
.sp-teams { font-size: 17px; font-weight: 800; }
.sp-time { color: #6f7f95; font-size: 13px; }
.sp-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 8px; }
.sp-odds { display: inline-flex; gap: 8px; flex-wrap: wrap; }
.sp-odds label { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border: 1px solid rgba(120,150,190,.45); border-radius: 6px; cursor: pointer; }
.sp-odds b { color: #c0392b; }
.sp-form input[type="number"] { width: 140px; padding: 8px; }
.sp-form button { padding: 8px 16px; font-weight: 700; cursor: pointer; }
.sp-table { width: 100%; border-collapse: collapse; }
.sp-table th, .sp-table td { padding: 8px; border: 1px solid rgba(120,150,190,.26); text-align: center; }
.sp-admin { border: 1px dashed rgba(200,120,40,.6); border-radius: 8px; padding: 14px; margin-top: 22px; background: rgba(200,120,40,.06); }
.sp-admin h3 { margin: 0 0 10px; }
.sp-admin-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; align-items: end; }
.sp-admin-form label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: #6f7f95; }
.sp-admin-form input { padding: 7px; }
.sp-inline { display: inline-flex; gap: 6px; align-items: center; flex-wrap: wrap; }
</style>
<div class="sp-wrap">
    <div class="sp-head">
        <div>
            <div class="sp-title">菠菜系统</div>
            <div class="sp-muted">体育赛事竞猜，固定赔率。用电影票押 主胜 / 平局 / 客胜，押中得「押注 × 赔率」。</div>
        </div>
        <div class="sp-balance">我的电影票：<?php echo game_sp_money($CURUSER['seedbonus']) ?> 张</div>
    </div>

    <?php if ($message) { ?><div class="sp-message ok"><?php echo htmlspecialchars($message) ?></div><?php } ?>
    <?php if ($error) { ?><div class="sp-message err"><?php echo htmlspecialchars($error) ?></div><?php } ?>

    <div class="sp-section-title">进行中的赛事</div>
    <?php if ($leagueList) { ?>
        <div class="sp-tabs" id="spTabs">
            <span class="sp-tab is-active" data-league="__all">全部</span>
            <?php foreach ($leagueList as $lg) { ?>
                <span class="sp-tab" data-league="<?php echo htmlspecialchars($lg) ?>"><?php echo htmlspecialchars($lg) ?></span>
            <?php } ?>
        </div>
    <?php } ?>
    <?php if (!$openMatches) { ?>
        <div class="sp-match sp-muted">暂无可押注的赛事，敬请期待。</div>
    <?php } ?>
    <?php foreach ($openMatches as $m) { $lg = $m['league'] !== '' ? $m['league'] : '其他'; ?>
        <div class="sp-match" data-league="<?php echo htmlspecialchars($lg) ?>">
            <div class="sp-match-top">
                <?php if ($m['league'] !== '') { ?><span class="sp-league"><?php echo htmlspecialchars(game_sp_tr($m['league'])) ?></span><?php } ?>
                <span class="sp-teams"><?php echo htmlspecialchars(game_sp_tr($m['home_team'])) ?> <span class="sp-muted">vs</span> <?php echo htmlspecialchars(game_sp_tr($m['away_team'])) ?></span>
            </div>
            <div class="sp-time">开赛：<?php echo htmlspecialchars($m['match_time']) ?>　·　截止：<?php echo htmlspecialchars($m['bet_deadline']) ?></div>
            <form class="sp-form" method="post" action="/games/sports/">
                <input type="hidden" name="action" value="bet">
                <input type="hidden" name="match_id" value="<?php echo (int)$m['id'] ?>">
                <div class="sp-odds">
                    <label><input type="radio" name="choice" value="home" checked> 主胜 <b><?php echo number_format($m['odds_home'], 2) ?></b></label>
                    <label><input type="radio" name="choice" value="draw"> 平局 <b><?php echo number_format($m['odds_draw'], 2) ?></b></label>
                    <label><input type="radio" name="choice" value="away"> 客胜 <b><?php echo number_format($m['odds_away'], 2) ?></b></label>
                </div>
                <input type="number" name="amount" min="1" step="1" placeholder="电影票数量" required>
                <button type="submit">押注</button>
            </form>
        </div>
    <?php } ?>

    <div class="sp-section-title">我的最近押注</div>
    <table class="sp-table">
        <tr><th>赛事</th><th>选择</th><th>赔率</th><th>押注</th><th>状态</th><th>返还</th></tr>
        <?php while ($bet = mysql_fetch_assoc($myBetsRes)) { ?>
            <tr>
                <td><?php echo htmlspecialchars(game_sp_tr($bet['home_team']) . ' vs ' . game_sp_tr($bet['away_team'])) ?></td>
                <td><?php echo game_sp_choice_label($bet['choice']) ?></td>
                <td><?php echo number_format($bet['odds'], 2) ?></td>
                <td><?php echo game_sp_money($bet['amount']) ?></td>
                <td><?php echo $betStatusLabel[$bet['status']] ?? htmlspecialchars($bet['status']) ?></td>
                <td><?php echo game_sp_money($bet['payout']) ?></td>
            </tr>
        <?php } ?>
    </table>

    <div class="sp-section-title">最近开奖</div>
    <table class="sp-table">
        <tr><th>赛事</th><th>比分</th><th>结果</th><th>时间</th></tr>
        <?php while ($m = mysql_fetch_assoc($resultRes)) { ?>
            <tr>
                <td><?php echo htmlspecialchars(($m['league'] !== '' ? '[' . game_sp_tr($m['league']) . '] ' : '') . game_sp_tr($m['home_team']) . ' vs ' . game_sp_tr($m['away_team'])) ?></td>
                <td><?php echo ($m['home_score'] === null || $m['away_score'] === null) ? '-' : ((int)$m['home_score'] . ' : ' . (int)$m['away_score']) ?></td>
                <td><?php echo $m['status'] === 'cancelled' ? '已取消(退款)' : game_sp_choice_label($m['result']) ?></td>
                <td><?php echo htmlspecialchars($m['updated_at']) ?></td>
            </tr>
        <?php } ?>
    </table>

    <?php if ($isAdmin) {
        $apiKeySet = trim((string)game_sp_config_get('api_key', '')) !== '';
    ?>
        <div class="sp-admin">
            <h3>🛠 管理员：赛事数据 API（football-data.org，免费档）</h3>
            <div class="sp-muted" style="margin-bottom:8px;">
                到 football-data.org 注册免费账号，拿到 <b>X-Auth-Token</b> 填入此处（免费档约 10 次/分钟）。当前状态：<b><?php echo $apiKeySet ? '已设置' : '未设置' ?></b>
            </div>
            <form method="post" action="/games/sports/" class="sp-inline" style="margin-bottom:14px;">
                <input type="hidden" name="action" value="save_api_key">
                <input type="password" name="api_key" placeholder="粘贴 X-Auth-Token" style="width:280px;padding:7px" autocomplete="off">
                <button type="submit">保存 token</button>
            </form>

            <h3>🛠 管理员：从 API 导入赛程</h3>
            <div class="sp-muted" style="margin-bottom:8px;">填联赛代码 + 日期范围。免费档常用代码：世界杯 <b>WC</b>、欧冠 <b>CL</b>、英超 <b>PL</b>、西甲 <b>PD</b>、意甲 <b>SA</b>、德甲 <b>BL1</b>、法甲 <b>FL1</b>、欧洲杯 <b>EC</b>、巴甲 <b>BSA</b>（<b>中超不在免费覆盖</b>，请用下方“手动创建比赛”）。导入的赛事为“草稿”，需设赔率后才开盘。</div>
            <form method="post" action="/games/sports/">
                <input type="hidden" name="action" value="import_fixtures">
                <div class="sp-admin-form">
                    <label>联赛代码<input type="text" name="competition" maxlength="10" placeholder="如 WC / PL" required></label>
                    <label>起始日期<input type="date" name="from" value="<?php echo date('Y-m-d') ?>" required></label>
                    <label>结束日期<input type="date" name="to" value="<?php echo date('Y-m-d', TIMENOW + 14 * 86400) ?>" required></label>
                    <label>&nbsp;<button type="submit">导入赛程</button></label>
                </div>
            </form>

            <?php if ($draftMatches) { ?>
                <h3 style="margin-top:18px;">🛠 草稿赛事（设赔率后开盘）</h3>
                <table class="sp-table">
                    <tr><th>赛事</th><th>开赛</th><th>设赔率(主/平/客) + 截止 → 开盘</th><th>操作</th></tr>
                    <?php foreach ($draftMatches as $m) { ?>
                        <tr>
                            <td><?php echo htmlspecialchars(game_sp_tr($m['home_team']) . ' vs ' . game_sp_tr($m['away_team'])) ?><br><span class="sp-muted"><?php echo htmlspecialchars(game_sp_tr($m['league'])) ?></span></td>
                            <td><?php echo htmlspecialchars($m['match_time']) ?></td>
                            <td>
                                <form method="post" action="/games/sports/" class="sp-inline">
                                    <input type="hidden" name="action" value="publish_match">
                                    <input type="hidden" name="match_id" value="<?php echo (int)$m['id'] ?>">
                                    <input type="number" name="odds_home" min="1.01" max="1000" step="0.01" placeholder="主" style="width:60px" required>
                                    <input type="number" name="odds_draw" min="1.01" max="1000" step="0.01" placeholder="平" style="width:60px" required>
                                    <input type="number" name="odds_away" min="1.01" max="1000" step="0.01" placeholder="客" style="width:60px" required>
                                    <input type="text" name="bet_deadline" value="<?php echo htmlspecialchars($m['bet_deadline']) ?>" style="width:140px" title="押注截止">
                                    <button type="submit">开盘</button>
                                </form>
                            </td>
                            <td>
                                <form method="post" action="/games/sports/" onsubmit="return confirm('删除该草稿赛事？');">
                                    <input type="hidden" name="action" value="delete_draft">
                                    <input type="hidden" name="match_id" value="<?php echo (int)$m['id'] ?>">
                                    <button type="submit">删除</button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                </table>
            <?php } ?>

            <h3 style="margin-top:18px;">🛠 手动创建比赛</h3>
            <form method="post" action="/games/sports/">
                <input type="hidden" name="action" value="create_match">
                <div class="sp-admin-form">
                    <label>联赛<input type="text" name="league" maxlength="80" placeholder="如 世界杯"></label>
                    <label>主队<input type="text" name="home_team" maxlength="80" required></label>
                    <label>客队<input type="text" name="away_team" maxlength="80" required></label>
                    <label>比赛时间<?php echo datetimepicker_input('match_time', '', '', ['require_files' => true, 'style' => 'padding:7px']) ?></label>
                    <label>押注截止<?php echo datetimepicker_input('bet_deadline', '', '', ['style' => 'padding:7px']) ?></label>
                    <label>主胜赔率<input type="number" name="odds_home" min="1.01" max="1000" step="0.01" required></label>
                    <label>平局赔率<input type="number" name="odds_draw" min="1.01" max="1000" step="0.01" required></label>
                    <label>客胜赔率<input type="number" name="odds_away" min="1.01" max="1000" step="0.01" required></label>
                    <label>&nbsp;<button type="submit">创建比赛</button></label>
                </div>
            </form>

            <h3 style="margin-top:18px;">🛠 已开盘比赛：录结果 / 取消</h3>
            <div class="sp-muted" style="margin-bottom:8px;">API 导入的比赛结束后可自动获取比分结算；手动创建的、或暂无结果的请用“结算”手动录入。</div>
            <form method="post" action="/games/sports/" style="margin-bottom:8px;">
                <input type="hidden" name="action" value="auto_settle_all">
                <button type="submit">⚡ 一键自动结算（已结束的，每次最多 10 场）</button>
            </form>
            <table class="sp-table">
                <tr><th>赛事</th><th>截止</th><th>押注(主/平/客)</th><th>录结果</th><th>操作</th></tr>
                <?php foreach ($adminMatches as $m) { ?>
                    <tr>
                        <td><?php echo htmlspecialchars(game_sp_tr($m['home_team']) . ' vs ' . game_sp_tr($m['away_team'])) ?><br><span class="sp-muted"><?php echo htmlspecialchars(game_sp_tr($m['league'])) ?></span></td>
                        <td><?php echo htmlspecialchars($m['bet_deadline']) ?></td>
                        <td><?php echo game_sp_money($m['_totals']['home']) ?> / <?php echo game_sp_money($m['_totals']['draw']) ?> / <?php echo game_sp_money($m['_totals']['away']) ?></td>
                        <td>
                            <form method="post" action="/games/sports/" class="sp-inline">
                                <input type="hidden" name="action" value="settle_match">
                                <input type="hidden" name="match_id" value="<?php echo (int)$m['id'] ?>">
                                <select name="result">
                                    <option value="home">主胜</option>
                                    <option value="draw">平局</option>
                                    <option value="away">客胜</option>
                                </select>
                                <input type="number" name="home_score" min="0" max="99" style="width:48px" placeholder="主">
                                <input type="number" name="away_score" min="0" max="99" style="width:48px" placeholder="客">
                                <button type="submit" onclick="return confirm('确认结算并派彩？此操作不可撤销。');">结算</button>
                            </form>
                        </td>
                        <td>
                            <?php if ($m['external_id'] !== '') { ?>
                            <form method="post" action="/games/sports/" style="margin-bottom:4px;">
                                <input type="hidden" name="action" value="auto_settle">
                                <input type="hidden" name="match_id" value="<?php echo (int)$m['id'] ?>">
                                <button type="submit">自动结算</button>
                            </form>
                            <?php } ?>
                            <form method="post" action="/games/sports/" onsubmit="return confirm('确认取消该比赛并退还所有押注？');">
                                <input type="hidden" name="action" value="cancel_match">
                                <input type="hidden" name="match_id" value="<?php echo (int)$m['id'] ?>">
                                <button type="submit">取消/退款</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                <?php if (!$adminMatches) { ?><tr><td colspan="5" class="sp-muted">暂无已开盘比赛。</td></tr><?php } ?>
            </table>
        </div>
    <?php } ?>
</div>
<script>
(function () {
    var tabs = document.getElementById('spTabs');
    if (!tabs) { return; }
    var matches = document.querySelectorAll('.sp-match[data-league]');
    tabs.addEventListener('click', function (e) {
        var tab = e.target.closest('.sp-tab');
        if (!tab) { return; }
        tabs.querySelectorAll('.sp-tab').forEach(function (t) { t.classList.remove('is-active'); });
        tab.classList.add('is-active');
        var lg = tab.getAttribute('data-league');
        matches.forEach(function (row) {
            row.style.display = (lg === '__all' || row.getAttribute('data-league') === lg) ? '' : 'none';
        });
    });
})();
</script>
<?php
stdfoot();
