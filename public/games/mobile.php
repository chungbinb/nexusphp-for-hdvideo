<?php
/**
 * 游戏大厅 —— 手机版独立页面（仿手机游戏中心 App）。
 * 导航(顶栏/底部Tab/我的/个性化)统一复用 include/mobile_shell.php 公共外壳，
 * 与首页/论坛/种子一致；大厅内容沿用深色游戏中心风格。
 * 由 games/index.php 在检测到手机 UA 时 require 进来，复用其作用域里的
 * $games / $CURUSER 及 game_*() 函数。
 */
if (!isset($games) || !is_array($games)) { return; }
require_once ROOT_PATH . 'include/mobile_shell.php';

$mUid = (int)($CURUSER['id'] ?? 0);
$mName = (string)($CURUSER['username'] ?? '');
$mBonus = number_format(floor((float)($CURUSER['seedbonus'] ?? 0)));

// 总榜数据
$mProfit = game_lb_bonus('profit', null, 10);
$mProfitLow = game_lb_bonus('profit', null, 10, 'ASC');
$mActive = game_lb_bonus('active', null, 10);
$mWin = game_lb_bonus('wincount', null, 10);

function gm_icon_style($theme)
{
    if (is_file(__DIR__ . '/icons/' . $theme . '.png')) {
        return ' style="background-image:url(\'/games/icons/' . htmlspecialchars($theme) . '.png?v=2\')"';
    }
    return '';
}

// 输出统一手机外壳头部（DOCTYPE/head/body/.m-main + 顶栏/底部Tab 由 page_foot 输出）
mobile_shell_page_head('游戏', 'games', 'page-games');
?>
<style>
/* 游戏大厅沿用深色游戏中心风格(外壳顶/底栏仍为浅色个性化主题，类似种子页) */
body.page-games { background: #0c1622 !important; color: #e7eef7 !important; }
.gm { max-width: 640px; margin: 0 auto; padding: 4px 14px 14px; }

.gm-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 6px 2px 16px; }
.gm-top-title { font-size: 21px; font-weight: 800; letter-spacing: .5px; color: #e7eef7; }
.gm-bal { font-size: 13px; color: #9fb6cf; background: rgba(120,150,190,.16); border: 1px solid rgba(120,150,190,.3); padding: 6px 12px; border-radius: 999px; white-space: nowrap; }
.gm-bal b { color: #ffd770; }

.gm-tabs { display: flex; gap: 22px; overflow-x: auto; margin: 2px 0 16px; padding-bottom: 6px; color: #9eb4ca; font-size: 16px; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
.gm-tabs::-webkit-scrollbar { display: none; }
.gm-tab2 { white-space: nowrap; padding-bottom: 7px; position: relative; color: inherit !important; text-decoration: none !important; }
.gm-tab2.on { color: #fff !important; font-weight: 700; }
.gm-tab2.on::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 3px; background: #35b8f1; border-radius: 2px; }

.gm-sec { font-size: 15px; font-weight: 800; color: #d4e3f4; margin: 20px 2px 12px; }

.gm-sort { display: flex; align-items: center; gap: 8px; margin: 0 0 14px; padding: 11px 12px; border: 1px solid rgba(91,129,166,.24); border-radius: 10px; background: #16222f; }
.gm-sort label { color: #b8c9db; font-weight: 700; white-space: nowrap; }
.gm-sort select { min-width: 0; flex: 1; min-height: 42px; padding: 0 34px 0 11px; border: 1px solid rgba(91,129,166,.38); border-radius: 8px; background: #0d1b28; color: #fff; }
.gm-sort button { min-height: 42px; padding: 0 14px; border: 0; border-radius: 8px; background: #1f6fb0; color: #fff; font-weight: 800; }

.gm-list { display: flex; flex-direction: column; gap: 16px; }
.gm-sc { display: block; background: #16222f; border: 1px solid rgba(91,129,166,.2); border-radius: 12px; overflow: hidden; transition: transform .12s ease; }
.gm-sc:active { transform: scale(.99); }
.gm-sc-banner { position: relative; aspect-ratio: 2 / 1; background-color: var(--game-b,#0a1622); background-image: radial-gradient(circle at 20% 22%, rgba(255,255,255,.24), transparent 24%), linear-gradient(135deg, var(--game-a,#2a4a66), var(--game-b,#0a1622)); background-size: cover; background-position: center; }
.gm-sc-ttl { position: absolute; left: 16px; right: 14px; bottom: 12px; font-size: 26px; font-weight: 900; color: #fff; text-shadow: 0 3px 12px rgba(0,0,0,.6); }
.gm-sc-ver { position: absolute; top: 11px; right: 11px; font-size: 11px; font-weight: 700; color: #fff; background: rgba(0,0,0,.42); padding: 3px 9px; border-radius: 999px; }
.gm-sc-foot { display: flex; align-items: center; gap: 10px; padding: 11px 13px; }
.gm-sc-tags { min-width: 0; flex: 1; font-size: 12px; color: #90a8c0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.gm-sc-go { flex: none; background: #1f6fb0; color: #fff; font-size: 13px; font-weight: 800; padding: 9px 16px; border-radius: 8px; }
.gm-sc.off { opacity: .6; }
.gm-sc.off .gm-sc-go { background: #7a2b2b; }
.theme-dice{--game-a:#1e88e5;--game-b:#07182d;} .theme-sports{--game-a:#2ecc71;--game-b:#0b3d1f;}
.theme-ddz{--game-a:#e74c3c;--game-b:#2c1a0c;} .theme-scratch{--game-a:#f1c232;--game-b:#6b3f00;}
.theme-poker{--game-a:#16865c;--game-b:#071b2c;}
.theme-wheel{--game-a:#b84cff;--game-b:#18224f;} .theme-quiz{--game-a:#13b58a;--game-b:#092c38;}
.theme-chest{--game-a:#ff7f50;--game-b:#371323;} .theme-blackjack{--game-a:#1f9a52;--game-b:#07210f;}
.theme-slots{--game-a:#d4a017;--game-b:#3a2a10;} .theme-plinko{--game-a:#2980b9;--game-b:#0a1a2a;}
.theme-hilo{--game-a:#8e44ad;--game-b:#1a0b26;} .theme-moviequiz{--game-a:#9b59b6;--game-b:#161226;}

.gm-board { margin-top: 4px; }
.gm-info { scroll-margin-top: 12px; margin-top: 20px; padding: 14px; border: 1px solid rgba(91,129,166,.24); border-radius: 12px; background: #16222f; }
.gm-info h2 { margin: 0 0 11px !important; color: #fff !important; font-size: 17px; }
.gm-rule-list, .gm-coming-list { display: grid; gap: 9px; }
.gm-rule-item, .gm-coming-item { padding: 11px 12px; border: 1px solid rgba(91,129,166,.2); border-radius: 9px; background: #101d29; }
.gm-rule-item strong, .gm-coming-item strong { display: block; color: #fff; }
.gm-rule-item span, .gm-coming-item span { display: block; margin-top: 4px; color: #9fb6cf; line-height: 1.55; }
</style>

<div class="gm">
    <div class="gm-top">
        <div class="gm-top-title">🎮 游戏中心</div>
        <div class="gm-bal">电影票 <b><?php echo $mBonus ?></b></div>
    </div>

    <div class="gm-tabs">
        <a class="gm-tab2 on" href="#game-list">游戏列表</a>
        <a class="gm-tab2" href="#game-rules">游戏规则</a>
        <a class="gm-tab2" href="#coming-soon">即将推出</a>
    </div>

    <form class="gm-sort" method="get" action="">
        <label for="gmGameSort">排序</label>
        <select id="gmGameSort" name="sort" onchange="this.form.submit()">
            <?php foreach ($gameSortOptions as $sortKey => $sortLabel) { ?>
                <option value="<?php echo htmlspecialchars($sortKey) ?>" <?php echo $gameSort === $sortKey ? 'selected' : '' ?>><?php echo htmlspecialchars($sortLabel) ?></option>
            <?php } ?>
        </select>
        <button type="submit">应用</button>
    </form>

    <div class="gm-list" id="game-list">
        <?php foreach ($games as $game) {
            $ctrlKey = preg_match('#^/games/([^/]+)/#', $game['href'], $m) ? $m[1] : null;
            $gClosed = $ctrlKey ? !game_is_open($ctrlKey) : false;
            $gCanAccess = $ctrlKey ? game_user_can_access($ctrlKey) : true;
            $gBlocked = $gClosed && !$gCanAccess;
            $disabled = $game['href'] === '#' || $gBlocked;
            $href = $disabled ? '#' : $game['href'];
            $go = $gClosed ? ($gCanAccess ? '预览' : '未开放') : '进入';
            $tags = !empty($game['tags']) ? implode(' · ', $game['tags']) : htmlspecialchars($game['subtitle'] ?? '');
            // 海报：/games/posters/<theme>.jpg 或 .png（设计员按规格出图后即自动生效）
            $poster = '';
            foreach (['jpg', 'png', 'webp'] as $ext) {
                if (is_file(__DIR__ . '/posters/' . $game['theme'] . '.' . $ext)) {
                    $poster = '/games/posters/' . $game['theme'] . '.' . $ext . '?v=1';
                    break;
                }
            }
            ?>
            <a class="gm-sc<?php echo $disabled ? ' off' : '' ?>" href="<?php echo htmlspecialchars($href) ?>"<?php echo $disabled ? ' onclick="return false;"' : '' ?>>
                <div class="gm-sc-banner theme-<?php echo htmlspecialchars($game['theme']) ?>"<?php if ($poster) { echo ' style="background-image:url(\'' . htmlspecialchars($poster) . '\')"'; } ?>>
                    <?php if (!empty($game['badge'])) { ?><span class="gm-sc-ver"><?php echo htmlspecialchars($game['badge']) ?></span><?php } ?>
                    <?php if (!$poster) { ?><div class="gm-sc-ttl"><?php echo htmlspecialchars($game['title']) ?></div><?php } ?>
                </div>
                <div class="gm-sc-foot">
                    <div class="gm-sc-tags"><?php echo htmlspecialchars($tags) ?></div>
                    <span class="gm-sc-go"><?php echo htmlspecialchars($go) ?> ›</span>
                </div>
            </a>
        <?php } ?>
    </div>

    <section class="gm-info" id="game-rules" aria-labelledby="gmRulesTitle">
        <h2 id="gmRulesTitle">游戏规则</h2>
        <div class="gm-rule-list">
            <div class="gm-rule-item"><strong>统一结算</strong><span>大厅游戏统一使用电影票参与和结算，详细投入、赔率及奖励以各游戏页面为准。</span></div>
            <div class="gm-rule-item"><strong>公平记录</strong><span>开奖结果由服务端生成并记录，排行榜与个人战绩按照实际结算数据更新。</span></div>
            <div class="gm-rule-item"><strong>内测说明</strong><span>内测或公测游戏仍可能调整规则；未开放游戏仅供有权限的管理员预览。</span></div>
        </div>
    </section>

    <section class="gm-info" id="coming-soon" aria-labelledby="gmComingTitle">
        <h2 id="gmComingTitle">即将推出</h2>
        <div class="gm-coming-list">
            <?php if (!$comingGames) { ?>
                <div class="gm-coming-item"><span>暂无即将推出的游戏</span></div>
            <?php } else { foreach ($comingGames as $game) { ?>
                <div class="gm-coming-item"><strong><?php echo htmlspecialchars($game['title']) ?></strong><span><?php echo htmlspecialchars($game['badge'] ?? $game['status']) ?> · <?php echo htmlspecialchars($game['subtitle']) ?></span></div>
            <?php } } ?>
        </div>
    </section>

    <div class="gm-sec">🏆 游戏大厅总榜</div>
    <div class="gm-board">
        <?php
        echo game_lb_css();
        echo game_lb_table('💰 盈亏榜', $mProfit, '净盈亏',
            function ($r) { return ((float)$r['amt'] >= 0 ? '+' : '') . game_lb_money($r['amt']); },
            function ($r) { return (float)$r['amt'] >= 0 ? 'glb-pos' : 'glb-neg'; }, $mProfitLow);
        echo game_lb_table('🔥 活跃榜', $mActive, '参与次数',
            function ($r) { return number_format((int)$r['amt']) . ' 次'; });
        echo game_lb_table('🎉 中奖榜', $mWin, '中奖次数',
            function ($r) { return number_format((int)$r['amt']) . ' 次'; },
            function ($r) { return 'glb-pos'; });
        ?>
    </div>
</div>
<?php
// 输出统一手机外壳尾部（顶栏/抽屉/底部Tab/我的/管理/个性化 + 脚本）
mobile_shell_page_foot('games');
