// Replace the #outer-based wide-margin rules with a simple body padding approach.
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
    }, (res) => { let d = ''; res.on('data', (c) => (d += c)); res.on('end', () => resolve(d)); });
    req.on('error', reject); req.on('timeout', () => req.destroy(new Error('timeout')));
    req.write(body); req.end();
  });
}
const getFile = async (p) => { const j = JSON.parse(await call('/files?action=GetFileBody', { action: 'GetFileBody', path: p })); if (!j.status) throw new Error('get failed'); return j.data; };
const putFile = (p, c) => call('/files?action=SaveFileBody', { action: 'SaveFileBody', path: p, data: c, encoding: 'utf-8' });

function removeBlock(content, marker) {
  const start = content.indexOf(marker);
  if (start < 0) return content;
  const tail = 'auto !important;\n\t}\n}';
  const tEnd = content.indexOf(tail, start);
  if (tEnd < 0) return content;
  let end = tEnd + tail.length;
  let s = start;
  while (s > 0 && content[s - 1] === '\n') s--;
  return content.slice(0, s) + content.slice(end);
}

const PAD = `

/* qd-wide-pad : widescreen leaves ~100px left/right margins (simple body padding) */
@media (min-width: 1100px) {
	body.layout-wide:not(.inframe) {
		padding-left: 100px !important;
		padding-right: 100px !important;
		box-sizing: border-box !important;
	}
}
`;

let css = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_modern-refresh_widepad_${stamp}.css`), css, 'utf8');

const before = css.length;
css = removeBlock(css, '/* qd-wide-margin :');
css = removeBlock(css, '/* qd-wide-margin2 :');
console.log('removed bytes:', before - css.length);
if (!css.includes('qd-wide-pad')) { css += PAD; console.log('appended qd-wide-pad'); }

console.log('resp:', (await putFile(REMOTE, css)).slice(0, 60));
const v = await getFile(REMOTE);
const ok = v.includes('qd-wide-pad') && !v.includes('qd-wide-margin :') && !v.includes('qd-wide-margin2 :');
console.log(ok ? '>>> VERIFIED: switched to body padding.' : '>>> WARNING verify');
process.exit(0);
