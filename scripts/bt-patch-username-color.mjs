// Stop the global a{color:!important} from overriding class-colored usernames (XXX_Name links).
// Excludes [class$="_Name"] links from the forced primary color. Idempotent + verified.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/public/styles/modern-refresh.css';

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

const OLD = 'a,\na:link,\na:visited {\n    color: var(--bili-primary) !important;\n    text-decoration: none;\n    transition: color 0.2s ease;\n}\n\na:hover {\n    color: var(--bili-primary-hover) !important;\n}';
const NEO = 'a,\na:link,\na:visited {\n    text-decoration: none;\n    transition: color 0.2s ease;\n}\n\n/* 等级用户名链接(XXX_Name)排除在外，保留按等级的颜色 */\na:not([class$="_Name"]),\na:link:not([class$="_Name"]),\na:visited:not([class$="_Name"]) {\n    color: var(--bili-primary) !important;\n}\n\na:hover:not([class$="_Name"]) {\n    color: var(--bili-primary-hover) !important;\n}';

const apply = process.argv.includes('--apply');
console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
console.log('Size:', content.length);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_modern-refresh_${stamp}.css`), content, 'utf8');

if (content.includes('a:not([class$="_Name"])')) { console.log('\n>>> Already patched. Nothing to do.'); process.exit(0); }
const n = content.split(OLD).length - 1;
if (n !== 1) { console.error(`ABORT: expected 1 occurrence of the link-color block, found ${n}.`); process.exit(2); }
const patched = content.replace(OLD, NEO);
console.log('Matched link-color block uniquely. delta', patched.length - content.length);
if (!apply) { console.log('\n[DRY RUN] re-run with --apply'); process.exit(0); }
console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, patched));
const v = await getFile(REMOTE);
console.log(v.includes('a:not([class$="_Name"])') ? '\n>>> VERIFIED: username links excluded from forced color.' : '\n>>> WARNING: verification failed.');
process.exit(0);
