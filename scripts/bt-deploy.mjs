// BaoTa (宝塔) deploy helper using Node's OpenSSL (bypasses Windows schannel TLS issues).
// Usage:
//   node bt-deploy.mjs sites
//   node bt-deploy.mjs put <localFile> <remotePath>
//   node bt-deploy.mjs get <remotePath>
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = process.env.BT_CONFIG ||
  path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');

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
    const params = new URLSearchParams({ request_time: String(rt), request_token: token, ...extra });
    const body = params.toString();
    const req = https.request({
      host: u.hostname,
      port: u.port || 443,
      path: endpoint,
      method: 'POST',
      rejectUnauthorized: false,
      servername: u.hostname, // SNI; OpenSSL handles IP-literal fine
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Content-Length': Buffer.byteLength(body),
      },
      timeout: 30000,
    }, (res) => {
      let data = '';
      res.on('data', (c) => (data += c));
      res.on('end', () => resolve({ status: res.statusCode, body: data }));
    });
    req.on('error', reject);
    req.on('timeout', () => req.destroy(new Error('timeout')));
    req.write(body);
    req.end();
  });
}

const cmd = process.argv[2];

if (cmd === 'sites') {
  const r = await call('/data?action=getData', { action: 'getData', table: 'sites', limit: '200', p: '1' });
  let parsed;
  try { parsed = JSON.parse(r.body); } catch { console.log(r.status, r.body.slice(0, 800)); process.exit(0); }
  const list = parsed.data || parsed;
  for (const s of list) console.log(`${s.status ?? '?'}  ${s.name}\t-> ${s.path}`);
} else if (cmd === 'get') {
  const remote = process.argv[3];
  const r = await call('/files?action=GetFileBody', { action: 'GetFileBody', path: remote });
  process.stdout.write(r.body);
} else if (cmd === 'put') {
  const local = process.argv[3];
  const remote = process.argv[4];
  const content = fs.readFileSync(local, 'utf8');
  // SaveFileBody only writes to EXISTING files; create it first (ignore "already exists").
  const c = await call('/files?action=CreateFile', { action: 'CreateFile', path: remote });
  const r = await call('/files?action=SaveFileBody', {
    action: 'SaveFileBody', path: remote, data: content, encoding: 'utf-8',
  });
  console.log(`PUT ${local}\n -> ${remote}\n create=${c.body}\n status=${r.status} resp=${r.body}`);
} else if (cmd === 'rm') {
  const remote = process.argv[3];
  const r = await call('/files?action=DeleteFile', { action: 'DeleteFile', path: remote });
  console.log(`RM ${remote}\n resp=${r.body}`);
} else if (cmd === 'api') {
  // node bt-deploy.mjs api <endpoint> <action> [k=v ...]
  const endpoint = process.argv[3];
  const action = process.argv[4];
  const extra = { action };
  for (const kv of process.argv.slice(5)) {
    const i = kv.indexOf('=');
    if (i > 0) extra[kv.slice(0, i)] = kv.slice(i + 1);
  }
  const r = await call(endpoint, extra);
  console.log(`status=${r.status}\n${r.body}`);
} else {
  console.error('Unknown command. Use: sites | get <remote> | put <local> <remote> | rm <remote> | api <endpoint> <action> [k=v...]');
  process.exit(1);
}
