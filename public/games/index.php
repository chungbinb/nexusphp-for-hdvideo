<?php
require "../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;
require_once "../../include/game_control.php";
require_once "../../include/game_leaderboard.php";

$games = [
    [
        'title' => '股票模拟交易',
        'released_at' => '2026-07-10',
        'released_order' => 1783700000,
        'badge' => '内测中 v0.2',
        'subtitle' => '对接沪深 A 股真实行情，使用站内电影票买卖虚拟股票，支持实时持仓、市值和盈亏统计。',
        'date' => '已开放',
        'price' => '进入交易',
        'href' => '/games/stock/',
        'status' => '可玩',
        'tags' => ['真实行情', 'A股', '电影票', '模拟交易'],
        'theme' => 'stock',
        'shots' => ['stock-a', 'stock-b', 'stock-c'],
    ],
    [
        'title' => '炸金花',
        'released_at' => '2026-07-10',
        'released_order' => 1783659000,
        'badge' => '内测中 v0.2',
        'subtitle' => '支持 3–10 人桌，真人优先且缺人可补机器人，可指定玩家比牌或向全桌发起全比。',
        'date' => '已开放',
        'price' => '立即入桌',
        'href' => '/games/zjh/',
        'status' => '可玩',
        'tags' => ['炸金花', '真人与机器人', '电影票', '心理博弈'],
        'theme' => 'zjh',
        'shots' => ['poker-a', 'poker-b', 'poker-c'],
    ],
    [
        'title' => '压大小',
        'released_at' => '2026-06-23',
        'released_order' => 1782191170,
        'badge' => '公测中 v1.2',
        'subtitle' => '每 10 分钟开奖一次，使用电影票押注，押中返还本金并获得等额奖励。',
        'date' => '已开放',
        'price' => '立即进入',
        'href' => '/games/big-small/',
        'status' => '可玩',
        'tags' => ['电影票', '开奖', '竞猜', '轻量休闲'],
        'theme' => 'dice',
        'shots' => ['dice-a', 'dice-b', 'dice-c'],
    ],
    [
        'title' => '菠菜系统',
        'released_at' => '2026-06-26',
        'released_order' => 1782454501,
        'badge' => '内测中 v0.6',
        'subtitle' => '体育赛事竞猜，固定赔率押注主胜/平局/客胜，押中按赔率派彩，比如当前的世界杯。',
        'date' => '已开放',
        'price' => '立即进入',
        'href' => '/games/sports/',
        'status' => '可玩',
        'tags' => ['体育', '世界杯', '竞猜', '赔率'],
        'theme' => 'sports',
        'shots' => ['dice-a', 'dice-b', 'dice-c'],
    ],
    [
        'title' => '斗地主',
        'released_at' => '2026-06-26',
        'released_order' => 1782471319,
        'badge' => '内测中 v0.1',
        'subtitle' => '三人对战，匹配开局（无人则补机器人），可邀请好友进桌，用电影票计分。',
        'date' => '开发中',
        'price' => '进入大厅',
        'href' => '/games/ddz/',
        'status' => '开发中',
        'tags' => ['真人对战', '斗地主', '电影票', '组队'],
        'theme' => 'ddz',
        'shots' => ['dice-a', 'dice-b', 'dice-c'],
    ],
    [
        'title' => '德州扑克',
        'released_at' => '2026-07-10',
        'released_order' => 1783648102,
        'badge' => '内测中 v0.1',
        'subtitle' => '四人固定限注德州，完整翻牌/转牌/河牌流程，只匹配真人玩家同桌竞技。',
        'date' => '已开放',
        'price' => '立即入座',
        'href' => '/games/poker/',
        'status' => '可玩',
        'tags' => ['德州扑克', '真人对战', '电影票', '牌型竞技'],
        'theme' => 'poker',
        'shots' => ['poker-a', 'poker-b', 'poker-c'],
    ],
    [
        'title' => '刮刮乐',
        'released_at' => '2026-06-26',
        'released_order' => 1782481883,
        'badge' => '内测中 v0.7',
        'subtitle' => '花电影票刮一张即时开奖，刮中倍数 × 面额返还，适合快速试手气。',
        'date' => '已开放',
        'price' => '立即进入',
        'href' => '/games/scratch/',
        'status' => '可玩',
        'tags' => ['电影票', '概率', '休闲'],
        'theme' => 'scratch',
        'shots' => ['scratch-a', 'scratch-b', 'scratch-c'],
    ],
    [
        'title' => '幸运转盘',
        'released_at' => '2026-06-23',
        'released_order' => 1782191171,
        'subtitle' => '每日限次抽奖，奖励覆盖电影票、临时道具和活动权益。',
        'date' => '已开放',
        'price' => '立即进入',
        'href' => '/games/enter.php?game=lucky-draw',
        'status' => '可玩',
        'tags' => ['每日', '抽奖', '活动'],
        'theme' => 'wheel',
        'shots' => ['wheel-a', 'wheel-b', 'wheel-c'],
    ],
    [
        'title' => '答题挑战',
        'released_at' => '2026-06-26',
        'released_order' => 1782482312,
        'badge' => '内测中 v0.1',
        'subtitle' => '免费答题，答对得电影票，连对越多奖励越高；管理员可在题库里加题。',
        'date' => '已开放',
        'price' => '立即进入',
        'href' => '/games/quiz/',
        'status' => '可玩',
        'tags' => ['知识', '电影', '挑战'],
        'theme' => 'quiz',
        'shots' => ['quiz-a', 'quiz-b', 'quiz-c'],
    ],
    [
        'title' => '签到宝箱',
        'released_at' => '2026-06-26',
        'released_order' => 1782482559,
        'badge' => '内测中 v0.1',
        'subtitle' => '连续签到 7/15/30 天解锁宝箱，开出随机电影票，断签后重新累计可再领。',
        'date' => '已开放',
        'price' => '立即进入',
        'href' => '/games/chest/',
        'status' => '可玩',
        'tags' => ['签到', '宝箱', '连续奖励'],
        'theme' => 'chest',
        'shots' => ['chest-a', 'chest-b', 'chest-c'],
    ],
    [
        'title' => '二十一点',
        'released_at' => '2026-06-27',
        'released_order' => 1782523548,
        'badge' => '公测 1.0',
        'subtitle' => '经典 21 点，用电影票下注，要牌/停牌/加倍，点数接近 21 且不爆即胜，黑杰克 1.5 倍赔。',
        'date' => '已开放',
        'price' => '立即进入',
        'href' => '/games/blackjack/',
        'status' => '可玩',
        'tags' => ['扑克', '21点', '电影票', '对庄'],
        'theme' => 'blackjack',
        'shots' => ['dice-a', 'dice-b', 'dice-c'],
    ],
    [
        'title' => '老虎机',
        'released_at' => '2026-06-27',
        'released_order' => 1782538842,
        'badge' => '内测中 v0.3',
        'subtitle' => '投入电影票拉一把，三轴转动，三个相同按倍数派彩，两个🍒回本，7️⃣大奖。',
        'date' => '已开放',
        'price' => '立即进入',
        'href' => '/games/slots/',
        'status' => '可玩',
        'tags' => ['老虎机', '电影票', '概率', '大奖'],
        'theme' => 'slots',
        'shots' => ['dice-a', 'dice-b', 'dice-c'],
    ],
    [
        'title' => 'Plinko 弹珠',
        'released_at' => '2026-06-27',
        'released_order' => 1782538841,
        'badge' => '内测中 v0.3',
        'subtitle' => '放下小球穿过钉板，落到不同倍率格子，越靠边倍数越高，动画刺激。',
        'date' => '已开放',
        'price' => '立即进入',
        'href' => '/games/plinko/',
        'status' => '可玩',
        'tags' => ['弹珠', '电影票', '概率', '休闲'],
        'theme' => 'plinko',
        'shots' => ['dice-a', 'dice-b', 'dice-c'],
    ],
    [
        'title' => '猜高低',
        'released_at' => '2026-06-27',
        'released_order' => 1782538840,
        'badge' => '内测中 v0.3',
        'subtitle' => '猜下一张牌比当前大还是小，猜中可叠倍续猜，随时收手落袋，规则极简。',
        'date' => '已开放',
        'price' => '立即进入',
        'href' => '/games/hilo/',
        'status' => '可玩',
        'tags' => ['扑克', '猜牌', '电影票', '叠倍'],
        'theme' => 'hilo',
        'shots' => ['dice-a', 'dice-b', 'dice-c'],
    ],
    [
        'title' => '猜电影',
        'released_at' => '2026-06-27',
        'released_order' => 1782543471,
        'badge' => '内测中 v0.3',
        'subtitle' => '看电影截图或经典台词猜片名，答对得电影票，连对越多奖励越高，管理员可加题。',
        'date' => '已开放',
        'price' => '立即进入',
        'href' => '/games/moviequiz/',
        'status' => '可玩',
        'tags' => ['电影', '截图', '台词', '竞猜'],
        'theme' => 'moviequiz',
        'shots' => ['quiz-a', 'quiz-b', 'quiz-c'],
    ],
];

$gameKeyByTheme = [
    'dice' => 'big-small', 'sports' => 'sports', 'ddz' => 'ddz', 'poker' => 'poker', 'zjh' => 'zjh', 'stock' => 'stock',
    'scratch' => 'scratch', 'wheel' => 'lucky-draw', 'quiz' => 'quiz', 'chest' => 'chest',
    'blackjack' => 'blackjack', 'slots' => 'slots', 'plinko' => 'plinko', 'hilo' => 'hilo',
    'moviequiz' => 'moviequiz',
];
foreach ($games as &$game) {
    $game['key'] = $gameKeyByTheme[$game['theme']] ?? $game['theme'];
}
unset($game);
$gamePlayingCounts = game_presence_counts(array_column($games, 'key'));

$gameSortOptions = [
    'newest' => '新到旧',
    'oldest' => '旧到新',
    'name' => '名称排序',
];
$gameSort = (string)($_GET['sort'] ?? 'newest');
if (!isset($gameSortOptions[$gameSort])) {
    $gameSort = 'newest';
}
usort($games, function (array $left, array $right) use ($gameSort): int {
    if ($gameSort === 'name') {
        return strcmp((string)$left['title'], (string)$right['title']);
    }
    $comparison = (int)$left['released_order'] <=> (int)$right['released_order'];
    return $gameSort === 'oldest' ? $comparison : -$comparison;
});
$comingGames = array_values(array_filter($games, fn(array $game): bool => ($game['status'] ?? '') !== '可玩'));

// 手机端访问：走独立的手机版页面（自带头/尾，不经过桌面版 stdhead），互不影响电脑端。
// 加 ?pc=1 可在手机上强制看电脑版。
if (empty($_GET['pc'])
    && preg_match('/Mobile|Android|iPhone|iPod|Windows Phone|BlackBerry|webOS|HarmonyOS/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
    require __DIR__ . '/mobile.php';
    exit;
}

stdhead("游戏大厅");
?>
<style>
body.page-games:not(.inframe),
body.page-games-php:not(.inframe) {
    background: #0d1824 !important;
}

.steam-games {
    max-width: 1200px;
    margin: 0 auto;
    padding: 14px 18px 34px;
    color: #dce8f6;
}

.steam-games a {
    color: inherit !important;
}

.steam-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 28px;
    margin: 2px 0 26px;
    color: #9eb4ca;
    font-size: 18px;
}

.steam-tab {
    position: relative;
    padding-bottom: 8px;
    color: inherit !important;
    text-decoration: none !important;
}

.steam-tab.is-active {
    color: #fff !important;
}

.steam-tab.is-active::after {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 3px;
    background: var(--bili-primary, #35b8f1);
}

.steam-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 340px;
    gap: 20px;
    align-items: start;
}

.steam-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.steam-list-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 4px;
    padding: 12px 14px;
    border: 1px solid rgba(91, 129, 166, .22);
    background: #162636;
}

.steam-list-heading {
    margin: 0 !important;
    color: #fff !important;
    font-size: 19px;
}

.steam-list-heading small {
    margin-left: 8px;
    color: #8ea6bd;
    font-size: 12px;
    font-weight: 600;
}

.steam-sort-form { display: flex; align-items: center; gap: 8px; }
.steam-sort-form label { color: #aebfd0; font-weight: 700; white-space: nowrap; }
.steam-sort-form select {
    min-height: 38px;
    padding: 0 34px 0 11px;
    border: 1px solid rgba(98, 169, 220, .4);
    border-radius: 4px;
    background: #0e1d2b;
    color: #fff;
    cursor: pointer;
}
.steam-sort-form select:focus-visible { outline: 3px solid rgba(53, 184, 241, .42); outline-offset: 2px; }
.steam-sort-submit {
    min-height: 38px;
    padding: 0 13px !important;
    border: 0 !important;
    border-radius: 4px !important;
    background: var(--bili-primary, #278ac1) !important;
    color: #fff !important;
    font-weight: 700;
    cursor: pointer;
}

.steam-game-row {
    display: grid;
    grid-template-columns: 379px minmax(0, 1fr) 148px;
    min-height: 99px;
    background: #1b2b3a;
    border: 1px solid rgba(91, 129, 166, 0.18);
    text-decoration: none !important;
    transition: background 0.16s ease, border-color 0.16s ease, transform 0.16s ease;
}

.steam-game-row:hover,
.steam-game-row.is-active {
    background: #304a62;
    border-color: rgba(98, 169, 220, 0.38);
    transform: translateY(-1px);
}

.steam-capsule,
.steam-shot {
    position: relative;
    overflow: hidden;
    min-height: 99px;
    background:
        radial-gradient(circle at 22% 24%, rgba(255,255,255,.26), transparent 20%),
        linear-gradient(135deg, var(--game-a), var(--game-b));
}

.steam-capsule::before,
.steam-shot::before {
    content: "";
    position: absolute;
    inset: 0;
    background:
        linear-gradient(120deg, transparent 0 34%, rgba(255,255,255,.18) 35% 36%, transparent 37% 100%),
        repeating-linear-gradient(0deg, rgba(255,255,255,.08) 0 1px, transparent 1px 9px);
    mix-blend-mode: screen;
    opacity: .6;
}

.steam-capsule::after {
    content: attr(data-title);
    position: absolute;
    left: 18px;
    right: 18px;
    bottom: 14px;
    color: #fff;
    font-size: 28px;
    font-weight: 900;
    line-height: 1;
    letter-spacing: 0;
    text-shadow: 0 3px 12px rgba(0,0,0,.55);
}

.theme-dice { --game-a: #1e88e5; --game-b: #07182d; }
.theme-sports { --game-a: #2ecc71; --game-b: #0b3d1f; }
.theme-ddz { --game-a: #e74c3c; --game-b: #2c1a0c; }
.theme-poker { --game-a: #16865c; --game-b: #071b2c; }
.theme-zjh { --game-a: #d39a35; --game-b: #2b110d; }
.theme-stock { --game-a: #df3045; --game-b: #07192a; }

.steam-capsule.has-icon {
    background-color: #0b1728;
    background-repeat: no-repeat;
    background-position: center;
    background-size: contain;
}
.steam-capsule.has-icon::before,
.steam-capsule.has-icon::after {
    content: none;
    display: none;
}
.theme-scratch { --game-a: #f1c232; --game-b: #6b3f00; }
.theme-wheel { --game-a: #b84cff; --game-b: #18224f; }
.theme-quiz { --game-a: #13b58a; --game-b: #092c38; }
.theme-chest { --game-a: #ff7f50; --game-b: #371323; }
.theme-blackjack { --game-a: #1f9a52; --game-b: #07210f; }
.theme-slots { --game-a: #d4a017; --game-b: #3a2a10; }
.theme-plinko { --game-a: #2980b9; --game-b: #0a1a2a; }
.theme-hilo { --game-a: #8e44ad; --game-b: #1a0b26; }
.theme-moviequiz { --game-a: #9b59b6; --game-b: #161226; }

.steam-game-main {
    padding: 12px 12px 10px;
    min-width: 0;
}

.steam-game-title {
    color: #fff;
    font-size: 17px;
    font-weight: 700;
    line-height: 1.25;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.steam-badge {
    font-size: 11px;
    font-weight: 700;
    color: #ffcf6b;
    background: rgba(0, 0, 0, 0.28);
    padding: 1px 6px;
    border-radius: 4px;
    vertical-align: middle;
    white-space: nowrap;
}

.steam-game-subtitle {
    margin-top: 6px;
    color: #c8d8e8;
    line-height: 1.45;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.steam-game-date {
    margin-top: 10px;
    color: #8ea1b3;
}

.steam-game-price {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    padding: 0 12px;
}

.steam-playing-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-width: 90px;
    color: #b9cee0;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}
.steam-playing-count::before { content:""; width:8px; height:8px; border-radius:50%; background:#22c55e; box-shadow:0 0 8px rgba(34,197,94,.65); }
.steam-playing-count.is-empty::before { background:#72869a; box-shadow:none; }
.steam-playing-count b { color:#fff; font-size:14px; }

.steam-price-pill {
    min-width: 78px;
    padding: 8px 10px;
    background: var(--bili-primary, #0f1d2b);
    color: #fff;
    text-align: center;
    font-weight: 700;
}

.steam-game-row.is-disabled {
    cursor: default;
}

.steam-game-row.is-disabled .steam-price-pill {
    color: #b5c5d4;
}

.steam-preview {
    padding: 10px;
    background: #203549;
    border: 1px solid rgba(91, 129, 166, 0.22);
}

.steam-preview-title {
    margin: 8px 2px 8px;
    color: #fff;
    font-size: 18px;
    font-weight: 800;
}

.steam-preview-meta {
    margin: 0 2px 10px;
    color: #aebfd0;
    line-height: 1.55;
}

.steam-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 0 2px 12px;
}

.steam-tag {
    padding: 5px 8px;
    background: #2d465d;
    color: #dce8f6;
    font-size: 12px;
}

.steam-shot {
    height: 176px;
    margin-top: 10px;
    border: 8px solid #08131d;
}

.steam-shot::after {
    content: attr(data-label);
    position: absolute;
    left: 16px;
    bottom: 14px;
    color: #fff;
    font-size: 18px;
    font-weight: 800;
    text-shadow: 0 3px 12px rgba(0,0,0,.72);
}

.shot-dice-a { --game-a: #20a7f7; --game-b: #0b1728; }
.shot-dice-b { --game-a: #6ee7ff; --game-b: #1d2963; }
.shot-dice-c { --game-a: #9fb8ff; --game-b: #10212d; }
.shot-scratch-a { --game-a: #ffdc52; --game-b: #6b2d00; }
.shot-scratch-b { --game-a: #ffe998; --game-b: #5b3d14; }
.shot-scratch-c { --game-a: #f3b43f; --game-b: #17212e; }
.shot-wheel-a { --game-a: #cf6cff; --game-b: #25164d; }
.shot-wheel-b { --game-a: #ff6f91; --game-b: #213562; }
.shot-wheel-c { --game-a: #59d1ff; --game-b: #2b1d4f; }
.shot-quiz-a { --game-a: #28d5a1; --game-b: #0d3344; }
.shot-quiz-b { --game-a: #89f7b9; --game-b: #19404d; }
.shot-quiz-c { --game-a: #23a6d5; --game-b: #1d273b; }
.shot-chest-a { --game-a: #ff9d62; --game-b: #441923; }
.shot-chest-b { --game-a: #ffc371; --game-b: #31264d; }
.shot-chest-c { --game-a: #ff6f61; --game-b: #14212f; }
.shot-poker-a { --game-a: #15986a; --game-b: #071a2b; }
.shot-poker-b { --game-a: #d0a84f; --game-b: #143c32; }
.shot-poker-c { --game-a: #a93e48; --game-b: #081a2b; }

.steam-more {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 12px;
    color: #dce8f6;
}

.steam-more button {
    min-height: 28px;
    padding: 4px 14px !important;
    border-radius: 2px !important;
    background: #d8dde3 !important;
    color: #111827 !important;
    font-weight: 700;
}

.steam-board {
    position: sticky;
    top: 12px;
}

/* 右侧榜单为窄栏，三个榜竖向堆叠 */
.steam-board .glb-grid {
    grid-template-columns: 1fr;
    gap: 12px;
}

.steam-board-title {
    margin: 0 0 14px;
    color: #fff;
    font-size: 20px;
    font-weight: 800;
}

.steam-board-sub {
    font-size: 13px;
    font-weight: 600;
    color: #8ea6bd;
}

.steam-board .glb-card {
    background: #1b2b3a;
    border-color: rgba(91, 129, 166, 0.22);
}

.steam-board .glb-card-title {
    background: color-mix(in srgb, var(--bili-primary, #35b8f1) 16%, transparent);
    color: #fff;
}

.steam-hall-section {
    scroll-margin-top: 18px;
    margin-top: 22px;
    padding: 18px;
    border: 1px solid rgba(91, 129, 166, .22);
    background: #162636;
}
.steam-hall-section h2 { margin: 0 0 13px !important; color: #fff !important; font-size: 20px; }
.steam-coming-list { display: grid; gap: 9px; }
.steam-coming-item { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 14px; border: 1px solid rgba(91, 129, 166, .2); background: #1b2b3a; }
.steam-coming-item strong { color: #fff; }
.steam-coming-item span { color: #9eb4ca; }

@media (max-width: 980px) {
    .steam-layout {
        grid-template-columns: 1fr;
    }

    .steam-board {
        position: static;
        margin-top: 8px;
    }

    .steam-board .glb-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 760px) {
    .steam-board .glb-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
    .steam-games {
        padding: 8px 8px 24px;
    }

    .steam-tabs {
        gap: 18px;
        font-size: 16px;
    }

    .steam-list-head { align-items: stretch; flex-direction: column; }
    .steam-sort-form { width: 100%; }
    .steam-sort-form select { flex: 1; min-width: 0; }

    .steam-game-row {
        grid-template-columns: 116px minmax(0, 1fr);
    }

    .steam-capsule {
        min-height: 94px;
    }

    .steam-capsule::after {
        left: 10px;
        right: 10px;
        font-size: 20px;
    }

    .steam-game-price {
        grid-column: 2;
        flex-direction: row;
        justify-content: space-between;
        padding: 0 12px 10px;
    }

    .steam-shot {
        height: 132px;
    }
}
</style>
<div class="steam-games">
    <nav class="steam-tabs" aria-label="游戏分类">
        <a class="steam-tab is-active" href="#game-list">游戏列表</a>
        <a class="steam-tab" href="/games/rules.php">游戏规则</a>
        <a class="steam-tab" href="#coming-soon">即将推出</a>
    </nav>

    <?php
    $hallProfit = game_lb_bonus('profit', null, 10);
    $hallProfitLow = game_lb_bonus('profit', null, 10, 'ASC');
    $hallActive = game_lb_bonus('active', null, 10);
    $hallWin    = game_lb_bonus('wincount', null, 10);
    echo game_lb_css();
    ?>
    <div class="steam-layout">
        <section class="steam-list" id="game-list" aria-label="游戏列表">
            <div class="steam-list-head">
                <h1 class="steam-list-heading">游戏列表 <small><?php echo count($games) ?> 款</small></h1>
                <form class="steam-sort-form" method="get" action="">
                    <?php if (!empty($_GET['pc'])) { ?><input type="hidden" name="pc" value="1"><?php } ?>
                    <label for="gameSort">排序</label>
                    <select id="gameSort" name="sort" onchange="this.form.submit()">
                        <?php foreach ($gameSortOptions as $sortKey => $sortLabel) { ?>
                            <option value="<?php echo htmlspecialchars($sortKey) ?>" <?php echo $gameSort === $sortKey ? 'selected' : '' ?>><?php echo htmlspecialchars($sortLabel) ?></option>
                        <?php } ?>
                    </select>
                    <button class="steam-sort-submit" type="submit">应用</button>
                </form>
            </div>
            <?php foreach ($games as $index => $game) { ?>
                <?php
                $ctrlKey = $game['key'] ?? (preg_match('#^/games/([^/]+)/#', $game['href'], $m) ? $m[1] : null);
                $gClosed = $ctrlKey ? !game_is_open($ctrlKey) : false;
                $gCanAccess = $ctrlKey ? game_user_can_access($ctrlKey) : true;
                $gBlocked = $gClosed && !$gCanAccess;
                $disabled = $game['href'] === '#' || $gBlocked;
                $rowHref = $disabled ? '#' : $game['href'];
                if ($gClosed) {
                    $priceText = $gCanAccess ? '进入预览' : '未开放';
                    $dateText = $gCanAccess ? '未开放（管理员可进）' : '未开放';
                } else {
                    $priceText = $game['price'];
                    $dateText = $game['date'];
                }
                $hasIcon = is_file(__DIR__ . '/icons/' . $game['theme'] . '.png');
                $playingCount = (int)($gamePlayingCounts[$game['key']] ?? 0);
                ?>
                <a class="steam-game-row theme-<?php echo htmlspecialchars($game['theme']) ?> <?php echo $index === 0 ? 'is-active' : '' ?> <?php echo $disabled ? 'is-disabled' : '' ?>"
                   href="<?php echo htmlspecialchars($rowHref) ?>"
                   <?php echo $disabled ? 'onclick="return false;"' : '' ?>>
                    <div class="steam-capsule<?php echo $hasIcon ? ' has-icon' : '' ?>" data-title="<?php echo htmlspecialchars($game['title']) ?>"<?php if ($hasIcon) { echo ' style="background-image:url(\'/games/icons/' . htmlspecialchars($game['theme']) . '.png?v=2\')"'; } ?>></div>
                    <div class="steam-game-main">
                        <div class="steam-game-title"><?php echo htmlspecialchars($game['title']) ?><?php if (!empty($game['badge'])) { ?> <span class="steam-badge"><?php echo htmlspecialchars($game['badge']) ?></span><?php } ?><?php if ($gClosed) { ?> <span class="steam-badge" style="color:#ff9d9d;background:rgba(120,0,0,.32)"><?php echo $gCanAccess ? '未开放·管理员可见' : '未开放' ?></span><?php } ?></div>
                        <div class="steam-game-subtitle"><?php echo htmlspecialchars($game['subtitle']) ?></div>
                        <div class="steam-game-date"><?php echo htmlspecialchars($dateText) ?> · 加入大厅 <?php echo htmlspecialchars($game['released_at']) ?></div>
                    </div>
                    <div class="steam-game-price">
                        <span class="steam-playing-count<?php echo $playingCount === 0 ? ' is-empty' : '' ?>" data-game-playing="<?php echo htmlspecialchars($game['key']) ?>" aria-label="<?php echo $playingCount ?> 人正在游玩"><b data-game-playing-value><?php echo number_format($playingCount) ?></b> 人游玩</span>
                        <span class="steam-price-pill"><?php echo htmlspecialchars($priceText) ?></span>
                    </div>
                </a>
            <?php } ?>
        </section>

        <aside class="steam-board" aria-label="游戏大厅总榜">
            <h2 class="steam-board-title">🏆 总榜 <span class="steam-board-sub">汇总全部游戏</span></h2>
            <div class="glb-grid">
                <?php
                echo game_lb_table('💰 盈亏榜', $hallProfit, '净盈亏',
                    function ($r) { return ((float)$r['amt'] >= 0 ? '+' : '') . game_lb_money($r['amt']); },
                    function ($r) { return (float)$r['amt'] >= 0 ? 'glb-pos' : 'glb-neg'; }, $hallProfitLow);
                echo game_lb_table('🔥 活跃榜', $hallActive, '参与次数',
                    function ($r) { return number_format((int)$r['amt']) . ' 次'; });
                echo game_lb_table('🎉 中奖榜', $hallWin, '中奖次数',
                    function ($r) { return number_format((int)$r['amt']) . ' 次'; },
                    function ($r) { return 'glb-pos'; });
                ?>
            </div>
        </aside>
    </div>

    <section class="steam-hall-section" id="coming-soon" aria-labelledby="comingSoonTitle">
        <h2 id="comingSoonTitle">即将推出</h2>
        <div class="steam-coming-list">
            <?php if (!$comingGames) { ?>
                <div class="steam-coming-item"><span>暂无即将推出的游戏</span></div>
            <?php } else { foreach ($comingGames as $game) { ?>
                <div class="steam-coming-item"><strong><?php echo htmlspecialchars($game['title']) ?></strong><span><?php echo htmlspecialchars($game['badge'] ?? $game['status']) ?> · <?php echo htmlspecialchars($game['subtitle']) ?></span></div>
            <?php } } ?>
        </div>
    </section>
</div>
<?php echo game_presence_hall_script(); ?>
<?php
stdfoot();
