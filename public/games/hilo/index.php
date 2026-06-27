<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;
require_once "../../../include/game_control.php";
game_guard('hilo');
require_once "../../../include/game_leaderboard.php";

/**
 * 猜高低 Hi-Lo — guess whether the next card is higher or lower than the current one.
 * Each correct guess multiplies the pot (multiplier = (1-edge)/win-probability); cash
 * out anytime, a wrong guess (or a tie) loses everything. Server-authoritative state.
 */
const HL_BUSINESS_TYPE = 110; // 猜高低（历史记录为 13）
const HL_GAME_TABLE = 'hdvideo_hilo_games';
const HL_RESULT_TABLE = 'hdvideo_hilo_results';
const HL_CHIPS = [100, 500, 1000, 5000, 10000];
const HL_EDGE = 0.05;

function hl_money($v) { return number_format((float)$v, 1, '.', ''); }

function hl_ensure_tables()
{
    static $done = false;
    if ($done) return;
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . HL_GAME_TABLE . "` (
            `uid` int unsigned NOT NULL,
            `bet` bigint NOT NULL DEFAULT 0,
            `cur_rank` tinyint unsigned NOT NULL DEFAULT 1,
            `cur_suit` tinyint unsigned NOT NULL DEFAULT 0,
            `mult` decimal(16,4) NOT NULL DEFAULT '1.0000',
            `streak` int NOT NULL DEFAULT 0,
            `status` varchar(10) NOT NULL DEFAULT 'done',
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . HL_RESULT_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `uid` int unsigned NOT NULL,
            `bet` bigint NOT NULL DEFAULT 0,
            `delta` decimal(20,1) NOT NULL DEFAULT '0.0',
            `streak` int NOT NULL DEFAULT 0,
            `outcome` varchar(12) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_uid` (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function hl_rank_label($r) { return ['', 'A', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K'][$r]; }
function hl_suit_symbol($s) { return ['♠', '♥', '♦', '♣'][$s]; }
function hl_card_json($r, $s) { return ['r' => hl_rank_label($r), 's' => hl_suit_symbol($s), 'red' => ($s === 1 || $s === 2)]; }

function hl_step_mult($curRank, $dir)
{
    $cnt = $dir === 'hi' ? (13 - $curRank) : ($curRank - 1);
    if ($cnt <= 0) return 0.0;
    return round((1 - HL_EDGE) * 13 / $cnt, 2);
}

/** Guess options for the current rank, with this-step multipliers. */
function hl_options($curRank)
{
    return [
        'hi' => ['avail' => $curRank < 13, 'mult' => hl_step_mult($curRank, 'hi')],
        'lo' => ['avail' => $curRank > 1, 'mult' => hl_step_mult($curRank, 'lo')],
    ];
}

function hl_state_json($g, $extra = [])
{
    $bet = (int)$g['bet'];
    $mult = (float)$g['mult'];
    return array_merge([
        'status' => $g['status'],
        'card' => hl_card_json((int)$g['cur_rank'], (int)$g['cur_suit']),
        'rank' => (int)$g['cur_rank'],
        'bet' => $bet,
        'mult' => round($mult, 2),
        'pot' => round($bet * $mult, 1),
        'streak' => (int)$g['streak'],
        'options' => hl_options((int)$g['cur_rank']),
        'balance' => (float)$GLOBALS['CURUSER']['seedbonus'],
    ], $extra);
}

function hl_load($uid)
{
    $res = sql_query("SELECT * FROM `" . HL_GAME_TABLE . "` WHERE `uid` = " . (int)$uid . " FOR UPDATE");
    return $res ? mysql_fetch_assoc($res) : null;
}

function hl_save($uid, $g)
{
    $now = date('Y-m-d H:i:s');
    sql_query(sprintf(
        "INSERT INTO `" . HL_GAME_TABLE . "` (`uid`,`bet`,`cur_rank`,`cur_suit`,`mult`,`streak`,`status`,`updated_at`) VALUES (%d,%d,%d,%d,%s,%d,%s,%s)
         ON DUPLICATE KEY UPDATE `bet`=VALUES(`bet`),`cur_rank`=VALUES(`cur_rank`),`cur_suit`=VALUES(`cur_suit`),`mult`=VALUES(`mult`),`streak`=VALUES(`streak`),`status`=VALUES(`status`),`updated_at`=VALUES(`updated_at`)",
        (int)$uid, (int)$g['bet'], (int)$g['cur_rank'], (int)$g['cur_suit'], sqlesc(number_format((float)$g['mult'], 4, '.', '')), (int)$g['streak'], sqlesc($g['status']), sqlesc($now)
    )) or sqlerr(__FILE__, __LINE__);
}

function hl_bonus_log($uid, $old, $delta, $new, $comment)
{
    $now = date('Y-m-d H:i:s');
    sql_query(sprintf(
        "INSERT INTO bonus_logs (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
        HL_BUSINESS_TYPE, (int)$uid, sqlesc(hl_money($old)), sqlesc(hl_money($delta)), sqlesc(hl_money($new)), sqlesc($comment), sqlesc($now), sqlesc($now)
    )) or sqlerr(__FILE__, __LINE__);
}

function hl_record($uid, $bet, $delta, $streak, $outcome)
{
    $now = date('Y-m-d H:i:s');
    sql_query(sprintf(
        "INSERT INTO `" . HL_RESULT_TABLE . "` (`uid`,`bet`,`delta`,`streak`,`outcome`,`created_at`) VALUES (%d,%d,%s,%d,%s,%s)",
        (int)$uid, (int)$bet, sqlesc(hl_money($delta)), (int)$streak, sqlesc($outcome), sqlesc($now)
    )) or sqlerr(__FILE__, __LINE__);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    hl_ensure_tables();
    $uid = (int)$CURUSER['id'];
    $action = $_POST['action'];
    try {
        if ($action === 'start') {
            $bet = (int)($_POST['bet'] ?? 0);
            if ($bet < 1) { echo json_encode(['ok' => false, 'error' => '请输入有效的下注。']); exit; }
            sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
            $g = hl_load($uid);
            if ($g && $g['status'] === 'playing') { sql_query("ROLLBACK"); echo json_encode(['ok' => false, 'error' => '你有进行中的牌局。']); exit; }
            $res = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = $uid FOR UPDATE") or sqlerr(__FILE__, __LINE__);
            $old = (float)mysql_fetch_assoc($res)['seedbonus'];
            if ($old < $bet) { sql_query("ROLLBACK"); echo json_encode(['ok' => false, 'error' => '电影票不足，当前 ' . hl_money($old) . ' 张。']); exit; }
            $new = $old - $bet;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` - " . sqlesc(hl_money($bet)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            hl_bonus_log($uid, $old, -$bet, $new, "[猜高低] 下注 {$bet}");
            $GLOBALS['CURUSER']['seedbonus'] = $new;
            $g = ['bet' => $bet, 'cur_rank' => mt_rand(1, 13), 'cur_suit' => mt_rand(0, 3), 'mult' => 1.0, 'streak' => 0, 'status' => 'playing'];
            hl_save($uid, $g);
            sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
            clear_user_cache($uid);
            echo json_encode(['ok' => true] + hl_state_json($g), JSON_UNESCAPED_UNICODE); exit;
        }

        if ($action === 'guess') {
            $dir = $_POST['dir'] ?? '';
            if ($dir !== 'hi' && $dir !== 'lo') { echo json_encode(['ok' => false, 'error' => '无效选择。']); exit; }
            sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
            $g = hl_load($uid);
            if (!$g || $g['status'] !== 'playing') { sql_query("ROLLBACK"); echo json_encode(['ok' => false, 'error' => '没有进行中的牌局。']); exit; }
            $cur = (int)$g['cur_rank'];
            $step = hl_step_mult($cur, $dir);
            if ($step <= 0) { sql_query("ROLLBACK"); echo json_encode(['ok' => false, 'error' => '该方向不可选。']); exit; }
            $nr = mt_rand(1, 13); $ns = mt_rand(0, 3);
            $correct = ($dir === 'hi' && $nr > $cur) || ($dir === 'lo' && $nr < $cur);
            if ($correct) {
                $g['cur_rank'] = $nr; $g['cur_suit'] = $ns;
                $g['mult'] = (float)$g['mult'] * $step;
                $g['streak'] = (int)$g['streak'] + 1;
                hl_save($uid, $g);
                sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
                echo json_encode(['ok' => true, 'correct' => true] + hl_state_json($g), JSON_UNESCAPED_UNICODE); exit;
            }
            // wrong or tie -> bust
            $g['cur_rank'] = $nr; $g['cur_suit'] = $ns; $g['status'] = 'done';
            hl_record($uid, (int)$g['bet'], -(int)$g['bet'], (int)$g['streak'], 'bust');
            hl_save($uid, $g);
            sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
            $view = hl_state_json($g, ['correct' => false, 'tie' => ($nr === $cur)]);
            echo json_encode(['ok' => true] + $view, JSON_UNESCAPED_UNICODE); exit;
        }

        if ($action === 'cashout') {
            sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
            $g = hl_load($uid);
            if (!$g || $g['status'] !== 'playing') { sql_query("ROLLBACK"); echo json_encode(['ok' => false, 'error' => '没有进行中的牌局。']); exit; }
            $bet = (int)$g['bet']; $pot = round($bet * (float)$g['mult'], 1);
            $res = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = $uid FOR UPDATE") or sqlerr(__FILE__, __LINE__);
            $old = (float)mysql_fetch_assoc($res)['seedbonus'];
            $new = $old + $pot;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(hl_money($pot)) . " WHERE `id` = $uid") or sqlerr(__FILE__, __LINE__);
            hl_bonus_log($uid, $old, $pot, $new, "[猜高低] 收手 连胜{$g['streak']} 返还 {$pot}");
            hl_record($uid, $bet, $pot - $bet, (int)$g['streak'], 'cashout');
            $GLOBALS['CURUSER']['seedbonus'] = $new;
            $g['status'] = 'done';
            hl_save($uid, $g);
            sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
            clear_user_cache($uid);
            echo json_encode(['ok' => true, 'cashout' => true, 'pot' => $pot] + hl_state_json($g), JSON_UNESCAPED_UNICODE); exit;
        }
        echo json_encode(['ok' => false, 'error' => '未知操作。']); exit;
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        echo json_encode(['ok' => false, 'error' => '系统错误，请重试。']); exit;
    }
}

hl_ensure_tables();
$initRes = sql_query("SELECT * FROM `" . HL_GAME_TABLE . "` WHERE `uid` = " . (int)$CURUSER['id']) or sqlerr(__FILE__, __LINE__);
$initRow = mysql_fetch_assoc($initRes);
$initState = ($initRow && $initRow['status'] === 'playing') ? hl_state_json($initRow) : null;
$myRes = sql_query("SELECT * FROM `" . HL_RESULT_TABLE . "` WHERE `uid` = " . (int)$CURUSER['id'] . " ORDER BY `id` DESC LIMIT 10") or sqlerr(__FILE__, __LINE__);
$sumRes = sql_query("SELECT COUNT(*) AS n, SUM(`delta`) AS net FROM `" . HL_RESULT_TABLE . "` WHERE `uid` = " . (int)$CURUSER['id']) or sqlerr(__FILE__, __LINE__);
$sum = mysql_fetch_assoc($sumRes);

stdhead("猜高低");
echo game_back_link();
?>
<style>
.hl-wrap { max-width: 720px; margin: 0 auto; }
.hl-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.hl-title { font-size: 24px; font-weight: 800; }
.hl-badge { font-size: 12px; font-weight: 700; color: #8e44ad; background: rgba(142,68,173,.14); padding: 2px 8px; border-radius: 999px; vertical-align: middle; }
.hl-balance { font-size: 14px; font-weight: 700; }
.hl-muted { color: #6f7f95; }
.hl-stage { border: 1px solid rgba(120,150,190,.34); border-radius: 12px; padding: 20px; margin-bottom: 14px; background: radial-gradient(circle at 50% 0,#3a2350,#160b22); color: #efe6f7; text-align: center; }
.hl-card { display: inline-flex; flex-direction: column; justify-content: space-between; width: 96px; height: 132px; border-radius: 10px; background: #fff; color: #1b2b3a; padding: 8px 10px; font-weight: 800; font-size: 26px; box-shadow: 0 3px 10px rgba(0,0,0,.35); }
.hl-card.red { color: #d8362f; }
.hl-card .bot { align-self: flex-end; transform: rotate(180deg); }
.hl-info { margin: 12px 0; font-weight: 700; }
.hl-pot { color: #ffd770; }
.hl-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin-top: 6px; }
.hl-btn { padding: 10px 18px; font-weight: 800; cursor: pointer; border-radius: 6px; border: 1px solid #8e44ad; background: #8e44ad; color: #fff; }
.hl-btn.up { border-color: #2e8b57; background: #2e8b57; }
.hl-btn.down { border-color: #c0392b; background: #c0392b; }
.hl-btn.cash { border-color: #d4a017; background: #d4a017; }
.hl-btn:disabled { opacity: .45; cursor: not-allowed; }
.hl-result { min-height: 24px; margin-top: 10px; font-size: 17px; font-weight: 900; }
.hl-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 16px; margin-bottom: 14px; background: rgba(30,60,100,.06); }
.hl-controls { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.hl-chip { padding: 7px 12px; border: 1px solid rgba(120,150,190,.45); border-radius: 6px; cursor: pointer; font-weight: 700; background: rgba(255,255,255,.6); }
.hl-chip.sel { background: #8e44ad; color: #fff; border-color: #8e44ad; }
.hl-bet { width: 110px; padding: 8px; border: 1px solid rgba(120,150,190,.45); border-radius: 6px; }
.hl-tbl { width: 100%; border-collapse: collapse; }
.hl-tbl th, .hl-tbl td { padding: 8px; border: 1px solid rgba(120,150,190,.26); text-align: center; }
.hl-pos { color: #16a34a; font-weight: 700; } .hl-neg { color: #dc2626; font-weight: 700; }
</style>
<div class="hl-wrap">
    <div class="hl-head">
        <div>
            <div class="hl-title">猜高低 <span class="hl-badge">内测中 v0.1</span></div>
            <div class="hl-muted">猜下一张牌比当前大还是小（A 最小、K 最大）。猜中可叠倍续猜，随时收手，猜错或相同则归零。</div>
        </div>
        <div class="hl-balance">我的电影票：<b id="hlBal"><?php echo hl_money($CURUSER['seedbonus']) ?></b> 张</div>
    </div>

    <div class="hl-stage">
        <div class="hl-card" id="hlCard"><span class="top">?</span><span class="bot">?</span></div>
        <div class="hl-info" id="hlInfo">投入电影票开始</div>
        <div class="hl-actions" id="hlGuessRow" style="display:none">
            <button type="button" class="hl-btn up" id="hlHi">高 ↑ <small id="hlHiM"></small></button>
            <button type="button" class="hl-btn down" id="hlLo">低 ↓ <small id="hlLoM"></small></button>
            <button type="button" class="hl-btn cash" id="hlCash">收手</button>
        </div>
        <div class="hl-actions" id="hlBetRow">
            <?php foreach (HL_CHIPS as $i => $c) { ?>
                <span class="hl-chip<?php echo $i === 0 ? ' sel' : '' ?>" data-bet="<?php echo $c ?>"><?php echo number_format($c) ?></span>
            <?php } ?>
            <input type="number" min="1" class="hl-bet" id="hlBet" value="<?php echo HL_CHIPS[0] ?>">
            <button type="button" class="hl-btn" id="hlStart">开始</button>
        </div>
        <div class="hl-result" id="hlResult"></div>
    </div>

    <div class="hl-panel">
        <h3 style="margin:0 0 10px">我的最近战绩（共 <?php echo (int)($sum['n'] ?? 0) ?> 局，净 <span class="<?php echo (float)($sum['net'] ?? 0) >= 0 ? 'hl-pos' : 'hl-neg' ?>"><?php echo ((float)($sum['net'] ?? 0) >= 0 ? '+' : '') . number_format((float)($sum['net'] ?? 0), 0) ?></span>）</h3>
        <table class="hl-tbl">
            <tr><th>时间</th><th>下注</th><th>连胜</th><th>结果</th><th>盈亏</th></tr>
            <tbody>
            <?php while ($r = mysql_fetch_assoc($myRes)) { ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['created_at']) ?></td>
                    <td><?php echo (int)$r['bet'] ?></td>
                    <td><?php echo (int)$r['streak'] ?></td>
                    <td><?php echo $r['outcome'] === 'cashout' ? '收手' : '失败' ?></td>
                    <td class="<?php echo (float)$r['delta'] >= 0 ? 'hl-pos' : 'hl-neg' ?>"><?php echo ((float)$r['delta'] >= 0 ? '+' : '') . hl_money($r['delta']) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>

    <?php
    $hlNet = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, SUM(`s`.`delta`) AS amt FROM `" . HL_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
    $hlCnt = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, COUNT(*) AS amt FROM `" . HL_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC LIMIT 10");
    $hlStreak = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, MAX(`s`.`streak`) AS amt, COUNT(*) AS cnt FROM `" . HL_RESULT_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` GROUP BY `s`.`uid`, `u`.`username` ORDER BY amt DESC, cnt DESC LIMIT 10");
    echo game_lb_css();
    ?>
    <div class="hl-panel">
        <h3 style="margin:0 0 12px">🏆 猜高低榜单</h3>
        <div class="glb-grid">
            <?php
            echo game_lb_table('💰 盈亏榜', $hlNet, '净盈亏', function ($r) { return ((float)$r['amt'] >= 0 ? '+' : '') . game_lb_money($r['amt']); }, function ($r) { return (float)$r['amt'] >= 0 ? 'glb-pos' : 'glb-neg'; });
            echo game_lb_table('🔥 活跃榜', $hlCnt, '局数', function ($r) { return number_format((int)$r['amt']) . ' 局'; });
            echo game_lb_table('🍀 连胜榜', $hlStreak, '最高连胜', function ($r) { return number_format((int)$r['amt']) . ' 连'; });
            ?>
        </div>
    </div>
</div>
<script>
(function () {
    var busy = false, bet = <?php echo HL_CHIPS[0] ?>;
    var betInput = document.getElementById('hlBet');
    var betRow = document.getElementById('hlBetRow'), guessRow = document.getElementById('hlGuessRow');
    var cardEl = document.getElementById('hlCard'), infoEl = document.getElementById('hlInfo'), resultEl = document.getElementById('hlResult');
    var hiBtn = document.getElementById('hlHi'), loBtn = document.getElementById('hlLo'), cashBtn = document.getElementById('hlCash'), startBtn = document.getElementById('hlStart');
    var hiM = document.getElementById('hlHiM'), loM = document.getElementById('hlLoM');
    var initState = <?php echo json_encode($initState, JSON_UNESCAPED_UNICODE) ?: 'null' ?>;
    function fmt(n) { return (Math.round(n * 10) / 10).toFixed(1); }

    function showCard(c) {
        cardEl.className = 'hl-card' + (c.red ? ' red' : '');
        cardEl.innerHTML = '<span class="top">' + c.r + c.s + '</span><span class="bot">' + c.r + c.s + '</span>';
    }

    function renderPlaying(d) {
        showCard(d.card);
        betRow.style.display = 'none';
        guessRow.style.display = 'flex';
        infoEl.innerHTML = '当前累计 <span class="hl-pot">' + Math.round(d.pot) + '</span> 电影票（×' + d.mult.toFixed(2) + '，连胜 ' + d.streak + '）';
        hiBtn.disabled = !d.options.hi.avail; loBtn.disabled = !d.options.lo.avail;
        hiM.textContent = d.options.hi.avail ? '×' + d.options.hi.mult.toFixed(2) : '—';
        loM.textContent = d.options.lo.avail ? '×' + d.options.lo.mult.toFixed(2) : '—';
        if (d.balance != null) document.getElementById('hlBal').textContent = fmt(d.balance);
    }

    function renderDone(d, msg, cls) {
        showCard(d.card);
        guessRow.style.display = 'none';
        betRow.style.display = 'flex';
        startBtn.textContent = '再来一局';
        infoEl.textContent = '投入电影票开始';
        resultEl.className = 'hl-result ' + cls;
        resultEl.textContent = msg;
        if (d.balance != null) document.getElementById('hlBal').textContent = fmt(d.balance);
    }

    function post(action, extra) {
        if (busy) return;
        busy = true; hiBtn.disabled = loBtn.disabled = cashBtn.disabled = startBtn.disabled = true; resultEl.textContent = '';
        fetch('/games/hilo/', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=' + action + (extra || '') })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { resultEl.className = 'hl-result hl-neg'; resultEl.textContent = d.error || '出错了'; return; }
                if (d.status === 'playing') { renderPlaying(d); }
                else if (d.cashout) { renderDone(d, '🎉 收手成功，赢得 ' + Math.round(d.pot) + '（连胜 ' + d.streak + '）', 'hl-pos'); }
                else { renderDone(d, d.tie ? '😱 相同点数，归零' : '💥 猜错了，归零', 'hl-neg'); }
            })
            .catch(function () { resultEl.className = 'hl-result hl-neg'; resultEl.textContent = '网络错误'; })
            .finally(function () { busy = false; cashBtn.disabled = false; startBtn.disabled = false; });
    }

    document.querySelectorAll('.hl-chip').forEach(function (c) {
        c.addEventListener('click', function () {
            document.querySelectorAll('.hl-chip').forEach(function (x) { x.classList.remove('sel'); });
            c.classList.add('sel'); bet = parseInt(c.getAttribute('data-bet'), 10); betInput.value = bet;
        });
    });
    betInput.addEventListener('input', function () { bet = Math.max(1, parseInt(betInput.value, 10) || 1); document.querySelectorAll('.hl-chip').forEach(function (x) { x.classList.remove('sel'); }); });

    startBtn.addEventListener('click', function () { post('start', '&bet=' + bet); });
    hiBtn.addEventListener('click', function () { post('guess', '&dir=hi'); });
    loBtn.addEventListener('click', function () { post('guess', '&dir=lo'); });
    cashBtn.addEventListener('click', function () { post('cashout'); });

    if (initState) { renderPlaying(initState); resultEl.textContent = '继续上一局'; }
})();
</script>
<?php
stdfoot();
