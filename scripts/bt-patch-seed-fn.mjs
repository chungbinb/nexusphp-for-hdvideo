// Surgically replace hdvideo_seed_filter_options on LIVE functions.php so the
// region/style sync stops burning the SMALLINT auto_increment (INSERT...ON DUPLICATE).
// Idempotent + verified. Uses Node's OpenSSL to bypass Windows schannel.
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

const OLD = `function hdvideo_seed_filter_options($table, array $names)
{
    $count = count($names);
    $now = sqlesc(date('Y-m-d H:i:s'));
    $listedNames = [];
    foreach ($names as $index => $name) {
        $name = trim((string)$name);
        if ($name === '') {
            continue;
        }
        $listedNames[] = sqlesc($name);
        $sortIndex = $count - $index;
        hdvideo_run_schema_sql("INSERT INTO \`$table\` (name, sort_index, enabled, created_at, updated_at) VALUES (" . sqlesc($name) . ", $sortIndex, 1, $now, $now) ON DUPLICATE KEY UPDATE sort_index = VALUES(sort_index), enabled = VALUES(enabled), updated_at = VALUES(updated_at)");
    }
    if ($listedNames) {
        hdvideo_run_schema_sql("UPDATE \`$table\` SET enabled = 0, updated_at = $now WHERE name NOT IN (" . implode(',', $listedNames) . ")");
    }
}`;

const NEO = `function hdvideo_seed_filter_options($table, array $names)
{
    $now = sqlesc(date('Y-m-d H:i:s'));
    // 先读出已有 name => id，避免用 INSERT ... ON DUPLICATE KEY UPDATE。
    // 后者即使命中"重复→更新"分支，InnoDB 仍会白白消耗一个自增值，
    // 列表页每次加载都同步一遍，会很快把 SMALLINT 自增主键烧到 65535 上限，
    // 导致之后任何新地区/风格都插不进来。这里改成：已存在只 UPDATE，只有新名字才 INSERT。
    $existing = [];
    $res = @sql_query("SELECT id, name FROM \`$table\`");
    if ($res) {
        while ($row = mysql_fetch_assoc($res)) {
            $existing[(string)$row['name']] = (int)$row['id'];
        }
    }
    $clean = [];
    foreach ($names as $name) {
        $name = trim((string)$name);
        if ($name !== '' && !in_array($name, $clean, true)) {
            $clean[] = $name;
        }
    }
    $count = count($clean);
    $listedNames = [];
    foreach ($clean as $index => $name) {
        $listedNames[] = sqlesc($name);
        $sortIndex = $count - $index;
        if (isset($existing[$name])) {
            hdvideo_run_schema_sql("UPDATE \`$table\` SET sort_index = $sortIndex, enabled = 1, updated_at = $now WHERE id = " . $existing[$name]);
        } else {
            hdvideo_run_schema_sql("INSERT INTO \`$table\` (name, sort_index, enabled, created_at, updated_at) VALUES (" . sqlesc($name) . ", $sortIndex, 1, $now, $now)");
        }
    }
    if ($listedNames) {
        hdvideo_run_schema_sql("UPDATE \`$table\` SET enabled = 0, updated_at = $now WHERE name NOT IN (" . implode(',', $listedNames) . ")");
    }
}`;

const apply = process.argv.includes('--apply');
console.log('Fetching live file:', REMOTE);
let content = await getFile(REMOTE);
console.log('Live size:', content.length);

const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const backup = path.join(__dirname, `_backup_functions_seed_${stamp}.php`);
fs.writeFileSync(backup, content, 'utf8');
console.log('Backup:', backup);

if (content.includes('已存在只 UPDATE，只有新名字才 INSERT')) {
  console.log('\n>>> Already patched. Nothing to do.');
  process.exit(0);
}
const n = content.split(OLD).length - 1;
if (n !== 1) {
  console.error(`\nABORT: expected exactly 1 occurrence of the old function, found ${n}.`);
  process.exit(2);
}
const patched = content.replace(OLD, NEO);
console.log('Matched old function uniquely. New size:', patched.length, '(delta', patched.length - content.length, ')');
if (!apply) { console.log('\n[DRY RUN] re-run with --apply'); process.exit(0); }

console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, patched));
const v = await getFile(REMOTE);
const ok = v.includes('已存在只 UPDATE，只有新名字才 INSERT') && !v.includes('ON DUPLICATE KEY UPDATE sort_index = VALUES(sort_index)');
console.log(ok ? '\n>>> VERIFIED: seed fn patched on live.' : '\n>>> WARNING: verification failed.');
process.exit(ok ? 0 : 3);
