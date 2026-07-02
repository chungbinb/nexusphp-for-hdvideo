// Relocate the stats strip INTO #top-account-widget (left of avatar) so it rides the fixed top-right block.
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

let php = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_functions_navstatsreloc_${stamp}.php`), php, 'utf8');

const widgetTag = '<div id="top-account-widget">';
const s = php.indexOf('<div id="top-stats-bar"');
const w = php.indexOf(widgetTag);
if (s < 0 || w < 0) { console.error('ABORT: markers not found', s, w); process.exit(2); }
if (s > w) { console.log('>>> strip already inside widget (s>w)'); process.exit(0); }
// already relocated? check if strip is right after widgetTag
if (php.indexOf(widgetTag + '\n\t<div id="top-stats-bar"') >= 0 || php.indexOf(widgetTag + '\n<div id="top-stats-bar"') >= 0) { console.log('>>> already relocated'); process.exit(0); }

const stripBlock = php.slice(s, w).trim();   // the strip html (before the widget)
let out = php.slice(0, s) + php.slice(w);     // remove strip from before widget
out = out.replace(widgetTag, widgetTag + '\n\t' + stripBlock);  // insert inside widget
console.log('save:', (await putFile(REMOTE, out)).slice(0, 50));
const v = await getFile(REMOTE);
const ok = v.indexOf(widgetTag + '\n\t<div id="top-stats-bar"') >= 0;
console.log(ok ? '>>> VERIFIED: strip relocated inside widget' : '>>> WARNING verify');
process.exit(0);
