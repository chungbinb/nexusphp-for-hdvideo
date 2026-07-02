// Top-nav brand: show /pic/logo.png if it exists, else fall back to the site-name text.
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

const OLD = 'print (\'<li class="nav-brand"><a href="index.php" title="\'.$brandTitle.\'">\'.$brandLogoHtml.\'<span class="nav-brand-text">\'.$brandTitle.\'</span></a></li>\');';
const NEO = '$brandInner = is_file(ROOT_PATH . \'public/pic/logo.png\') ? \'<img class="nav-brand-logo" src="/pic/logo.png" alt="\'.$brandTitle.\'" loading="lazy" decoding="async" />\' : \'<span class="nav-brand-text">\'.$brandTitle.\'</span>\'; print (\'<li class="nav-brand"><a href="index.php" title="\'.$brandTitle.\'">\'.$brandInner.\'</a></li>\');';

const apply = process.argv.includes('--apply');
console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
console.log('Size:', content.length);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_functions_brand_${stamp}.php`), content, 'utf8');

if (content.includes("is_file(ROOT_PATH . 'public/pic/logo.png')")) { console.log('\n>>> Already applied.'); process.exit(0); }
const n = content.split(OLD).length - 1;
if (n !== 1) { console.error(`ABORT: expected 1 brand print line, found ${n}.`); process.exit(2); }
const patched = content.replace(OLD, NEO);
console.log('Matched uniquely. delta', patched.length - content.length);
if (!apply) { console.log('\n[DRY RUN] re-run with --apply'); process.exit(0); }
console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, patched));
const v = await getFile(REMOTE);
console.log(v.includes("is_file(ROOT_PATH . 'public/pic/logo.png')") ? '\n>>> VERIFIED: brand logo/text fallback added.' : '\n>>> WARNING: verification failed.');
process.exit(0);
