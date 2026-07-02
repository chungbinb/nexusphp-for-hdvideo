// Remove the settings-page width cap so it follows the layout (wide/narrow) width.
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

let css = (await getFile(REMOTE)).replace(/\r\n/g, '\n');
const block = `html[data-site-theme] body.page-settings #outer.outer,\nhtml[data-site-theme] body.page-settings table.mainouter {\n\tmax-width: 1040px !important;\n\tmargin-left: auto !important;\n\tmargin-right: auto !important;\n}`;
let changed = false;
if (css.includes(block)) { css = css.replace(block, '/* qd-settings width cap removed (follow layout width) */'); changed = true; console.log('removed settings outer cap'); }
else { console.log('settings cap block not found'); }
if (css.includes('\tmax-width: 1040px;\n\tpadding: 7px;')) { css = css.replace('\tmax-width: 1040px;\n\tpadding: 7px;', '\tmax-width: none;\n\tpadding: 7px;'); changed = true; console.log('tabs max-width -> none'); }
else { console.log('tabs max-width not matched'); }
if (changed) { console.log('save:', (await putFile(REMOTE, css)).slice(0, 50)); }
else { console.log('nothing changed'); }
process.exit(0);
