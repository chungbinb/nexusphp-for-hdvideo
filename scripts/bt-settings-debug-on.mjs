// TEMP: add an admin-only error logger to settings.php that writes warnings/fatals to /tmp,
// so we can see why the post-save page is blank. Removed again by bt-settings-debug-off.mjs.
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
const REMOTE = '/www/wwwroot/hdvideo.top/public/settings.php';
let php = await getFile(REMOTE);
const marker = '/* qd-debug-logger */';
if (php.includes(marker)) { console.log('>>> already on'); process.exit(0); }
const anchor = `if (!ob_get_level()) { @ob_start(); }`;
if (php.split(anchor).length - 1 !== 1) { console.error('ABORT anchor'); process.exit(2); }
const dbg = anchor + `\n${marker}\n@ini_set('display_errors','0');\nerror_reporting(E_ALL);\n@file_put_contents('/tmp/qd_settings_err.log', date('c')." REQUEST action=".(isset($_REQUEST['action'])?$_REQUEST['action']:'-')." method=".$_SERVER['REQUEST_METHOD']."\\n", FILE_APPEND);\nset_error_handler(function($no,$str,$file,$line){ @file_put_contents('/tmp/qd_settings_err.log', date('c')." [".$no."] ".$str." @ ".$file.":".$line."\\n", FILE_APPEND); return false; });\nregister_shutdown_function(function(){ $e=error_get_last(); if($e){ @file_put_contents('/tmp/qd_settings_err.log', date('c')." FATAL ".json_encode($e,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\\n", FILE_APPEND); } @file_put_contents('/tmp/qd_settings_err.log', date('c')." headers_sent=".(headers_sent($hf,$hl)?('YES @ '.$hf.':'.$hl):'no')." ob_level=".ob_get_level()."\\n", FILE_APPEND); });`;
php = php.replace(anchor, dbg);
console.log('save:', (await putFile(REMOTE, php)).slice(0, 40));
const v = await getFile(REMOTE);
console.log(v.includes(marker) ? '>>> VERIFIED on' : '>>> WARN');
process.exit(0);
