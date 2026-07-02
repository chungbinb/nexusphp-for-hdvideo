// Fix fatal "Object of class PDOStatement could not be converted to string" in the
// HitAndRun plugin: do_log() interpolates the PDOStatement returned by NexusDB::statement().
// This fires on every saveSetting() (via do_action 'nexus_setting_update') and breaks ALL
// settings-page saves. Make the log line not stringify the statement object.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const cfg = JSON.parse(fs.readFileSync(path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json'), 'utf8'));
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
const REMOTE = '/www/wwwroot/hdvideo.top/vendor/xiaomlove/nexusphp-hit-and-run/src/HitAndRunRepository.php';

let php = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_HitAndRunRepository_${stamp}.php`), php, 'utf8');

const oldStr = 'do_log("sql: $sql, result: $result");';
const newStr = 'do_log("sql: $sql, result: " . (is_object($result) ? get_class($result) : var_export($result, true)));';
const n = php.split(oldStr).length - 1;
if (n !== 2) { console.error(`ABORT: expected 2 occurrences, found ${n}`); process.exit(2); }
php = php.split(oldStr).join(newStr);

console.log('save:', (await putFile(REMOTE, php)).slice(0, 40));
const v = await getFile(REMOTE);
const remaining = v.split(oldStr).length - 1;
const applied = v.split(newStr).length - 1;
console.log('verify: remaining buggy =', remaining, ' fixed lines =', applied);
console.log(remaining === 0 && applied === 2 ? '>>> VERIFIED' : '>>> WARNING');
process.exit(0);
