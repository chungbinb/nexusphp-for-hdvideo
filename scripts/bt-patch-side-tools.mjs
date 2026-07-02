// Inject the site-wide floating side tools (返回旧版 / 个性化) block into stdhead,
// right before the existing theme <script> in functions.php. Idempotent + verified.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/include/functions.php';
const BLOCK = fs.readFileSync(path.join(__dirname, 'qd-side-tools-block.html'), 'utf8');

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

const apply = process.argv.includes('--apply');
console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
console.log('Size:', content.length);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_functions_sidetools_${stamp}.php`), content, 'utf8');

if (content.includes('qd-side-tools')) { console.log('\n>>> Already injected. Nothing to do.'); process.exit(0); }

// Anchor: the theme IIFE. Inject the block before the <script> that immediately precedes it.
const marker = "var THEME_KEY = 'nexus_site_theme';";
const mIdx = content.indexOf(marker);
if (mIdx < 0) { console.error('ABORT: theme marker not found.'); process.exit(2); }
const scriptIdx = content.lastIndexOf('<script>', mIdx);
if (scriptIdx < 0) { console.error('ABORT: <script> before marker not found.'); process.exit(2); }
// safety: the <script> must be close to the marker (same block)
if (mIdx - scriptIdx > 200) { console.error(`ABORT: nearest <script> is ${mIdx - scriptIdx} chars before marker — unexpected.`); process.exit(2); }

const patched = content.slice(0, scriptIdx) + BLOCK + '\n' + content.slice(scriptIdx);
console.log('Injection point at index', scriptIdx, '| block', BLOCK.length, 'chars | new size', patched.length);
if (!apply) { console.log('\n[DRY RUN] re-run with --apply'); process.exit(0); }

console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, patched));
const v = await getFile(REMOTE);
const ok = v.includes('qd-side-tools') && v.includes("var THEME_KEY = 'nexus_site_theme';") && (v.split("var THEME_KEY = 'nexus_site_theme';").length - 1) === 1;
console.log(ok ? '\n>>> VERIFIED: side tools injected.' : '\n>>> WARNING: verification failed.');
process.exit(ok ? 0 : 3);
