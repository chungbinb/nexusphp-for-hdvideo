<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;
require_once "../../../include/game_control.php";
game_guard('slots');
require_once "../../../include/game_leaderboard.php";

/**
 * 老虎机 Slots — 3 reels, weighted symbols, server-authoritative spin.
 * 三个相同按该符号倍数派彩；恰好两个🍒回本；其余不中。
 */
const SL_BUSINESS_TYPE = 13;
const SL_TABLE = 'hdvideo_slots_records';
const SL_CHIPS = [100, 500, 1000, 5000, 10000];
// [emoji, weight, triple multiplier]; index 0 (🍒) also triggers the two-cherry refund
const SL_SYMBOLS = [
    ['🍒', 30, 8],
    ['🍋', 25, 12],
    ['🔔', 20, 18],
    ['🍉', 15, 30],
    ['⭐', 7, 120],
    ['7️⃣', 3, 500],
];

function sl_money($v) { return number_format((float)$v, 1, '.', ''); }

function sl_ensure_table()
{
    static $done = false;
    if ($done) return;
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . SL_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `uid` int unsigned NOT NULL,
            `bet` bigint NOT NULL DEFAULT 0,
            `delta` decimal(20,1) NOT NULL DEFAULT '0.0',
            `result` varchar(20) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_uid` (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function sl_pick_reel()
{
    $total = 0;
    foreach (SL_SYMBOLS as $s) $total += $s[1];
    $r = mt_rand(1, $total);
    foreach (SL_SYMBOLS as $i => $s) {
        if ($r <= $s[1]) return $i;
        $r -= $s[1];
    }
    return 0;
}

function sl_spin($bet)
{
    global $CURUSER;
    $bet = (int)$bet;
    if ($bet < 1) return [null, '请输入有效的下注。'];
    sl_ensure_table();
    $uid = (int)$CURUSER['id'];
    $now = date('Y-m-d H:i:s');
    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $res = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = $uid FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $row = mysql_fetch_assoc($res);
        if (!$row) { sql_query("ROLLBACK"); return [null, '用户不存在。']; }
        $old = (float)$row['seedbonus'];
        if ($old < $bet) { sql_query("ROLLBACK"); return [null, '电影票不足，当前 ' . sl_money($old) . ' 张。']; }

        $reels = [sl_pick_reel(), sl_pick_reel(), sl_pick_reel()];
        $mult = 0.0; $label = '';
        if ($reels[0] === $reels[1] && $reels[1] === $reels[2]) {
            $mult = SL_SYMBOLS[$reels[0]][2];
            $label = '三连 ' . SL_SYMBOLS[$reels[0]][0] . ' ×' . $mult;
        } else {
            $cherries = 0;
            foreach ($reels as $r) if ($r === 0) $cherries++;
            if ($cherries === 2) { $mult = 1.0; $label = '两🍒 回本'; }
        }
        $payout = round($bet * $mult, 1);
        $net = $payout - $bet;
        $new = $old + $net;
        sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(sl_money($net)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
        $symbols = SL_SYMBOLS[$reels[0]][0] . SL_SYMBOLS[$reels[1]][0] . SL_SYMBOLS[$reels[2]][0];
        sql_query(sprintf(
            "INSERT INTO bonus_logs (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
            SL_BUSINESS_TYPE, $uid, sqlesc(sl_money($old)), sqlesc(sl_money($net)), sqlesc(sl_money($new)),
            sqlesc("[老虎机] {$symbols} " . ($mult > 0 ? "中奖×{$mult}" : "未中奖")), sqlesc($now), sqlesc($now)
        )) or sqlerr(__FILE__, __LINE__);
        sql_query(sprintf(
            "INSERT INTO `" . SL_TABLE . "` (`uid`,`bet`,`delta`,`result`,`created_at`) VALUES (%d,%d,%s,%s,%s)",
            $uid, $bet, sqlesc(sl_money($net)), sqlesc($symbols), sqlesc($now)
        )) or sqlerr(__FILE__, __LINE__);
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        clear_user_cache($uid);
        $CURUSER['seedbonus'] = $new;
        return [['reels' => $reels, 'mult' => $mult, 'label' => $label, 'delta' => $net, 'balance' => $new], ''];
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'spin') {
    header('Content-Type: application/json');
    [$r, $err] = sl_spin((int)($_POST['bet'] ?? 0));
    echo json_encode($err !== '' ? ['ok' => false, 'error' => $err] : (['ok' => true] + $r), JSON_UNESCAPED_UNICODE);
    exit;
}

sl_ensure_table();
$myRes = sql_query("SELECT * FROM `" . SL_TABLE . "` WHERE `uid` = " . (int)$CURUSER['id'] . " ORDER BY `id` DESC LIMIT 10") or sqlerr(__FILE__, __LINE__);
$sumRes = sql_query("SELECT COUNT(*) AS n, SUM(`delta`) AS net FROM `" . SL_TABLE . "` WHERE `uid` = " . (int)$CURUSER['id']) or sqlerr(__FILE__, __LINE__);
$sum = mysql_fetch_assoc($sumRes);

stdhead("老虎机");
echo game_back_link();
?>
<style>
.sl-wrap { max-width: 720px; margin: 0 auto; }
.sl-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.sl-title { font-size: 24px; font-weight: 800; }
.sl-badge { font-size: 12px; font-weight: 700; color: #b8860b; background: rgba(184,134,11,.14); padding: 2px 8px; border-radius: 999px; vertical-align: middle; }
.sl-balance { font-size: 14px; font-weight: 700; }
.sl-muted { color: #6f7f95; }
.sl-machine { border: 1px solid rgba(120,150,190,.34); border-radius: 12px; padding: 18px; margin-bottom: 14px; background: linear-gradient(135deg,#3a2a10,#1c1206); }
.sl-reels { display: flex; gap: 12px; justify-content: center; }
.sl-reel { width: 96px; height: 96px; border-radius: 10px; background: #fff; display: flex; align-items: center; justify-content: center; font-size: 52px; box-shadow: inset 0 3px 8px rgba(0,0,0,.25); }
.sl-result { text-align: center; min-height: 26px; margin: 12px 0; font-size: 18px; font-weight: 900; color: #ffd770; }
.sl-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 16px; margin-bottom: 14px; background: rgba(30,60,100,.06); }
.sl-controls { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.sl-chip { padding: 7px 12px; border: 1px solid rgba(120,150,190,.45); border-radius: 6px; cursor: pointer; font-weight: 700; background: rgba(255,255,255,.6); }
.sl-chip.sel { background: #b8860b; color: #fff; border-color: #b8860b; }
.sl-bet { width: 110px; padding: 8px; border: 1px solid rgba(120,150,190,.45); border-radius: 6px; }
.sl-btn { padding: 10px 22px; font-weight: 800; cursor: pointer; border-radius: 6px; border: 1px solid #b8860b; background: #b8860b; color: #fff; }
.sl-btn:disabled { opacity: .5; cursor: not-allowed; }
.sl-pay { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.sl-pay span { padding: 4px 8px; border-radius: 6px; background: rgba(0,0,0,.05); font-size: 13px; }
.sl-tbl { width: 100%; border-collapse: collapse; }
.sl-tbl th, .sl-tbl td { padding: 8px; border: 1px solid rgba(120,150,190,.26); text-align: center; }
.sl-pos { color: #16a34a; font-weight: 700; } .sl-neg { color: #dc2626; font-weight: 700; }
</style>
<div class="sl-wrap">
    <div class="sl-head">
        <div>
            <div class="sl-title">老虎机 <span class="sl-badge">内测中 v0.1</span></div>
            <div class="sl-muted">投入电影票拉一把，三个相同按倍数派彩，两个🍒回本。</div>
        </div>
        <div class="sl-balance">我的电影票：<b id="slBal"><?php echo sl_money($CURUSER['seedbonus']) ?></b> 张</div>
    </div>

    <div class="sl-machine">
        <div class="sl-reels">
            <div class="sl-reel" id="slR0">🍒</div>
            <div class="sl-reel" id="slR1">🔔</div>
            <div class="sl-reel" id="slR2">7️⃣</div>
        </div>
        <div class="sl-result" id="slResult"></div>
        <div class="sl-controls" style="justify-content:center">
            <span class="sl-muted" style="color:#ffd770">下注</span>
            <?php foreach (SL_CHIPS as $i => $c) { ?>
                <span class="sl-chip<?php echo $i === 0 ? ' sel' : '' ?>" data-bet="<?php echo $c ?>"><?php echo number_format($c) ?></span>
            <?php } ?>
            <input type="number" min="1" class="sl-bet" id="slBet" value="<?php echo SL_CHIPS[0] ?>">
            <button type="button" class="sl-btn" id="slSpin">拉一把 🎰</button>
        </div>
    </div>

    <div class="sl-panel">
        <h3 style="margin:0 0 8px">赔率表（三个相同）</h3>
        <div class="sl-pay">
            <?php foreach (SL_SYMBOLS as $s) { ?><span><?php echo $s[0] ?>×<?php echo $s[0] ?><?php echo $s[0] ?> = <?php echo $s[2] ?>倍</span><?php } ?>
            <span>🍒🍒(两个) = 回本</span>
        </div>
    </div>

    <div class="sl-panel">
        <h3 style="margin:0 0 10px">我的最近战绩（共 <?php echo (int)($sum['n'] ?? 0) ?> 把，净 <span class="<?php echo (float)($sum['net'] ?? 0) >= 0 ? 'sl-pos' : 'sl-neg' ?>"><?php echo ((float)($sum['net'] ?? 0) >= 0 ? '+' : '') . number_format((float)($sum['net'] ?? 0), 0) ?></span>）</h3>
        <table class="sl-tbl">
            <tr><th>时间</th><th>结果</th><th>下注</th><th>盈亏</th></tr>
            <tbody id="slRows">
            <?php while ($r = mysql_fetch_assoc($myRes)) { ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['created_at']) ?></td>
                    <td style="font-size:18px"><?php echo htmlspecialchars($r['result']) ?></td>
                    <td><?php echo (int)$r['bet'] ?></td>
                    <td class="<?php echo (float)$r['delta'] >= 0 ? 'sl-pos' : 'sl-neg' ?>"><?php echo ((float)$r['delta'] >= 0 ? '+' : '') . sl_money($r['delta']) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>

    <?php
    $slNet = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, SUM(`s`.`delta`) AS amt FROM `" . SL_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
    $slCnt = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, COUNT(*) AS amt FROM `" . SL_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
    $slLuck = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, MAX(`s`.`delta`) AS amt, COUNT(*) AS cnt FROM `" . SL_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC, cnt DESC LIMIT 10");
    echo game_lb_css();
    ?>
    <div class="sl-panel">
        <h3 style="margin:0 0 12px">🏆 老虎机榜单</h3>
        <div class="glb-grid">
            <?php
            echo game_lb_table('💰 盈亏榜', $slNet, '净盈亏', function ($r) { return ((float)$r['amt'] >= 0 ? '+' : '') . game_lb_money($r['amt']); }, function ($r) { return (float)$r['amt'] >= 0 ? 'glb-pos' : 'glb-neg'; });
            echo game_lb_table('🔥 活跃榜', $slCnt, '次数', function ($r) { return number_format((int)$r['amt']) . ' 把'; });
            echo game_lb_table('🍀 手气榜', $slLuck, '单把最高赢', function ($r) { return game_lb_money($r['amt']); }, function ($r) { return (float)$r['amt'] > 0 ? 'glb-pos' : ''; });
            ?>
        </div>
    </div>
</div>
<script>
(function () {
    var SYMS = <?php echo json_encode(array_map(function ($s) { return $s[0]; }, SL_SYMBOLS), JSON_UNESCAPED_UNICODE) ?>;
    var busy = false, bet = <?php echo SL_CHIPS[0] ?>;
    var betInput = document.getElementById('slBet'), spinBtn = document.getElementById('slSpin'), resultEl = document.getElementById('slResult');
    var reelEls = [document.getElementById('slR0'), document.getElementById('slR1'), document.getElementById('slR2')];
    function fmt(n) { return (Math.round(n * 10) / 10).toFixed(1); }
    document.querySelectorAll('.sl-chip').forEach(function (c) {
        c.addEventListener('click', function () {
            document.querySelectorAll('.sl-chip').forEach(function (x) { x.classList.remove('sel'); });
            c.classList.add('sel'); bet = parseInt(c.getAttribute('data-bet'), 10); betInput.value = bet;
        });
    });
    betInput.addEventListener('input', function () { bet = Math.max(1, parseInt(betInput.value, 10) || 1); document.querySelectorAll('.sl-chip').forEach(function (x) { x.classList.remove('sel'); }); });

    spinBtn.addEventListener('click', function () {
        if (busy) return;
        busy = true; spinBtn.disabled = true; resultEl.textContent = '';
        var spinners = reelEls.map(function (el) {
            return setInterval(function () { el.textContent = SYMS[Math.floor(Math.random() * SYMS.length)]; }, 70);
        });
        fetch('/games/slots/', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=spin&bet=' + bet })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                setTimeout(function () { clearInterval(spinners[0]); if (d.ok) reelEls[0].textContent = SYMS[d.reels[0]]; }, 500);
                setTimeout(function () { clearInterval(spinners[1]); if (d.ok) reelEls[1].textContent = SYMS[d.reels[1]]; }, 800);
                setTimeout(function () {
                    clearInterval(spinners[2]);
                    if (!d.ok) { resultEl.style.color = '#ff9a9a'; resultEl.textContent = d.error || '出错了'; busy = false; spinBtn.disabled = false; return; }
                    reelEls[2].textContent = SYMS[d.reels[2]];
                    if (d.mult > 0) { resultEl.style.color = '#ffd770'; resultEl.textContent = '🎉 ' + d.label + '（' + (d.delta >= 0 ? '+' : '') + Math.round(d.delta) + '）'; }
                    else { resultEl.style.color = '#ff9a9a'; resultEl.textContent = '未中奖（' + Math.round(d.delta) + '）'; }
                    document.getElementById('slBal').textContent = fmt(d.balance);
                    var tb = document.getElementById('slRows'); var tr = document.createElement('tr');
                    var cls = d.delta >= 0 ? 'sl-pos' : 'sl-neg';
                    tr.innerHTML = '<td>刚刚</td><td style="font-size:18px">' + SYMS[d.reels[0]] + SYMS[d.reels[1]] + SYMS[d.reels[2]] + '</td><td>' + bet + '</td><td class="' + cls + '">' + (d.delta >= 0 ? '+' : '') + Math.round(d.delta) + '</td>';
                    tb.insertBefore(tr, tb.firstChild);
                    busy = false; spinBtn.disabled = false;
                }, 1100);
            })
            .catch(function () { spinners.forEach(clearInterval); resultEl.style.color = '#ff9a9a'; resultEl.textContent = '网络错误'; busy = false; spinBtn.disabled = false; });
    });
})();
</script>
<?php
stdfoot();
