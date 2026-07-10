<?php
require '../include/bittorrent.php';
dbconn();
loggedinorreturn();
parked();

use App\Services\FreeleechPoolService;

function fp_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fp_number($value): string
{
    return number_format((float)$value, 1);
}

function fp_csrf_token(int $uid): string
{
    $redis = \Nexus\Database\NexusDB::redis();
    $key = 'freeleech_pool:csrf:' . $uid;
    $token = (string)$redis->get($key);
    if ($token === '') {
        $token = bin2hex(random_bytes(24));
        $redis->setex($key, 3600, $token);
    }
    return $token;
}

$uid = (int)$CURUSER['id'];
$message = '';
$error = '';
$csrfToken = fp_csrf_token($uid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrfToken, (string)($_POST['token'] ?? ''))) throw new RuntimeException('页面凭证已失效，请刷新后重试。');
        $result = FreeleechPoolService::contribute($uid, (float)($_POST['amount'] ?? 0));
        $accepted = (float)$result['accepted'];
        $message = '成功向站免池投放 ' . fp_number($accepted) . ' 魔力。';
        if ((float)$result['requested'] > $accepted) $message .= ' 本轮只差 ' . fp_number($accepted) . '，超出部分未扣除。';
        if (!empty($result['activated'])) $message .= ' 目标已达成，全站 Free 立即生效！';
        $CURUSER['seedbonus'] = (float)$result['wallet'];
        $csrfToken = fp_csrf_token($uid);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

try {
    $status = FreeleechPoolService::status($uid);
} catch (Throwable $e) {
    stderr('站免池暂不可用', fp_h($e->getMessage()));
}

$active = (bool)$status['active'];
$enabled = (bool)$status['enabled'];
$campaign = $status['campaign'];
$statusText = !$enabled ? '暂停开放' : ($active ? '全站 Free 生效中' : '正在筹集');
$balance = (float)$CURUSER['seedbonus'];

stdhead('全站站免池');
?>
<style>
:root {
    --fp-primary: var(--bili-primary, #7c3aed); --fp-primary-2: var(--bili-accent, #a78bfa);
    --fp-bg: color-mix(in srgb, var(--fp-primary) 5%, var(--bili-bg, #f6f7fb));
    --fp-card: var(--bili-surface, #fff); --fp-soft: color-mix(in srgb, var(--fp-primary) 7%, var(--fp-card));
    --fp-text: var(--bili-text, #172033); --fp-muted: var(--bili-text-secondary, #52627a);
    --fp-line: color-mix(in srgb, var(--fp-primary) 18%, var(--bili-border, #d9e3ef));
    --fp-green: #15895c; --fp-danger: #c83f4d;
    --fp-shadow: 0 18px 45px color-mix(in srgb, var(--fp-primary) 13%, transparent);
}
:root[data-site-theme="night"] {
    --fp-bg: color-mix(in srgb, var(--fp-primary) 7%, #0b1220); --fp-card: #121d2d;
    --fp-soft: color-mix(in srgb, var(--fp-primary) 10%, #182638); --fp-text: #f1f5fb;
    --fp-muted: #afbdd0; --fp-line: color-mix(in srgb, var(--fp-primary) 24%, #2a3d55);
    --fp-green: #55d39a; --fp-danger: #ff7d89; --fp-shadow: 0 22px 55px rgba(0,0,0,.3);
}
.fp-page { min-height: calc(100vh - 90px); padding: 22px 18px 48px; background: radial-gradient(circle at 12% 0, color-mix(in srgb,var(--fp-primary) 14%,transparent), transparent 32%), var(--fp-bg) !important; color: var(--fp-text); }
.fp-wrap { width: min(1160px,100%); margin: 0 auto; }
.fp-top { display:flex; align-items:center; justify-content:space-between; gap:14px; margin-bottom:16px; }
.fp-back { display:inline-flex; align-items:center; gap:7px; min-height:40px; padding:0 13px; border:1px solid var(--fp-line); border-radius:11px; background:var(--fp-card); color:var(--fp-text)!important; text-decoration:none!important; font-weight:700; }
.fp-wallet { padding:10px 14px; border:1px solid var(--fp-line); border-radius:12px; background:var(--fp-card); color:var(--fp-muted); }.fp-wallet b{margin-left:6px;color:var(--fp-primary);font-size:17px;}
.fp-hero { position:relative; overflow:hidden; padding:30px; border:1px solid color-mix(in srgb,var(--fp-primary) 55%,transparent); border-radius:24px; background:linear-gradient(135deg,color-mix(in srgb,var(--fp-primary) 66%,#201834) 0%,color-mix(in srgb,var(--fp-primary) 72%,#332653) 55%,color-mix(in srgb,var(--fp-primary-2) 48%,#254357) 120%); color:#fff; box-shadow:var(--fp-shadow); }
.fp-hero::after { content:""; position:absolute; right:-75px; top:-95px; width:260px; height:260px; border:50px solid rgba(255,255,255,.08); border-radius:50%; }
.fp-hero-main { position:relative; z-index:1; display:flex; align-items:flex-start; justify-content:space-between; gap:25px; }
.fp-kicker { display:inline-flex; align-items:center; gap:8px; margin-bottom:10px; color:rgba(255,255,255,.88); font-size:13px; font-weight:800; letter-spacing:.08em; }.fp-kicker svg{width:20px;height:20px;}
.fp-hero h1 { margin:0; padding:0!important; border:0!important; background:transparent!important; color:#fff!important; font-size:clamp(29px,4vw,45px); line-height:1.1; }.fp-hero p{max-width:700px;margin:12px 0 0;color:#e3e6ff;font-size:15px;line-height:1.75;}
.fp-status { flex:0 0 auto; padding:9px 13px; border:1px solid rgba(255,255,255,.3); border-radius:999px; background:rgba(10,12,35,.2); color:#fff; font-weight:800; }
.fp-progress-head { display:flex; justify-content:space-between; gap:12px; margin:25px 0 9px; font-size:13px; color:#e9e6ff; }.fp-progress-head b{font-size:16px;color:#fff;}
.fp-progress { position:relative; height:19px; overflow:hidden; border:1px solid rgba(255,255,255,.22); border-radius:999px; background:rgba(8,11,36,.32); box-shadow:inset 0 2px 5px rgba(0,0,0,.25); }
.fp-progress > span { position:absolute; inset:0 auto 0 0; display:block; height:100%; min-width:0; margin:0!important; border-radius:inherit; background:linear-gradient(90deg,color-mix(in srgb,var(--fp-primary) 88%,#fff),color-mix(in srgb,var(--fp-primary-2) 74%,#fff)); transform-origin:left center; transition:width .35s ease; }.fp-progress > b{position:absolute;z-index:2;inset:0;display:grid;place-items:center;color:#fff;font-size:11px;text-shadow:0 1px 2px rgba(0,0,0,.75);}
.fp-grid { display:grid; grid-template-columns:minmax(0,1.35fr) minmax(300px,.65fr); gap:16px; margin-top:16px; }
.fp-card { overflow:hidden; border:1px solid var(--fp-line); border-radius:18px; background:var(--fp-card); box-shadow:var(--fp-shadow); }
.fp-card h2 { margin:0; padding:16px 18px!important; border:0!important; border-bottom:1px solid var(--fp-line)!important; border-radius:0!important; background:linear-gradient(90deg,var(--fp-soft),color-mix(in srgb,var(--fp-primary-2) 5%,var(--fp-card)))!important; color:var(--fp-text)!important; font-size:17px; }
.fp-card-body { padding:18px; }
.fp-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; }
.fp-stat { padding:14px; border:1px solid var(--fp-line); border-radius:13px; background:var(--fp-soft); color:var(--fp-muted); font-size:12px; box-shadow:inset 0 2px 0 color-mix(in srgb,var(--fp-primary) 38%,transparent); }.fp-stat b{display:block;margin-top:5px;color:var(--fp-primary);font-size:18px;}
.fp-form { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:10px; margin-top:18px; }.fp-form label{grid-column:1/-1;color:var(--fp-text);font-weight:800;}
.fp-input { width:100%; min-height:48px; box-sizing:border-box; padding:0 14px!important; border:1px solid var(--fp-line)!important; border-radius:11px!important; background:var(--fp-soft)!important; color:var(--fp-text)!important; font-size:16px!important; }
.fp-submit { min-width:150px; min-height:48px; padding:0 20px; border:0; border-radius:11px; background:linear-gradient(135deg,var(--fp-primary),var(--fp-primary-2)); color:#fff; font-weight:900; cursor:pointer; box-shadow:0 9px 20px color-mix(in srgb,var(--fp-primary) 28%,transparent); }.fp-submit:disabled{opacity:.48;cursor:not-allowed;}
.fp-quick { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }.fp-quick button{min-height:36px;padding:0 12px;border:1px solid var(--fp-line);border-radius:9px;background:var(--fp-soft);color:var(--fp-text);font-weight:700;cursor:pointer;transition:border-color .2s,background .2s;}.fp-quick button:hover{border-color:var(--fp-primary);background:color-mix(in srgb,var(--fp-primary) 13%,var(--fp-card));}
.fp-help { margin:12px 0 0; color:var(--fp-muted); font-size:12px; line-height:1.6; }
.fp-alert { margin-bottom:16px; padding:12px 15px; border-radius:12px; font-weight:750; }.fp-alert.ok{border:1px solid rgba(21,137,92,.35);background:rgba(21,137,92,.1);color:var(--fp-green);}.fp-alert.error{border:1px solid rgba(200,63,77,.35);background:rgba(200,63,77,.1);color:var(--fp-danger);}
.fp-countdown { margin-top:17px; padding:15px; border:1px solid rgba(255,255,255,.24); border-radius:13px; background:rgba(8,11,36,.23); color:#e9e6ff; }.fp-countdown b{display:block;margin-top:5px;color:#fff;font-size:25px;letter-spacing:.03em;}
.fp-table { width:100%; border-collapse:collapse; font-size:13px; }.fp-table th,.fp-table td{padding:11px 12px;border-bottom:1px solid var(--fp-line);text-align:left;}.fp-table th{color:var(--fp-muted);font-weight:700;}.fp-table td:last-child,.fp-table th:last-child{text-align:right;}.fp-table .amount{color:var(--fp-primary);font-weight:850;}
.fp-empty { padding:25px 16px; color:var(--fp-muted); text-align:center; }
.fp-note { margin-top:16px; padding:15px 18px; border:1px solid var(--fp-line); border-radius:14px; background:var(--fp-card); color:var(--fp-muted); font-size:13px; line-height:1.7; }.fp-note strong{color:var(--fp-text);}
.fp-back:focus-visible,.fp-input:focus-visible,.fp-submit:focus-visible,.fp-quick button:focus-visible{outline:3px solid color-mix(in srgb,var(--fp-primary) 72%,#fff);outline-offset:2px;}
@media(max-width:880px){.fp-grid{grid-template-columns:1fr}.fp-stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.fp-page{padding:10px 9px 30px}.fp-hero{padding:22px 18px}.fp-hero-main{flex-direction:column}.fp-status{align-self:flex-start}.fp-form{grid-template-columns:1fr}.fp-submit{width:100%}.fp-wallet{font-size:11px}.fp-wallet b{font-size:14px}.fp-card-body{padding:14px}.fp-stat{padding:11px}}
@media(prefers-reduced-motion:reduce){*{transition-duration:.01ms!important;animation-duration:.01ms!important}}
</style>

<main class="fp-page">
    <div class="fp-wrap">
        <div class="fp-top">
            <a class="fp-back" href="torrents.php" aria-label="返回种子列表"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>返回种子</a>
            <div class="fp-wallet">我的魔力余额 <b><?php echo fp_number($balance) ?></b></div>
        </div>

        <?php if ($message !== '') { ?><div class="fp-alert ok" role="status"><?php echo fp_h($message) ?></div><?php } ?>
        <?php if ($error !== '') { ?><div class="fp-alert error" role="alert"><?php echo fp_h($error) ?></div><?php } ?>

        <section class="fp-hero" aria-labelledby="fpTitle">
            <div class="fp-hero-main">
                <div>
                    <div class="fp-kicker"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 9h16v10H4z"/><path d="M7 9V6h10v3"/><path d="M9 14h6M12 11v6"/></svg>COMMUNITY FREELEECH POOL</div>
                    <h1 id="fpTitle">全站站免池</h1>
                    <p>每一份魔力都会进入当前公共站免池。累计达到目标后，系统立即为全站所有种子开启 Free，下载量在有效期内不计入个人下载统计。</p>
                </div>
                <span class="fp-status"><?php echo fp_h($statusText) ?></span>
            </div>
            <div class="fp-progress-head"><span>本轮已筹集 <b><?php echo fp_number($status['collected']) ?></b></span><span>目标 <b><?php echo fp_number($status['goal']) ?></b></span></div>
            <div class="fp-progress" role="progressbar" aria-label="站免池筹集进度" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo fp_h($status['percent']) ?>"><span style="width:<?php echo fp_h($status['percent']) ?>%"></span><b><?php echo fp_h($status['percent']) ?>%</b></div>
            <?php if ($active) { ?><div class="fp-countdown">全站 Free 剩余时间<b id="fpCountdown" data-until="<?php echo (int)$status['active_until'] ?>">计算中…</b></div><?php } ?>
        </section>

        <div class="fp-grid">
            <section class="fp-card">
                <h2>投放魔力</h2>
                <div class="fp-card-body">
                    <div class="fp-stats">
                        <div class="fp-stat">还差魔力<b><?php echo fp_number($status['remaining']) ?></b></div>
                        <div class="fp-stat">站免时长<b><?php echo (int)$status['duration_hours'] ?> 小时</b></div>
                        <div class="fp-stat">我的贡献<b><?php echo fp_number($status['my_total']) ?></b></div>
                        <div class="fp-stat">最低投放<b><?php echo fp_number($status['min_contribution']) ?></b></div>
                    </div>
                    <form class="fp-form" method="post" action="freeleech_pool.php">
                        <label for="fpAmount">本次投放魔力</label>
                        <input type="hidden" name="token" value="<?php echo fp_h($csrfToken) ?>">
                        <input class="fp-input" id="fpAmount" name="amount" type="number" min="<?php echo fp_h($status['min_contribution']) ?>" max="<?php echo fp_h(max($status['remaining'], $status['min_contribution'])) ?>" step="0.1" inputmode="decimal" required <?php echo (!$enabled || $active) ? 'disabled' : '' ?> aria-describedby="fpHelp">
                        <button class="fp-submit" type="submit" <?php echo (!$enabled || $active) ? 'disabled' : '' ?>><?php echo $active ? 'Free 生效中' : ($enabled ? '投入站免池' : '暂未开放') ?></button>
                    </form>
                    <?php if ($enabled && !$active) { ?><div class="fp-quick" aria-label="快捷投放金额">
                        <?php foreach ([100, 1000, 10000, 100000] as $quick) { ?><button type="button" data-amount="<?php echo $quick ?>"><?php echo number_format($quick) ?></button><?php } ?>
                    </div><?php } ?>
                    <p class="fp-help" id="fpHelp">实际扣除不会超过本轮剩余目标，超出部分自动保留在你的账户中。投放后不可撤回。</p>
                </div>
            </section>

            <section class="fp-card">
                <h2>贡献排行榜</h2>
                <?php if (!$status['top']) { ?><div class="fp-empty">本轮还没有贡献，等你点亮第一份进度。</div><?php } else { ?>
                <table class="fp-table"><thead><tr><th>玩家</th><th>贡献魔力</th></tr></thead><tbody>
                    <?php foreach ($status['top'] as $row) { ?><tr><td><?php echo fp_h($row['username']) ?></td><td class="amount"><?php echo fp_number($row['amount']) ?></td></tr><?php } ?>
                </tbody></table><?php } ?>
            </section>

            <section class="fp-card">
                <h2>最近投放</h2>
                <?php if (!$status['recent']) { ?><div class="fp-empty">暂无投放记录</div><?php } else { ?>
                <table class="fp-table"><thead><tr><th>玩家</th><th>投放</th></tr></thead><tbody>
                    <?php foreach ($status['recent'] as $row) { ?><tr><td><?php echo fp_h($row['username']) ?><br><small><?php echo fp_h($row['created_at']) ?></small></td><td class="amount">+<?php echo fp_number($row['amount']) ?></td></tr><?php } ?>
                </tbody></table><?php } ?>
            </section>

            <section class="fp-card">
                <h2>站免规则</h2>
                <div class="fp-card-body fp-help">
                    <p><strong>达标即生效：</strong>累计魔力达到本轮目标后，无需人工操作，立即开启全站 Free。</p>
                    <p><strong>覆盖所有种子：</strong>站免期间下载倍率统一为 0，原有上传倍率继续按促销规则计算。</p>
                    <p><strong>自动开启下一轮：</strong>本轮站免到期后，系统自动建立新的筹集轮次。</p>
                </div>
            </section>
        </div>
        <div class="fp-note"><strong>说明：</strong>站免池只扣除实际接受的魔力，并在魔力流水中记录“站免池贡献”。后台可设置开放状态、本轮目标、Free 时长和最低投放金额。</div>
    </div>
</main>
<script>
(function(){
    var input=document.getElementById('fpAmount');
    document.querySelectorAll('.fp-quick button[data-amount]').forEach(function(button){button.addEventListener('click',function(){if(!input)return;input.value=button.getAttribute('data-amount');input.focus();});});
    var countdown=document.getElementById('fpCountdown');
    if(countdown){var until=Number(countdown.getAttribute('data-until'))*1000;var paint=function(){var left=Math.max(0,Math.floor((until-Date.now())/1000));var d=Math.floor(left/86400),h=Math.floor(left%86400/3600),m=Math.floor(left%3600/60),s=left%60;countdown.textContent=(d?d+' 天 ':'')+String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');if(left===0)location.reload();};paint();setInterval(paint,1000);}
})();
</script>
<?php stdfoot(); ?>
