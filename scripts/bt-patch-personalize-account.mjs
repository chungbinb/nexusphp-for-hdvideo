// Persist personalization to the user account: ajax endpoints + server-side output + dropdown modal.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const AJAX = '/www/wwwroot/hdvideo.top/public/ajax.php';
const FUNCS = '/www/wwwroot/hdvideo.top/include/functions.php';
const methods = fs.readFileSync(path.join(__dirname, '_ajax_methods.txt'), 'utf8');
const headBlock = fs.readFileSync(path.join(__dirname, '_stdhead_personalize.txt'), 'utf8');
const modalV2 = fs.readFileSync(path.join(__dirname, 'qd-modal-picker-v2.html'), 'utf8');

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

const apply = process.argv.includes('--apply');
const stamp = new Date().toISOString().replace(/[:.]/g, '-');

// ---- ajax.php ----
let ajax = await getFile(AJAX);
fs.writeFileSync(path.join(__dirname, `_backup_ajax_${stamp}.php`), ajax, 'utf8');
let ajaxChanged = false;
if (!ajax.includes('function savePersonalize')) {
  const A_OLD = "}\n\n$class = 'AjaxInterface';";
  if ((ajax.split(A_OLD).length - 1) !== 1) { console.error('ABORT ajax: anchor count != 1'); process.exit(2); }
  ajax = ajax.replace(A_OLD, methods + "}\n\n$class = 'AjaxInterface';");
  ajaxChanged = true;
  console.log('ajax.php: methods inserted');
} else { console.log('ajax.php: already has methods'); }

// ---- functions.php ----
let funcs = await getFile(FUNCS);
fs.writeFileSync(path.join(__dirname, `_backup_functions_personalize_${stamp}.php`), funcs, 'utf8');
let funcsChanged = false;
// op1: stdhead server output after modern-refresh link
const LINK = '<link rel="stylesheet" href="styles/modern-refresh.css?v=<?php echo intval($modernRefreshVersion) ?>" type="text/css" />';
if (!funcs.includes('window.__QD_P__=')) {
  if ((funcs.split(LINK).length - 1) !== 1) { console.error('ABORT funcs op1: link anchor count != 1'); process.exit(2); }
  funcs = funcs.replace(LINK, LINK + headBlock);
  console.log('functions.php: stdhead output inserted');
  funcsChanged = true;
}
// op2: replace modal block with v2
const MS = '<div class="qd-modal" id="qd-personalize-modal"';
const ME = '<!-- /QD floating side tools -->';
if (!funcs.includes('qd-preset-select')) {
  const sIdx = funcs.indexOf(MS), eIdx = funcs.indexOf(ME);
  if (sIdx < 0 || eIdx < 0 || eIdx <= sIdx) { console.error('ABORT funcs op2: modal markers'); process.exit(2); }
  const span = eIdx - sIdx;
  if (span < 4000 || span > 12000) { console.error(`ABORT funcs op2: span ${span} unexpected`); process.exit(2); }
  funcs = funcs.slice(0, sIdx) + modalV2 + '\n' + funcs.slice(eIdx);
  console.log(`functions.php: modal replaced (was ${span} chars -> ${modalV2.length})`);
  funcsChanged = true;
}

if (!apply) { console.log('\n[DRY RUN] ajaxChanged=' + ajaxChanged + ' funcsChanged=' + funcsChanged); process.exit(0); }
if (ajaxChanged) { console.log('PUT ajax.php:', await putFile(AJAX, ajax)); }
if (funcsChanged) { console.log('PUT functions.php:', await putFile(FUNCS, funcs)); }
const v1 = await getFile(AJAX), v2 = await getFile(FUNCS);
const ok = v1.includes('function savePersonalize') && v2.includes('window.__QD_P__=') && v2.includes('qd-preset-select');
console.log(ok ? '\n>>> VERIFIED: account persistence + dropdown installed.' : '\n>>> WARNING: verify.');
process.exit(0);
