// Restore the settings-page CSS that was lost when live modern-refresh.css got reverted:
//  (a) re-append the qd-settings-tabs styling block (PHP outputs this HTML, CSS was missing)
//  (b) remove the settings #outer width cap so wide-mode is wide again (the width-fix)
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
fs.writeFileSync(path.join(__dirname, `_backup_modern-refresh_restore_${stamp}.css`), css, 'utf8');
console.log('live css length:', css.length, ' has qd-settings-tabs:', css.includes('qd-settings-tabs'));

// (b) remove the settings #outer width cap (so wide layout is wide again)
const capBlock = `html[data-site-theme] body.page-settings #outer.outer,\nhtml[data-site-theme] body.page-settings table.mainouter {\n\tmax-width: 1040px !important;\n\tmargin-left: auto !important;\n\tmargin-right: auto !important;\n}`;
if (css.includes(capBlock)) { css = css.replace(capBlock, '/* qd-settings width cap removed (follow layout width) */'); console.log('removed settings #outer width cap'); }
else { console.log('NOTE: settings #outer cap block not found (already removed?)'); }

// (a) append qd-settings-tabs styling (rounded, follows theme, no width cap)
if (!css.includes('qd-settings-tabs')) {
  const block = `\n\n/* qd-settings-tabs : top tab bar for the settings page (restored) */\n.qd-settings-tabs {\n\tdisplay: flex;\n\tflex-wrap: wrap;\n\tgap: 6px;\n\tmargin: 0 auto 18px;\n\tmax-width: none;\n\tpadding: 7px;\n\tbackground: var(--theme-panel-bg, var(--bili-surface, #fff));\n\tborder: 1px solid var(--theme-panel-border, var(--bili-border, #e6e9ef));\n\tborder-radius: 12px;\n\tbox-sizing: border-box;\n}\n.qd-settings-tab {\n\tpadding: 7px 15px;\n\tborder-radius: 8px;\n\tfont-size: 13px;\n\tfont-weight: 500;\n\tcolor: var(--theme-page-text, var(--bili-text, #18191c)) !important;\n\ttext-decoration: none !important;\n\twhite-space: nowrap;\n\ttransition: background 0.12s ease, color 0.12s ease;\n}\n.qd-settings-tab:hover {\n\tbackground: color-mix(in srgb, var(--bili-primary, #00aeec) 14%, transparent);\n}\n.qd-settings-tab.is-active {\n\tbackground: var(--bili-primary, #00aeec) !important;\n\tcolor: #fff !important;\n}\n`;
  css += block;
  console.log('appended qd-settings-tabs block');
} else {
  console.log('qd-settings-tabs already present, skipping append');
}

console.log('save:', (await putFile(REMOTE, css)).slice(0, 60));
const v = (await getFile(REMOTE)).replace(/\r\n/g, '\n');
const ok = v.includes('.qd-settings-tabs {') && v.includes('.qd-settings-tab.is-active') && !v.includes('max-width: 1040px !important;');
console.log('verify length:', v.length, ' qd-settings-tabs present:', v.includes('qd-settings-tabs'), ' cap gone:', !v.includes('max-width: 1040px !important;'));
console.log(ok ? '>>> VERIFIED' : '>>> WARNING verify');
process.exit(0);
