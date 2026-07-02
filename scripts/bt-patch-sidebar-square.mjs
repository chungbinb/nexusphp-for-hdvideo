// Make the floating side buttons square + connected (no gaps, no circles). Idempotent + verified.
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

const ops = [
  {
    label: 'container connected',
    old: '.qd-side-tools{position:fixed;right:14px;top:50%;transform:translateY(-50%);z-index:9990;display:flex;flex-direction:column;gap:14px;}',
    neo: '.qd-side-tools{position:fixed;right:14px;top:50%;transform:translateY(-50%);z-index:9990;display:flex;flex-direction:column;gap:0;border-radius:14px;overflow:hidden;box-shadow:var(--bili-shadow-md,0 8px 24px rgba(24,25,28,.14));}',
  },
  {
    label: 'btn square',
    old: '.qd-side-btn{width:56px;height:56px;border:none;border-radius:50%;background:var(--bili-surface,#fff);color:var(--bili-primary,#00aeec);box-shadow:var(--bili-shadow-md,0 8px 24px rgba(24,25,28,.12));display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;text-decoration:none;line-height:1.05;transition:transform .15s ease,box-shadow .15s ease,color .15s ease;padding:0;}',
    neo: '.qd-side-btn{width:58px;height:58px;border:none;border-radius:0;background:var(--bili-surface,#fff);color:var(--bili-primary,#00aeec);box-shadow:none;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;text-decoration:none;line-height:1.05;transition:background .15s ease,color .15s ease;padding:0;}\n.qd-side-btn + .qd-side-btn{border-top:1px solid var(--bili-border,#e6e9ef);}',
  },
  {
    label: 'btn hover',
    old: '.qd-side-btn:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(24,25,28,.2);color:var(--bili-primary-hover,#38bff2);}',
    neo: '.qd-side-btn:hover{background:var(--bili-surface-soft,#f2f3f5);color:var(--bili-primary-hover,#38bff2);}',
  },
  {
    label: 'mobile',
    old: '@media (max-width:768px){.qd-side-tools{right:8px;gap:10px;}.qd-side-btn{width:48px;height:48px;}.qd-side-btn svg{width:18px;height:18px;}}',
    neo: '@media (max-width:768px){.qd-side-tools{right:8px;}.qd-side-btn{width:50px;height:50px;}.qd-side-btn svg{width:18px;height:18px;}}',
  },
];

const apply = process.argv.includes('--apply');
console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
console.log('Size:', content.length);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_functions_square_${stamp}.php`), content, 'utf8');

if (content.includes('.qd-side-btn + .qd-side-btn{border-top')) { console.log('\n>>> Already squared. Nothing to do.'); process.exit(0); }

for (const op of ops) {
  const n = content.split(op.old).length - 1;
  if (n !== 1) { console.error(`ABORT [${op.label}]: expected 1, found ${n}.`); process.exit(2); }
  content = content.replace(op.old, op.neo);
  console.log(`ok [${op.label}]`);
}
if (!apply) { console.log('\n[DRY RUN] re-run with --apply'); process.exit(0); }
console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, content));
const v = await getFile(REMOTE);
console.log(v.includes('.qd-side-btn + .qd-side-btn{border-top') && v.includes('border-radius:0;background:var(--bili-surface') ? '\n>>> VERIFIED: sidebar squared & connected.' : '\n>>> WARNING: verification failed.');
process.exit(0);
