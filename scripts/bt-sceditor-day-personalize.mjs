// Make SCEditor iframe follow personalized surface in day + observe style changes.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/public/upload.php';
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

const EDITS = [
  {
    name: 'qdEdTheme-day',
    old: `function qdEdTheme(){var nb=document.documentElement.getAttribute('data-site-theme')==='night'||(document.body&&document.body.classList.contains('theme-night'));try{inst.css(nb?'html,body{background:#0b1422 !important;color:#dbe5f3 !important;}a{color:#5fa7ff !important;}':'');}catch(e){}}`,
    neo: `function qdEdTheme(){var nb=document.documentElement.getAttribute('data-site-theme')==='night'||(document.body&&document.body.classList.contains('theme-night'));try{if(nb){inst.css('html,body{background:#0b1422 !important;color:#dbe5f3 !important;}a{color:#5fa7ff !important;}');}else{var cs=getComputedStyle(document.documentElement);var s=(cs.getPropertyValue('--bili-surface')||'').trim()||'#ffffff';var t=(cs.getPropertyValue('--bili-text')||'').trim()||'#18191c';inst.css('html,body{background:'+s+' !important;color:'+t+' !important;}');}}catch(e){}}`,
  },
  {
    name: 'observe-style',
    old: `qdEtMO.observe(document.documentElement,{attributes:true,attributeFilter:['data-site-theme','class']});if(document.body){qdEtMO.observe(document.body,{attributes:true,attributeFilter:['class']});}`,
    neo: `qdEtMO.observe(document.documentElement,{attributes:true,attributeFilter:['data-site-theme','class','style']});if(document.body){qdEtMO.observe(document.body,{attributes:true,attributeFilter:['class']});}`,
  },
];

let up = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_upload_dayperso_${stamp}.php`), up, 'utf8');
if (up.includes("cs.getPropertyValue('--bili-surface')")) { console.log('>>> already done'); process.exit(0); }
for (const e of EDITS) { const n = up.split(e.old).length - 1; if (n !== 1) { console.error(`ABORT [${e.name}]: count ${n}`); process.exit(2); } }
for (const e of EDITS) { up = up.replace(e.old, e.neo); console.log('applied:', e.name); }
console.log('save:', (await putFile(REMOTE, up)).slice(0, 50));
const v = await getFile(REMOTE);
console.log(v.includes("cs.getPropertyValue('--bili-surface')") && v.includes("'class','style'") ? '>>> VERIFIED' : '>>> WARNING verify');
process.exit(0);
