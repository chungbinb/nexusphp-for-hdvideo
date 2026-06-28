<?php
/**
 * 猜电影 —— 手机版（竖屏 App 风格）。自带完整 HTML 头尾，不经过桌面版 stdhead。
 * 由 moviequiz/index.php 在手机 UA 且非管理界面时 require；获取题目/答题的 AJAX 仍在 index.php。
 * 复用同一套前端逻辑（元素 id 与桌面版一致），仅换皮肤；榜单收进右上角悬浮按钮弹窗。
 * 管理员的题库管理请用 ?pc=1（桌面版）。
 */
if (!defined('MQ_Q_TABLE')) { return; }

$mBal = number_format((float)($CURUSER['seedbonus'] ?? 0), 1);

// 榜单（与桌面版同款查询）
$mqEarn = game_lb_bonus('profit', '[猜电影]', 10);
$mqRight = game_lb_bonus('active', '[猜电影]', 10);
$mqStreakLb = game_lb_run("SELECT `s`.`uid` AS uid, `u`.`username` AS username, `s`.`best` AS amt FROM `" . MQ_STATE_TABLE . "` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`uid` WHERE `s`.`best` > 0 ORDER BY `s`.`best` DESC LIMIT 10");
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<title>猜电影</title>
<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; }
body { background: #0c1622; color: #e6eef8; font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif; }
a { color: inherit; text-decoration: none; }

.mq-wrap { max-width: 640px; margin: 0 auto; padding: 0 14px calc(40px + env(safe-area-inset-bottom)); }

/* 顶栏 */
.mq-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 2px 8px; position: sticky; top: 0; background: #0c1622; z-index: 5; }
.mq-back { display: inline-flex; align-items: center; gap: 4px; font-size: 15px; color: #9fb6cf; }
.mq-htitle { font-size: 18px; font-weight: 800; }
.mq-lb-btn { font-size: 13px; font-weight: 800; color: #e7c6ff; background: rgba(142,68,173,.22); border: 1px solid rgba(190,130,230,.45); border-radius: 999px; padding: 6px 12px; cursor: pointer; }

.mq-balance { font-size: 14px; font-weight: 700; text-align: center; margin: 4px 0 12px; color: #cfe0f2; }
.mq-balance b { color: #ffd770; font-size: 17px; }
.mq-muted { color: #7f93ab; }

.mq-panel { border: 1px solid rgba(120,150,190,.34); border-radius: 16px; padding: 16px 14px; margin-bottom: 14px; background: rgba(30,60,100,.12); }
.mq-stat { display: flex; gap: 14px; flex-wrap: wrap; font-weight: 700; margin-bottom: 14px; font-size: 14px; justify-content: center; }
.mq-stat b { color: #e7c6ff; }

.mq-clue-type { display: inline-block; font-size: 12px; font-weight: 700; color: #e7c6ff; background: rgba(142,68,173,.25); padding: 3px 10px; border-radius: 999px; margin-bottom: 12px; }
.mq-shot { text-align: center; }
.mq-shot img { max-width: 100%; max-height: 52vh; border-radius: 10px; box-shadow: 0 3px 12px rgba(0,0,0,.45); }
.mq-quote { font-size: 19px; font-weight: 700; line-height: 1.6; padding: 18px; background: rgba(142,68,173,.12); border-left: 4px solid #8e44ad; border-radius: 8px; }

.mq-answer-row { display: flex; flex-direction: column; gap: 10px; margin-top: 16px; }
.mq-input { width: 100%; padding: 14px 14px; border: 1px solid rgba(120,150,190,.45); border-radius: 10px; font-size: 16px; background: rgba(255,255,255,.96); color: #1b2b3a; }
.mq-btn { width: 100%; padding: 15px 20px; font-weight: 900; font-size: 17px; cursor: pointer; border-radius: 12px; border: 1px solid #8e44ad; background: linear-gradient(180deg,#a05ec4,#8e44ad); color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,.4); }
.mq-btn:disabled { opacity: .5; cursor: not-allowed; }
.mq-result { margin-top: 14px; font-weight: 800; min-height: 24px; text-align: center; font-size: 15px; }

/* 榜单弹窗 */
.mq-modal { position: fixed; inset: 0; z-index: 300; display: none; }
.mq-modal.show { display: block; }
.mq-modal-mask { position: absolute; inset: 0; background: rgba(0,0,0,.62); }
.mq-modal-card { position: absolute; left: 50%; top: 50%; transform: translate(-50%,-50%); width: min(560px, 92vw); max-height: 88vh; overflow-y: auto; background: #10202f; border: 1px solid rgba(120,150,190,.3); border-radius: 16px; padding: 16px 14px; box-shadow: 0 20px 60px rgba(0,0,0,.6); }
.mq-modal-h { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.mq-modal-h h3 { margin: 0; font-size: 17px; }
.mq-modal-x { font-size: 22px; color: #9fb6cf; padding: 2px 8px; cursor: pointer; }
</style>
</head>
<body>
<div class="mq-wrap">
    <div class="mq-top">
        <a class="mq-back" href="/games/">‹ 大厅</a>
        <div class="mq-htitle">猜电影</div>
        <span class="mq-lb-btn" id="mqLbBtn">🏆 排行榜</span>
    </div>

    <div class="mq-balance">我的电影票：<b id="mqBal"><?php echo $mBal ?></b> 张</div>

    <div class="mq-panel">
        <div class="mq-stat">
            <span>当前连击：<b id="mqStreak"><?php echo (int)$myState['streak'] ?></b></span>
            <span>今日已答：<b id="mqDay"><?php echo (int)$myState['day_count'] ?></b> / <?php echo MQ_DAILY_LIMIT ?></span>
            <span class="mq-muted">最高连击：<?php echo (int)$myState['best'] ?></span>
        </div>
        <?php if ($qCount === 0) { ?>
            <div class="mq-muted" style="text-align:center">题库为空，请管理员先添加截图/台词题目。</div>
        <?php } else { ?>
            <div style="text-align:center"><span id="mqClueType" class="mq-clue-type" style="display:none"></span></div>
            <div id="mqClue">加载题目中…</div>
            <div class="mq-answer-row">
                <input type="text" class="mq-input" id="mqInput" placeholder="输入电影名…" autocomplete="off" disabled>
                <button type="button" class="mq-btn" id="mqSubmit" disabled>提交</button>
                <button type="button" class="mq-btn" id="mqNext" style="display:none">下一题</button>
            </div>
            <div class="mq-result" id="mqResult"></div>
        <?php } ?>
    </div>

    <div class="mq-panel" style="text-align:center;font-size:13px">
        <span class="mq-muted">看截图或台词猜电影名，答对得电影票，连对越多奖励越高（连击 ×<?php echo MQ_BASE_REWARD ?>，封顶 ×<?php echo MQ_STREAK_CAP ?>）。每日上限 <?php echo MQ_DAILY_LIMIT ?> 题。</span>
    </div>
</div>

<div class="mq-modal" id="mqLbModal">
    <div class="mq-modal-mask" data-close="1"></div>
    <div class="mq-modal-card">
        <div class="mq-modal-h">
            <h3>🏆 猜电影榜单</h3>
            <span class="mq-modal-x" data-close="1">✕</span>
        </div>
        <div class="glb-grid">
            <?php
            echo game_lb_css();
            echo game_lb_table('💰 收益榜', $mqEarn, '累计电影票', function ($r) { return game_lb_money($r['amt']); }, function ($r) { return 'glb-pos'; });
            echo game_lb_table('✅ 答对榜', $mqRight, '答对次数', function ($r) { return number_format((int)$r['amt']) . ' 次'; });
            echo game_lb_table('🔥 连击榜', $mqStreakLb, '最高连击', function ($r) { return number_format((int)$r['amt']) . ' 连'; });
            ?>
        </div>
    </div>
</div>

<?php if ($qCount > 0) { ?>
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

<script>
(function () {
    var modal = document.getElementById('mqLbModal');
    document.getElementById('mqLbBtn').addEventListener('click', function () { modal.classList.add('show'); });
    modal.addEventListener('click', function (e) { if (e.target.getAttribute('data-close')) modal.classList.remove('show'); });
})();
</script>
</body>
</html>
