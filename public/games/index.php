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
        'title' => '压大小',
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
        'badge' => '内测中 v0.2',
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
        'badge' => '开发中 v0.1',
        'subtitle' => '三人真人对战，满 3 人开局（不加机器人），可邀请好友进桌，用电影票计分。',
        'date' => '开发中',
        'price' => '进入大厅',
        'href' => '/games/ddz/',
        'status' => '开发中',
        'tags' => ['真人对战', '斗地主', '电影票', '组队'],
        'theme' => 'ddz',
        'shots' => ['dice-a', 'dice-b', 'dice-c'],
    ],
    [
        'title' => '刮刮乐',
        'badge' => '内测中 v0.1',
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
        'subtitle' => '每日限次抽奖，奖励覆盖电影票、临时道具和活动权益。',
        'date' => '已开放',
        'price' => '立即进入',
        'href' => '/plugin/lucky-draw',
        'status' => '可玩',
        'tags' => ['每日', '抽奖', '活动'],
        'theme' => 'wheel',
        'shots' => ['wheel-a', 'wheel-b', 'wheel-c'],
    ],
    [
        'title' => '答题挑战',
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
];

$featured = $games[0];

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
}

.steam-tab.is-active {
    color: #fff;
}

.steam-tab.is-active::after {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 3px;
    background: #35b8f1;
}

.steam-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 20px;
    align-items: start;
}

.steam-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
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
    align-items: center;
    justify-content: flex-end;
    padding: 0 12px;
}

.steam-price-pill {
    min-width: 78px;
    padding: 8px 10px;
    background: #0f1d2b;
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
    margin-top: 26px;
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
    background: rgba(53, 184, 241, 0.14);
    color: #fff;
}

@media (max-width: 980px) {
    .steam-layout {
        grid-template-columns: 1fr;
    }

    .steam-preview {
        order: -1;
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
        justify-content: flex-start;
        padding: 0 12px 10px;
    }

    .steam-shot {
        height: 132px;
    }
}
</style>
<div class="steam-games">
    <nav class="steam-tabs" aria-label="游戏分类">
        <span class="steam-tab is-active">热门新品</span>
        <span class="steam-tab">热销游戏</span>
        <span class="steam-tab">热门即将推出</span>
        <span class="steam-tab">优惠</span>
        <span class="steam-tab">人气蹿升的免费游戏</span>
    </nav>

    <div class="steam-layout">
        <section class="steam-list" aria-label="游戏列表">
            <?php foreach ($games as $index => $game) { ?>
                <?php
                $ctrlKey = preg_match('#^/games/([^/]+)/#', $game['href'], $m) ? $m[1] : null;
                $gClosed = $ctrlKey ? !game_is_open($ctrlKey) : false;
                $gCanAccess = $ctrlKey ? game_user_can_access($ctrlKey) : true;
                $gBlocked = $gClosed && !$gCanAccess;
                $disabled = $game['href'] === '#' || $gBlocked;
                $rowHref = $disabled ? '#' : $game['href'];
                $priceText = $gBlocked ? '未开放' : $game['price'];
                $dateText = $gBlocked ? '管理员维护中' : $game['date'];
                $hasIcon = is_file(__DIR__ . '/icons/' . $game['theme'] . '.png');
                ?>
                <a class="steam-game-row theme-<?php echo htmlspecialchars($game['theme']) ?> <?php echo $index === 0 ? 'is-active' : '' ?> <?php echo $disabled ? 'is-disabled' : '' ?>"
                   href="<?php echo htmlspecialchars($rowHref) ?>"
                   <?php echo $disabled ? 'onclick="return false;"' : '' ?>>
                    <div class="steam-capsule<?php echo $hasIcon ? ' has-icon' : '' ?>" data-title="<?php echo htmlspecialchars($game['title']) ?>"<?php if ($hasIcon) { echo ' style="background-image:url(\'/games/icons/' . htmlspecialchars($game['theme']) . '.png?v=1\')"'; } ?>></div>
                    <div class="steam-game-main">
                        <div class="steam-game-title"><?php echo htmlspecialchars($game['title']) ?><?php if (!empty($game['badge'])) { ?> <span class="steam-badge"><?php echo htmlspecialchars($game['badge']) ?></span><?php } ?><?php if ($gClosed) { ?> <span class="steam-badge" style="color:#ff9d9d;background:rgba(120,0,0,.32)"><?php echo $gCanAccess ? '未开放·管理员可见' : '未开放' ?></span><?php } ?></div>
                        <div class="steam-game-subtitle"><?php echo htmlspecialchars($game['subtitle']) ?></div>
                        <div class="steam-game-date"><?php echo htmlspecialchars($dateText) ?></div>
                    </div>
                    <div class="steam-game-price"><span class="steam-price-pill"><?php echo htmlspecialchars($priceText) ?></span></div>
                </a>
            <?php } ?>
        </section>
    </div>

    <?php
    $hallProfit = game_lb_bonus('profit', null, 10);
    $hallActive = game_lb_bonus('active', null, 10);
    $hallWin    = game_lb_bonus('wincount', null, 10);
    echo game_lb_css();
    ?>
    <section class="steam-board" aria-label="游戏大厅总榜">
        <h2 class="steam-board-title">🏆 游戏大厅总榜 <span class="steam-board-sub">汇总全部游戏（电影票）</span></h2>
        <div class="glb-grid">
            <?php
            echo game_lb_table('💰 盈亏榜', $hallProfit, '净盈亏',
                function ($r) { return ((float)$r['amt'] >= 0 ? '+' : '') . game_lb_money($r['amt']); },
                function ($r) { return (float)$r['amt'] >= 0 ? 'glb-pos' : 'glb-neg'; });
            echo game_lb_table('🔥 活跃榜', $hallActive, '参与次数',
                function ($r) { return number_format((int)$r['amt']) . ' 次'; });
            echo game_lb_table('🎉 中奖榜', $hallWin, '中奖次数',
                function ($r) { return number_format((int)$r['amt']) . ' 次'; },
                function ($r) { return 'glb-pos'; });
            ?>
        </div>
    </section>

    <div class="steam-more">
        <span>查看更多：</span>
        <button type="button">热门新品</button>
        <span>或</span>
        <button type="button">全部游戏</button>
    </div>
</div>
<?php
stdfoot();
