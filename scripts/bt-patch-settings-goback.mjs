// Make settings.php auto-return to the settings menu after saving a sub-section.
// Inserts a JS redirect at the end of go_back(). Regex-based (whitespace tolerant),
// idempotent + verified. Uses Node's OpenSSL to bypass Windows schannel.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/public/settings.php';

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

const apply = process.argv.includes('--apply');
console.log('Fetching live file:', REMOTE);
let content = await getFile(REMOTE);
console.log('Live size:', content.length);

const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const backup = path.join(__dirname, `_backup_settings_${stamp}.php`);
fs.writeFileSync(backup, content, 'utf8');
console.log('Backup:', backup);

if (content.includes("location.replace('settings.php')")) {
  console.log('\n>>> Already patched. Nothing to do.');
  process.exit(0);
}

// Match the go_back() body up to the end of the stdmsg(...) call, then its closing brace.
const re = /(function go_back\(\)\s*\{[\s\S]*?std_to_go_back'\]\);)([ \t]*\r?\n)(\})/;
const matches = content.match(new RegExp(re, 'g'));
if (!matches || matches.length !== 1) {
  console.error(`\nABORT: expected exactly 1 go_back() match, found ${matches ? matches.length : 0}.`);
  process.exit(2);
}
const inject = `\n\t// 保存后短暂显示"已保存"提示，然后自动跳回设定主页（用 replace 避免后退时重复提交表单）。\n\techo "<script>setTimeout(function(){ location.replace('settings.php'); }, 800);</script>";\n`;
const patched = content.replace(re, (m, p1, p2, p3) => p1 + inject + p3);
console.log('Matched go_back() uniquely. New size:', patched.length, '(delta', patched.length - content.length, ')');

if (!apply) { console.log('\n[DRY RUN] re-run with --apply'); process.exit(0); }

console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, patched));
const v = await getFile(REMOTE);
const ok = v.includes("location.replace('settings.php')");
console.log(ok ? '\n>>> VERIFIED: settings.php patched on live.' : '\n>>> WARNING: verification failed.');
process.exit(ok ? 0 : 3);
