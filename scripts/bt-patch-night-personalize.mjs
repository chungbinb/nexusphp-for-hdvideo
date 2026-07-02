// Make personalization day-only: remove vars in night, observe theme toggle, modal reads saved colors.
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

const EDITS = [
  {
    name: 'style-day-scope',
    old: `($qdPV !== '' ? (':root{' . $qdPV . '}') : '')`,
    neo: `($qdPV !== '' ? (':root[data-site-theme="day"],html:not([data-site-theme]){' . $qdPV . '}') : '')`,
  },
  {
    name: 'applySaved-day-only',
    old: `function applySaved(){var c=getSaved();for(var i=0;i<VARS.length;i++){if(c&&c[VARS[i]]){root.style.setProperty(VARS[i],c[VARS[i]]);}}}`,
    neo: `function isNight(){return root.getAttribute('data-site-theme')==='night'||(document.body&&document.body.classList.contains('theme-night'));}function applySaved(){var c=getSaved();for(var i=0;i<VARS.length;i++){if(isNight()||!c||!c[VARS[i]]){root.style.removeProperty(VARS[i]);}else{root.style.setProperty(VARS[i],c[VARS[i]]);}}}try{var qdMO=new MutationObserver(function(){applySaved();});qdMO.observe(root,{attributes:true,attributeFilter:['data-site-theme','class']});if(document.body){qdMO.observe(document.body,{attributes:true,attributeFilter:['class']});}}catch(e){}`,
  },
  {
    name: 'openModal-prefer-saved',
    old: `function openModal(){var sw=swatches();for(var i=0;i<sw.length;i++){setSwatch(sw[i],toHex(curVal(sw[i].getAttribute('data-var'))));}modal.hidden=false;}`,
    neo: `function openModal(){var c=getSaved();var sw=swatches();for(var i=0;i<sw.length;i++){var v=sw[i].getAttribute('data-var');setSwatch(sw[i],toHex((c&&c[v])||curVal(v)));}modal.hidden=false;}`,
  },
];

console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_functions_night_${stamp}.php`), content, 'utf8');
if (content.includes('function isNight()')) { console.log('>>> Already patched.'); process.exit(0); }
for (const e of EDITS) {
  const n = content.split(e.old).length - 1;
  if (n !== 1) { console.error(`ABORT [${e.name}]: expected 1 match, found ${n}.`); process.exit(2); }
}
for (const e of EDITS) { content = content.replace(e.old, e.neo); console.log('applied:', e.name); }
console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, content));
const v = await getFile(REMOTE);
const ok = v.includes('function isNight()') && v.includes(':root[data-site-theme="day"],html:not([data-site-theme])') && v.includes('var c=getSaved();var sw=swatches();');
console.log(ok ? '>>> VERIFIED: night-personalize patch applied.' : '>>> WARNING: verify.');
process.exit(0);
