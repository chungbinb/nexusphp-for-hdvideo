// Update the preset color schemes to have noticeably-tinted page/card backgrounds.
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

const OLD = "var PRESETS=[['#00aeec','#fb7299','#f6f7fb','#ffffff','#18191c'],['#fb7299','#ffb3c8','#fff5f8','#ffffff','#5a3a44'],['#7cb342','#aed581','#f4f8ee','#ffffff','#33402a'],['#1e88e5','#4fc3f7','#eef5fb','#ffffff','#1c3a52'],['#7c5cff','#b39ddb','#f5f3ff','#ffffff','#2d2640'],['#ff7043','#ffab91','#fff5f0','#ffffff','#4a2c1f']];";
const NEO = "var PRESETS=[['#00aeec','#fb7299','#f6f7fb','#ffffff','#18191c'],['#fb7299','#f8a5c2','#fcdfe9','#fdeff4','#5a3a44'],['#689f38','#9ccc65','#d3e8bb','#e6f2d6','#2e3d22'],['#1976d2','#4fc3f7','#cfe5f6','#e3f0fa','#13314a'],['#7c4dff','#b388ff','#ddd0f5','#ece3fa','#2a2340'],['#f4511e','#ff8a65','#ffdcc9','#ffece2','#4a2818']];";

console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_functions_presets_${stamp}.php`), content, 'utf8');
if (content.includes("['#689f38','#9ccc65'")) { console.log('>>> Already updated.'); process.exit(0); }
const n = content.split(OLD).length - 1;
if (n !== 1) { console.error(`ABORT: expected 1, found ${n}.`); process.exit(2); }
content = content.replace(OLD, NEO);
console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, content));
const v = await getFile(REMOTE);
console.log(v.includes("['#689f38','#9ccc65'") ? '>>> VERIFIED: presets updated.' : '>>> WARNING: verify.');
process.exit(0);
