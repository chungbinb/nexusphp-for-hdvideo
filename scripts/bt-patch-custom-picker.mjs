// Replace the personalization modal's native color inputs with a custom color picker.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/include/functions.php';
const NEWBLOCK = fs.readFileSync(path.join(__dirname, 'qd-modal-picker.html'), 'utf8');

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

const START = '<div class="qd-modal" id="qd-personalize-modal"';
const END = '<!-- /QD floating side tools -->';

const apply = process.argv.includes('--apply');
console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
console.log('Size:', content.length);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_functions_picker_${stamp}.php`), content, 'utf8');

if (content.includes('qd-pk-sv')) { console.log('\n>>> Already has custom picker.'); process.exit(0); }
const sIdx = content.indexOf(START);
const eIdx = content.indexOf(END);
if (sIdx < 0 || eIdx < 0 || eIdx <= sIdx) { console.error('ABORT: markers not found / wrong order.'); process.exit(2); }
const removed = eIdx - sIdx;
if (removed < 2000 || removed > 8000) { console.error(`ABORT: span to replace is ${removed} chars — unexpected.`); process.exit(2); }
const patched = content.slice(0, sIdx) + NEWBLOCK + '\n' + content.slice(eIdx);
console.log(`Replacing ${removed} chars with ${NEWBLOCK.length}-char new block. New size: ${patched.length}`);
if (!apply) { console.log('\n[DRY RUN] re-run with --apply'); process.exit(0); }
console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, patched));
const v = await getFile(REMOTE);
const ok = v.includes('qd-pk-sv') && v.includes('qd-side-tools') && !v.includes('<input type="color" data-var');
console.log(ok ? '\n>>> VERIFIED: custom picker installed.' : '\n>>> WARNING: verify.');
process.exit(0);
