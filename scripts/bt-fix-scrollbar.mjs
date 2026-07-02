// Fix the phantom right-side scrollbar gutter: body becomes a 2nd scroll container due to overflow-x:hidden.
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

const OLD = `    line-height: 1.6;\n    overflow-x: hidden !important;\n}`;
const NEO = `    line-height: 1.6;\n    overflow-x: clip !important;\n}`;
const APPEND = `\n\n/* qd-html-bg : theme the root element so no white gutter shows behind the scrollbar */\nhtml[data-site-theme] {\n\tbackground: var(--theme-page-bg) !important;\n}\n`;

console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_modern-refresh_scrollbar_${stamp}.css`), content, 'utf8');

let changed = false;
if (content.includes('qd-html-bg')) {
  console.log('append already present');
} else {
  content += APPEND; changed = true; console.log('appended qd-html-bg');
}
if (content.includes(`    line-height: 1.6;\n    overflow-x: clip !important;`)) {
  console.log('body overflow-x already clip');
} else {
  const n = content.split(OLD).length - 1;
  if (n !== 1) { console.error(`ABORT [body-clip]: expected 1 match, found ${n}.`); process.exit(2); }
  content = content.replace(OLD, NEO); changed = true; console.log('body overflow-x -> clip');
}
if (!changed) { console.log('>>> nothing to do.'); process.exit(0); }
console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, content));
const v = await getFile(REMOTE);
const ok = v.includes('qd-html-bg') && v.includes(`    line-height: 1.6;\n    overflow-x: clip !important;`);
console.log(ok ? '>>> VERIFIED: scrollbar fix applied.' : '>>> WARNING: verify.');
process.exit(0);
