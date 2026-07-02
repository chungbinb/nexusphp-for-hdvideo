// Add site-wide class-color rules for username links (XXX_Name), copying the palette the
// theme already uses for .top-account-name. Higher specificity than a{!important} so it wins.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/public/styles/modern-refresh.css';

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

const colors = [
  ['StaffLeader', '#8b0000'], ['SysOp', '#a0522d'], ['Administrator', '#4b0082'], ['Moderator', '#6495ed'],
  ['ForumModerator', '#1cc6d5'], ['Retiree', '#1cc6d5'], ['Uploader', '#dc143c'], ['VIP', '#009f00'],
  ['NexusMaster', '#38acec'], ['UltimateUser', '#006400'], ['ExtremeUser', '#ff8c00'], ['VeteranUser', '#483d8b'],
  ['InsaneUser', '#8b008b'], ['CrazyUser', '#00bfff'], ['EliteUser', '#008b8b'], ['PowerUser', '#daa520'],
  ['User', '#2d3748'], ['Peasant', '#708090'],
];
const MARK = '/* qd-username-colors-global */';
let block = '\n\n' + MARK + '\n';
for (const [name, color] of colors) {
  block += `.${name}_Name, .${name}_Name b, .${name}_Name:hover { color: ${color} !important; }\n`;
}

const apply = process.argv.includes('--apply');
console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
console.log('Size:', content.length);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_modern-refresh2_${stamp}.css`), content, 'utf8');

if (content.includes(MARK)) { console.log('\n>>> Already added. Nothing to do.'); process.exit(0); }
const patched = content + block;
console.log('Appending', colors.length, 'username color rules. delta', patched.length - content.length);
if (!apply) { console.log('\n[DRY RUN] re-run with --apply'); process.exit(0); }
console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, patched));
const v = await getFile(REMOTE);
console.log(v.includes(MARK) && v.includes('.SysOp_Name, .SysOp_Name b') ? '\n>>> VERIFIED: global username colors added.' : '\n>>> WARNING: verification failed.');
process.exit(0);
