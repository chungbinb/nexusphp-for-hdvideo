// Square the settings-page tab bar corners (outer box + each tab) to 0.
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
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_modern-refresh_tabsq_${stamp}.css`), css, 'utf8');

let changed = 0;
// outer box: border-radius: 12px;  (inside the .qd-settings-tabs block)
const outerOld = `.qd-settings-tabs {\n\tdisplay: flex;\n\tflex-wrap: wrap;\n\tgap: 6px;\n\tmargin: 0 auto 18px;\n\tmax-width: none;\n\tpadding: 7px;\n\tbackground: var(--theme-panel-bg, var(--bili-surface, #fff));\n\tborder: 1px solid var(--theme-panel-border, var(--bili-border, #e6e9ef));\n\tborder-radius: 12px;\n\tbox-sizing: border-box;\n}`;
if (css.includes(outerOld)) { css = css.replace(outerOld, outerOld.replace('border-radius: 12px;', 'border-radius: 0;')); changed++; console.log('outer box -> square'); }
else { console.log('WARN outer box anchor not matched'); }

// each tab: border-radius: 8px;
const tabOld = `.qd-settings-tab {\n\tpadding: 7px 15px;\n\tborder-radius: 8px;`;
if (css.includes(tabOld)) { css = css.replace(tabOld, `.qd-settings-tab {\n\tpadding: 7px 15px;\n\tborder-radius: 0;`); changed++; console.log('tab -> square'); }
else { console.log('WARN tab anchor not matched'); }

if (!changed) { console.error('ABORT: nothing changed'); process.exit(2); }
console.log('save:', (await putFile(REMOTE, css)).slice(0, 50));
const v = (await getFile(REMOTE)).replace(/\r\n/g, '\n');
const okOuter = v.includes('border-radius: 0;\n\tbox-sizing: border-box;');
const okTab = v.includes('.qd-settings-tab {\n\tpadding: 7px 15px;\n\tborder-radius: 0;');
console.log('verify outer square:', okOuter, ' tab square:', okTab);
console.log(okOuter && okTab ? '>>> VERIFIED' : '>>> WARNING verify');
process.exit(0);
