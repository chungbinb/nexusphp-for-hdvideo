<?php
require "../../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();
$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;
require_once "../../../include/game_control.php";
game_guard('moviequiz');
require_once "../../../include/game_leaderboard.php";

/**
 * 猜电影 — admin-managed bank of movie clues (screenshot image or famous quote);
 * the player types the movie name. Correct answers earn 电影票 with a streak bonus;
 * wrong resets the streak; a daily cap limits farming. Free-text answer is graded
 * server-side against the answer + aliases (normalized), never revealed until graded.
 */
const MQ_BUSINESS_TYPE = 13;
const MQ_Q_TABLE = 'hdvideo_movie_questions';
const MQ_STATE_TABLE = 'hdvideo_movie_state';
const MQ_IMPORTED_TABLE = 'hdvideo_movie_imported';   // 记录已导入过的种子，删题后也不再重复导入
const MQ_BASE_REWARD = 30;     // 电影票 per correct (×streak, capped)
const MQ_STREAK_CAP = 10;
const MQ_DAILY_LIMIT = 30;     // max answers per day

function mq_is_admin()
{
    return get_user_class() >= UC_ADMINISTRATOR;
}

function mq_ensure_tables()
{
    static $done = false;
    if ($done) return;
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . MQ_Q_TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `type` varchar(10) NOT NULL DEFAULT 'shot',
            `clue` text NOT NULL,
            `answer` varchar(255) NOT NULL DEFAULT '',
            `aliases` text,
            `status` tinyint NOT NULL DEFAULT 1,
            `source` varchar(10) NOT NULL DEFAULT 'manual',
            `torrent_id` int unsigned NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_status` (`status`),
            KEY `idx_torrent` (`torrent_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // tables created before auto-import lacked source/torrent_id; add on demand.
    $col = sql_query("SHOW COLUMNS FROM `" . MQ_Q_TABLE . "` LIKE 'source'");
    if ($col && !mysql_fetch_assoc($col)) {
        sql_query("ALTER TABLE `" . MQ_Q_TABLE . "` ADD COLUMN `source` varchar(10) NOT NULL DEFAULT 'manual'") or sqlerr(__FILE__, __LINE__);
        sql_query("ALTER TABLE `" . MQ_Q_TABLE . "` ADD COLUMN `torrent_id` int unsigned NOT NULL DEFAULT 0") or sqlerr(__FILE__, __LINE__);
    }
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . MQ_IMPORTED_TABLE . "` (
            `torrent_id` int unsigned NOT NULL,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`torrent_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    @sql_query("
        CREATE TABLE IF NOT EXISTS `" . MQ_STATE_TABLE . "` (
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

function mq_norm($s)
{
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    $s = preg_replace('/\s+/u', '', $s);
    $s = preg_replace('/[[:punct:]·。，、；：！？（）《》“”‘’—…【】　]/u', '', $s);
    return $s;
}

function mq_match($guess, $answer, $aliasesText)
{
    $g = mq_norm($guess);
    if ($g === '') return false;
    $cands = [mq_norm($answer)];
    foreach (preg_split('/[\r\n,，;；、]+/u', (string)$aliasesText) as $a) {
        $a = mq_norm($a);
        if ($a !== '') $cands[] = $a;
    }
    return in_array($g, $cands, true);
}

function mq_type_label($t)
{
    return $t === 'quote' ? '台词' : '截图';
}

function mq_get_state($uid)
{
    $res = sql_query("SELECT * FROM `" . MQ_STATE_TABLE . "` WHERE `uid` = " . (int)$uid . " LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $s = mysql_fetch_assoc($res);
    if (!$s) {
        $now = date('Y-m-d H:i:s');
        sql_query("INSERT INTO `" . MQ_STATE_TABLE . "` (`uid`,`updated_at`) VALUES (" . (int)$uid . ", " . sqlesc($now) . ")") or sqlerr(__FILE__, __LINE__);
        $s = ['uid' => $uid, 'q_id' => 0, 'streak' => 0, 'best' => 0, 'day' => null, 'day_count' => 0];
    }
    $today = date('Y-m-d');
    if (($s['day'] ?? null) !== $today) {
        $s['day'] = $today;
        $s['day_count'] = 0;
        sql_query("UPDATE `" . MQ_STATE_TABLE . "` SET `day` = " . sqlesc($today) . ", `day_count` = 0, `updated_at` = " . sqlesc(date('Y-m-d H:i:s')) . " WHERE `uid` = " . (int)$uid) or sqlerr(__FILE__, __LINE__);
    }
    return $s;
}

function mq_question_count()
{
    mq_ensure_tables();
    $res = sql_query("SELECT COUNT(*) AS c FROM `" . MQ_Q_TABLE . "` WHERE `status` = 1") or sqlerr(__FILE__, __LINE__);
    return (int)mysql_fetch_assoc($res)['c'];
}

function mq_next_question($uid)
{
    mq_ensure_tables();
    $s = mq_get_state($uid);
    if ((int)$s['day_count'] >= MQ_DAILY_LIMIT) {
        return ['limit' => true];
    }
    $res = sql_query("SELECT * FROM `" . MQ_Q_TABLE . "` WHERE `status` = 1 ORDER BY RAND() LIMIT 1") or sqlerr(__FILE__, __LINE__);
    $q = mysql_fetch_assoc($res);
    if (!$q) {
        return ['empty' => true];
    }
    sql_query("UPDATE `" . MQ_STATE_TABLE . "` SET `q_id` = " . (int)$q['id'] . ", `updated_at` = " . sqlesc(date('Y-m-d H:i:s')) . " WHERE `uid` = " . (int)$uid) or sqlerr(__FILE__, __LINE__);
    return [
        'id' => (int)$q['id'],
        'type' => $q['type'],
        'clue' => $q['clue'],
        'streak' => (int)$s['streak'],
        'day_count' => (int)$s['day_count'],
        'day_limit' => MQ_DAILY_LIMIT,
    ];
}

function mq_answer($uid, $guess)
{
    global $CURUSER;
    mq_ensure_tables();
    $now = date('Y-m-d H:i:s');
    sql_query("START TRANSACTION") or sqlerr(__FILE__, __LINE__);
    try {
        $res = sql_query("SELECT * FROM `" . MQ_STATE_TABLE . "` WHERE `uid` = " . (int)$uid . " FOR UPDATE") or sqlerr(__FILE__, __LINE__);
        $s = mysql_fetch_assoc($res);
        if (!$s || (int)$s['q_id'] <= 0) {
            sql_query("ROLLBACK");
            return ['error' => '请先获取题目。'];
        }
        $today = date('Y-m-d');
        $dayCount = ($s['day'] === $today) ? (int)$s['day_count'] : 0;
        if ($dayCount >= MQ_DAILY_LIMIT) {
            sql_query("ROLLBACK");
            return ['limit' => true];
        }
        $qres = sql_query("SELECT * FROM `" . MQ_Q_TABLE . "` WHERE `id` = " . (int)$s['q_id'] . " LIMIT 1") or sqlerr(__FILE__, __LINE__);
        $q = mysql_fetch_assoc($qres);
        if (!$q) {
            sql_query("ROLLBACK");
            return ['error' => '题目已失效，请获取下一题。'];
        }
        $correct = mq_match($guess, $q['answer'], $q['aliases']);
        $streak = (int)$s['streak'];
        $reward = 0;
        if ($correct) {
            $streak++;
            $reward = MQ_BASE_REWARD * min($streak, MQ_STREAK_CAP);
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
                MQ_BUSINESS_TYPE, (int)$uid, sqlesc(number_format($oldB, 1, '.', '')), sqlesc(number_format($reward, 1, '.', '')), sqlesc(number_format($newBonus, 1, '.', '')),
                sqlesc("[猜电影] 答对第{$streak}连击，奖励{$reward}"), sqlesc($now), sqlesc($now)
            )) or sqlerr(__FILE__, __LINE__);
            clear_user_cache($uid);
        }
        sql_query("UPDATE `" . MQ_STATE_TABLE . "` SET `q_id` = 0, `streak` = $streak, `best` = $best, `day` = " . sqlesc($today) . ", `day_count` = $dayCount, `updated_at` = " . sqlesc($now) . " WHERE `uid` = " . (int)$uid) or sqlerr(__FILE__, __LINE__);
        sql_query("COMMIT") or sqlerr(__FILE__, __LINE__);
        if ($reward > 0) {
            $GLOBALS['CURUSER']['seedbonus'] = $newBonus;
        }
        return [
            'correct' => $correct,
            'answer' => $q['answer'],
            'reward' => $reward,
            'streak' => $streak,
            'balance' => $newBonus,
            'day_count' => $dayCount,
            'day_limit' => MQ_DAILY_LIMIT,
        ];
    } catch (Throwable $e) {
        sql_query("ROLLBACK");
        throw $e;
    }
}

function mq_add_question($data)
{
    mq_ensure_tables();
    $type = ($data['type'] ?? 'shot') === 'quote' ? 'quote' : 'shot';
    $clue = trim((string)($data['clue'] ?? ''));
    $answer = trim((string)($data['answer'] ?? ''));
    $aliases = trim((string)($data['aliases'] ?? ''));
    if ($clue === '') return $type === 'quote' ? "台词内容不能为空。" : "截图图片地址不能为空。";
    if ($answer === '' || mb_strlen($answer) > 255) return "电影名(答案)不能为空且不超过 255 字。";
    sql_query(sprintf(
        "INSERT INTO `" . MQ_Q_TABLE . "` (`type`,`clue`,`answer`,`aliases`,`status`,`created_at`) VALUES (%s,%s,%s,%s,1,%s)",
        sqlesc($type), sqlesc(mb_substr($clue, 0, 2000)), sqlesc(mb_substr($answer, 0, 255)), sqlesc(mb_substr($aliases, 0, 1000)), sqlesc(date('Y-m-d H:i:s'))
    )) or sqlerr(__FILE__, __LINE__);
    return "";
}

function mq_delete_question($id)
{
    mq_ensure_tables();
    sql_query("DELETE FROM `" . MQ_Q_TABLE . "` WHERE `id` = " . (int)$id) or sqlerr(__FILE__, __LINE__);
    return "";
}

function mq_delete_batch($ids)
{
    mq_ensure_tables();
    $clean = [];
    foreach ((array)$ids as $i) {
        $i = (int)$i;
        if ($i > 0) $clean[] = $i;
    }
    if (!$clean) return "未选择任何题目。";
    sql_query("DELETE FROM `" . MQ_Q_TABLE . "` WHERE `id` IN (" . implode(',', $clean) . ")") or sqlerr(__FILE__, __LINE__);
    return "";
}

/** Extract http(s) image URLs from a BBCode description ([img]...[/img] / [img=...]). */
function mq_extract_images($descr)
{
    $urls = [];
    if (preg_match_all('/\[img[^\]]*\]\s*([^\[\]]+?)\s*\[\/img\]/i', (string)$descr, $m)) {
        foreach ($m[1] as $u) $urls[] = trim($u);
    }
    if (preg_match_all('/\[img=\s*([^\]\s]+)\s*\]/i', (string)$descr, $m2)) {
        foreach ($m2[1] as $u) $urls[] = trim($u);
    }
    return array_values(array_filter($urls, function ($u) { return preg_match('#^https?://#i', $u); }));
}

/** Value of a 「◎X　　Y　 …」 field in the description (full-width spaces tolerated). */
function mq_descr_field($descr, $c1, $c2)
{
    if (preg_match('/◎' . $c1 . '[\s\x{3000}]*' . $c2 . '[\s\x{3000}：:]*([^\r\n]+)/u', (string)$descr, $m)) {
        return trim($m[1]);
    }
    return '';
}

/** Build acceptable aliases from 译名 + a season/year-stripped base + 副标题. */
function mq_build_aliases($primary, $descr, $smallDescr)
{
    $aliases = [];
    $trans = mq_descr_field($descr, '译', '名');
    if ($trans !== '') {
        foreach (preg_split('#[/／]#u', $trans) as $p) {
            $p = trim($p);
            if ($p !== '') $aliases[] = $p;
        }
    }
    $base = trim(preg_replace('/[\s\x{3000}]*(第[0-9一二三四五六七八九十百零]+[季部期]|Season[\s]*\d+|S\d+|\(?(19|20)\d\d\)?)[\s\x{3000}]*$/iu', '', $primary));
    if ($base !== '' && $base !== $primary) $aliases[] = $base;
    $sd = trim((string)$smallDescr);
    if ($sd !== '' && mb_strlen($sd) <= 60) $aliases[] = $sd;
    $out = [];
    foreach ($aliases as $a) {
        if ($a !== '' && $a !== $primary && !in_array($a, $out, true)) $out[] = $a;
    }
    return implode("\n", $out);
}

/** Auto-generate screenshot questions from torrents that have screenshots. */
function mq_import_from_torrents($limit)
{
    mq_ensure_tables();
    $limit = max(1, min(100, (int)$limit));
    $descrField = function_exists('hdvideo_column_exists') && hdvideo_column_exists('torrents', 'descr')
        ? "COALESCE(NULLIF(torrent_extras.descr,''), torrents.descr)"
        : "torrent_extras.descr";
    $sql = "SELECT torrents.id AS id, torrents.name AS name, torrents.small_descr AS small_descr, $descrField AS descr
            FROM torrents LEFT JOIN torrent_extras ON torrents.id = torrent_extras.torrent_id
            WHERE torrents.visible = 'yes' AND $descrField LIKE '%[img%'
              AND torrents.id NOT IN (SELECT torrent_id FROM `" . MQ_IMPORTED_TABLE . "`)
              AND torrents.id NOT IN (SELECT torrent_id FROM `" . MQ_Q_TABLE . "` WHERE torrent_id > 0)
            ORDER BY RAND() LIMIT $limit";
    $res = sql_query($sql) or sqlerr(__FILE__, __LINE__);
    $added = 0;
    $now = date('Y-m-d H:i:s');
    while ($t = mysql_fetch_assoc($res)) {
        // remember we processed this torrent so it is never picked again (even if its
        // question is later deleted), until the admin resets the import log.
        sql_query(sprintf("INSERT IGNORE INTO `" . MQ_IMPORTED_TABLE . "` (`torrent_id`,`created_at`) VALUES (%d,%s)", (int)$t['id'], sqlesc($now))) or sqlerr(__FILE__, __LINE__);
        $imgs = mq_extract_images($t['descr']);
        if (count($imgs) < 2) continue; // need a screenshot beyond the poster
        $name = mq_descr_field($t['descr'], '片', '名');
        if ($name === '') $name = trim((string)$t['small_descr']);
        if ($name === '') $name = trim((string)$t['name']);
        if ($name === '' || mb_strlen($name) > 255) continue;
        $cand = array_slice($imgs, 1); // skip the poster (first image)
        $shot = $cand[array_rand($cand)];
        $aliases = mq_build_aliases($name, $t['descr'], $t['small_descr']);
        sql_query(sprintf(
            "INSERT INTO `" . MQ_Q_TABLE . "` (`type`,`clue`,`answer`,`aliases`,`status`,`source`,`torrent_id`,`created_at`) VALUES ('shot',%s,%s,%s,1,'auto',%d,%s)",
            sqlesc(mb_substr($shot, 0, 2000)), sqlesc(mb_substr($name, 0, 255)), sqlesc(mb_substr($aliases, 0, 1000)), (int)$t['id'], sqlesc($now)
        )) or sqlerr(__FILE__, __LINE__);
        $added++;
    }
    return $added;
}

// ---- AJAX: next question ----
if (($_GET['ajax'] ?? '') === 'question') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true] + mq_next_question((int)$CURUSER['id']), JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'answer') {
        header('Content-Type: application/json');
        $r = mq_answer((int)$CURUSER['id'], $_POST['guess'] ?? '');
        echo json_encode(['ok' => empty($r['error']) && empty($r['limit'])] + $r, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'import_torrents' && mq_is_admin()) {
        $n = mq_import_from_torrents($_POST['count'] ?? 20);
        header('Location: /games/moviequiz/?view=admin&imported=' . (int)$n);
        exit;
    }
    $error = '';
    if ($action === 'add_q' && mq_is_admin()) {
        $error = mq_add_question($_POST);
    } elseif ($action === 'del_q' && mq_is_admin()) {
        $error = mq_delete_question($_POST['id'] ?? 0);
    } elseif ($action === 'del_batch' && mq_is_admin()) {
        $error = mq_delete_batch($_POST['ids'] ?? []);
    } elseif ($action === 'del_auto' && mq_is_admin()) {
        mq_ensure_tables();
        sql_query("DELETE FROM `" . MQ_Q_TABLE . "` WHERE `source` = 'auto'") or sqlerr(__FILE__, __LINE__);
    } elseif ($action === 'reset_import' && mq_is_admin()) {
        mq_ensure_tables();
        sql_query("DELETE FROM `" . MQ_IMPORTED_TABLE . "`") or sqlerr(__FILE__, __LINE__);
    }
    header('Location: /games/moviequiz/' . ($error !== '' ? '?view=admin&error=' . urlencode($error) : ($action !== '' ? '?view=admin&msg=1' : '')));
    exit;
}

$error = trim((string)($_GET['error'] ?? ''));
$view = $_GET['view'] ?? '';
$isAdmin = mq_is_admin();
$qCount = mq_question_count();
$myState = mq_get_state((int)$CURUSER['id']);

stdhead("猜电影");
echo game_back_link();
?>
<style>
.mq-wrap { max-width: 760px; margin: 0 auto; }
.mq-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.mq-title { font-size: 24px; font-weight: 800; }
.mq-badge { font-size: 12px; font-weight: 700; color: #8e44ad; background: rgba(142,68,173,.14); padding: 2px 8px; border-radius: 999px; vertical-align: middle; }
.mq-balance { font-size: 14px; font-weight: 700; }
.mq-muted { color: #6f7f95; }
.mq-message { padding: 10px 12px; border-radius: 6px; margin-bottom: 14px; font-weight: 700; background: rgba(220,60,70,.14); color: #c02432; }
.mq-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 8px; padding: 16px; margin-bottom: 14px; background: rgba(30,60,100,.06); }
.mq-nav a { padding: 9px 14px; font-weight: 700; text-decoration: none; color: #6f7f95; border-bottom: 3px solid transparent; }
.mq-nav a.on { color: #8e44ad; border-bottom-color: #8e44ad; }
.mq-stat { display: flex; gap: 16px; flex-wrap: wrap; font-weight: 700; margin-bottom: 12px; }
.mq-clue-type { display: inline-block; font-size: 12px; font-weight: 700; color: #8e44ad; background: rgba(142,68,173,.12); padding: 2px 8px; border-radius: 999px; margin-bottom: 10px; }
.mq-shot { text-align: center; }
.mq-shot img { max-width: 100%; max-height: 360px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,.25); }
.mq-quote { font-size: 20px; font-weight: 700; line-height: 1.6; padding: 18px; background: rgba(142,68,173,.06); border-left: 4px solid #8e44ad; border-radius: 6px; }
.mq-answer-row { display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
.mq-input { flex: 1; min-width: 200px; padding: 11px 12px; border: 1px solid rgba(120,150,190,.45); border-radius: 8px; font-size: 16px; }
.mq-btn { padding: 10px 20px; font-weight: 800; cursor: pointer; border-radius: 6px; border: 1px solid #8e44ad; background: #8e44ad; color: #fff; }
.mq-btn:disabled { opacity: .5; cursor: not-allowed; }
.mq-result { margin-top: 12px; font-weight: 800; min-height: 22px; }
.mq-admin-form { display: grid; gap: 8px; max-width: 620px; }
.mq-admin-form input[type=text], .mq-admin-form textarea, .mq-admin-form select { padding: 8px; width: 100%; box-sizing: border-box; }
.mq-table { width: 100%; border-collapse: collapse; }
.mq-table th, .mq-table td { padding: 6px 8px; border: 1px solid rgba(120,150,190,.26); text-align: left; font-size: 13px; vertical-align: top; }
.mq-table img { max-width: 120px; max-height: 70px; border-radius: 4px; }
</style>
<div class="mq-wrap">
    <div class="mq-head">
        <div>
            <div class="mq-title">猜电影 <span class="mq-badge">内测中 v0.3</span></div>
            <div class="mq-muted">看截图或台词猜电影名，答对得电影票，连对越多奖励越高（连击 ×<?php echo MQ_BASE_REWARD ?>，封顶 ×<?php echo MQ_STREAK_CAP ?>）。每日上限 <?php echo MQ_DAILY_LIMIT ?> 题。</div>
        </div>
        <div class="mq-balance">我的电影票：<b id="mqBal"><?php echo number_format((float)$CURUSER['seedbonus'], 1) ?></b> 张</div>
    </div>

    <?php if ($error) { ?><div class="mq-message"><?php echo htmlspecialchars($error) ?></div><?php } ?>

    <?php if ($isAdmin) { ?>
        <div class="mq-nav" style="margin-bottom:14px;border-bottom:1px solid rgba(120,150,190,.3)">
            <a href="/games/moviequiz/" class="<?php echo $view !== 'admin' ? 'on' : '' ?>">猜电影</a>
            <a href="/games/moviequiz/?view=admin" class="<?php echo $view === 'admin' ? 'on' : '' ?>">题库管理</a>
        </div>
    <?php } ?>

    <?php if ($view === 'admin' && $isAdmin) {
        $qres = sql_query("SELECT * FROM `" . MQ_Q_TABLE . "` ORDER BY `id` DESC LIMIT 200") or sqlerr(__FILE__, __LINE__);
        $autoCnt = (int)mysql_fetch_assoc(sql_query("SELECT COUNT(*) AS c FROM `" . MQ_Q_TABLE . "` WHERE `source` = 'auto'"))['c'];
        $impLog = (int)mysql_fetch_assoc(sql_query("SELECT COUNT(*) AS c FROM `" . MQ_IMPORTED_TABLE . "`"))['c'];
        $imported = isset($_GET['imported']) ? (int)$_GET['imported'] : -1;
    ?>
        <?php if ($imported >= 0) { ?><div class="mq-panel" style="background:rgba(46,204,113,.12);color:#16a34a;font-weight:700">✅ 已从种子库自动导入 <?php echo $imported ?> 道截图题。</div><?php } ?>
        <div class="mq-panel">
            <h3 style="margin:0 0 10px">从种子库自动导入截图题</h3>
            <div class="mq-muted" style="margin-bottom:8px">随机抽取「带截图的种子」，取一张截图作题、用简介里的「◎片名」作答案，并自动生成别名（译名/去季号/副标题）。<b>已处理过的种子不会再被导入（即使你把题删了也不会重来）</b>。当前自动题 <?php echo $autoCnt ?> 道，已处理种子 <?php echo $impLog ?> 个。</div>
            <form method="post" action="/games/moviequiz/" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="hidden" name="action" value="import_torrents">
                <label>本次导入数量 <input type="number" name="count" value="20" min="1" max="100" style="width:80px;padding:6px"></label>
                <button type="submit" class="mq-btn">开始导入</button>
            </form>
            <form method="post" action="/games/moviequiz/" onsubmit="return confirm('重置导入记录后，之前处理/删除过的种子又会重新进入可导入池，确定？');" style="margin-top:8px">
                <input type="hidden" name="action" value="reset_import">
                <button type="submit" style="background:transparent;border:1px solid #9b59b6;color:#9b59b6;padding:6px 12px;border-radius:6px;font-weight:700;cursor:pointer">重置导入记录（允许重新导入已处理过的种子）</button>
            </form>
        </div>
        <div class="mq-panel">
            <h3 style="margin:0 0 10px">添加题目（共 <?php echo $qCount ?> 道启用）</h3>
            <form class="mq-admin-form" method="post" action="/games/moviequiz/">
                <input type="hidden" name="action" value="add_q">
                <label>类型
                    <select name="type">
                        <option value="shot">截图（填图片URL）</option>
                        <option value="quote">台词（填台词文字）</option>
                    </select>
                </label>
                <label>线索（截图填图片地址 http(s)://… ；台词填台词原文）
                    <textarea name="clue" rows="2" placeholder="https://图片地址.jpg 或 一句经典台词" required></textarea>
                </label>
                <input type="text" name="answer" placeholder="正确电影名（如：肖申克的救赎）" maxlength="255" required>
                <input type="text" name="aliases" placeholder="可接受的其它答案，逗号或换行分隔（如英文名、别名、简称）" maxlength="1000">
                <div><button type="submit" class="mq-btn">添加题目</button></div>
            </form>
            <div class="mq-muted" style="margin-top:8px">提示：答案匹配会忽略大小写、空格、标点；多写几个别名命中率更高。截图图片需可外链访问。</div>
        </div>
        <div class="mq-panel">
            <h3 style="margin:0 0 10px">题库</h3>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
                <form method="post" action="/games/moviequiz/" onsubmit="return confirm('删除全部自动导入的题目？此操作不可撤销。');" style="display:inline">
                    <input type="hidden" name="action" value="del_auto">
                    <button type="submit" style="background:#9b59b6;border:1px solid #9b59b6;color:#fff;padding:7px 12px;border-radius:6px;font-weight:700;cursor:pointer">清空全部自动导入题</button>
                </form>
            </div>
            <form method="post" action="/games/moviequiz/" onsubmit="return confirm('确定删除选中的题目？');">
                <input type="hidden" name="action" value="del_batch">
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
                    <button type="submit" style="background:#c0392b;border:1px solid #c0392b;color:#fff;padding:7px 14px;border-radius:6px;font-weight:800;cursor:pointer">删除选中</button>
                    <label style="font-weight:700"><input type="checkbox" id="mqCheckAll"> 全选本页</label>
                    <span class="mq-muted" id="mqSelCount"></span>
                </div>
                <table class="mq-table">
                    <tr><th style="width:30px"></th><th>#</th><th>类型</th><th>线索</th><th>答案 / 别名</th><th>来源</th></tr>
                    <?php while ($q = mysql_fetch_assoc($qres)) { ?>
                        <tr>
                            <td style="text-align:center"><input type="checkbox" class="mq-ck" name="ids[]" value="<?php echo (int)$q['id'] ?>"></td>
                            <td><?php echo (int)$q['id'] ?></td>
                            <td><?php echo mq_type_label($q['type']) ?></td>
                            <td><?php if ($q['type'] === 'shot') { ?><img src="<?php echo htmlspecialchars($q['clue']) ?>" loading="lazy" alt=""><?php } else { ?><?php echo htmlspecialchars(mb_substr($q['clue'], 0, 120)) ?><?php } ?></td>
                            <td><b><?php echo htmlspecialchars($q['answer']) ?></b><?php if (trim((string)$q['aliases']) !== '') { ?><br><span class="mq-muted"><?php echo htmlspecialchars($q['aliases']) ?></span><?php } ?></td>
                            <td><?php if (($q['source'] ?? '') === 'auto') { ?>自动<?php if ((int)($q['torrent_id'] ?? 0) > 0) { ?> <a href="/details.php?id=<?php echo (int)$q['torrent_id'] ?>" target="_blank">#<?php echo (int)$q['torrent_id'] ?></a><?php } ?><?php } else { ?>手动<?php } ?></td>
                        </tr>
                    <?php } ?>
                </table>
            </form>
        </div>
        <script>
        (function () {
            var all = document.getElementById('mqCheckAll');
            var cks = function () { return Array.prototype.slice.call(document.querySelectorAll('.mq-ck')); };
            var cnt = document.getElementById('mqSelCount');
            function upd() { var n = cks().filter(function (c) { return c.checked; }).length; cnt.textContent = n > 0 ? ('已选 ' + n + ' 题') : ''; }
            if (all) all.addEventListener('change', function () { cks().forEach(function (c) { c.checked = all.checked; }); upd(); });
            cks().forEach(function (c) { c.addEventListener('change', upd); });
        })();
        </script>
    <?php } else { ?>
        <div class="mq-panel">
            <div class="mq-stat">
                <span>当前连击：<b id="mqStreak"><?php echo (int)$myState['streak'] ?></b></span>
                <span>今日已答：<b id="mqDay"><?php echo (int)$myState['day_count'] ?></b> / <?php echo MQ_DAILY_LIMIT ?></span>
                <span class="mq-muted">历史最高连击：<?php echo (int)$myState['best'] ?></span>
            </div>
            <?php if ($qCount === 0) { ?>
                <div class="mq-muted">题库为空，请管理员先在「题库管理」添加截图/台词题目。</div>
            <?php } else { ?>
                <div id="mqClueType" class="mq-clue-type" style="display:none"></div>
                <div id="mqClue">加载题目中…</div>
                <div class="mq-answer-row">
                    <input type="text" class="mq-input" id="mqInput" placeholder="输入电影名…" autocomplete="off" disabled>
                    <button type="button" class="mq-btn" id="mqSubmit" disabled>提交</button>
                    <button type="button" class="mq-btn" id="mqNext" style="display:none">下一题</button>
                </div>
                <div class="mq-result" id="mqResult"></div>
            <?php } ?>
        </div>
    <?php } ?>

    <?php if ($view !== 'admin') {
        $mqEarn = game_lb_bonus('profit', '[猜电影]', 10);
        $mqRight = game_lb_bonus('active', '[猜电影]', 10);
        $mqStreakLb = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, `s`.`best` AS amt FROM `" . MQ_STATE_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` WHERE `s`.`best` > 0 ORDER BY `s`.`best` DESC LIMIT 10");
        echo game_lb_css();
    ?>
        <div class="mq-panel">
            <h3 style="margin:0 0 12px">🏆 猜电影榜单</h3>
            <div class="glb-grid">
                <?php
                echo game_lb_table('💰 收益榜', $mqEarn, '累计电影票', function ($r) { return game_lb_money($r['amt']); }, function ($r) { return 'glb-pos'; });
                echo game_lb_table('✅ 答对榜', $mqRight, '答对次数', function ($r) { return number_format((int)$r['amt']) . ' 次'; });
                echo game_lb_table('🔥 连击榜', $mqStreakLb, '最高连击', function ($r) { return number_format((int)$r['amt']) . ' 连'; });
                ?>
            </div>
        </div>
    <?php } ?>
</div>
<?php if ($view !== 'admin' && $qCount > 0) { ?>
<script>
(function () {
    var clueTypeEl = document.getElementById('mqClueType'), clueEl = document.getElementById('mqClue'), resEl = document.getElementById('mqResult');
    var input = document.getElementById('mqInput'), submitBtn = document.getElementById('mqSubmit'), nextBtn = document.getElementById('mqNext');
    var streakEl = document.getElementById('mqStreak'), dayEl = document.getElementById('mqDay'), balEl = document.getElementById('mqBal');
    var answering = false, hasQ = false;
    function esc(s) { return (s || '').replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]); }); }

    function loadQ() {
        resEl.textContent = ''; nextBtn.style.display = 'none'; submitBtn.style.display = '';
        input.value = ''; input.disabled = true; submitBtn.disabled = true; hasQ = false;
        clueTypeEl.style.display = 'none'; clueEl.textContent = '加载题目中…';
        fetch('/games/moviequiz/?ajax=question', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.limit) { clueEl.textContent = '今日答题已达上限，明天再来吧～'; return; }
            if (d.empty) { clueEl.textContent = '题库为空。'; return; }
            streakEl.textContent = d.streak; dayEl.textContent = d.day_count;
            clueTypeEl.style.display = ''; clueTypeEl.textContent = d.type === 'quote' ? '🎬 台词' : '🖼️ 截图';
            if (d.type === 'quote') { clueEl.className = 'mq-quote'; clueEl.textContent = d.clue; }
            else { clueEl.className = 'mq-shot'; clueEl.innerHTML = '<img src="' + esc(d.clue) + '" alt="电影截图">'; }
            input.disabled = false; submitBtn.disabled = false; hasQ = true; answering = false;
            input.focus();
        }).catch(function () { clueEl.textContent = '加载失败'; });
    }

    function submit() {
        if (answering || !hasQ) return;
        var g = input.value.trim();
        if (g === '') { input.focus(); return; }
        answering = true; submitBtn.disabled = true; input.disabled = true;
        fetch('/games/moviequiz/', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=answer&guess=' + encodeURIComponent(g) })
            .then(function (r) { return r.json(); }).then(function (d) {
                if (d.error) { resEl.textContent = d.error; submitBtn.style.display = 'none'; nextBtn.style.display = ''; return; }
                if (d.limit) { resEl.textContent = '今日答题已达上限。'; submitBtn.style.display = 'none'; return; }
                streakEl.textContent = d.streak; dayEl.textContent = d.day_count;
                if (d.balance != null) balEl.textContent = (Math.round(d.balance * 10) / 10).toFixed(1);
                resEl.innerHTML = d.correct
                    ? ('<span style="color:#16a34a">✅ 答对！是《' + esc(d.answer) + '》，连击 ' + d.streak + '，奖励 +' + d.reward + ' 电影票</span>')
                    : ('<span style="color:#dc2626">❌ 答错了，正确答案：《' + esc(d.answer) + '》，连击清零</span>');
                submitBtn.style.display = 'none'; nextBtn.style.display = '';
            }).catch(function () { resEl.textContent = '提交失败'; submitBtn.disabled = false; input.disabled = false; answering = false; });
    }

    submitBtn.addEventListener('click', submit);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
    nextBtn.addEventListener('click', loadQ);
    loadQ();
})();
</script>
<?php } ?>
<?php
stdfoot();
