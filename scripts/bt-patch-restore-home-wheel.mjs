// Restore the lucky-draw plugin's homepage block: un-comment the nexus_home_module hook.
// Idempotent + verified.
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

const REMOTE = '/www/wwwroot/hdvideo.top/vendor/xiaomlove/nexusphp-lucky-draw/src/LuckyDrawRepository.php';
const OLD = "// add_filter('nexus_home_module', [$self, 'filterRenderOnHomePage'], 10, 1); // 幸运大转盘已移至游戏大厅，取消首页板块注入";
const NEO = "add_filter('nexus_home_module', [$self, 'filterRenderOnHomePage'], 10, 1);";

const apply = process.argv.includes('--apply');
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
console.log('Size:', content.length);
fs.writeFileSync(path.join(__dirname, `_backup_LuckyDrawRepository_restore_${stamp}.php`), content, 'utf8');

if (!content.includes(OLD) && content.includes("\n        add_filter('nexus_home_module'")) {
  console.log('>>> Hook already active (uncommented). Nothing to do.');
  process.exit(0);
}
const n = content.split(OLD).length - 1;
if (n !== 1) { console.error(`ABORT: expected 1 commented hook, found ${n}.`); process.exit(2); }
const patched = content.replace(OLD, NEO);
console.log('Matched the commented hook uniquely. delta', patched.length - content.length);
if (!apply) { console.log('[DRY RUN] re-run with --apply'); process.exit(0); }
console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, patched));
const v = await getFile(REMOTE);
const ok = !v.includes(OLD) && /\n\s*add_filter\('nexus_home_module'/.test(v);
console.log(ok ? '>>> VERIFIED: homepage hook restored.' : '>>> WARNING: verification failed.');
process.exit(ok ? 0 : 3);
