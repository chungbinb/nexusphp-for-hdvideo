<?php
require '../include/bittorrent.php';
dbconn();
loggedinorreturn();
parked();
require_once ROOT_PATH . 'include/mobile_shell.php';

function tp_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function tp_csrf_token(int $uid): string
{
    $key = 'torrent_promotion:csrf:' . $uid;
    try {
        $redis = \Nexus\Database\NexusDB::redis();
        $token = (string)$redis->get($key);
        if ($token === '') { $token = bin2hex(random_bytes(24)); $redis->setex($key, 3600, $token); }
        return $token;
    } catch (\Throwable $e) {
        if (empty($_SESSION[$key])) $_SESSION[$key] = bin2hex(random_bytes(24));
        return (string)$_SESSION[$key];
    }
}

$uid = (int)$CURUSER['id'];
$torrentId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($torrentId <= 0) bark('无效的种子 ID。');
$error = '';
$success = !empty($_GET['success']) ? '推广购买成功，所选功能已立即生效。' : '';
$token = tp_csrf_token($uid);
try {
    $status = \App\Services\TorrentPromotionService::status($torrentId);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($token, (string)($_POST['token'] ?? ''))) throw new RuntimeException('页面凭证已失效，请刷新后重试。');
        $buyPin = !empty($_POST['buy_pin']);
        $buyFree = !empty($_POST['buy_free']);
        $durationChoice = (string)($_POST['duration_choice'] ?? '12');
        $durationHours = $durationChoice === 'custom' ? (int)($_POST['custom_hours'] ?? 0) : (int)$durationChoice;
        if (!in_array($durationChoice, ['12', '24', '36', 'custom'], true) || $durationHours < 1 || $durationHours > 720) {
            throw new RuntimeException('请选择 1 到 720 小时之间的推广时长。');
        }
        \App\Services\TorrentPromotionService::purchase($uid, $torrentId, $buyPin, $buyFree, $durationHours);
        try { \Nexus\Database\NexusDB::redis()->del('torrent_promotion:csrf:' . $uid); } catch (\Throwable $e) {}
        header('Location: /torrent_promotion.php?id=' . $torrentId . '&success=1');
        exit;
    }
} catch (\Throwable $e) {
    $error = $e->getMessage();
    if (!isset($status)) {
        bark($error ?: '无法读取种子推广状态。');
    }
}

$torrent = $status['torrent'];
$settings = $status['settings'];
$tags = $status['tags'];
$pinCost = (float)$settings['bonus_sticky_cost'];
$freeCost = (float)$settings['bonus_free_cost'];
$pinBaseHours = max(1, (int)$settings['bonus_sticky_days'] * 24);
$freeBaseHours = max(1, (int)$settings['bonus_free_hours']);
$promotionEnabled = (int)$settings['bonus_promotion_enabled'] === 1;
$wallet = (float)$CURUSER['seedbonus'];
$officialLabel = $tags['official'] ? ($tags['source'] ? '源码官组 · 一级置顶' : '非源码官组 · 二级置顶') : '非官组 · 魔力置顶进入三级';
$rewardOptions = array_values(array_filter(array_map('intval', \App\Models\Setting::getBonusRewardOptions()), fn ($value) => $value > 0));
sort($rewardOptions);
$hasRewarded = get_row_count('magic', 'WHERE torrentid=' . sqlesc($torrentId) . ' AND userid=' . sqlesc($uid)) > 0;
$rewardUserCount = 0;
$rewardSum = 0;
$rewardStats = sql_query('SELECT COUNT(DISTINCT userid) AS users, COALESCE(SUM(value), 0) AS total FROM magic WHERE torrentid=' . sqlesc($torrentId)) or sqlerr(__FILE__, __LINE__);
if ($rewardRow = mysql_fetch_assoc($rewardStats)) {
    $rewardUserCount = (int)$rewardRow['users'];
    $rewardSum = (float)$rewardRow['total'];
}
$rewardDisabledReason = '';
if ((int)$torrent->owner === $uid) $rewardDisabledReason = '不能奖励自己发布的种子。';
elseif ($hasRewarded) $rewardDisabledReason = '你已经奖励过这个种子。';
elseif (!$rewardOptions) $rewardDisabledReason = '后台尚未配置奖励选项。';
mobile_std_head('种子魔力推广', 'torrents', 'page-torrent-promotion');
?>
<style>
.tp-wrap{max-width:860px;margin:18px auto 40px;padding:0 16px;color:var(--bili-text,#18191c)}
.tp-card{overflow:hidden;border:1px solid var(--bili-border,#e6e9ef);border-radius:16px;background:var(--bili-surface,#fff);box-shadow:0 12px 34px rgba(24,25,28,.08)}
.tp-head{padding:24px;background:color-mix(in srgb,var(--bili-primary,#00aeec) 12%,var(--bili-surface,#fff));border-bottom:1px solid var(--bili-border,#e6e9ef)}
.tp-head h1{margin:0 0 8px!important;color:var(--bili-text,#18191c)!important;font-size:26px}.tp-head p{margin:0;color:var(--bili-text-secondary,#61666d);line-height:1.7}
.tp-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;padding:18px 24px}.tp-meta div{padding:12px;border:1px solid color-mix(in srgb,var(--bili-primary,#00aeec) 18%,transparent);border-radius:10px;background:color-mix(in srgb,var(--bili-primary,#00aeec) 7%,transparent)}
.tp-meta span{display:block;color:var(--bili-text-secondary,#61666d);font-size:12px}.tp-meta b{display:block;margin-top:4px;overflow-wrap:anywhere;color:var(--bili-text,#18191c);font-size:15px}
.tp-form{padding:4px 24px 24px}.tp-fieldset{min-width:0!important;margin:0!important;padding:0!important;border:0!important}.tp-legend{display:block;width:100%;margin:0 0 10px;padding:0;color:var(--bili-text,#18191c);font-size:14px;font-weight:800}.tp-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.tp-option{position:relative;display:grid!important;grid-template-columns:32px minmax(0,1fr);grid-template-rows:auto auto 1fr;column-gap:12px;min-height:164px;padding:18px 52px 18px 18px;border:2px solid color-mix(in srgb,var(--bili-primary,#00aeec) 18%,transparent);border-radius:12px;background:color-mix(in srgb,var(--bili-primary,#00aeec) 6%,transparent);cursor:pointer;transition:border-color .18s,background .18s}
.tp-option:hover{border-color:var(--bili-primary,#00aeec);background:color-mix(in srgb,var(--bili-primary,#00aeec) 10%,transparent)}.tp-option:has(input:checked){border-color:var(--bili-primary,#00aeec);background:color-mix(in srgb,var(--bili-primary,#00aeec) 14%,transparent)}
.tp-option input{position:absolute;right:16px;top:16px;width:20px;height:20px;accent-color:var(--bili-primary,#00aeec)}.tp-icon{display:inline-flex!important;align-items:center;justify-content:center;grid-column:1;grid-row:1;width:28px!important;height:28px!important;min-width:28px;min-height:28px;color:var(--bili-primary,#00aeec);line-height:0}.tp-icon svg{display:block!important;width:28px!important;height:28px!important;min-width:28px;min-height:28px;max-width:none!important;max-height:none!important;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.tp-option h2{grid-column:2;grid-row:1;margin:2px 0 6px!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important;color:var(--bili-text,#18191c)!important;font-size:18px;line-height:1.35;text-align:left!important}.tp-option p{grid-column:1/-1;grid-row:2;margin:4px 0 0;color:var(--bili-text-secondary,#61666d);line-height:1.65}.tp-price{display:block;grid-column:1/-1;grid-row:3;align-self:end;margin-top:12px;color:var(--bili-primary,#00aeec);font-weight:800}
.tp-duration{margin:0 0 16px}.tp-duration-options{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.tp-duration-choice{display:flex!important;align-items:center;justify-content:center;min-height:44px;border:1px solid color-mix(in srgb,var(--bili-primary,#00aeec) 22%,transparent);border-radius:10px;background:color-mix(in srgb,var(--bili-primary,#00aeec) 6%,transparent);color:var(--bili-text,#18191c);font-weight:800;cursor:pointer}.tp-duration-choice input[type=radio]{position:absolute;opacity:0;pointer-events:none}.tp-duration-choice:has(input[type=radio]:checked){border-color:var(--bili-primary,#00aeec);background:var(--bili-primary,#00aeec);color:#fff}.tp-custom{display:flex;gap:8px;align-items:center}.tp-custom input[type=number]{width:72px;min-height:30px;border:1px solid color-mix(in srgb,var(--bili-primary,#00aeec) 30%,transparent);border-radius:7px;background:var(--bili-surface,#fff);color:var(--bili-text,#18191c);text-align:center}.tp-duration-note{margin:8px 0 0;color:var(--bili-text-secondary,#61666d);font-size:12px;line-height:1.6}
.tp-total{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:16px;padding:14px 16px;border:1px solid color-mix(in srgb,var(--bili-primary,#00aeec) 16%,transparent);border-radius:10px;background:color-mix(in srgb,var(--bili-primary,#00aeec) 7%,transparent)}.tp-total strong{color:var(--bili-primary,#00aeec);font-size:20px}
.tp-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px}.tp-btn{min-height:44px;padding:0 20px;border-radius:9px;font-weight:800;cursor:pointer}.tp-back{display:inline-flex;align-items:center;border:1px solid color-mix(in srgb,var(--bili-primary,#00aeec) 22%,transparent);background:color-mix(in srgb,var(--bili-primary,#00aeec) 7%,transparent);color:var(--bili-text,#18191c)!important}.tp-submit{border:1px solid var(--bili-primary,#00aeec);background:var(--bili-primary,#00aeec);color:#fff}.tp-submit:disabled{opacity:.5;cursor:not-allowed}
.tp-reward{margin-top:16px;padding:20px 24px 24px;border-top:1px solid var(--bili-border,#e6e9ef)}.tp-reward-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:14px}.tp-reward h2{margin:0 0 6px!important;color:var(--bili-text,#18191c)!important;font-size:19px}.tp-reward p{margin:0;color:var(--bili-text-secondary,#61666d);line-height:1.65}.tp-reward-stat{white-space:nowrap;color:var(--bili-primary,#00aeec);font-weight:800}.tp-reward-options{display:flex;flex-wrap:wrap;gap:10px}.tp-reward-btn{min-height:38px;padding:0 14px;border:1px solid var(--bili-primary,#00aeec);border-radius:999px;background:color-mix(in srgb,var(--bili-primary,#00aeec) 9%,transparent);color:var(--bili-primary,#00aeec);font-weight:800;cursor:pointer}.tp-reward-btn:hover{background:var(--bili-primary,#00aeec);color:#fff}.tp-reward-btn:disabled{opacity:.55;cursor:not-allowed}.tp-reward-disabled{color:var(--bili-text-secondary,#61666d);font-weight:700}
.tp-alert{margin:16px 24px 0;padding:11px 14px;border-radius:9px}.tp-alert.error{border:1px solid #d14343;background:rgba(209,67,67,.1);color:#a42828}.tp-alert.success{border:1px solid #16875b;background:rgba(22,135,91,.1);color:#0f7049}
@media(max-width:640px){.tp-wrap{padding:0 12px}.tp-head,.tp-form,.tp-reward{padding-left:16px;padding-right:16px}.tp-meta{grid-template-columns:1fr;padding:16px}.tp-duration-options{grid-template-columns:repeat(2,minmax(0,1fr))}.tp-options{grid-template-columns:1fr}.tp-option{min-height:152px}.tp-actions{flex-direction:column-reverse}.tp-btn{width:100%}.tp-reward-head{display:block}.tp-reward-stat{display:block;margin-top:8px;white-space:normal}}
</style>
<main class="tp-wrap">
<section class="tp-card">
    <header class="tp-head"><h1>种子魔力推广</h1><p>置顶与 Free 是两个独立功能，可单独选择，也可同时购买。重复购买会从当前有效期继续延长。</p></header>
    <?php if ($error): ?><div class="tp-alert error" role="alert"><?php echo tp_h($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="tp-alert success" role="status"><?php echo tp_h($success) ?></div><?php endif; ?>
    <?php if (!$promotionEnabled): ?><div class="tp-alert error" role="status">魔力推广当前未开放。</div><?php endif; ?>
    <div class="tp-meta">
        <div><span>种子</span><b>#<?php echo $torrentId ?> · <?php echo tp_h($torrent->name) ?></b></div>
        <div><span>当前归类</span><b><?php echo tp_h($officialLabel) ?></b></div>
        <div><span>我的魔力</span><b><?php echo number_format($wallet, 1) ?></b></div>
    </div>
    <form method="post" class="tp-form" id="torrentPromotionForm">
        <input type="hidden" name="id" value="<?php echo $torrentId ?>"><input type="hidden" name="token" value="<?php echo tp_h($token) ?>">
        <fieldset class="tp-fieldset tp-duration">
            <legend class="tp-legend">选择推广时长</legend>
            <div class="tp-duration-options">
                <label class="tp-duration-choice"><input type="radio" name="duration_choice" value="12" checked>12h</label>
                <label class="tp-duration-choice"><input type="radio" name="duration_choice" value="24">24h</label>
                <label class="tp-duration-choice"><input type="radio" name="duration_choice" value="36">36h</label>
                <label class="tp-duration-choice tp-custom"><input type="radio" name="duration_choice" value="custom"><span>自定义</span><input type="number" name="custom_hours" id="customHours" min="1" max="720" step="1" value="48" aria-label="自定义小时"></label>
            </div>
            <p class="tp-duration-note">费用按后台配置的基准时长等比例计算，自定义最多 720 小时。</p>
        </fieldset>
        <fieldset class="tp-fieldset"><legend class="tp-legend">选择推广功能</legend><div class="tp-options">
            <label class="tp-option"><input type="checkbox" name="buy_pin" value="1" data-base-cost="<?php echo $pinCost ?>" data-base-hours="<?php echo $pinBaseHours ?>"<?php echo $promotionEnabled ? '' : ' disabled' ?>><span class="tp-icon"><svg viewBox="0 0 24 24"><path d="M8 4h8l-1 6 3 3H6l3-3-1-6zM12 13v7"/></svg></span><h2>魔力置顶</h2><p><?php echo $tags['official'] ? '官组魔力置顶显示在一级置顶之前。' : '非官组魔力置顶进入三级置顶。' ?>按所选时长延长。</p><span class="tp-price"><span data-price-output>0.0</span> 魔力</span></label>
            <label class="tp-option"><input type="checkbox" name="buy_free" value="1" data-base-cost="<?php echo $freeCost ?>" data-base-hours="<?php echo $freeBaseHours ?>"<?php echo $promotionEnabled ? '' : ' disabled' ?>><span class="tp-icon"><svg viewBox="0 0 24 24"><path d="M12 3v18M5 8h10a4 4 0 010 8H7"/></svg></span><h2>种子 Free</h2><p>该种子下载倍率变为 Free，按所选时长延长，不影响置顶选择。</p><span class="tp-price"><span data-price-output>0.0</span> 魔力</span></label>
        </div></fieldset>
        <div class="tp-total"><span>本次合计</span><strong><span id="promotionTotal">0.0</span> 魔力</strong></div>
        <div class="tp-actions"><a class="tp-btn tp-back" href="/details.php?id=<?php echo $torrentId ?>">返回种子详情</a><button class="tp-btn tp-submit" id="promotionSubmit" type="submit" disabled>确认购买</button></div>
    </form>
    <section class="tp-reward" aria-labelledby="torrentRewardTitle">
        <div class="tp-reward-head">
            <div>
                <h2 id="torrentRewardTitle">种子奖励魔力</h2>
                <p>奖励会直接转给发布人，用来吸引更多人气下载该种。</p>
            </div>
            <span class="tp-reward-stat">已奖励 <?php echo number_format($rewardSum, 1) ?> · <?php echo $rewardUserCount ?> 人</span>
        </div>
        <div class="tp-reward-options" id="torrentRewardOptions">
            <?php if ($rewardDisabledReason): ?>
                <span class="tp-reward-disabled"><?php echo tp_h($rewardDisabledReason) ?></span>
            <?php else: foreach ($rewardOptions as $rewardValue): ?>
                <button class="tp-reward-btn" type="button" data-reward-value="<?php echo $rewardValue ?>"<?php echo $rewardValue > $wallet ? ' disabled' : '' ?>>+<?php echo number_format($rewardValue) ?> 魔力</button>
            <?php endforeach; endif; ?>
        </div>
    </section>
</section>
</main>
<script>
(function(){var form=document.getElementById('torrentPromotionForm'),total=document.getElementById('promotionTotal'),submit=document.getElementById('promotionSubmit'),custom=document.getElementById('customHours');if(!form)return;function hours(){var checked=form.querySelector('input[name="duration_choice"]:checked');if(!checked)return 12;if(checked.value==='custom')return Math.max(1,Math.min(720,Number(custom.value||1)));return Number(checked.value||12);}function price(input,duration){var baseCost=Number(input.dataset.baseCost||0),baseHours=Math.max(1,Number(input.dataset.baseHours||1));return Math.round(baseCost*duration/baseHours*10)/10;}function format(value){return value.toLocaleString(undefined,{minimumFractionDigits:1,maximumFractionDigits:1});}function update(){var sum=0,chosen=0,duration=hours();form.querySelectorAll('input[data-base-cost]').forEach(function(input){var current=price(input,duration),box=input.closest('.tp-option'),out=box&&box.querySelector('[data-price-output]');if(out)out.textContent=format(current);if(input.checked){sum+=current;chosen++;}});total.textContent=format(sum);submit.disabled=!chosen;}form.addEventListener('change',function(e){if(e.target===custom)form.querySelector('input[name="duration_choice"][value="custom"]').checked=true;update();});form.addEventListener('input',update);form.addEventListener('submit',function(e){if(submit.disabled||!window.confirm('确认消耗 '+total.textContent+' 魔力购买所选推广 '+hours()+' 小时吗？'))e.preventDefault();});update();})();
(function(){var wrap=document.getElementById('torrentRewardOptions');if(!wrap||!window.jQuery)return;wrap.addEventListener('click',function(e){var btn=e.target.closest('[data-reward-value]');if(!btn||btn.disabled)return;var value=Number(btn.dataset.rewardValue||0);if(!value||!window.confirm('确认奖励发布人 '+value.toLocaleString()+' 魔力吗？'))return;btn.disabled=true;jQuery.post('magic.php',{id:<?php echo $torrentId ?>,value:value},function(res){if(!res||res.ret!==0){alert((res&&res.msg)||'奖励失败，请稍后重试。');btn.disabled=false;return;}alert('奖励成功，感谢支持这个种子。');window.location.reload();},'json').fail(function(){alert('奖励失败，请稍后重试。');btn.disabled=false;});});})();
</script>
<?php mobile_std_foot('torrents'); ?>
