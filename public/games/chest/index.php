<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;

/**
 * 签到宝箱 — milestone chests tied to the existing 签到 (attendance.days = current
 * consecutive sign-in days). Reaching 7/15/30 consecutive days unlocks a chest of
 * random 电影票, claimable once per streak; breaking the streak resets the claims.
 */
const CH_BUSINESS_TYPE = 13;
const CH_STATE_TABLE = 'hdvideo_chest_state';
// milestone days => [min reward, max reward]
const CH_MILESTONES = [
    7  => [100, 300],
    15 => [300, 800],
    30 => [800, 2000],
];

function ch_ensure_table()
{
    static $done = false;
    if ($done) return;
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . CH_STATE_TABLE . "` (
            `uid` int unsigned NOT NULL,
            `streak_start` date DEFAULT NULL,
            `claimed_mask` int unsigned NOT NULL DEFAULT 0,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

/** [days, streakStart|null] from the attendance system. */
function ch_attendance($uid)
{
    $res = @sql_query("SELECT `days`, `added` FROM `attendance` WHERE `uid` = " . (int)$uid . " ORDER BY `added` DESC LIMIT 1");
    $row = $res ? mysql_fetch_assoc($res) : null;
    if (!$row) return [0, null];
    $days = (int)$row['days'];
    if ($days <= 0) return [0, null];
    $ts = strtotime((string)$row['added']);
    $start = $ts ? date('Y-m-d', $ts - ($days - 1) * 86400) : null;
    return [$days, $start];
}

/** Load chest state; reset claims if the streak (start date) changed. */
function ch_state($uid, $streakStart)
{
    ch_ensure_table();
    $now = date('Y-m-d H:i:s');
    $res = sql_query("SELECT * FROM `" . CH_STATE_TABLE . "` WHERE `uid` = " . (int)$uid . " LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $s = mysql_fetch_assoc($res);
    if (!$s) {
        sql_query("INSERT INTO `" . CH_STATE_TABLE . "` (`uid`,`streak_start`,`claimed_mask`,`updated_at`) VALUES (" . (int)$uid . ", " . ($streakStart ? sqlesc($streakStart) : 'NULL') . ", 0, " . sqlesc($now) . ")") or sqlerr(__FILE__, __LINE__);
        return ['streak_start' => $streakStart, 'claimed_mask' => 0];
    }
    if (($s['streak_start'] ?? null) !== $streakStart) {
        sql_query("UPDATE `" . CH_STATE_TABLE . "` SET `streak_start` = " . ($streakStart ? sqlesc($streakStart) : 'NULL') . ", `claimed_mask` = 0, `updated_at` = " . sqlesc($now) . " WHERE `uid` = " . (int)$uid) or sqlerr(__FILE__, __LINE__);
        return ['streak_start' => $streakStart, 'claimed_mask' => 0];
    }
    return ['streak_start' => $s['streak_start'], 'claimed_mask' => (int)$s['claimed_mask']];
}

function ch_claim($uid, $idx)
{
    $idx = (int)$idx;
    $days_keys = array_keys(CH_MILESTONES);
    if (!isset($days_keys[$idx])) return [null, '无效宝箱。'];
    $milestone = $days_keys[$idx];
    $range = CH_MILESTONES[$milestone];
    [$days, $streakStart] = ch_attendance($uid);
    if ($days < $milestone) return [null, "连续签到不足 {$milestone} 天。"];
    ch_ensure_table();
    $now = date('Y-m-d H:i:s');
    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $res = sql_query("SELECT * FROM `" . CH_STATE_TABLE . "` WHERE `uid` = " . (int)$uid . " FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $s = mysql_fetch_assoc($res);
        $mask = $s ? (int)$s['claimed_mask'] : 0;
        $curStart = $s['streak_start'] ?? null;
        if ($curStart !== $streakStart) {
            $mask = 0; // streak changed
        }
        if ($mask & (1 << $idx)) {
            sql_query("ROLLBACK");
            return [null, '这个宝箱本轮已领取。'];
        }
        $reward = mt_rand($range[0], $range[1]);
        $ures = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = " . (int)$uid . " FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $u = mysql_fetch_assoc($ures);
        $oldB = (float)$u['seedbonus'];
        $newB = $oldB + $reward;
        sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(number_format($reward, 1, '.', '')) . " WHERE `id` = " . (int)$uid) or sqlerr(__FILE__, __LINE__);
        sql_query(sprintf(
            "INSERT INTO bonus_logs (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
            CH_BUSINESS_TYPE, (int)$uid, sqlesc(number_format($oldB, 1, '.', '')), sqlesc(number_format($reward, 1, '.', '')), sqlesc(number_format($newB, 1, '.', '')),
            sqlesc("[签到宝箱] 连续签到{$milestone}天宝箱，奖励{$reward}"), sqlesc($now), sqlesc($now)
        )) or sqlerr(__FILE__, __LINE__);
        $newMask = $mask | (1 << $idx);
        sql_query("UPDATE `" . CH_STATE_TABLE . "` SET `streak_start` = " . ($streakStart ? sqlesc($streakStart) : 'NULL') . ", `claimed_mask` = $newMask, `updated_at` = " . sqlesc($now) . " WHERE `uid` = " . (int)$uid) or sqlerr(__FILE__, __LINE__);
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        clear_user_cache($uid);
        $GLOBALS['CURUSER']['seedbonus'] = $newB;
        return [['reward' => $reward, 'balance' => $newB], ""];
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        throw $e;
    }
}

// ---- AJAX claim ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'open') {
    header('Content-Type: application/json');
    [$r, $err] = ch_claim((int)$CURUSER['id'], $_POST['m'] ?? -1);
    if ($err !== '') {
        echo json_encode(['ok' => false, 'error' => $err]);
        exit;
    }
    echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
    exit;
}

[$days, $streakStart] = ch_attendance((int)$CURUSER['id']);
$state = ch_state((int)$CURUSER['id'], $streakStart);
$mask = (int)$state['claimed_mask'];

stdhead("签到宝箱");
?>
<style>
.cb-wrap { max-width: 760px; margin: 0 auto; }
.cb-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.cb-title { font-size: 24px; font-weight: 800; }
.cb-badge { font-size: 12px; font-weight: 700; color: #e67e22; background: rgba(230,126,34,.12); padding: 2px 8px; border-radius: 999px; vertical-align: middle; }
.cb-balance { font-size: 14px; font-weight: 700; }
.cb-muted { color: #6f7f95; }
.cb-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 16px; margin-bottom: 14px; background: rgba(30,60,100,.06); }
.cb-streak { font-size: 17px; font-weight: 800; margin-bottom: 6px; }
.cb-chests { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-top: 8px; }
.cb-chest { border: 1px solid rgba(120,150,190,.34); border-radius: 12px; padding: 16px 10px; text-align: center; background: rgba(0,0,0,.03); }
.cb-chest.open { border-color: #2ecc71; background: rgba(46,204,113,.1); }
.cb-chest.done { opacity: .6; }
.cb-chest .ico { font-size: 40px; }
.cb-chest .ms { font-weight: 800; margin: 6px 0 2px; }
.cb-chest .rg { font-size: 12px; color: #6f7f95; }
.cb-btn { margin-top: 8px; padding: 8px 14px; font-weight: 800; cursor: pointer; border-radius: 6px; border: 1px solid #e67e22; background: #e67e22; color: #fff; }
.cb-btn:disabled { opacity: .5; cursor: not-allowed; background: #b9c2cc; border-color: #b9c2cc; }
.cb-msg { margin-top: 12px; font-weight: 800; }
</style>
<div class="cb-wrap">
    <div class="cb-head">
        <div>
            <div class="cb-title">签到宝箱 <span class="cb-badge">内测中 v0.1</span></div>
            <div class="cb-muted">连续签到解锁宝箱，开出随机电影票。断签后重新累计可再领。<a href="/attendance.php">去签到 »</a></div>
        </div>
        <div class="cb-balance">我的电影票：<b id="cbBal"><?php echo number_format((float)$CURUSER['seedbonus'], 1) ?></b> 张</div>
    </div>

    <div class="cb-panel">
        <div class="cb-streak">🔥 当前连续签到：<?php echo (int)$days ?> 天</div>
        <?php if ($days <= 0) { ?>
            <div class="cb-muted">你还没有连续签到记录，先去 <a href="/attendance.php">签到</a> 吧。</div>
        <?php } ?>
        <div class="cb-chests">
            <?php $i = 0; foreach (CH_MILESTONES as $ms => $range) {
                $claimed = (bool)($mask & (1 << $i));
                $unlocked = $days >= $ms;
                $cls = $claimed ? 'done' : ($unlocked ? 'open' : '');
            ?>
                <div class="cb-chest <?php echo $cls ?>">
                    <div class="ico"><?php echo $claimed ? '✅' : ($unlocked ? '🎁' : '🔒') ?></div>
                    <div class="ms">连续 <?php echo $ms ?> 天</div>
                    <div class="rg"><?php echo $range[0] ?>~<?php echo $range[1] ?> 电影票</div>
                    <?php if ($claimed) { ?>
                        <div class="cb-muted" style="margin-top:8px">本轮已领</div>
                    <?php } elseif ($unlocked) { ?>
                        <button type="button" class="cb-btn" data-m="<?php echo $i ?>">开宝箱</button>
                    <?php } else { ?>
                        <button type="button" class="cb-btn" disabled>还差 <?php echo $ms - $days ?> 天</button>
                    <?php } ?>
                </div>
            <?php $i++; } ?>
        </div>
        <div class="cb-msg" id="cbMsg"></div>
    </div>
</div>
<script>
(function () {
    var busy = false;
    document.querySelectorAll('.cb-btn[data-m]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (busy) { return; }
            busy = true; b.disabled = true;
            fetch('/games/chest/', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=open&m=' + b.getAttribute('data-m') })
                .then(function (r) { return r.json(); }).then(function (d) {
                    var msg = document.getElementById('cbMsg');
                    if (!d.ok) { msg.textContent = d.error || '出错了'; b.disabled = false; busy = false; return; }
                    msg.innerHTML = '<span style="color:#16a34a">🎉 开出 ' + d.reward + ' 电影票！</span>';
                    document.getElementById('cbBal').textContent = (Math.round(d.balance * 10) / 10).toFixed(1);
                    var chest = b.closest('.cb-chest');
                    chest.classList.remove('open'); chest.classList.add('done');
                    chest.querySelector('.ico').textContent = '✅';
                    b.outerHTML = '<div class="cb-muted" style="margin-top:8px">本轮已领</div>';
                    busy = false;
                }).catch(function () { document.getElementById('cbMsg').textContent = '网络错误'; b.disabled = false; busy = false; });
        });
    });
})();
</script>
<?php
stdfoot();
