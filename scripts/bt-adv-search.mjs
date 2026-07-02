// Add 高级搜索 button to torrents search bar + bind all advanced-search triggers.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/public/torrents.php';
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
    name: 'adv-button',
    old: `<button type="submit">搜索</button></form>`,
    neo: `<button type="submit">搜索</button><button type="button" class="qd-torrent-search-adv top-advanced-search-trigger">高级搜索</button></form>`,
  },
  {
    name: 'bind-all-triggers',
    old: `\ttrigger.addEventListener('click', function (e) {\n\t\te.preventDefault();\n\t\topenModal();\n\t});`,
    neo: `\tdocument.querySelectorAll('.top-advanced-search-trigger').forEach(function (tg) {\n\t\ttg.addEventListener('click', function (e) {\n\t\t\te.preventDefault();\n\t\t\topenModal();\n\t\t});\n\t});`,
  },
];

let php = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_torrents_advsearch_${stamp}.php`), php, 'utf8');
if (php.includes('qd-torrent-search-adv')) { console.log('>>> already added'); process.exit(0); }
for (const e of EDITS) { const n = php.split(e.old).length - 1; if (n !== 1) { console.error(`ABORT [${e.name}]: count ${n}`); process.exit(2); } }
for (const e of EDITS) { php = php.replace(e.old, e.neo); console.log('applied:', e.name); }
console.log('save:', (await putFile(REMOTE, php)).slice(0, 50));
const v = await getFile(REMOTE);
console.log(v.includes('qd-torrent-search-adv') && v.includes("querySelectorAll('.top-advanced-search-trigger').forEach") ? '>>> VERIFIED' : '>>> WARNING verify');
process.exit(0);
