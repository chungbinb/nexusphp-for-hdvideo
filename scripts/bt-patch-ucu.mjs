// Fix the infinite recursion in user_can_upload() (stderr->stdhead->user_can_upload)
// that makes pages time out at 100s for users who hit the upload-deny limit.
// Extracts OLD (original) and NEO (fixed) byte-exact from local files to avoid escaping issues.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/include/functions.php';
const LOCAL_FIXED = path.join(repoRoot, 'include', 'functions.php');           // has NEO
const LOCAL_ORIG = path.join(__dirname, '_backup_functions_seed_2026-06-19T10-01-50-768Z.php'); // has OLD (original user_can_upload)

const START = 'function user_can_upload($where = "torrents"){';
const END = 'if ($where == "torrents")';

function extractRegion(file) {
  const s = fs.readFileSync(file, 'utf8');
  const a = s.indexOf(START);
  if (a < 0) throw new Error('START not found in ' + file);
  const b = s.indexOf(END, a);
  if (b < 0) throw new Error('END not found in ' + file);
  return s.slice(a, b);
}
const OLD = extractRegion(LOCAL_ORIG);
const NEO = extractRegion(LOCAL_FIXED);
if (OLD === NEO) throw new Error('OLD and NEO are identical — local fix missing?');
if (!NEO.includes('denyCheckInProgress')) throw new Error('NEO missing the guard — wrong local file?');

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
console.log('OLD region', OLD.length, 'chars; NEO region', NEO.length, 'chars');
console.log('Fetching live file:', REMOTE);
let content = await getFile(REMOTE);
console.log('Live size:', content.length);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const backup = path.join(__dirname, `_backup_functions_ucu_${stamp}.php`);
fs.writeFileSync(backup, content, 'utf8');
console.log('Backup:', backup);

if (content.includes('denyCheckInProgress')) { console.log('\n>>> Already patched. Nothing to do.'); process.exit(0); }
const n = content.split(OLD).length - 1;
if (n !== 1) { console.error(`\nABORT: expected exactly 1 occurrence of OLD region, found ${n}.`); process.exit(2); }
const patched = content.replace(OLD, NEO);
console.log('Matched uniquely. New size:', patched.length, '(delta', patched.length - content.length, ')');
if (!apply) { console.log('\n[DRY RUN] re-run with --apply'); process.exit(0); }

console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, patched));
const v = await getFile(REMOTE);
const ok = v.includes('denyCheckInProgress') && (v.split('function user_can_upload').length - 1) === 1;
console.log(ok ? '\n>>> VERIFIED: functions.php user_can_upload patched on live.' : '\n>>> WARNING: verification failed.');
process.exit(ok ? 0 : 3);
