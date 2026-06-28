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
        'slots'     => ['name' => '老虎机', 'is_open' => 1, 'min_class' => 15, 'sort' => 8],
        'plinko'    => ['name' => 'Plinko弹珠', 'is_open' => 1, 'min_class' => 15, 'sort' => 9],
        'hilo'      => ['name' => '猜高低', 'is_open' => 1, 'min_class' => 15, 'sort' => 10],
        'moviequiz' => ['name' => '猜电影', 'is_open' => 0, 'min_class' => 15, 'sort' => 11], // 题库就绪后再开放
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

/** 「返回游戏大厅」按钮（各游戏页 stdhead 后输出）。 */
function game_back_link()
{
    return game_mobile_css()
        . '<div class="game-back-bar" style="max-width:820px;margin:10px auto 0;padding:0 6px;">'
        . '<a href="/games/" style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:700;color:var(--bili-text-secondary,#61666d);text-decoration:none;padding:6px 12px;border:1px solid rgba(120,150,190,.35);border-radius:8px;background:rgba(120,150,190,.08);">« 返回游戏大厅</a>'
        . '</div>';
}

/**
 * 手机端适配样式（各游戏页通用）。所有游戏页都用统一的类名后缀（*-wrap / *-head /
 * *-title / *-balance），所以这里用属性选择器一处适配全部游戏。仅输出一次。
 * 配合 stdhead() 里给 /games/ 页加的 viewport meta 生效。
 */
function game_mobile_css()
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return '<style>
    @media (max-width: 768px) {
        /* 包裹容器铺满屏幕、留出安全边距，避免内容贴边或被撑出横向滚动 */
        body.game-page [class$="-wrap"] { max-width:100% !important; width:auto !important; padding-left:12px !important; padding-right:12px !important; box-sizing:border-box; }
        /* 标题行：标题与余额允许换行，不再被挤压 */
        body.game-page [class$="-head"] { flex-wrap:wrap; gap:6px 12px !important; }
        body.game-page [class$="-title"] { font-size:21px !important; }
        body.game-page [class$="-balance"] { font-size:13px; }
        /* 表格更紧凑，列多也尽量塞下 */
        body.game-page table { font-size:12px; }
        body.game-page table th, body.game-page table td { padding:6px 5px !important; }
        /* 输入框做成更大的触摸目标，并防止 iOS 聚焦时缩放（>=16px） */
        body.game-page input[type="number"], body.game-page input[type="text"] { font-size:16px !important; padding:9px 8px !important; }
        /* 大按钮（押注/开始等）便于点按 */
        body.game-page [class$="-btn"] { min-height:44px; padding-top:0; padding-bottom:0; }
        /* 快捷筹码：等分铺排两三列，方便单手点 */
        body.game-page [class$="-chip"] { flex:1 1 auto; text-align:center; min-width:60px; padding-top:9px; padding-bottom:9px; }
    }
    @media (max-width: 430px) {
        body.game-page [class$="-wrap"] { padding-left:8px !important; padding-right:8px !important; }
        body.game-page [class$="-title"] { font-size:19px !important; }
        body.game-page table th, body.game-page table td { padding:5px 3px !important; font-size:11px; }
    }
    </style>';
}

/** Block entry to a closed game (call near the top of a game page). */
function game_guard($key)
{
    // 贷款逾期(16天+)的用户暂停游戏等娱乐功能（魔力银行 P2 风控）。
    if (function_exists('get_user_class')) {
        @require_once __DIR__ . '/bank.php';
        if (function_exists('bank_restricted')) {
            $uid = isset($GLOBALS['CURUSER']['id']) ? (int)$GLOBALS['CURUSER']['id'] : 0;
            if ($uid > 0) {
                $rs = bank_restricted($uid);
                if (!empty($rs['restricted'])) {
                    stdhead('暂停使用');
                    echo '<div style="max-width:640px;margin:48px auto;text-align:center">'
                        . '<div style="font-size:46px">🔒</div>'
                        . '<h2 style="margin:10px 0">娱乐功能已暂停</h2>'
                        . '<p style="color:#6f7f95">你的银行贷款已逾期 ' . (int)$rs['days'] . ' 天，按规则暂停游戏/抽奖等娱乐功能，请先到「高清银行」还清欠款后再来。</p>'
                        . '<p style="margin-top:16px"><a href="/games/">« 返回游戏大厅</a></p>'
                        . '</div>';
                    stdfoot();
                    exit;
                }
            }
        }
    }
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
