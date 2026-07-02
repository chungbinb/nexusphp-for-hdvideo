// New site header tweak:
//  A) top "游戏大厅" button -> staff.php (管理组, users icon)
//  B) add a "游戏大厅" button to the right floating bar, below 个性化
// Idempotent + verified.
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
const getFile = async (p) => { const r = await call('/files?action=GetFileBody', { action: 'GetFileBody', path: p }); const j = JSON.parse(r.body); if (!j.status) throw new Error('GetFileBody failed'); return j.data; };
const putFile = async (p, c) => (await call('/files?action=SaveFileBody', { action: 'SaveFileBody', path: p, data: c, encoding: 'utf-8' })).body;

const GAME_D1 = 'M6.35 7.9H4.75M5.55 7.1V8.7M13.05 7.9H13.06M15.25 9.55H15.26';
const GAME_D2 = 'M6.05 5.1H13.95C15.3 5.1 16.45 6.08 16.67 7.41L17.25 10.9C17.55 12.69 16.17 14.32 14.35 14.32C13.47 14.32 12.64 13.93 12.08 13.25L11.53 12.58H8.47L7.92 13.25C7.36 13.93 6.53 14.32 5.65 14.32C3.83 14.32 2.45 12.69 2.75 10.9L3.33 7.41C3.55 6.08 4.7 5.1 6.05 5.1Z';
const USERS_D1 = 'M13 9a2 2 0 1 0-1-3.7M13.5 12.6c1.8.2 3 1.4 3 3.4';
const USERS_D2 = 'M8 9a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM4 16c0-2.2 1.8-3.5 4-3.5s4 1.3 4 3.5';

// floating-bar 游戏大厅 button (reuses the game-controller icon)
const FLOAT_GAME = '\t<a class="qd-side-btn" href="games/" title="游戏大厅">\n\t\t<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="' + GAME_D1 + '"></path><path d="' + GAME_D2 + '"></path></svg>\n\t\t<span class="qd-side-text">游戏大厅</span>\n\t</a>\n';

const ops = [
  { label: 'A1 top href->staff', old: 'href="games/" aria-label="游戏大厅"', neo: 'href="staff.php" aria-label="管理组"' },
  { label: 'A2 top icon path1', old: GAME_D1, neo: USERS_D1 },
  { label: 'A3 top icon path2', old: GAME_D2, neo: USERS_D2 },
  { label: 'A4 top text', old: '<span class="top-link-game-text">游戏大厅</span>', neo: '<span class="top-link-game-text">管理组</span>' },
  { label: 'B  add floating 游戏大厅', old: '>个性化</span>\n\t</button>\n</div>', neo: '>个性化</span>\n\t</button>\n' + FLOAT_GAME + '</div>' },
];

const apply = process.argv.includes('--apply');
console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
console.log('Size:', content.length);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_functions_navswap_${stamp}.php`), content, 'utf8');

if (content.includes('href="staff.php" aria-label="管理组"')) { console.log('\n>>> Already applied. Nothing to do.'); process.exit(0); }

for (const op of ops) {
  const n = content.split(op.old).length - 1;
  if (n !== 1) { console.error(`ABORT [${op.label}]: expected 1 occurrence, found ${n}.`); process.exit(2); }
  content = content.replace(op.old, op.neo);
  console.log(`ok [${op.label}]`);
}
console.log('All ops applied. New size:', content.length);
if (!apply) { console.log('\n[DRY RUN] re-run with --apply'); process.exit(0); }

console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, content));
const v = await getFile(REMOTE);
const ok = v.includes('href="staff.php" aria-label="管理组"') && v.includes('href="games/" title="游戏大厅"') && !v.includes('href="games/" aria-label="游戏大厅"');
console.log(ok ? '\n>>> VERIFIED: nav swap done.' : '\n>>> WARNING: verification failed.');
process.exit(ok ? 0 : 3);
