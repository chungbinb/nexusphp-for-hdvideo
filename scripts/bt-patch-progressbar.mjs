// Restyle renderProgressBar to a clean 2px green progress bar (class-based, styled via CSS).
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/nexus/Torrent/Torrent.php';

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

const OLD = `            '<div style="padding: 1px;margin-top: 2px;border: 1px solid #838383" title="%s"><div style="width: %s;background-color: %s;height: 2px"></div></div>',
            $activeStatus . " $progress", $progress, $color`;
const NEO = `            '<div class="qd-dl-progress" data-status="%s" title="%s"><i style="width: %s"></i></div>',
            $activeStatus, $activeStatus . " $progress", $progress`;

console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_Torrent_progressbar_${stamp}.php`), content, 'utf8');
if (content.includes('qd-dl-progress')) { console.log('>>> Already patched.'); process.exit(0); }
const n = content.split(OLD).length - 1;
if (n !== 1) { console.error(`ABORT: expected 1 match, found ${n}.`); process.exit(2); }
content = content.replace(OLD, NEO);
console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, content));
const v = await getFile(REMOTE);
console.log(v.includes('qd-dl-progress') ? '>>> VERIFIED: renderProgressBar restyled.' : '>>> WARNING: verify.');
process.exit(0);
