<?php
/**
 * 答题挑战 —— 手机版（竖屏 App 风格）。自带完整 HTML 头尾，不经过桌面版 stdhead。
 * 由 quiz/index.php 在手机 UA 时 require；取题 / 答题的 AJAX 仍在 index.php。
 * 复用同一套前端逻辑（元素 id 与桌面版一致），换成手机皮肤。
 * 本游戏没有榜单，故不渲染「排行榜」按钮 / 弹窗；题库管理为电脑版功能。
 */
if (!defined('QZ_BUSINESS_TYPE')) { return; }

$mBal = number_format((float)($CURUSER['seedbonus'] ?? 0), 1);
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<title>答题挑战</title>
<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; }
body { background: #0c1622; color: #e6eef8; font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif; }
a { color: inherit; text-decoration: none; }

.qz-wrap { max-width: 640px; margin: 0 auto; padding: 0 14px calc(40px + env(safe-area-inset-bottom)); }

/* 顶栏 */
.qz-top { position: sticky; top: 0; z-index: 5; display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 2px 10px; background: linear-gradient(180deg, #0c1622 70%, rgba(12,22,34,0)); }
.qz-back { display: inline-flex; align-items: center; gap: 4px; font-size: 14px; color: #9fb6cf; }
.qz-back svg { width: 18px; height: 18px; }
.qz-ttl { font-size: 17px; font-weight: 900; letter-spacing: 1px; }
.qz-top .sp { width: 52px; } /* 右侧占位，使标题居中（无榜单按钮） */

/* 余额 */
.qz-bal { text-align: center; font-size: 13px; color: #9fb6cf; margin: 2px 0 14px; }
.qz-bal b { color: #ffd86b; font-size: 15px; }

/* 统计 */
.qz-stat { display: flex; gap: 10px; margin-bottom: 14px; }
.qz-stat .cell { flex: 1; background: rgba(40,70,110,.28); border: 1px solid rgba(120,150,190,.3); border-radius: 12px; padding: 10px 6px; text-align: center; }
.qz-stat .cell .v { font-size: 20px; font-weight: 900; color: #2ecc71; }
.qz-stat .cell .v.amber { color: #ffd86b; }
.qz-stat .cell .v.mut { color: #cfe0f2; }
.qz-stat .cell .k { font-size: 11px; color: #8ea4bd; margin-top: 2px; }

/* 提示 */
.qz-msg { padding: 10px 12px; border-radius: 10px; margin-bottom: 14px; font-weight: 700; background: rgba(220,60,70,.18); color: #ff9a9a; text-align: center; }
.qz-tip { font-size: 12px; color: #6f87a3; text-align: center; line-height: 1.6; margin-bottom: 14px; }
.qz-admin { text-align: center; margin-bottom: 14px; }
.qz-admin a { display: inline-block; font-size: 12px; color: #9fb6cf; border: 1px solid rgba(120,150,190,.35); border-radius: 999px; padding: 5px 14px; }

/* 题卡 */
.qz-card { background: rgba(30,60,100,.22); border: 1px solid rgba(120,150,190,.32); border-radius: 16px; padding: 18px 16px 16px; box-shadow: 0 8px 26px rgba(0,0,0,.35); }
.qz-q { font-size: 20px; font-weight: 800; line-height: 1.5; min-height: 30px; margin-bottom: 18px; text-align: center; }
.qz-opts { display: grid; gap: 12px; }
.qz-opt { display: flex; align-items: center; gap: 12px; text-align: left; width: 100%; padding: 15px 14px; border: 1px solid rgba(120,150,190,.4); border-radius: 14px; background: rgba(255,255,255,.05); color: #e6eef8; font-size: 16px; font-weight: 600; cursor: pointer; transition: transform .05s, background .15s, border-color .15s; }
.qz-opt:active { transform: scale(.985); }
.qz-opt .lab { flex: none; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 900; background: rgba(120,150,190,.28); color: #cfe0f2; }
.qz-opt.right { background: rgba(46,204,113,.22); border-color: #2ecc71; }
.qz-opt.right .lab { background: #2ecc71; color: #fff; }
.qz-opt.wrong { background: rgba(220,60,70,.2); border-color: #c0392b; }
.qz-opt.wrong .lab { background: #c0392b; color: #fff; }
.qz-opt:disabled { cursor: default; }

.qz-result { margin-top: 16px; text-align: center; font-size: 16px; font-weight: 900; min-height: 22px; }

.qz-next { display: none; width: 100%; margin-top: 16px; padding: 15px; border: none; border-radius: 14px; background: #2ecc71; color: #fff; font-size: 17px; font-weight: 900; cursor: pointer; box-shadow: 0 4px 14px rgba(46,204,113,.4); }
.qz-next:active { transform: scale(.99); }
</style>
</head>
<body>
<div class="qz-wrap">
    <div class="qz-top">
        <a class="qz-back" href="/games/">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"></path></svg>
            大厅
        </a>
        <div class="qz-ttl">答题挑战</div>
        <span class="sp"></span>
    </div>

    <div class="qz-bal">我的电影票：<b id="qzBal"><?php echo $mBal ?></b> 张</div>

    <?php if ($error) { ?><div class="qz-msg"><?php echo htmlspecialchars($error) ?></div><?php } ?>

    <div class="qz-stat">
        <div class="cell"><div class="v" id="qzStreak"><?php echo (int)$myState['streak'] ?></div><div class="k">当前连击</div></div>
        <div class="cell"><div class="v amber"><span id="qzDay"><?php echo (int)$myState['day_count'] ?></span>/<?php echo QZ_DAILY_LIMIT ?></div><div class="k">今日已答</div></div>
        <div class="cell"><div class="v mut"><?php echo (int)$myState['best'] ?></div><div class="k">历史最高</div></div>
    </div>

    <div class="qz-tip">答对得电影票，连对越多奖励越高（连击 ×<?php echo QZ_BASE_REWARD ?>，封顶 ×<?php echo QZ_STREAK_CAP ?>）。</div>

    <?php if ($isAdmin) { ?>
        <div class="qz-admin"><a href="/games/quiz/?view=admin&pc=1">题库管理（电脑版）</a></div>
    <?php } ?>

    <?php if ($qCount === 0) { ?>
        <div class="qz-card"><div class="qz-q" style="font-size:15px;color:#8ea4bd">题库为空，请管理员先添加题目。</div></div>
    <?php } else { ?>
        <div class="qz-card">
            <div class="qz-q" id="qzQ">加载题目中…</div>
            <div class="qz-opts" id="qzOpts"></div>
            <div class="qz-result" id="qzResult"></div>
            <button type="button" class="qz-next" id="qzNext">下一题</button>
        </div>
    <?php } ?>
</div>

<?php if ($qCount > 0) { ?>
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
            optsEl.innerHTML = d.options.map(function (o, i) { return '<button class="qz-opt" data-i="' + i + '"><span class="lab">' + labels[i] + '</span><span>' + esc(o) + '</span></button>'; }).join('');
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
                resEl.innerHTML = d.correct ? ('<span style="color:#2ecc71">✅ 答对！连击 ' + d.streak + '，奖励 +' + d.reward + ' 电影票</span>') : '<span style="color:#ff6b6b">❌ 答错了，连击清零</span>';
                nextBtn.style.display = '';
            }).catch(function () { resEl.textContent = '提交失败'; nextBtn.style.display = ''; });
    }
    nextBtn.addEventListener('click', loadQ);
    loadQ();
})();
</script>
<?php } ?>
</body>
</html>
