// Add a "轮播区" (top banner carousel) on/off switch to 基础设定 (basic settings),
// and gate the carousel render in functions.php by that setting.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const cfg = JSON.parse(fs.readFileSync(configPath, 'utf8'));
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
const getFile = async (p) => { const j = JSON.parse(await call('/files?action=GetFileBody', { action: 'GetFileBody', path: p })); if (!j.status) throw new Error('get failed: ' + p); return j.data; };
const putFile = (p, c) => call('/files?action=SaveFileBody', { action: 'SaveFileBody', path: p, data: c, encoding: 'utf-8' });
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
function countReplace(src, oldStr, newStr, label) {
  const n = src.split(oldStr).length - 1;
  if (n !== 1) { console.error(`ABORT: anchor '${label}' matched ${n} times (need 1)`); process.exit(2); }
  return src.replace(oldStr, newStr);
}

// ---------- settings.php ----------
const SETTINGS = '/www/wwwroot/hdvideo.top/public/settings.php';
let php = await getFile(SETTINGS);
fs.writeFileSync(path.join(__dirname, `_backup_settings_carousel_${stamp}.php`), php, 'utf8');
if (php.includes('show_carousel')) { console.log('>>> settings.php already has show_carousel, skipping'); }
else {
  // Edit A: add yesorno row after the BASEURL row in basicsettings form
  const baseurlAnchor = `\ttr($lang_settings['row_base_url'],"<input type='text' style=\\"width: 300px\\" name=BASEURL value='".($config["BASEURL"] ? $config["BASEURL"] : $_SERVER["HTTP_HOST"])."'> ".$lang_settings['text_it_should_be'] . $_SERVER["HTTP_HOST"] . $lang_settings['text_base_url_note'], 1);`;
  const yesornoRow = baseurlAnchor + `\n\tyesorno('轮播区', 'show_carousel', isset($config["show_carousel"]) ? $config["show_carousel"] : 'yes', '开启后页面顶部显示海报轮播区，关闭则隐藏。');`;
  php = countReplace(php, baseurlAnchor, yesornoRow, 'basicsettings BASEURL row');

  // Edit B: add show_carousel to savesettings_basic validConfig
  const validOld = `\t\t'SITENAME', 'BASEURL', 'announce_url'\n\t);`;
  const validNew = `\t\t'SITENAME', 'BASEURL', 'announce_url', 'show_carousel'\n\t);`;
  php = countReplace(php, validOld, validNew, 'savesettings_basic validConfig');

  console.log('settings.php save:', (await putFile(SETTINGS, php)).slice(0, 50));
  const v = await getFile(SETTINGS);
  console.log('settings.php verify:', v.includes("yesorno('轮播区', 'show_carousel'") && v.includes("'announce_url', 'show_carousel'") ? 'VERIFIED' : 'WARNING');
}

// ---------- functions.php ----------
const FUNCS = '/www/wwwroot/hdvideo.top/include/functions.php';
let fn = await getFile(FUNCS);
fs.writeFileSync(path.join(__dirname, `_backup_functions_carousel_${stamp}.php`), fn, 'utf8');
if (fn.includes("get_setting('basic.show_carousel')")) { console.log('>>> functions.php already gated, skipping'); }
else {
  const condOld = `<?php if (!in_array(nexus()->getScript(), ['upload', 'details'], true) && empty($GLOBALS['nexus_hide_top_banner'])) {`;
  const condNew = `<?php if (!in_array(nexus()->getScript(), ['upload', 'details'], true) && empty($GLOBALS['nexus_hide_top_banner']) && get_setting('basic.show_carousel') != 'no') {`;
  fn = countReplace(fn, condOld, condNew, 'carousel render condition');
  console.log('functions.php save:', (await putFile(FUNCS, fn)).slice(0, 50));
  const v2 = await getFile(FUNCS);
  console.log('functions.php verify:', v2.includes("get_setting('basic.show_carousel') != 'no'") ? 'VERIFIED' : 'WARNING');
}
console.log('>>> DONE');
process.exit(0);
