<?php
/**
 * 签到宝箱 —— 手机版（竖屏）。自带完整 HTML 头尾，不经过桌面版 stdhead。
 * 由 chest/index.php 在手机 UA 时 require；开宝箱 claim 的 AJAX 仍在 index.php。
 * 复用同一套前端逻辑（元素 id 与桌面版一致：cbBal / cbMsg / .cb-btn[data-m] / .cb-chest）；
 * 榜单收进右上角悬浮按钮弹窗。
 * 进入此页面前 index.php 已计算 $days / $streakStart / $state / $mask。
 */
if (!defined('CH_STATE_TABLE')) { return; }

$mBal = number_format((float)($CURUSER['seedbonus'] ?? 0), 1);

$chStreak = game_lb_run("SELECT `a`.`uid` AS uid, `u`.`username` AS username, `a`.`days` AS amt FROM `attendance` `a` INNER JOIN `users` `u` ON `u`.`id` = `a`.`uid` WHERE `a`.`days` > 0 ORDER BY `a`.`days` DESC LIMIT 10");
$chTotal = game_lb_run("SELECT `a`.`uid` AS uid, `u`.`username` AS username, `a`.`total_days` AS amt FROM `attendance` `a` INNER JOIN `users` `u` ON `u`.`id` = `a`.`uid` WHERE `a`.`total_days` > 0 ORDER BY `a`.`total_days` DESC LIMIT 10");
$chBonus = game_lb_bonus('profit', '[签到宝箱]', 10);
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<title>签到宝箱</title>
<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; }
body { background: #0c1622; color: #e6eef8; font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", Helvetica, Arial, sans-serif; }
a { color: inherit; text-decoration: none; }

.cb-wrap { max-width: 640px; margin: 0 auto; padding: 0 14px calc(40px + env(safe-area-inset-bottom)); }

/* 顶栏 */
.cb-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 2px 8px; position: sticky; top: 0; background: #0c1622; z-index: 5; }
.cb-back { display: inline-flex; align-items: center; gap: 4px; font-size: 15px; color: #9fb6cf; }
.cb-htitle { font-size: 18px; font-weight: 800; }
.cb-lb-btn { font-size: 13px; font-weight: 800; color: #ffe9a8; background: rgba(184,134,11,.18); border: 1px solid rgba(255,210,120,.4); border-radius: 999px; padding: 6px 12px; cursor: pointer; }

.cb-balance { font-size: 14px; font-weight: 700; text-align: center; margin: 4px 0 12px; color: #cfe0f2; }
.cb-balance b { color: #ffd770; font-size: 17px; }
.cb-muted { color: #7f93ab; }
.cb-muted a { color: #9fb6cf; text-decoration: underline; }

.cb-hero { border: 1px solid rgba(120,150,190,.34); border-radius: 16px; padding: 18px 14px; margin-bottom: 14px; background: linear-gradient(135deg,#3a2a10,#1c1206); text-align: center; }
.cb-streak { font-size: 19px; font-weight: 900; color: #ffd770; }
.cb-sub { font-size: 13px; color: #cdbb95; margin-top: 6px; }
.cb-sub a { color: #ffe9a8; text-decoration: underline; }

.cb-chests { display: grid; grid-template-columns: 1fr; gap: 12px; margin-bottom: 14px; }
.cb-chest { border: 1px solid rgba(120,150,190,.34); border-radius: 14px; padding: 16px 14px; background: rgba(30,60,100,.12); display: flex; align-items: center; gap: 14px; }
.cb-chest.open { border-color: #2ecc71; background: rgba(46,204,113,.12); }
.cb-chest.done { opacity: .55; }
.cb-chest .ico { font-size: 44px; line-height: 1; flex: 0 0 auto; }
.cb-chest .info { flex: 1 1 auto; min-width: 0; }
.cb-chest .ms { font-weight: 900; font-size: 16px; }
.cb-chest .rg { font-size: 12px; color: #7f93ab; margin-top: 3px; }
.cb-chest .act { flex: 0 0 auto; }
.cb-btn { padding: 12px 18px; font-weight: 900; font-size: 15px; cursor: pointer; border-radius: 12px; border: 1px solid #b8860b; background: linear-gradient(180deg,#e0a82a,#b8860b); color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,.4); white-space: nowrap; }
.cb-btn:disabled { opacity: .5; cursor: not-allowed; background: #2a3a4c; border-color: #2a3a4c; box-shadow: none; }
.cb-done-tag { color: #7f93ab; font-size: 13px; font-weight: 700; white-space: nowrap; }
.cb-msg { text-align: center; min-height: 24px; margin: 6px 0 4px; font-weight: 900; font-size: 16px; }

/* 榜单弹窗 */
.cb-modal { position: fixed; inset: 0; z-index: 300; display: none; }
.cb-modal.show { display: block; }
.cb-modal-mask { position: absolute; inset: 0; background: rgba(0,0,0,.62); }
.cb-modal-card { position: absolute; left: 50%; top: 50%; transform: translate(-50%,-50%); width: min(560px, 92vw); max-height: 88vh; overflow-y: auto; background: #10202f; border: 1px solid rgba(120,150,190,.3); border-radius: 16px; padding: 16px 14px; box-shadow: 0 20px 60px rgba(0,0,0,.6); }
.cb-modal-h { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.cb-modal-h h3 { margin: 0; font-size: 17px; }
.cb-modal-x { font-size: 22px; color: #9fb6cf; padding: 2px 8px; cursor: pointer; }
<?php echo game_lb_css(); ?>
</style>
</head>
<body>
<div class="cb-wrap">
    <div class="cb-top">
        <a class="cb-back" href="/games/">‹ 大厅</a>
        <div class="cb-htitle">签到宝箱</div>
        <span class="cb-lb-btn" id="cbLbBtn">🏆 排行榜</span>
    </div>

    <div class="cb-balance">我的电影票：<b id="cbBal"><?php echo $mBal ?></b> 张</div>

    <div class="cb-hero">
        <div class="cb-streak">🔥 当前连续签到 <?php echo (int)$days ?> 天</div>
        <?php if ($days <= 0) { ?>
            <div class="cb-sub">你还没有连续签到记录，先去 <a href="/attendance.php">签到</a> 吧。</div>
        <?php } else { ?>
            <div class="cb-sub">连续签到解锁宝箱，开出随机电影票。<a href="/attendance.php">去签到 »</a></div>
        <?php } ?>
    </div>

    <div class="cb-chests">
        <?php $i = 0; foreach (CH_MILESTONES as $ms => $tier) {
            $claimed = (bool)($mask & (1 << $i));
            $unlocked = $days >= $ms;
            $cls = $claimed ? 'done' : ($unlocked ? 'open' : '');
        ?>
            <div class="cb-chest <?php echo $cls ?>">
                <div class="ico"><?php echo $claimed ? '✅' : ($unlocked ? '🎁' : '🔒') ?></div>
                <div class="info">
                    <div class="ms">连续 <?php echo $ms ?> 天</div>
                    <div class="rg">随机：电影票 / 上传量 / 下载减免</div>
                </div>
                <div class="act">
                    <?php if ($claimed) { ?>
                        <span class="cb-done-tag">本轮已领</span>
                    <?php } elseif ($unlocked) { ?>
                        <button type="button" class="cb-btn" data-m="<?php echo $i ?>">开宝箱</button>
                    <?php } else { ?>
                        <button type="button" class="cb-btn" disabled>还差 <?php echo $ms - $days ?> 天</button>
                    <?php } ?>
                </div>
            </div>
        <?php $i++; } ?>
    </div>
    <div class="cb-msg" id="cbMsg"></div>
</div>

<div class="cb-modal" id="cbLbModal">
    <div class="cb-modal-mask" data-close="1"></div>
    <div class="cb-modal-card">
        <div class="cb-modal-h">
            <h3>🏆 签到榜单</h3>
            <span class="cb-modal-x" data-close="1">✕</span>
        </div>
        <div class="glb-grid">
            <?php
            echo game_lb_table('🔥 连续签到榜', $chStreak, '连续天数',
                function ($r) { return number_format((int)$r['amt']) . ' 天'; });
            echo game_lb_table('🏅 累计签到榜', $chTotal, '累计天数',
                function ($r) { return number_format((int)$r['amt']) . ' 天'; });
            echo game_lb_table('🎁 宝箱收益榜', $chBonus, '累计电影票',
                function ($r) { return game_lb_money($r['amt']); },
                function ($r) { return 'glb-pos'; });
            ?>
        </div>
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
                    msg.innerHTML = '<span style="color:#16a34a">🎉 开出 ' + d.label + '！</span>';
                    document.getElementById('cbBal').textContent = (Math.round(d.balance * 10) / 10).toFixed(1);
                    var chest = b.closest('.cb-chest');
                    chest.classList.remove('open'); chest.classList.add('done');
                    chest.querySelector('.ico').textContent = '✅';
                    b.outerHTML = '<div class="cb-muted" style="margin-top:8px">本轮已领</div>';
                    busy = false;
                }).catch(function () { document.getElementById('cbMsg').textContent = '网络错误'; b.disabled = false; busy = false; });
        });
    });

    // 榜单弹窗
    var modal = document.getElementById('cbLbModal');
    document.getElementById('cbLbBtn').addEventListener('click', function () { modal.classList.add('show'); });
    modal.addEventListener('click', function (e) { if (e.target.getAttribute('data-close')) modal.classList.remove('show'); });
})();
</script>
</body>
</html>
