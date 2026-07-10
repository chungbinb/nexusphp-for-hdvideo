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
        \App\Services\TorrentPromotionService::purchase($uid, $torrentId, $buyPin, $buyFree);
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
$promotionEnabled = (int)$settings['bonus_promotion_enabled'] === 1;
$wallet = (float)$CURUSER['seedbonus'];
$officialLabel = $tags['official'] ? ($tags['source'] ? '源码官组 · 一级置顶' : '非源码官组 · 二级置顶') : '非官组 · 魔力置顶进入三级';
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
.tp-total{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:16px;padding:14px 16px;border:1px solid color-mix(in srgb,var(--bili-primary,#00aeec) 16%,transparent);border-radius:10px;background:color-mix(in srgb,var(--bili-primary,#00aeec) 7%,transparent)}.tp-total strong{color:var(--bili-primary,#00aeec);font-size:20px}
.tp-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px}.tp-btn{min-height:44px;padding:0 20px;border-radius:9px;font-weight:800;cursor:pointer}.tp-back{display:inline-flex;align-items:center;border:1px solid color-mix(in srgb,var(--bili-primary,#00aeec) 22%,transparent);background:color-mix(in srgb,var(--bili-primary,#00aeec) 7%,transparent);color:var(--bili-text,#18191c)!important}.tp-submit{border:1px solid var(--bili-primary,#00aeec);background:var(--bili-primary,#00aeec);color:#fff}.tp-submit:disabled{opacity:.5;cursor:not-allowed}
.tp-alert{margin:16px 24px 0;padding:11px 14px;border-radius:9px}.tp-alert.error{border:1px solid #d14343;background:rgba(209,67,67,.1);color:#a42828}.tp-alert.success{border:1px solid #16875b;background:rgba(22,135,91,.1);color:#0f7049}
@media(max-width:640px){.tp-wrap{padding:0 12px}.tp-head,.tp-form{padding-left:16px;padding-right:16px}.tp-meta{grid-template-columns:1fr;padding:16px}.tp-options{grid-template-columns:1fr}.tp-option{min-height:152px}.tp-actions{flex-direction:column-reverse}.tp-btn{width:100%}}
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
        <fieldset class="tp-fieldset"><legend class="tp-legend">选择推广功能</legend><div class="tp-options">
            <label class="tp-option"><input type="checkbox" name="buy_pin" value="1" data-cost="<?php echo $pinCost ?>"<?php echo $promotionEnabled ? '' : ' disabled' ?>><span class="tp-icon"><svg viewBox="0 0 24 24"><path d="M8 4h8l-1 6 3 3H6l3-3-1-6zM12 13v7"/></svg></span><h2>魔力置顶</h2><p><?php echo $tags['official'] ? '官组魔力置顶显示在一级置顶之前。' : '非官组魔力置顶进入三级置顶。' ?>每次延长 <?php echo (int)$settings['bonus_sticky_days'] ?> 天。</p><span class="tp-price"><?php echo number_format($pinCost, 1) ?> 魔力</span></label>
            <label class="tp-option"><input type="checkbox" name="buy_free" value="1" data-cost="<?php echo $freeCost ?>"<?php echo $promotionEnabled ? '' : ' disabled' ?>><span class="tp-icon"><svg viewBox="0 0 24 24"><path d="M12 3v18M5 8h10a4 4 0 010 8H7"/></svg></span><h2>种子 Free</h2><p>该种子下载倍率变为 Free，每次延长 <?php echo (int)$settings['bonus_free_hours'] ?> 小时，不影响置顶选择。</p><span class="tp-price"><?php echo number_format($freeCost, 1) ?> 魔力</span></label>
        </div></fieldset>
        <div class="tp-total"><span>本次合计</span><strong><span id="promotionTotal">0.0</span> 魔力</strong></div>
        <div class="tp-actions"><a class="tp-btn tp-back" href="/details.php?id=<?php echo $torrentId ?>">返回种子详情</a><button class="tp-btn tp-submit" id="promotionSubmit" type="submit" disabled>确认购买</button></div>
    </form>
</section>
</main>
<script>
(function(){var form=document.getElementById('torrentPromotionForm'),total=document.getElementById('promotionTotal'),submit=document.getElementById('promotionSubmit');if(!form)return;function update(){var sum=0,chosen=0;form.querySelectorAll('input[data-cost]').forEach(function(input){if(input.checked){sum+=Number(input.dataset.cost||0);chosen++;}});total.textContent=sum.toLocaleString(undefined,{minimumFractionDigits:1,maximumFractionDigits:1});submit.disabled=!chosen;}form.addEventListener('change',update);form.addEventListener('submit',function(e){if(submit.disabled||!window.confirm('确认消耗 '+total.textContent+' 魔力购买所选推广吗？'))e.preventDefault();});update();})();
</script>
<?php mobile_std_foot('torrents'); ?>
