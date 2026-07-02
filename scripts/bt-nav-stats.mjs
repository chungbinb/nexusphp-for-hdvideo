// Surface account stats inline in the top nav (before the avatar widget).
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/include/functions.php';
const cfg = JSON.parse(fs.readFileSync(configPath, 'utf8'));
let panel = String(cfg.panel_url).replace(/\/+$/, ''); if (!/^https?:\/\//.test(panel)) panel = 'https://' + panel;
const u = new URL(panel); const apiKey = String(cfg.api_key);
const md5 = (t) => crypto.createHash('md5').update(t, 'utf8').digest('hex');
function call(endpoint, extra = {}) {
  return new Promise((resolve, reject) => {
    const rt = Math.floor(Date.now() / 1000); const token = md5(String(rt) + md5(apiKey));
    const body = new URLSearchParams({ request_time: String(rt), request_token: token, ...extra }).toString();
    const req = https.request({ host: u.hostname, port: u.port || 443, path: endpoint, method: 'POST', rejectUnauthorized: false,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(body) }, timeout: 60000 },
      (res) => { let d = ''; res.on('data', c => d += c); res.on('end', () => resolve(d)); });
    req.on('error', reject); req.on('timeout', () => req.destroy(new Error('timeout'))); req.write(body); req.end();
  });
}
const getFile = async (p) => { const j = JSON.parse(await call('/files?action=GetFileBody', { action: 'GetFileBody', path: p })); if (!j.status) throw new Error('get failed'); return j.data; };
const putFile = (p, c) => call('/files?action=SaveFileBody', { action: 'SaveFileBody', path: p, data: c, encoding: 'utf-8' });

const ANCHOR = `<div id="top-account-widget">`;
const STRIP = `<div id="top-stats-bar" class="top-stats-bar">
	<span class="top-stat"><i><?php echo $lang_functions['text_ratio'] ?? '分享率' ?></i><b><?php echo $ratio ?></b></span>
	<a class="top-stat" href="userdetails.php?id=<?php echo (int)$CURUSER['id'] ?>"><i class="up"><?php echo $lang_functions['text_uploaded'] ?? '上传' ?></i><b><?php echo mksize($CURUSER['uploaded']) ?></b></a>
	<a class="top-stat" href="userdetails.php?id=<?php echo (int)$CURUSER['id'] ?>"><i class="dn"><?php echo $lang_functions['text_downloaded'] ?? '下载' ?></i><b><?php echo mksize($CURUSER['downloaded']) ?></b></a>
	<a class="top-stat" href="mybonus.php"><i><?php echo $lang_functions['text_bonus'] ?? '电影票' ?></i><b><?php echo number_format($CURUSER['seedbonus'], 1) ?></b></a>
	<span class="top-stat"><i><?php echo $lang_functions['text_active_torrents'] ?? '活动' ?></i><b><em class="up"><?php echo (int)($activeseed ?? 0) ?></em>/<em class="dn"><?php echo (int)($activeleech ?? 0) ?></em></b></span>
	<a class="top-stat" href="invite.php?id=<?php echo (int)$CURUSER['id'] ?>"><i>邀请</i><b><?php echo (int)($topInviteCount ?? 0) ?></b></a>
	<a class="top-stat" href="myhr.php"><i>H&amp;R</i><b><?php echo (int)($topHrCount ?? 0) ?></b></a>
	<a class="top-stat" href="claim.php?uid=<?php echo (int)$CURUSER['id'] ?>"><i>认领</i><b><?php echo (int)($topClaimCount ?? 0) ?></b></a>
</div>
` + ANCHOR;

let php = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_functions_navstats_${stamp}.php`), php, 'utf8');
if (php.includes('id="top-stats-bar"')) { console.log('>>> already added'); process.exit(0); }
const n = php.split(ANCHOR).length - 1;
if (n !== 1) { console.error(`ABORT: anchor count ${n}`); process.exit(2); }
php = php.replace(ANCHOR, STRIP);
console.log('save:', (await putFile(REMOTE, php)).slice(0, 50));
const v = await getFile(REMOTE);
console.log(v.includes('id="top-stats-bar"') ? '>>> VERIFIED' : '>>> WARNING verify');
process.exit(0);
