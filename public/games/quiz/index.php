<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;
require_once "../../../include/game_control.php";
game_guard('quiz');

/**
 * 答题挑战 — free quiz from an admin-managed question bank. Correct answers earn
 * 电影票 with a consecutive-correct (streak) bonus; wrong resets the streak; a daily
 * answer cap limits farming. Server-side answer validation (answer never sent until graded).
 */
const QZ_BUSINESS_TYPE = 13;
const QZ_Q_TABLE = 'hdvideo_quiz_questions';
const QZ_STATE_TABLE = 'hdvideo_quiz_state';
const QZ_BASE_REWARD = 20;     // 电影票 per correct (×streak, capped)
const QZ_STREAK_CAP = 10;
const QZ_DAILY_LIMIT = 30;     // max answers per day

function qz_is_admin()
{
    return get_user_class() >= UC_ADMINISTRATOR;
}

function qz_ensure_tables()
{
    static $done = false;
    if ($done) return;
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . QZ_Q_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `question` varchar(500) NOT NULL,
            `opt_a` varchar(255) NOT NULL DEFAULT '',
            `opt_b` varchar(255) NOT NULL DEFAULT '',
            `opt_c` varchar(255) NOT NULL DEFAULT '',
            `opt_d` varchar(255) NOT NULL DEFAULT '',
            `answer` tinyint NOT NULL DEFAULT 0,
            `status` tinyint NOT NULL DEFAULT 1,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . QZ_STATE_TABLE . "` (
            `uid` int unsigned NOT NULL,
            `q_id` int unsigned NOT NULL DEFAULT 0,
            `streak` int unsigned NOT NULL DEFAULT 0,
            `best` int unsigned NOT NULL DEFAULT 0,
            `day` date DEFAULT NULL,
            `day_count` int unsigned NOT NULL DEFAULT 0,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function qz_get_state($uid)
{
    $res = sql_query("SELECT * FROM `" . QZ_STATE_TABLE . "` WHERE `uid` = " . (int)$uid . " LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $s = mysql_fetch_assoc($res);
    if (!$s) {
        $now = date('Y-m-d H:i:s');
        sql_query("INSERT INTO `" . QZ_STATE_TABLE . "` (`uid`,`updated_at`) VALUES (" . (int)$uid . ", " . sqlesc($now) . ")") or sqlerr(__FILE__, __LINE__);
        $s = ['uid' => $uid, 'q_id' => 0, 'streak' => 0, 'best' => 0, 'day' => null, 'day_count' => 0];
    }
    // daily reset of the answer counter
    $today = date('Y-m-d');
    if (($s['day'] ?? null) !== $today) {
        $s['day'] = $today;
        $s['day_count'] = 0;
        sql_query("UPDATE `" . QZ_STATE_TABLE . "` SET `day` = " . sqlesc($today) . ", `day_count` = 0, `updated_at` = " . sqlesc(date('Y-m-d H:i:s')) . " WHERE `uid` = " . (int)$uid) or sqlerr(__FILE__, __LINE__);
    }
    return $s;
}

function qz_question_count()
{
    qz_ensure_tables();
    $res = sql_query("SELECT COUNT(*) AS c FROM `" . QZ_Q_TABLE . "` WHERE `status` = 1") or sqlerr(__FILE__, __LINE__);
    return (int)mysql_fetch_assoc($res)['c'];
}

function qz_next_question($uid)
{
    qz_ensure_tables();
    $s = qz_get_state($uid);
    if ((int)$s['day_count'] >= QZ_DAILY_LIMIT) {
        return ['limit' => true];
    }
    $res = sql_query("SELECT * FROM `" . QZ_Q_TABLE . "` WHERE `status` = 1 ORDER BY RAND() LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $q = mysql_fetch_assoc($res);
    if (!$q) {
        return ['empty' => true];
    }
    sql_query("UPDATE `" . QZ_STATE_TABLE . "` SET `q_id` = " . (int)$q['id'] . ", `updated_at` = " . sqlesc(date('Y-m-d H:i:s')) . " WHERE `uid` = " . (int)$uid) or sqlerr(__FILE__, __LINE__);
    return [
        'id' => (int)$q['id'],
        'question' => $q['question'],
        'options' => [$q['opt_a'], $q['opt_b'], $q['opt_c'], $q['opt_d']],
        'streak' => (int)$s['streak'],
        'day_count' => (int)$s['day_count'],
        'day_limit' => QZ_DAILY_LIMIT,
    ];
}

function qz_answer($uid, $choice)
{
    global $CURUSER;
    qz_ensure_tables();
    $choice = (int)$choice;
    $now = date('Y-m-d H:i:s');
    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $res = sql_query("SELECT * FROM `" . QZ_STATE_TABLE . "` WHERE `uid` = " . (int)$uid . " FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $s = mysql_fetch_assoc($res);
        if (!$s || (int)$s['q_id'] <= 0) {
            sql_query("ROLLBACK");
            return ['error' => '请先获取题目。'];
        }
        $today = date('Y-m-d');
        $dayCount = ($s['day'] === $today) ? (int)$s['day_count'] : 0;
        if ($dayCount >= QZ_DAILY_LIMIT) {
            sql_query("ROLLBACK");
            return ['limit' => true];
        }
        $qres = sql_query("SELECT * FROM `" . QZ_Q_TABLE . "` WHERE `id` = " . (int)$s['q_id'] . " LIMIT 1") or sqlerr(__FILE__, __LINE__);
        $q = mysql_fetch_assoc($qres);
        if (!$q) {
            sql_query("ROLLBACK");
            return ['error' => '题目已失效，请获取下一题。'];
        }
        $correct = ($choice === (int)$q['answer']);
        $streak = (int)$s['streak'];
        $reward = 0;
        if ($correct) {
            $streak++;
            $reward = QZ_BASE_REWARD * min($streak, QZ_STREAK_CAP);
        } else {
            $streak = 0;
        }
        $best = max((int)$s['best'], $streak);
        $dayCount++;
        $newBonus = (float)($CURUSER['seedbonus'] ?? 0);
        if ($reward > 0) {
            $ures = sql_query("SELECT `seedbonus` FROM `users` WHERE `id` = " . (int)$uid . " FOR UPDATE") or sqlerr(__FILE__, __LINE__);
            $u = mysql_fetch_assoc($ures);
            $oldB = (float)$u['seedbonus'];
            $newBonus = $oldB + $reward;
            sql_query("UPDATE `users` SET `seedbonus` = `seedbonus` + " . sqlesc(number_format($reward, 1, '.', '')) . " WHERE `id` = " . (int)$uid) or sqlerr(__FILE__, __LINE__);
            sql_query(sprintf(
                "INSERT INTO bonus_logs (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
                QZ_BUSINESS_TYPE, (int)$uid, sqlesc(number_format($oldB, 1, '.', '')), sqlesc(number_format($reward, 1, '.', '')), sqlesc(number_format($newBonus, 1, '.', '')),
                sqlesc("[答题挑战] 答对第{$streak}连击，奖励{$reward}"), sqlesc($now), sqlesc($now)
            )) or sqlerr(__FILE__, __LINE__);
            clear_user_cache($uid);
        }
        sql_query("UPDATE `" . QZ_STATE_TABLE . "` SET `q_id` = 0, `streak` = $streak, `best` = $best, `day` = " . sqlesc($today) . ", `day_count` = $dayCount, `updated_at` = " . sqlesc($now) . " WHERE `uid` = " . (int)$uid) or sqlerr(__FILE__, __LINE__);
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        if ($reward > 0) {
            $GLOBALS['CURUSER']['seedbonus'] = $newBonus;
        }
        return [
            'correct' => $correct,
            'correctIndex' => (int)$q['answer'],
            'reward' => $reward,
            'streak' => $streak,
            'balance' => $newBonus,
            'day_count' => $dayCount,
            'day_limit' => QZ_DAILY_LIMIT,
        ];
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        throw $e;
    }
}

function qz_add_question($data)
{
    qz_ensure_tables();
    $q = trim((string)($data['question'] ?? ''));
    $opts = [trim((string)($data['opt_a'] ?? '')), trim((string)($data['opt_b'] ?? '')), trim((string)($data['opt_c'] ?? '')), trim((string)($data['opt_d'] ?? ''))];
    $ans = (int)($data['answer'] ?? -1);
    if ($q === '' || mb_strlen($q) > 500) return "题目不能为空且不超过 500 字。";
    foreach ($opts as $o) {
        if ($o === '') return "四个选项都要填写。";
    }
    if ($ans < 0 || $ans > 3) return "请选择正确答案。";
    sql_query(sprintf(
        "INSERT INTO `" . QZ_Q_TABLE . "` (`question`,`opt_a`,`opt_b`,`opt_c`,`opt_d`,`answer`,`status`,`created_at`) VALUES (%s,%s,%s,%s,%s,%d,1,%s)",
        sqlesc(mb_substr($q, 0, 500)), sqlesc(mb_substr($opts[0], 0, 255)), sqlesc(mb_substr($opts[1], 0, 255)), sqlesc(mb_substr($opts[2], 0, 255)), sqlesc(mb_substr($opts[3], 0, 255)), $ans, sqlesc(date('Y-m-d H:i:s'))
    )) or sqlerr(__FILE__, __LINE__);
    return "";
}

function qz_delete_question($id)
{
    qz_ensure_tables();
    sql_query("DELETE FROM `" . QZ_Q_TABLE . "` WHERE `id` = " . (int)$id) or sqlerr(__FILE__, __LINE__);
    return "";
}

// ---- AJAX: get next question ----
if (($_GET['ajax'] ?? '') === 'question') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true] + qz_next_question((int)$CURUSER['id']), JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'answer') {
        header('Content-Type: application/json');
        $r = qz_answer((int)$CURUSER['id'], $_POST['choice'] ?? -1);
        echo json_encode(['ok' => empty($r['error']) && empty($r['limit'])] + $r, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $error = '';
    if ($action === 'add_q' && qz_is_admin()) {
        $error = qz_add_question($_POST);
    } elseif ($action === 'del_q' && qz_is_admin()) {
        $error = qz_delete_question($_POST['id'] ?? 0);
    }
    header('Location: /games/quiz/' . ($error !== '' ? '?error=' . urlencode($error) : ($action !== '' ? '?view=admin&msg=1' : '')));
    exit;
}

$error = trim((string)($_GET['error'] ?? ''));
$view = $_GET['view'] ?? '';
$isAdmin = qz_is_admin();
$qCount = qz_question_count();
$myState = qz_get_state((int)$CURUSER['id']);

stdhead("答题挑战");
echo game_back_link();
?>
<style>
.qz-wrap { max-width: 760px; margin: 0 auto; }
.qz-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.qz-title { font-size: 24px; font-weight: 800; }
.qz-badge { font-size: 12px; font-weight: 700; color: #e67e22; background: rgba(230,126,34,.12); padding: 2px 8px; border-radius: 999px; vertical-align: middle; }
.qz-balance { font-size: 14px; font-weight: 700; }
.qz-muted { color: #6f7f95; }
.qz-message { padding: 10px 12px; border-radius: 6px; margin-bottom: 14px; font-weight: 700; background: rgba(220,60,70,.14); color: #c02432; }
.qz-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 16px; margin-bottom: 14px; background: rgba(30,60,100,.06); }
.qz-nav a { padding: 9px 14px; font-weight: 700; text-decoration: none; color: #6f7f95; border-bottom: 3px solid transparent; }
.qz-nav a.on { color: #2ecc71; border-bottom-color: #2ecc71; }
.qz-stat { display: flex; gap: 16px; flex-wrap: wrap; font-weight: 700; margin-bottom: 12px; }
.qz-q { font-size: 18px; font-weight: 800; margin: 6px 0 14px; }
.qz-opts { display: grid; gap: 10px; }
.qz-opt { text-align: left; padding: 12px 14px; border: 1px solid rgba(120,150,190,.45); border-radius: 8px; cursor: pointer; font-size: 15px; background: rgba(255,255,255,.5); }
.qz-opt:hover { border-color: #2ecc71; }
.qz-opt.right { background: rgba(46,204,113,.2); border-color: #2ecc71; }
.qz-opt.wrong { background: rgba(220,60,70,.18); border-color: #c0392b; }
.qz-opt:disabled { cursor: default; }
.qz-result { margin-top: 12px; font-weight: 800; }
.qz-btn { padding: 9px 18px; font-weight: 800; cursor: pointer; border-radius: 6px; border: 1px solid #2ecc71; background: #2ecc71; color: #fff; }
.qz-admin-form { display: grid; gap: 8px; max-width: 560px; }
.qz-admin-form input[type=text] { padding: 8px; }
.qz-table { width: 100%; border-collapse: collapse; }
.qz-table th, .qz-table td { padding: 6px 8px; border: 1px solid rgba(120,150,190,.26); text-align: left; font-size: 13px; }
</style>
<div class="qz-wrap">
    <div class="qz-head">
        <div>
            <div class="qz-title">答题挑战 <span class="qz-badge">内测中 v0.1</span></div>
            <div class="qz-muted">免费答题，答对得电影票，连对越多奖励越高（连击 ×<?php echo QZ_BASE_REWARD ?>，封顶 ×<?php echo QZ_STREAK_CAP ?>）。每日上限 <?php echo QZ_DAILY_LIMIT ?> 题。</div>
        </div>
        <div class="qz-balance">我的电影票：<b id="qzBal"><?php echo number_format((float)$CURUSER['seedbonus'], 1) ?></b> 张</div>
    </div>

    <?php if ($error) { ?><div class="qz-message"><?php echo htmlspecialchars($error) ?></div><?php } ?>

    <?php if ($isAdmin) { ?>
        <div class="qz-nav" style="margin-bottom:14px;border-bottom:1px solid rgba(120,150,190,.3)">
            <a href="/games/quiz/" class="<?php echo $view !== 'admin' ? 'on' : '' ?>">答题</a>
            <a href="/games/quiz/?view=admin" class="<?php echo $view === 'admin' ? 'on' : '' ?>">题库管理</a>
        </div>
    <?php } ?>

    <?php if ($view === 'admin' && $isAdmin) {
        $qres = sql_query("SELECT * FROM `" . QZ_Q_TABLE . "` ORDER BY `id` DESC LIMIT 200") or sqlerr(__FILE__, __LINE__);
    ?>
        <div class="qz-panel">
            <h3 style="margin:0 0 10px">添加题目（共 <?php echo $qCount ?> 道启用）</h3>
            <form class="qz-admin-form" method="post" action="/games/quiz/">
                <input type="hidden" name="action" value="add_q">
                <input type="text" name="question" placeholder="题目" maxlength="500" required>
                <input type="text" name="opt_a" placeholder="选项 A" maxlength="255" required>
                <input type="text" name="opt_b" placeholder="选项 B" maxlength="255" required>
                <input type="text" name="opt_c" placeholder="选项 C" maxlength="255" required>
                <input type="text" name="opt_d" placeholder="选项 D" maxlength="255" required>
                <label>正确答案：
                    <select name="answer">
                        <option value="0">A</option><option value="1">B</option><option value="2">C</option><option value="3">D</option>
                    </select>
                </label>
                <div><button type="submit" class="qz-btn">添加题目</button></div>
            </form>
        </div>
        <div class="qz-panel">
            <h3 style="margin:0 0 10px">题库</h3>
            <table class="qz-table">
                <tr><th>#</th><th>题目</th><th>答案</th><th></th></tr>
                <?php while ($q = mysql_fetch_assoc($qres)) {
                    $opts = [$q['opt_a'], $q['opt_b'], $q['opt_c'], $q['opt_d']];
                ?>
                    <tr>
                        <td><?php echo (int)$q['id'] ?></td>
                        <td><?php echo htmlspecialchars($q['question']) ?><br><span class="qz-muted">A.<?php echo htmlspecialchars($opts[0]) ?> B.<?php echo htmlspecialchars($opts[1]) ?> C.<?php echo htmlspecialchars($opts[2]) ?> D.<?php echo htmlspecialchars($opts[3]) ?></span></td>
                        <td><?php echo ['A', 'B', 'C', 'D'][(int)$q['answer']] ?? '?' ?></td>
                        <td>
                            <form method="post" action="/games/quiz/" onsubmit="return confirm('删除该题？');">
                                <input type="hidden" name="action" value="del_q">
                                <input type="hidden" name="id" value="<?php echo (int)$q['id'] ?>">
                                <button type="submit">删除</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    <?php } else { ?>
        <div class="qz-panel">
            <div class="qz-stat">
                <span>当前连击：<b id="qzStreak"><?php echo (int)$myState['streak'] ?></b></span>
                <span>今日已答：<b id="qzDay"><?php echo (int)$myState['day_count'] ?></b> / <?php echo QZ_DAILY_LIMIT ?></span>
                <span class="qz-muted">历史最高连击：<?php echo (int)$myState['best'] ?></span>
            </div>
            <?php if ($qCount === 0) { ?>
                <div class="qz-muted">题库为空，请管理员先在「题库管理」添加题目。</div>
            <?php } else { ?>
                <div class="qz-q" id="qzQ">加载题目中…</div>
                <div class="qz-opts" id="qzOpts"></div>
                <div class="qz-result" id="qzResult"></div>
                <div style="margin-top:12px"><button type="button" class="qz-btn" id="qzNext" style="display:none">下一题</button></div>
            <?php } ?>
        </div>
    <?php } ?>
</div>
<?php if ($view !== 'admin' && $qCount > 0) { ?>
<script>
(function () {
    var qEl = document.getElementById('qzQ'), optsEl = document.getElementById('qzOpts'), resEl = document.getElementById('qzResult'), nextBtn = document.getElementById('qzNext');
    var streakEl = document.getElementById('qzStreak'), dayEl = document.getElementById('qzDay'), balEl = document.getElementById('qzBal');
    var answering = false;
    function esc(s) { return (s || '').replace(/[&<>"]/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]); }); }
    function loadQ() {
        resEl.textContent = ''; nextBtn.style.display = 'none'; optsEl.innerHTML = ''; qEl.textContent = '加载题目中…';
        fetch('/games/quiz/?ajax=question', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.limit) { qEl.textContent = '今日答题已达上限，明天再来吧～'; return; }
            if (d.empty) { qEl.textContent = '题库为空。'; return; }
            qEl.textContent = d.question;
            streakEl.textContent = d.streak; dayEl.textContent = d.day_count;
            var labels = ['A', 'B', 'C', 'D'];
            optsEl.innerHTML = d.options.map(function (o, i) { return '<button class="qz-opt" data-i="' + i + '">' + labels[i] + '. ' + esc(o) + '</button>'; }).join('');
            optsEl.querySelectorAll('.qz-opt').forEach(function (b) { b.addEventListener('click', function () { answer(parseInt(b.getAttribute('data-i'), 10)); }); });
            answering = false;
        }).catch(function () { qEl.textContent = '加载失败'; });
    }
    function answer(choice) {
        if (answering) { return; }
        answering = true;
        var btns = optsEl.querySelectorAll('.qz-opt');
        btns.forEach(function (b) { b.disabled = true; });
        fetch('/games/quiz/', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=answer&choice=' + choice })
            .then(function (r) { return r.json(); }).then(function (d) {
                if (d.error) { resEl.textContent = d.error; nextBtn.style.display = ''; return; }
                if (d.limit) { resEl.textContent = '今日答题已达上限。'; return; }
                btns.forEach(function (b) {
                    var i = parseInt(b.getAttribute('data-i'), 10);
                    if (i === d.correctIndex) { b.classList.add('right'); }
                    else if (i === choice) { b.classList.add('wrong'); }
                });
                streakEl.textContent = d.streak; dayEl.textContent = d.day_count;
                if (d.balance != null) { balEl.textContent = (Math.round(d.balance * 10) / 10).toFixed(1); }
                resEl.innerHTML = d.correct ? ('<span style="color:#16a34a">✅ 答对！连击 ' + d.streak + '，奖励 +' + d.reward + ' 电影票</span>') : '<span style="color:#dc2626">❌ 答错了，连击清零</span>';
                nextBtn.style.display = '';
            }).catch(function () { resEl.textContent = '提交失败'; nextBtn.style.display = ''; });
    }
    nextBtn.addEventListener('click', loadQ);
    loadQ();
})();
</script>
<?php } ?>
<?php
stdfoot();
