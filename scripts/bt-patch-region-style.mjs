// Surgically patch the LIVE functions.php on hdvideo.top so the upload-page
// region/style lists read settings uncached (get_setting_from_db) and reflect
// backend changes immediately. Idempotent + verified. Bypasses Windows schannel
// by using Node's bundled OpenSSL.
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
    }, (res) => {
      let data = ''; res.on('data', (c) => (data += c)); res.on('end', () => resolve({ status: res.statusCode, body: data }));
    });
    req.on('error', reject);
    req.on('timeout', () => req.destroy(new Error('timeout')));
    req.write(body); req.end();
  });
}

const getFile = async (p) => {
  const r = await call('/files?action=GetFileBody', { action: 'GetFileBody', path: p });
  const j = JSON.parse(r.body);
  if (!j.status) throw new Error('GetFileBody failed: ' + r.body.slice(0, 300));
  return j.data;
};
const putFile = async (p, content) => {
  const r = await call('/files?action=SaveFileBody', { action: 'SaveFileBody', path: p, data: content, encoding: 'utf-8' });
  return r.body;
};

// (old -> new) replacements; each must match EXACTLY once.
const REPLACEMENTS = [
  {
    old: `    $raw = get_setting("torrent_region_style.$type", '');`,
    neo: `    // 直接读数据库（不走设置缓存），保证后台改了发布页地区/风格后能立即同步到\n    // torrent_regions / torrent_styles 表，避免 legacy 侧设置缓存未刷新导致一直用默认值。\n    $raw = get_setting_from_db("torrent_region_style.$type", '');`,
  },
  {
    old: `    return get_setting('torrent_region_style.enabled', 'yes') !== 'no';`,
    neo: `    return get_setting_from_db('torrent_region_style.enabled', 'yes') !== 'no';`,
  },
  {
    old: `    return get_setting('torrent_region_style.required', 'yes') !== 'no';`,
    neo: `    return get_setting_from_db('torrent_region_style.required', 'yes') !== 'no';`,
  },
];

const apply = process.argv.includes('--apply');

console.log('Fetching live file:', REMOTE);
let content = await getFile(REMOTE);
console.log('Live size:', content.length, 'chars');

// backup
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const backup = path.join(__dirname, `_backup_functions_${stamp}.php`);
fs.writeFileSync(backup, content, 'utf8');
console.log('Backup saved:', backup);

const alreadyDone = content.includes(`get_setting_from_db("torrent_region_style.$type"`)
  && content.includes(`get_setting_from_db('torrent_region_style.enabled'`)
  && content.includes(`get_setting_from_db('torrent_region_style.required'`);
if (alreadyDone) {
  console.log('\n>>> Live file ALREADY patched. Nothing to do.');
  process.exit(0);
}

let patched = content;
for (const { old, neo } of REPLACEMENTS) {
  const count = patched.split(old).length - 1;
  if (count !== 1) {
    console.error(`\nABORT: expected exactly 1 occurrence, found ${count} for:\n${old}`);
    process.exit(2);
  }
  patched = patched.replace(old, neo);
}
console.log('All 3 replacements matched uniquely.');
console.log('New size:', patched.length, 'chars (delta', patched.length - content.length, ')');

if (!apply) {
  console.log('\n[DRY RUN] Re-run with --apply to push to live.');
  process.exit(0);
}

console.log('\nUploading patched file to live...');
const resp = await putFile(REMOTE, patched);
console.log('SaveFileBody resp:', resp);

console.log('Verifying...');
const verify = await getFile(REMOTE);
const ok = verify.includes(`get_setting_from_db("torrent_region_style.$type"`)
  && verify.includes(`get_setting_from_db('torrent_region_style.enabled'`)
  && verify.includes(`get_setting_from_db('torrent_region_style.required'`)
  && !verify.includes(`$raw = get_setting("torrent_region_style.$type"`);
console.log(ok ? '\n>>> VERIFIED: live file patched successfully.' : '\n>>> WARNING: verification did not confirm the patch.');
process.exit(ok ? 0 : 3);
