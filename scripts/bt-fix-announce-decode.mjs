// EMERGENCY: announce.php hardcodes PostgreSQL decode(:x,'hex') which fails on MySQL
// ("FUNCTION hdvideo.decode does not exist") -> every cache-miss announce 500s.
// Replace with MySQL UNHEX(:x). Binds stay bin2hex(...), UNHEX(bin2hex(x)) == x.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const cfg = JSON.parse(fs.readFileSync(path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json'), 'utf8'));
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
const REMOTE = '/www/wwwroot/hdvideo.top/public/announce.php';

let php = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_announce_decode_${stamp}.php`), php, 'utf8');

// regex: decode(:name, 'hex')  ->  UNHEX(:name)
const re = /decode\((:[a-zA-Z_][a-zA-Z0-9_]*),\s*'hex'\)/g;
const matches = [...php.matchAll(re)].map(m => m[0]);
console.log('found decode(...) occurrences:', matches.length, JSON.stringify([...new Set(matches)]));
if (matches.length === 0) { console.log('>>> nothing to replace (already fixed?)'); process.exit(0); }
php = php.replace(re, 'UNHEX($1)');

// write local copy for php -l, then upload
const localTmp = path.join(__dirname, '_lint_announce_fixed.php');
fs.writeFileSync(localTmp, php, 'utf8');
console.log('save:', (await putFile(REMOTE, php)).slice(0, 40));
const v = await getFile(REMOTE);
const remaining = [...v.matchAll(re)].length;
console.log('verify remaining decode(...):', remaining, ' UNHEX count:', (v.match(/UNHEX\(:/g) || []).length);
console.log(remaining === 0 ? '>>> VERIFIED (no decode left)' : '>>> WARNING');
process.exit(0);
