// Move 幸运大转盘 (the nexusphp-lucky-draw plugin) fully into the game hall:
//  (1) game hall card -> /plugin/lucky-draw (correct target, not the old wof.php)
//  (2) stop the plugin from injecting its homepage block (comment the nexus_home_module hook)
// Idempotent + verified. Uses Node's OpenSSL to bypass Windows schannel.
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

const apply = process.argv.includes('--apply');
const stamp = new Date().toISOString().replace(/[:.]/g, '-');

const jobs = [
  {
    name: 'game hall card -> /plugin/lucky-draw',
    remote: '/www/wwwroot/hdvideo.top/public/games/index.php',
    old: "'href' => '/wof.php',",
    neo: "'href' => '/plugin/lucky-draw',",
    doneMark: "'href' => '/plugin/lucky-draw',",
    verify: (v) => v.includes("'href' => '/plugin/lucky-draw',") && !v.includes("'href' => '/wof.php',"),
  },
  {
    name: 'disable plugin homepage block hook',
    remote: '/www/wwwroot/hdvideo.top/vendor/xiaomlove/nexusphp-lucky-draw/src/LuckyDrawRepository.php',
    old: "add_filter('nexus_home_module', [$self, 'filterRenderOnHomePage'], 10, 1);",
    neo: "// add_filter('nexus_home_module', [$self, 'filterRenderOnHomePage'], 10, 1); // 幸运大转盘已移至游戏大厅，取消首页板块注入",
    doneMark: "// add_filter('nexus_home_module'",
    verify: (v) => v.includes("// add_filter('nexus_home_module'"),
  },
];

for (const job of jobs) {
  console.log(`\n=== ${job.name} ===`);
  console.log('Fetching:', job.remote);
  let content = await getFile(job.remote);
  console.log('Size:', content.length);
  fs.writeFileSync(path.join(__dirname, `_backup_${job.remote.split('/').pop()}_${stamp}.php`), content, 'utf8');
  if (content.includes(job.doneMark)) { console.log('>>> Already patched. Skipping.'); continue; }
  const n = content.split(job.old).length - 1;
  if (n !== 1) { console.error(`ABORT ${job.name}: expected 1 occurrence, found ${n}.`); process.exit(2); }
  const patched = content.replace(job.old, job.neo);
  console.log('Matched uniquely. delta', patched.length - content.length);
  if (!apply) { console.log('[DRY RUN] not writing'); continue; }
  console.log('Uploading...');
  console.log('resp:', await putFile(job.remote, patched));
  const v = await getFile(job.remote);
  console.log(job.verify(v) ? '>>> VERIFIED OK' : '>>> WARNING: verification FAILED');
}
console.log(apply ? '\nDone.' : '\n[DRY RUN complete] re-run with --apply');
