// Enable the "幸运转盘" card in the game hall (games/index.php) to open /wof.php.
// Idempotent + verified. Uses Node's OpenSSL to bypass Windows schannel.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/public/games/index.php';

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

const OLD = [
  "        'title' => '幸运转盘',",
  "        'subtitle' => '每日限次抽奖，奖励覆盖电影票、临时道具和活动权益。',",
  "        'date' => '计划中',",
  "        'price' => '即将开放',",
  "        'href' => '#',",
  "        'status' => '计划中',",
  "        'tags' => ['每日', '抽奖', '活动'],",
  "        'theme' => 'wheel',",
].join('\n');
const NEO = [
  "        'title' => '幸运转盘',",
  "        'subtitle' => '每日限次抽奖，奖励覆盖电影票、临时道具和活动权益。',",
  "        'date' => '已开放',",
  "        'price' => '立即进入',",
  "        'href' => '/wof.php',",
  "        'status' => '可玩',",
  "        'tags' => ['每日', '抽奖', '活动'],",
  "        'theme' => 'wheel',",
].join('\n');

const apply = process.argv.includes('--apply');
console.log('Fetching live file:', REMOTE);
let content = await getFile(REMOTE);
console.log('Live size:', content.length);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const backup = path.join(__dirname, `_backup_games_index_${stamp}.php`);
fs.writeFileSync(backup, content, 'utf8');
console.log('Backup:', backup);

if (content.includes("'href' => '/wof.php'")) { console.log('\n>>> Already patched. Nothing to do.'); process.exit(0); }
const n = content.split(OLD).length - 1;
if (n !== 1) { console.error(`\nABORT: expected exactly 1 occurrence of the wheel card, found ${n}.`); process.exit(2); }
const patched = content.replace(OLD, NEO);
console.log('Matched the 幸运转盘 card uniquely. New size:', patched.length, '(delta', patched.length - content.length, ')');
if (!apply) { console.log('\n[DRY RUN] re-run with --apply'); process.exit(0); }

console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, patched));
const v = await getFile(REMOTE);
const ok = v.includes("'href' => '/wof.php'");
console.log(ok ? '\n>>> VERIFIED: game hall wheel card patched on live.' : '\n>>> WARNING: verification failed.');
process.exit(ok ? 0 : 3);
