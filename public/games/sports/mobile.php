<?php
/**
 * 菠菜系统 —— 手机版（竖屏竞猜页）。自带完整 HTML 头尾，不经过桌面版 stdhead。
 * 由 sports/index.php 在手机 UA 且为普通玩家视图时 require；下注仍是同一个 POST 表单
 * （提交后重定向回本页），开奖/结算/管理逻辑都在 index.php。榜单收进右上角「排行榜」悬浮弹窗。
 * 管理视图（admin）不在手机版重现，管理员可点「赛事管理（电脑版）」跳到 ?view=admin&pc=1。
 */
if (!defined('GAME_SP_BET_TABLE')) { return; }

$uid = (int)$CURUSER['id'];
$mBal = game_sp_money($CURUSER['seedbonus']);
$mBalInt = (int)floor((float)$CURUSER['seedbonus']);
$RANK_MIN_INVEST = isset($RANK_MIN_INVEST) ? $RANK_MIN_INVEST : 1000;
$nowStr = isset($now) ? $now : date('Y-m-d H:i:s');

// 进行中的赛事（与电脑版 current 视图同一查询）
$openRes = sql_query("SELECT * FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `status` = 'open' AND `bet_deadline` > " . sqlesc($nowStr) . " ORDER BY `match_time` ASC, `id` ASC") or sqlerr(__FILE__, __LINE__);
$openMatches = [];
while ($m = mysql_fetch_assoc($openRes)) {
    $m['_lg'] = game_sp_tr($m['league'] !== '' ? $m['league'] : '其他');
    $openMatches[] = $m;
}

// 历史开奖（最近 20 场已结算/取消）
$histRes = sql_query("SELECT * FROM `" . GAME_SP_MATCH_TABLE . "` WHERE `status` IN ('settled','cancelled') ORDER BY `updated_at` DESC, `id` DESC LIMIT 20") or sqlerr(__FILE__, __LINE__);
$history = [];
while ($h = mysql_fetch_assoc($histRes)) { $history[] = $h; }

// 我的最近押注
$myBetsRes = sql_query("
    SELECT b.*, m.`home_team`, m.`away_team`, m.`league`
    FROM `" . GAME_SP_BET_TABLE . "` b
    INNER JOIN `" . GAME_SP_MATCH_TABLE . "` m ON m.`id` = b.`match_id`
    WHERE b.`uid` = $uid ORDER BY b.`id` DESC LIMIT 20
") or sqlerr(__FILE__, __LINE__);

// 榜单 + 我的胜负（弹窗）
$spWin = game_sp_leaderboard('(win_points - lose_points) DESC', 50, $RANK_MIN_INVEST, '(win_points - lose_points) > 0');
$spLose = game_sp_leaderboard('(win_points - lose_points) ASC', 50, $RANK_MIN_INVEST, '(win_points - lose_points) < 0');
$my = game_sp_my_stats($uid);
$myNet = $my['win_points'] - $my['lose_points'];
$betStatusLabel = ['pending' => '待开奖', 'won' => '中奖', 'lost' => '未中', 'refunded' => '已退回'];
$spIsAdmin = game_sp_is_admin();
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=no" />
<title>菠菜系统</title>
<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; min-height: 100%; }
body { background: #0c1622; color: #e7eef7; font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif; }
a { color: inherit; text-decoration: none; }

.sp { min-height: 100vh; padding-bottom: calc(28px + env(safe-area-inset-bottom)); }
.sp-top { position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; gap: 8px;
    padding: 12px 14px calc(10px); background: rgba(12,22,34,.96); border-bottom: 1px solid rgba(120,150,190,.18); backdrop-filter: blur(6px); }
.sp-back, .sp-lb { display: inline-flex; align-items: center; gap: 4px; font-size: 13px; font-weight: 700; color: #9fb6cf; }
.sp-lb { color: #2ecc71; }
.sp-ttl { font-size: 16px; font-weight: 900; }
.sp-ttl .v { font-size: 10px; font-weight: 800; color: #e67e22; background: rgba(230,126,34,.14); padding: 1px 6px; border-radius: 999px; margin-left: 4px; vertical-align: middle; }

.sp-bal { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 14px; }
.sp-bal .k { font-size: 12px; color: #8aa0b6; }
.sp-bal .v { font-size: 19px; font-weight: 900; color: #ffd86b; }

.sp-msg { margin: 4px 12px; padding: 9px 12px; border-radius: 8px; font-size: 13px; font-weight: 700; text-align: center; }
.sp-msg.ok { background: rgba(34,160,90,.18); color: #4ade80; }
.sp-msg.err { background: rgba(200,55,60,.2); color: #f87171; }

.sp-adminlink { margin: 4px 12px 0; }
.sp-adminlink a { display: block; text-align: center; padding: 8px; font-size: 13px; font-weight: 700; color: #e0a82c;
    border: 1px dashed rgba(224,168,44,.55); border-radius: 8px; background: rgba(224,168,44,.06); }

.sp-sec { font-size: 14px; font-weight: 800; color: #cfe0f2; margin: 16px 14px 8px; }

.sp-card { margin: 0 12px 12px; padding: 12px; border-radius: 12px; background: #12202f; border: 1px solid rgba(120,150,190,.2); }
.sp-empty { color: #8aa0b6; font-size: 13px; text-align: center; padding: 18px 0; }

.sp-mtop { display: flex; align-items: baseline; flex-wrap: wrap; gap: 6px; margin-bottom: 4px; }
.sp-league { font-size: 11px; font-weight: 700; color: #2f9bd6; }
.sp-teams { font-size: 16px; font-weight: 900; flex: 1; }
.sp-teams .vs { color: #8aa0b6; font-weight: 600; font-size: 13px; }
.sp-time { font-size: 11px; color: #8aa0b6; margin-bottom: 8px; }
.sp-watch { display: inline-block; margin-bottom: 8px; padding: 5px 10px; font-size: 12px; font-weight: 700; color: #2ecc71;
    border: 1px solid #2ecc71; border-radius: 7px; }

.sp-opts { display: grid; grid-template-columns: 1fr; gap: 8px; margin-bottom: 8px; }
.sp-opts.three label, .sp-opts.two label { }
.sp-opt { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 8px;
    padding: 11px 13px; border-radius: 10px; border: 2px solid rgba(120,150,190,.28); background: rgba(0,0,0,.18); cursor: pointer; }
.sp-opt input { position: absolute; opacity: 0; pointer-events: none; }
.sp-opt .nm { font-size: 15px; font-weight: 800; }
.sp-opt .nm .tag { font-size: 10px; color: #8aa0b6; margin-left: 3px; }
.sp-opt .rt { display: flex; align-items: baseline; gap: 8px; }
.sp-opt .od { font-size: 18px; font-weight: 900; color: #ff7a6a; }
.sp-opt .pl { font-size: 11px; color: #8aa0b6; }
.sp-opt--sel { border-color: #ffd86b; background: rgba(255,216,107,.12); box-shadow: 0 0 0 1px rgba(255,216,107,.4) inset; }

.sp-pool { font-size: 11px; color: #8aa0b6; margin: 4px 0 8px; line-height: 1.6; }
.sp-pool b { color: #2ecc71; }

/* 投注分布柱状 */
.sp-chart { margin: 8px 0; padding-top: 8px; border-top: 1px dashed rgba(120,150,190,.22); }
.sp-legend { display: flex; flex-wrap: wrap; gap: 12px; font-size: 11px; color: #8aa0b6; margin-bottom: 8px; }
.sp-leg { display: inline-flex; align-items: center; gap: 4px; }
.sp-dot { width: 9px; height: 9px; border-radius: 2px; display: inline-block; }
.sp-m-amt { background: #3a6ea5; } .sp-m-ppl { background: #c0883a; } .sp-m-pct { background: #2e8b57; }
.sp-groups { display: flex; align-items: flex-end; justify-content: space-around; gap: 8px; }
.sp-group { flex: 1; display: flex; flex-direction: column; align-items: center; }
.sp-gbars { display: flex; align-items: flex-end; gap: 5px; height: 96px; }
.sp-gbar { display: flex; flex-direction: column; align-items: center; justify-content: flex-end; }
.sp-gval { font-size: 10px; font-weight: 700; color: #b8c6d8; margin-bottom: 2px; white-space: nowrap; }
.sp-bar2 { width: 15px; border-radius: 3px 3px 0 0; min-height: 3px; }
.sp-col-label { margin-top: 6px; font-size: 12px; font-weight: 700; text-align: center; color: #cfe0f2; }

/* 押注栏 */
.sp-betbar { margin-top: 8px; }
.sp-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.sp-chip { padding: 7px 11px; border-radius: 8px; border: 1px solid rgba(120,150,190,.4); font-size: 13px; font-weight: 800;
    color: #cfe0f2; background: rgba(0,0,0,.18); cursor: pointer; user-select: none; }
.sp-chip.allin { background: #e67e22; color: #fff; border-color: #e67e22; }
.sp-betrow { display: flex; gap: 8px; }
.sp-betrow input { flex: 1; min-width: 0; padding: 11px; border-radius: 9px; border: 1px solid rgba(120,150,190,.4);
    background: #0e1b29; color: #fff; font-size: 16px; text-align: center; }
.sp-betrow button { flex: none; padding: 0 22px; border-radius: 9px; border: none; font-size: 15px; font-weight: 900;
    color: #06210f; background: linear-gradient(180deg,#37d97f,#1fae63); cursor: pointer; }

/* 历史 / 我的 表格 */
.sp-tbl { width: 100%; border-collapse: collapse; font-size: 12px; color: #dce8f6; }
.sp-tbl th, .sp-tbl td { padding: 7px 5px; border-bottom: 1px solid rgba(120,150,190,.16); text-align: center; }
.sp-tbl th { color: #8aa0b6; font-weight: 700; }
.sp-tbl td.l { text-align: left; }
.sp-pos { color: #4ade80; font-weight: 700; } .sp-neg { color: #f87171; font-weight: 700; }

/* 弹窗 */
.sp-modal { position: fixed; inset: 0; z-index: 100; display: none; }
.sp-modal.show { display: block; }
.sp-mask { position: absolute; inset: 0; background: rgba(0,0,0,.62); }
.sp-mcard { position: absolute; left: 50%; top: 50%; transform: translate(-50%,-50%); width: min(620px,94vw); max-height: 88vh;
    overflow-y: auto; background: #12202f; border: 1px solid rgba(120,150,190,.28); border-radius: 16px; padding: 16px 14px; }
.sp-mh { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.sp-mh h3 { margin: 0; font-size: 16px; }
.sp-x { font-size: 22px; color: #9fb6cf; padding: 2px 8px; cursor: pointer; }
.sp-tabs2 { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
.sp-t2 { padding: 6px 12px; border-radius: 999px; border: 1px solid rgba(120,150,190,.4); font-size: 13px; font-weight: 700; color: #cfe0f2; cursor: pointer; }
.sp-t2.on { background: #2ecc71; color: #fff; border-color: #2ecc71; }
.sp-pane { display: none; } .sp-pane.on { display: block; }
.sp-mystat { display: flex; flex-wrap: wrap; gap: 8px 14px; font-size: 13px; font-weight: 700; margin-bottom: 12px; color: #cfe0f2; }
</style>
</head>
<body>
<div class="sp">
    <div class="sp-top">
        <a class="sp-back" href="/games/">‹ 大厅</a>
        <div class="sp-ttl">菠菜系统<span class="v">v0.6</span></div>
        <span class="sp-lb" id="spLbBtn">🏆 排行榜</span>
    </div>

    <div class="sp-bal">
        <span class="k">我的电影票</span>
        <span class="v" id="spBal"><?php echo $mBal ?> 张</span>
    </div>

    <?php if ($message) { ?><div class="sp-msg ok"><?php echo htmlspecialchars($message) ?></div><?php } ?>
    <?php if ($error) { ?><div class="sp-msg err"><?php echo htmlspecialchars($error) ?></div><?php } ?>

    <?php if ($spIsAdmin) { ?>
        <div class="sp-adminlink"><a href="/games/sports/?view=admin&pc=1">🛠 赛事管理（电脑版）</a></div>
    <?php } ?>

    <div class="sp-sec">进行中的赛事</div>
    <?php if (!$openMatches) { ?>
        <div class="sp-card sp-empty">暂无可押注的赛事，敬请期待。</div>
    <?php } ?>
    <?php foreach ($openMatches as $m) {
        $pool = game_sp_pool((int)$m['id']);
        $dyn = game_sp_dynamic_odds($m, $pool);
        $hasDraw = (float)$m['odds_draw'] > 1;
        $watch = ($m['watch_url'] ?? '') !== '' ? $m['watch_url'] : game_sp_default_watch_url();

        $sideMeta = ['home' => ['label' => game_sp_tr($m['home_team'])]];
        if ($hasDraw) { $sideMeta['draw'] = ['label' => '平局']; }
        $sideMeta['away'] = ['label' => game_sp_tr($m['away_team'])];
        $poolTotal = (float)$pool['total'];
    ?>
        <div class="sp-card">
            <div class="sp-mtop">
                <?php if ($m['league'] !== '') { ?><span class="sp-league"><?php echo htmlspecialchars(game_sp_tr($m['league'])) ?></span><?php } ?>
                <span class="sp-teams"><?php echo htmlspecialchars(game_sp_tr($m['home_team'])) ?> <span class="vs">vs</span> <?php echo htmlspecialchars(game_sp_tr($m['away_team'])) ?></span>
            </div>
            <div class="sp-time">开赛 <?php echo htmlspecialchars($m['match_time']) ?> · 截止 <?php echo htmlspecialchars($m['bet_deadline']) ?></div>
            <a class="sp-watch" href="<?php echo htmlspecialchars($watch) ?>" target="_blank" rel="noopener noreferrer">📺 去看赛事</a>

            <form method="post" action="/games/sports/" class="sp-betform">
                <input type="hidden" name="action" value="bet">
                <input type="hidden" name="match_id" value="<?php echo (int)$m['id'] ?>">
                <div class="sp-opts <?php echo $hasDraw ? 'three' : 'two' ?>">
                    <label class="sp-opt" data-opt>
                        <span class="nm"><?php echo htmlspecialchars(game_sp_tr($m['home_team'])) ?><span class="tag">主</span></span>
                        <span class="rt"><span class="pl">已押 <?php echo number_format($pool['home']) ?></span><span class="od"><?php echo number_format($dyn['home'], 2) ?></span></span>
                        <input type="radio" name="choice" value="home" checked>
                    </label>
                    <?php if ($hasDraw) { ?>
                    <label class="sp-opt" data-opt>
                        <span class="nm">平局</span>
                        <span class="rt"><span class="pl">已押 <?php echo number_format($pool['draw']) ?></span><span class="od"><?php echo number_format($dyn['draw'], 2) ?></span></span>
                        <input type="radio" name="choice" value="draw">
                    </label>
                    <?php } ?>
                    <label class="sp-opt" data-opt>
                        <span class="nm"><?php echo htmlspecialchars(game_sp_tr($m['away_team'])) ?><span class="tag">客</span></span>
                        <span class="rt"><span class="pl">已押 <?php echo number_format($pool['away']) ?></span><span class="od"><?php echo number_format($dyn['away'], 2) ?></span></span>
                        <input type="radio" name="choice" value="away">
                    </label>
                </div>

                <div class="sp-pool">📊 下注 <b><?php echo (int)$pool['count'] ?></b> 笔 · 参与 <b><?php echo (int)$pool['players'] ?></b> 人 · 投注总额 <b><?php echo number_format($pool['total']) ?></b> 电影票<br>赔率随下注比率实时浮动（下注时锁定）</div>

                <div class="sp-chart">
                    <?php if ($poolTotal <= 0) { ?>
                        <div class="sp-empty" style="padding:8px 0">暂无下注，赔率为开盘线。</div>
                    <?php } else {
                        $maxAmt = 0.0; $maxPpl = 0;
                        foreach ($sideMeta as $k => $meta) {
                            $maxAmt = max($maxAmt, (float)$pool['sides'][$k]['amount']);
                            $maxPpl = max($maxPpl, (int)$pool['sides'][$k]['players']);
                        }
                        if ($maxAmt <= 0) $maxAmt = 1;
                        if ($maxPpl <= 0) $maxPpl = 1;
                        $H = 78;
                    ?>
                        <div class="sp-legend">
                            <span class="sp-leg"><i class="sp-dot sp-m-amt"></i>金额</span>
                            <span class="sp-leg"><i class="sp-dot sp-m-ppl"></i>人数</span>
                            <span class="sp-leg"><i class="sp-dot sp-m-pct"></i>占比</span>
                        </div>
                        <div class="sp-groups">
                            <?php foreach ($sideMeta as $k => $meta) {
                                $sd = $pool['sides'][$k];
                                $amt = (float)$sd['amount'];
                                $ppl = (int)$sd['players'];
                                $pct = round($amt / $poolTotal * 100, 1);
                                $hAmt = max(3, (int)round($amt / $maxAmt * $H));
                                $hPpl = max(3, (int)round($ppl / $maxPpl * $H));
                                $hPct = max(3, (int)round($pct / 100 * $H));
                            ?>
                                <div class="sp-group">
                                    <div class="sp-gbars">
                                        <div class="sp-gbar"><span class="sp-gval"><?php echo number_format($amt) ?></span><i class="sp-bar2 sp-m-amt" style="height:<?php echo $hAmt ?>px"></i></div>
                                        <div class="sp-gbar"><span class="sp-gval"><?php echo number_format($ppl) ?></span><i class="sp-bar2 sp-m-ppl" style="height:<?php echo $hPpl ?>px"></i></div>
                                        <div class="sp-gbar"><span class="sp-gval"><?php echo $pct ?>%</span><i class="sp-bar2 sp-m-pct" style="height:<?php echo $hPct ?>px"></i></div>
                                    </div>
                                    <div class="sp-col-label"><?php echo htmlspecialchars($meta['label']) ?></div>
                                </div>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </div>

                <div class="sp-betbar">
                    <div class="sp-chips">
                        <span class="sp-chip" data-amt="100">100</span>
                        <span class="sp-chip" data-amt="500">500</span>
                        <span class="sp-chip" data-amt="1000">1000</span>
                        <span class="sp-chip" data-amt="5000">5000</span>
                        <span class="sp-chip" data-amt="10000">10000</span>
                        <span class="sp-chip allin" data-amt="all">梭哈</span>
                    </div>
                    <div class="sp-betrow">
                        <input type="number" name="amount" min="1" step="1" inputmode="numeric" placeholder="电影票数量" required>
                        <button type="submit">押注</button>
                    </div>
                </div>
            </form>
        </div>
    <?php } ?>

    <div class="sp-sec">历史开奖</div>
    <div class="sp-card">
        <table class="sp-tbl">
            <tr><th class="l">赛事</th><th>比分</th><th>结果</th><th>投注额</th></tr>
            <?php foreach ($history as $h) {
                $st = game_sp_bet_stats((int)$h['id']);
                $score = ($h['home_score'] === null || $h['away_score'] === null) ? '-' : ((int)$h['home_score'] . ':' . (int)$h['away_score']);
                $rs = $h['status'] === 'cancelled' ? '取消退款' : game_sp_choice_label($h['result']);
            ?>
                <tr>
                    <td class="l"><?php echo htmlspecialchars(game_sp_tr($h['home_team']) . ' vs ' . game_sp_tr($h['away_team'])) ?></td>
                    <td><?php echo $score ?></td>
                    <td><?php echo htmlspecialchars($rs) ?></td>
                    <td><?php echo number_format($st['total']) ?></td>
                </tr>
            <?php } ?>
            <?php if (!$history) { ?><tr><td colspan="4" class="sp-empty">暂无开奖记录。</td></tr><?php } ?>
        </table>
    </div>
</div>

<div class="sp-modal" id="spLbModal">
    <div class="sp-mask" data-close="1"></div>
    <div class="sp-mcard">
        <div class="sp-mh"><h3>🏆 菠菜系统榜单</h3><span class="sp-x" data-close="1">✕</span></div>
        <div class="sp-mystat">
            <span>总 <?php echo $my['total'] ?></span>
            <span class="sp-pos">盈 <?php echo game_sp_points($my['win_points']) ?></span>
            <span class="sp-neg">亏 <?php echo game_sp_points($my['lose_points']) ?></span>
            <span>胜<?php echo $my['won'] ?>/负<?php echo $my['lost'] ?></span>
            <span>净 <span class="<?php echo $myNet >= 0 ? 'sp-pos' : 'sp-neg' ?>"><?php echo game_sp_points($myNet, true) ?></span></span>
        </div>
        <div class="sp-tabs2">
            <span class="sp-t2 on" data-pane="mine">我的押注</span>
            <span class="sp-t2" data-pane="win">胜榜</span>
            <span class="sp-t2" data-pane="lose">负榜</span>
        </div>
        <div class="sp-pane on" data-pane="mine">
            <table class="sp-tbl">
                <tr><th class="l">赛事</th><th>选择</th><th>赔率</th><th>押注</th><th>状态</th><th>返还</th></tr>
                <?php while ($b = mysql_fetch_assoc($myBetsRes)) { ?>
                    <tr>
                        <td class="l"><?php echo htmlspecialchars(game_sp_tr($b['home_team']) . ' vs ' . game_sp_tr($b['away_team'])) ?></td>
                        <td><?php echo htmlspecialchars(game_sp_side_name($b['choice'], $b)) ?></td>
                        <td><?php echo number_format($b['odds'], 2) ?></td>
                        <td><?php echo game_sp_money($b['amount']) ?></td>
                        <td><?php echo $betStatusLabel[$b['status']] ?? htmlspecialchars($b['status']) ?></td>
                        <td class="<?php echo (float)$b['payout'] > 0 ? 'sp-pos' : '' ?>"><?php echo game_sp_money($b['payout']) ?></td>
                    </tr>
                <?php } ?>
            </table>
        </div>
        <div class="sp-pane" data-pane="win">
            <table class="sp-tbl">
                <tr><th>#</th><th class="l">用户</th><th>胜场</th><th>总盈利</th></tr>
                <?php foreach ($spWin as $i => $r) { ?>
                    <tr><td><?php echo $i + 1 ?></td><td class="l"><?php echo htmlspecialchars($r['username']) ?></td><td><?php echo (int)$r['won_cnt'] ?></td><td class="sp-pos"><?php echo game_sp_points((float)$r['win_points'] - (float)$r['lose_points'], true) ?></td></tr>
                <?php } if (!$spWin) echo '<tr><td colspan="4" class="sp-empty">暂无盈利用户。</td></tr>'; ?>
            </table>
        </div>
        <div class="sp-pane" data-pane="lose">
            <table class="sp-tbl">
                <tr><th>#</th><th class="l">用户</th><th>负场</th><th>总亏损</th></tr>
                <?php foreach ($spLose as $i => $r) { ?>
                    <tr><td><?php echo $i + 1 ?></td><td class="l"><?php echo htmlspecialchars($r['username']) ?></td><td><?php echo (int)$r['lost_cnt'] ?></td><td class="sp-neg"><?php echo game_sp_points((float)$r['win_points'] - (float)$r['lose_points'], true) ?></td></tr>
                <?php } if (!$spLose) echo '<tr><td colspan="4" class="sp-empty">暂无亏损用户。</td></tr>'; ?>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    var balInt = <?php echo $mBalInt ?>;

    // 选项高亮 + 筹码填数
    document.querySelectorAll('.sp-betform').forEach(function (form) {
        function syncSel() {
            var checked = form.querySelector('input[name="choice"]:checked');
            form.querySelectorAll('[data-opt]').forEach(function (o) {
                o.classList.toggle('sp-opt--sel', o.contains(checked));
            });
        }
        form.querySelectorAll('input[name="choice"]').forEach(function (r) { r.addEventListener('change', syncSel); });
        form.querySelectorAll('[data-opt]').forEach(function (o) {
            o.addEventListener('click', function () { var r = o.querySelector('input'); if (r) { r.checked = true; syncSel(); } });
        });
        syncSel();

        var input = form.querySelector('input[name="amount"]');
        form.querySelectorAll('.sp-chip[data-amt]').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var a = chip.getAttribute('data-amt');
                input.value = a === 'all' ? balInt : a;
            });
        });
        form.addEventListener('submit', function (e) {
            if (!input.value || parseInt(input.value, 10) < 1) { e.preventDefault(); alert('请先输入或选择押注金额'); }
        });
    });

    // 弹窗
    var modal = document.getElementById('spLbModal');
    document.getElementById('spLbBtn').addEventListener('click', function () { modal.classList.add('show'); });
    modal.addEventListener('click', function (e) { if (e.target.getAttribute('data-close')) modal.classList.remove('show'); });
    document.querySelectorAll('.sp-t2').forEach(function (t) {
        t.addEventListener('click', function () {
            document.querySelectorAll('.sp-t2').forEach(function (x) { x.classList.remove('on'); });
            t.classList.add('on');
            var p = t.getAttribute('data-pane');
            document.querySelectorAll('.sp-pane').forEach(function (pane) { pane.classList.toggle('on', pane.getAttribute('data-pane') === p); });
        });
    });
})();
</script>
</body>
</html>
