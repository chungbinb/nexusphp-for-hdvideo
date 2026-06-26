<?php
require "../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;

$games = [
    [
        'title' => '压大小',
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
        'title' => '刮刮乐',
        'subtitle' => '使用电影票刮出奖励组合，适合快速消耗和回收小额电影票。',
        'date' => '筹备中',
        'price' => '即将开放',
        'href' => '#',
        'status' => '筹备中',
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
        'subtitle' => '围绕电影、剧集和站点规则出题，连续答对可获得额外奖励。',
        'date' => '计划中',
        'price' => '即将开放',
        'href' => '#',
        'status' => '计划中',
        'tags' => ['知识', '电影', '挑战'],
        'theme' => 'quiz',
        'shots' => ['quiz-a', 'quiz-b', 'quiz-c'],
    ],
    [
        'title' => '签到宝箱',
        'subtitle' => '连续签到积累宝箱进度，周期结束自动结算奖励。',
        'date' => '计划中',
        'price' => '即将开放',
        'href' => '#',
        'status' => '计划中',
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
    grid-template-columns: 230px minmax(0, 1fr) 148px;
    min-height: 86px;
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
    min-height: 86px;
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
                <?php $disabled = $game['href'] === '#'; ?>
                <a class="steam-game-row theme-<?php echo htmlspecialchars($game['theme']) ?> <?php echo $index === 0 ? 'is-active' : '' ?> <?php echo $disabled ? 'is-disabled' : '' ?>"
                   href="<?php echo htmlspecialchars($game['href']) ?>"
                   <?php echo $disabled ? 'onclick="return false;"' : '' ?>>
                    <div class="steam-capsule" data-title="<?php echo htmlspecialchars($game['title']) ?>"></div>
                    <div class="steam-game-main">
                        <div class="steam-game-title"><?php echo htmlspecialchars($game['title']) ?></div>
                        <div class="steam-game-subtitle"><?php echo htmlspecialchars($game['subtitle']) ?></div>
                        <div class="steam-game-date"><?php echo htmlspecialchars($game['date']) ?></div>
                    </div>
                    <div class="steam-game-price"><span class="steam-price-pill"><?php echo htmlspecialchars($game['price']) ?></span></div>
                </a>
            <?php } ?>
        </section>
    </div>

    <div class="steam-more">
        <span>查看更多：</span>
        <button type="button">热门新品</button>
        <span>或</span>
        <button type="button">全部游戏</button>
    </div>
</div>
<?php
stdfoot();
