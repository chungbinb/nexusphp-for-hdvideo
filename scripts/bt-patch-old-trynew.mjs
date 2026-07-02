// Add a "体验新版" button to the OLD site's stdhead (next to the donate banner),
// linking back to the new site. Injects into old/include/functions.php. Idempotent + verified.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/old/include/functions.php';

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

const OLD = '/donate.gif" alt="Make a donation" style="margin-left: 5px; margin-top: 50px;" /></a>\n<?php\n}\n?>';
const BTN = '\n\t\t\t<a href="https://hdvideo.top/" title="体验新版" style="display:inline-block;margin:50px 0 0 12px;padding:8px 20px;background:linear-gradient(180deg,#36beef,#00aeec);color:#fff !important;border-radius:20px;font-weight:bold;font-size:14px;text-decoration:none;vertical-align:top;box-shadow:0 3px 10px rgba(0,174,236,.35);">&#10024; 体验新版 &rarr;</a>';
const NEO = OLD + BTN;

const apply = process.argv.includes('--apply');
console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
console.log('Size:', content.length);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_old_functions_trynew_${stamp}.php`), content, 'utf8');

if (content.includes('title="体验新版"')) { console.log('\n>>> Already added. Nothing to do.'); process.exit(0); }
const n = content.split(OLD).length - 1;
if (n !== 1) { console.error(`ABORT: expected 1 donate-block anchor, found ${n}.`); process.exit(2); }
const patched = content.replace(OLD, NEO);
console.log('Matched donate block uniquely. delta', patched.length - content.length);
if (!apply) { console.log('\n[DRY RUN] re-run with --apply'); process.exit(0); }
console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, patched));
const v = await getFile(REMOTE);
console.log(v.includes('title="体验新版"') ? '\n>>> VERIFIED: 体验新版 button added to old site.' : '\n>>> WARNING: verification failed.');
process.exit(v.includes('title="体验新版"') ? 0 : 3);
