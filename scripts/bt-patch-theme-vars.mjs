// Make the day theme's --theme-page-bg/-panel-bg/-page-text reference the --bili-* vars,
// so the 个性化 modal (which sets --bili-*) actually changes the real page/panel/text.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/public/styles/modern-refresh.css';

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

const ops = [
  { old: '--theme-page-bg: #f6f8fd;', neo: '--theme-page-bg: var(--bili-bg);' },
  { old: '--theme-page-text: #1f2a3c;', neo: '--theme-page-text: var(--bili-text);' },
  { old: '--theme-panel-bg: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(245, 249, 255, 0.98) 100%);', neo: '--theme-panel-bg: var(--bili-surface);' },
];

console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_modern-refresh-themevars_${stamp}.css`), content, 'utf8');
if (content.includes('--theme-page-bg: var(--bili-bg);')) { console.log('>>> Already applied.'); process.exit(0); }
for (const op of ops) {
  const n = content.split(op.old).length - 1;
  if (n !== 1) { console.error(`ABORT: expected 1, found ${n} for: ${op.old}`); process.exit(2); }
  content = content.replace(op.old, op.neo);
}
console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, content));
const v = await getFile(REMOTE);
console.log(v.includes('--theme-page-bg: var(--bili-bg);') ? '>>> VERIFIED: theme vars now reference bili vars.' : '>>> WARNING: verify.');
process.exit(0);
