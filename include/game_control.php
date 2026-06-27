<?php
/**
 * Game hall open/closed control. The `game_hall_controls` table is self-managed
 * (created + seeded here on first use) and also editable from the Filament backend
 * resource (App\Models\GameHallControl / 游戏大厅控制). Legacy game pages call
 * game_guard($key) to block entry when a game is closed (except高级管理员 by min_class).
 */

function game_control_defaults()
{
    return [
        'big-small' => ['name' => '压大小', 'is_open' => 1, 'min_class' => 15, 'sort' => 1],
        'sports'    => ['name' => '菠菜系统', 'is_open' => 1, 'min_class' => 15, 'sort' => 2],
        'ddz'       => ['name' => '斗地主', 'is_open' => 1, 'min_class' => 15, 'sort' => 3],
        'scratch'   => ['name' => '刮刮乐', 'is_open' => 1, 'min_class' => 15, 'sort' => 4],
        'quiz'      => ['name' => '答题挑战', 'is_open' => 0, 'min_class' => 15, 'sort' => 5], // 默认关闭
        'chest'     => ['name' => '签到宝箱', 'is_open' => 1, 'min_class' => 15, 'sort' => 6],
        'blackjack' => ['name' => '二十一点', 'is_open' => 1, 'min_class' => 15, 'sort' => 7],
    ];
}

function game_controls_ensure()
{
    static $done = false;
    if ($done) {
        return;
    }
    @sql_query("
        CREATE TABLE IF NOT EXISTS `game_hall_controls` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `game_key` varchar(40) NOT NULL,
            `name` varchar(60) NOT NULL DEFAULT '',
            `is_open` tinyint(1) NOT NULL DEFAULT 1,
            `min_class` int NOT NULL DEFAULT 15,
            `sort` int NOT NULL DEFAULT 0,
            `created_at` datetime DEFAULT NULL,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_key` (`game_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $now = date('Y-m-d H:i:s');
    foreach (game_control_defaults() as $k => $d) {
        @sql_query(sprintf(
            "INSERT IGNORE INTO `game_hall_controls` (`game_key`,`name`,`is_open`,`min_class`,`sort`,`created_at`,`updated_at`) VALUES (%s,%s,%d,%d,%d,%s,%s)",
            sqlesc($k), sqlesc($d['name']), (int)$d['is_open'], (int)$d['min_class'], (int)$d['sort'], sqlesc($now), sqlesc($now)
        ));
    }
    $done = true;
}

function game_controls_all()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    game_controls_ensure();
    $cache = game_control_defaults();
    $res = @sql_query("SELECT `game_key`,`name`,`is_open`,`min_class` FROM `game_hall_controls`");
    if ($res) {
        while ($row = mysql_fetch_assoc($res)) {
            $cache[$row['game_key']] = [
                'name' => $row['name'],
                'is_open' => (int)$row['is_open'],
                'min_class' => (int)$row['min_class'],
            ];
        }
    }
    return $cache;
}

function game_control_get($key)
{
    $all = game_controls_all();
    return $all[$key] ?? ['name' => '', 'is_open' => 1, 'min_class' => 15];
}

function game_is_open($key)
{
    return (int)game_control_get($key)['is_open'] === 1;
}

function game_user_can_access($key)
{
    $c = game_control_get($key);
    if ((int)$c['is_open'] === 1) {
        return true;
    }
    return get_user_class() >= (int)$c['min_class'];
}

/** Block entry to a closed game (call near the top of a game page). */
function game_guard($key)
{
    if (game_user_can_access($key)) {
        return;
    }
    $c = game_control_get($key);
    $name = $c['name'] !== '' ? $c['name'] : '该游戏';
    stdhead($name);
    echo '<div style="max-width:640px;margin:48px auto;text-align:center">'
        . '<div style="font-size:46px">🚧</div>'
        . '<h2 style="margin:10px 0">' . htmlspecialchars($name) . ' 暂未开放</h2>'
        . '<p style="color:#6f7f95">正在完善中，敬请期待。</p>'
        . '<p style="margin-top:16px"><a href="/games/">« 返回游戏大厅</a></p>'
        . '</div>';
    stdfoot();
    exit;
}
