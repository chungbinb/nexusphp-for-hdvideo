// Make forum floor number reflect real floor when posts are shown in DESC order.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/public/forums.php';
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
  { name: 'define-realfloor',
    old: `++$pn;`,
    neo: `++$pn;\n        $realFloor = ($psort === 'desc') ? ($postcount - $offset - $pn + 1) : ($pn + $offset);` },
  { name: 'display-floor',
    old: `."<b>".($pn+$offset)."</b>".`,
    neo: `."<b>".$realFloor."</b>".` },
  { name: 'protect-floor',
    old: `$pn+$offset>1 && !can_view_post`,
    neo: `$realFloor>1 && !can_view_post` },
];

let php = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_forums_floor_${stamp}.php`), php, 'utf8');
if (php.includes('$realFloor')) { console.log('>>> already done'); process.exit(0); }
for (const e of EDITS) { const n = php.split(e.old).length - 1; if (n !== 1) { console.error(`ABORT [${e.name}]: count ${n}`); process.exit(2); } }
for (const e of EDITS) { php = php.replace(e.old, e.neo); console.log('applied:', e.name); }
console.log('save:', (await putFile(REMOTE, php)).slice(0, 50));
const v = await getFile(REMOTE);
console.log(v.includes('$realFloor = ($psort') && v.includes('."<b>".$realFloor."</b>".') ? '>>> VERIFIED' : '>>> WARNING verify');
process.exit(0);
