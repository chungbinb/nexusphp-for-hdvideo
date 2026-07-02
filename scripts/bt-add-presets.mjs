// Add 4 new color presets: 性感紫 妖娆紫 魅惑紫 清纯粉 (PRESETS array + <select> options).
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

const NEW_PRESETS = "['#9c27b0','#ff4081','#ecd6f4','#ddbbeb','#3b1d49'],['#ba68c8','#f06292','#f2e2f7','#e6cdf0','#45284f'],['#6a1b9a','#c2185b','#e3cbee','#cfaae2','#2f1340'],['#f48fb1','#f8bbd0','#fdf0f5','#fcdde9','#5a3a48']";

const EDITS = [
  {
    name: 'presets-array',
    old: `['#f4511e','#ff8a65','#ffdcc9','#ffceb6','#4a2818']];`,
    neo: `['#f4511e','#ff8a65','#ffdcc9','#ffceb6','#4a2818'],${NEW_PRESETS}];`,
  },
  {
    name: 'select-options',
    old: `<option value="5">落日橙</option>`,
    neo: `<option value="5">落日橙</option><option value="6">性感紫</option><option value="7">妖娆紫</option><option value="8">魅惑紫</option><option value="9">清纯粉</option>`,
  },
];

console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_functions_addpresets_${stamp}.php`), content, 'utf8');
if (content.includes('性感紫')) { console.log('>>> Already added.'); process.exit(0); }
for (const e of EDITS) {
  const n = content.split(e.old).length - 1;
  if (n !== 1) { console.error(`ABORT [${e.name}]: expected 1 match, found ${n}.`); process.exit(2); }
}
for (const e of EDITS) { content = content.replace(e.old, e.neo); console.log('applied:', e.name); }
console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, content));
const v = await getFile(REMOTE);
const ok = v.includes('性感紫') && v.includes("'#9c27b0'") && v.includes('清纯粉') && v.includes("'#f48fb1'");
console.log(ok ? '>>> VERIFIED: 4 presets added.' : '>>> WARNING: verify.');
process.exit(0);
