// Relabel the lucky-draw plugin currency from 魔力 to 电影票 (display only — 电影票 IS seedbonus).
// zh_CN: 魔力 -> 电影票 ; zh_TW: 魔力 -> 電影票. Idempotent + verified.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const cfg = JSON.parse(fs.readFileSync(configPath, 'utf8'));
let panel = String(cfg.panel_url).replace(/\/+$/, '');
if (!/^https?:\/\//.test(panel)) panel = 'https://' + panel;
const u = new URL(panel);
const apiKey = String(cfg.api_key);
const md5 = (t) => crypto.createHash('md5').update(t, 'utf8').digest('hex');
function call(endpoint, extra = {}) {
  return new Promise((resolve, reject) => {
    const rt = Math.floor(Date.now() / 1000);
    const token = md5(String(rt) + md5(apiKey));
    const body = new URLSearchParams({ request_time: String(rt), request_token: token, ...extra }).toString();
    const req = https.request({
      host: u.hostname, port: u.port || 443, path: endpoint, method: 'POST', rejectUnauthorized: false,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(body) }, timeout: 60000,
    }, (res) => { let d = ''; res.on('data', (c) => (d += c)); res.on('end', () => resolve({ status: res.statusCode, body: d })); });
    req.on('error', reject); req.on('timeout', () => req.destroy(new Error('timeout')));
    req.write(body); req.end();
  });
}
const getFile = async (p) => { const r = await call('/files?action=GetFileBody', { action: 'GetFileBody', path: p }); const j = JSON.parse(r.body); if (!j.status) throw new Error('GetFileBody failed: ' + p); return j.data; };
const putFile = async (p, c) => (await call('/files?action=SaveFileBody', { action: 'SaveFileBody', path: p, data: c, encoding: 'utf-8' })).body;

const base = '/www/wwwroot/hdvideo.top/vendor/xiaomlove/nexusphp-lucky-draw/resources/lang';
const jobs = [
  { remote: `${base}/zh_CN/lucky-draw.php`, from: '魔力', to: '电影票' },
  { remote: `${base}/zh_TW/lucky-draw.php`, from: '魔力', to: '電影票' },
];

const apply = process.argv.includes('--apply');
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
for (const job of jobs) {
  console.log(`\n=== ${job.remote.split('/lang/')[1]} : ${job.from} -> ${job.to} ===`);
  let content = await getFile(job.remote);
  const count = content.split(job.from).length - 1;
  console.log('size', content.length, '| occurrences of', job.from, '=', count);
  fs.writeFileSync(path.join(__dirname, `_backup_luckydraw_${job.remote.split('/lang/')[1].replace(/\//g, '_')}_${stamp}`), content, 'utf8');
  if (count === 0) { console.log('>>> No', job.from, 'left — already relabeled. Skipping.'); continue; }
  const patched = content.split(job.from).join(job.to);
  console.log('Will replace all', count, 'occurrences.');
  if (!apply) { console.log('[DRY RUN] not writing'); continue; }
  console.log('Uploading...');
  console.log('resp:', await putFile(job.remote, patched));
  const v = await getFile(job.remote);
  const ok = (v.split(job.from).length - 1) === 0 && v.includes(job.to);
  console.log(ok ? '>>> VERIFIED OK' : '>>> WARNING: verification FAILED');
}
console.log(apply ? '\nDone.' : '\n[DRY RUN complete] re-run with --apply');
