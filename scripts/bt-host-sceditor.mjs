// Create dirs + upload SCEditor files to public/vendor/sceditor, verifying each.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const LOCAL_BASE = path.join(repoRoot, 'public', 'vendor', 'sceditor');
const REMOTE_BASE = '/www/wwwroot/hdvideo.top/public/vendor/sceditor';

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
    }, (res) => { let d = ''; res.on('data', (c) => (d += c)); res.on('end', () => resolve(d)); });
    req.on('error', reject); req.on('timeout', () => req.destroy(new Error('timeout')));
    req.write(body); req.end();
  });
}
const createDir = (p) => call('/files?action=CreateDir', { action: 'CreateDir', path: p });
const createFile = (p) => call('/files?action=CreateFile', { action: 'CreateFile', path: p });
const saveBody = (p, c) => call('/files?action=SaveFileBody', { action: 'SaveFileBody', path: p, data: c, encoding: 'utf-8' });
const getBody = async (p) => { const r = JSON.parse(await call('/files?action=GetFileBody', { action: 'GetFileBody', path: p })); return r.status ? r.data : null; };

const dirs = ['', '/formats', '/themes', '/themes/content', '/icons'];
const files = ['sceditor.min.js', 'formats/bbcode.js', 'themes/default.min.css', 'themes/content/default.min.css', 'icons/monocons.js'];

for (const d of dirs) {
  const rd = REMOTE_BASE + d;
  const r = await createDir(rd);
  console.log('mkdir', rd, '->', r.slice(0, 80));
}
for (const f of files) {
  const local = path.join(LOCAL_BASE, f.replace(/\//g, path.sep));
  const remote = REMOTE_BASE + '/' + f;
  const content = fs.readFileSync(local, 'utf8');
  await createFile(remote);
  await saveBody(remote, content);
  const got = await getBody(remote);
  const ok = got !== null && got.length >= content.length - 5;
  console.log(ok ? 'OK  ' : 'FAIL', remote, `local=${content.length} remote=${got ? got.length : 'null'}`);
}
console.log('done.');
process.exit(0);
