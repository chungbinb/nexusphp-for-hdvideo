// Route carousel poster covers through the same-origin imgproxy.php (douban/amazon hotlink bypass).
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
    name: 'proxify-helper',
    old: `var current = 0;`,
    neo: `var current = 0;\n\t\tfunction proxify(u){if(!u)return u;return /doubanio\\.com|douban\\.com|media-amazon\\.com|ssl-images-amazon\\.com|tmdb\\.org/i.test(u)?('imgproxy.php?u='+encodeURIComponent(u)):u;}`,
  },
  {
    name: 'carousel-cover-proxy',
    old: `image.src = item.cover;`,
    neo: `image.src = proxify(item.cover);`,
  },
];

console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_functions_carouselproxy_${stamp}.php`), content, 'utf8');
if (content.includes('function proxify(')) { console.log('>>> Already patched.'); process.exit(0); }
for (const e of EDITS) {
  const n = content.split(e.old).length - 1;
  if (n !== 1) { console.error(`ABORT [${e.name}]: expected 1 match, found ${n}.`); process.exit(2); }
}
for (const e of EDITS) { content = content.replace(e.old, e.neo); console.log('applied:', e.name); }
console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, content));
const v = await getFile(REMOTE);
const ok = v.includes('function proxify(') && v.includes('image.src = proxify(item.cover);');
console.log(ok ? '>>> VERIFIED: carousel proxy patch applied.' : '>>> WARNING: verify.');
process.exit(0);
