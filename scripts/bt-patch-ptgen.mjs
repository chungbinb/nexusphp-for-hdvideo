// Fix the fatal: PTGen::listRatings() Argument #2 ($imdbLink) must be string, null given.
// Makes $imdbLink nullable and normalizes to '' (used only via !empty()). Idempotent + verified.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/nexus/PTGen/PTGen.php';

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
      host: u.hostname, port: u.port || 443, path: endpoint, method: 'POST',
      rejectUnauthorized: false,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(body) },
      timeout: 60000,
    }, (res) => { let d = ''; res.on('data', (c) => (d += c)); res.on('end', () => resolve({ status: res.statusCode, body: d })); });
    req.on('error', reject);
    req.on('timeout', () => req.destroy(new Error('timeout')));
    req.write(body); req.end();
  });
}
const getFile = async (p) => { const r = await call('/files?action=GetFileBody', { action: 'GetFileBody', path: p }); const j = JSON.parse(r.body); if (!j.status) throw new Error('GetFileBody failed: ' + r.body.slice(0, 300)); return j.data; };
const putFile = async (p, content) => { const r = await call('/files?action=SaveFileBody', { action: 'SaveFileBody', path: p, data: content, encoding: 'utf-8' }); return r.body; };

const OLD = `    public function listRatings(array $ptGenData, string $imdbLink, string $desc = ''): array
    {
        $results = [];`;
const NEO = `    public function listRatings(array $ptGenData, ?string $imdbLink = null, string $desc = ''): array
    {
        // 某些种子没有 IMDB 链接时 $torrentInfo['url'] 为 null，旧签名要求 string 会抛
        // TypeError 致命错误，导致整个种子列表页（torrents.php）崩溃。这里允许 null 并规整为 ''。
        $imdbLink = (string) $imdbLink;
        $results = [];`;

const apply = process.argv.includes('--apply');
console.log('Fetching live file:', REMOTE);
let content = await getFile(REMOTE);
console.log('Live size:', content.length);

const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const backup = path.join(__dirname, `_backup_PTGen_${stamp}.php`);
fs.writeFileSync(backup, content, 'utf8');
console.log('Backup:', backup);

if (content.includes('$imdbLink = (string) $imdbLink;')) {
  console.log('\n>>> Already patched. Nothing to do.');
  process.exit(0);
}
const n = content.split(OLD).length - 1;
if (n !== 1) {
  console.error(`\nABORT: expected exactly 1 occurrence of the old signature, found ${n}.`);
  process.exit(2);
}
const patched = content.replace(OLD, NEO);
console.log('Matched uniquely. New size:', patched.length, '(delta', patched.length - content.length, ')');
if (!apply) { console.log('\n[DRY RUN] re-run with --apply'); process.exit(0); }

console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, patched));
const v = await getFile(REMOTE);
const ok = v.includes('$imdbLink = (string) $imdbLink;') && v.includes('?string $imdbLink = null');
console.log(ok ? '\n>>> VERIFIED: PTGen.php patched on live.' : '\n>>> WARNING: verification failed.');
process.exit(ok ? 0 : 3);
