// Generic: append a CSS block (by file) to live modern-refresh.css, idempotent via marker arg.
// Usage: node bt-append-css.mjs <blockFile> <marker>
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const [blockFileArg, marker] = process.argv.slice(2);
if (!blockFileArg || !marker) { console.error('Usage: node bt-append-css.mjs <blockFile> <marker>'); process.exit(1); }
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/public/styles/modern-refresh.css';
const BLOCK = fs.readFileSync(path.resolve(__dirname, blockFileArg), 'utf8');
if (!BLOCK.includes(marker)) { console.error(`ABORT: block file does not contain marker "${marker}".`); process.exit(1); }

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

console.log('Fetching:', REMOTE, ' marker:', marker);
let content = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_modern-refresh_${marker}_${stamp}.css`), content, 'utf8');
if (content.includes(marker)) { console.log('>>> Already added.'); process.exit(0); }
console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, content + BLOCK));
const v = await getFile(REMOTE);
console.log(v.includes(marker) ? `>>> VERIFIED: ${marker} added.` : '>>> WARNING: verify.');
process.exit(0);
