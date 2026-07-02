// Improve readability of the forum post-order toggle button (.qd-postsort-btn):
// white text on a light personalized primary was unreadable. Switch to a high-contrast
// chip: light tint bg + theme ink text + colored border (works in day & night).
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const cfg = JSON.parse(fs.readFileSync(path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json'), 'utf8'));
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
const REMOTE = '/www/wwwroot/hdvideo.top/public/styles/modern-refresh.css';

let css = (await getFile(REMOTE)).replace(/\r\n/g, '\n');
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_modern-refresh_postsort_${stamp}.css`), css, 'utf8');

const oldBlock = `.qd-postsort-btn {
\tdisplay: inline-block;
\tpadding: 1px 11px;
\tmargin-left: 6px;
\tborder-radius: 12px;
\tbackground: var(--bili-primary, #00aeec) !important;
\tcolor: #fff !important;
\tfont-size: 12px;
\tline-height: 18px;
\twhite-space: nowrap;
\ttext-decoration: none !important;
\tvertical-align: middle;
}
.qd-postsort-btn:hover {
\tfilter: brightness(1.08);
\tcolor: #fff !important;
}`;

const newBlock = `.qd-postsort-btn {
\tdisplay: inline-block;
\tpadding: 2px 12px;
\tmargin-left: 6px;
\tborder-radius: 12px;
\tbackground: color-mix(in srgb, var(--bili-primary, #00aeec) 16%, var(--theme-panel-bg, #fff)) !important;
\tcolor: var(--theme-page-text, #18191c) !important;
\tborder: 1px solid color-mix(in srgb, var(--bili-primary, #00aeec) 55%, transparent) !important;
\tfont-size: 12px;
\tfont-weight: 600;
\tline-height: 18px;
\twhite-space: nowrap;
\ttext-decoration: none !important;
\tvertical-align: middle;
}
.qd-postsort-btn:hover {
\tbackground: color-mix(in srgb, var(--bili-primary, #00aeec) 30%, var(--theme-panel-bg, #fff)) !important;
\tcolor: var(--theme-page-text, #18191c) !important;
\tfilter: none;
}`;

const n = css.split(oldBlock).length - 1;
if (n !== 1) { console.error(`ABORT: qd-postsort-btn block matched ${n} times (need 1)`); process.exit(2); }
css = css.replace(oldBlock, newBlock);
console.log('save:', (await putFile(REMOTE, css)).slice(0, 50));
const v = (await getFile(REMOTE)).replace(/\r\n/g, '\n');
console.log('verify:', v.includes('color: var(--theme-page-text, #18191c) !important;\n\tborder: 1px solid color-mix') ? 'VERIFIED' : 'WARNING');
process.exit(0);
