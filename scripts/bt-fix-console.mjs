// Fix torrents.php console spam: (1) DOCTYPE -> standards mode; (2) stop douban mirror-cycling retry storm.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');

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
const getFile = async (p) => { const r = await call('/files?action=GetFileBody', { action: 'GetFileBody', path: p }); const j = JSON.parse(r.body); if (!j.status) throw new Error('GetFileBody failed: ' + p); return j.data; };
const putFile = async (p, c) => (await call('/files?action=SaveFileBody', { action: 'SaveFileBody', path: p, data: c, encoding: 'utf-8' })).body;
const stamp = new Date().toISOString().replace(/[:.]/g, '-');

async function patch(remote, old, neo, marker, label) {
  console.log(`\n[${label}] ${remote}`);
  let content = await getFile(remote);
  fs.writeFileSync(path.join(__dirname, `_backup_${label}_${stamp}.txt`), content, 'utf8');
  if (content.includes(marker)) { console.log('  already patched'); return; }
  const n = content.split(old).length - 1;
  if (n !== 1) { console.error(`  ABORT: expected 1 match, found ${n}.`); process.exit(2); }
  content = content.replace(old, neo);
  console.log('  resp:', await putFile(remote, content));
  const v = await getFile(remote);
  console.log(v.includes(marker) ? '  VERIFIED' : '  WARNING: verify');
}

await patch(
  '/www/wwwroot/hdvideo.top/include/functions.php',
  `<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">`,
  `<!DOCTYPE html>`,
  `<!DOCTYPE html>\n`,
  'doctype'
);
await patch(
  '/www/wwwroot/hdvideo.top/public/torrents.php',
  `var domains = ['img1.doubanio.com', 'img2.doubanio.com', 'img3.doubanio.com', 'img9.doubanio.com'];`,
  `var domains = []; /* qd: no douban mirror-cycling (all mirrors hotlink-block the same -> console spam) */`,
  `/* qd: no douban mirror-cycling`,
  'doubancycle'
);
console.log('\n>>> done.');
process.exit(0);
