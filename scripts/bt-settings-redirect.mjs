// Fix settings save -> reliable server-side redirect to settings home (POST-redirect-GET via output buffering).
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

const EDITS = [
  { name: 'ob-start',
    old: `if (get_user_class() < UC_SYSOP)\n9: permissiondenied();`,  // placeholder, replaced below
    skip: true },
];

let php = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_settings_redirect_${stamp}.php`), php, 'utf8');
if (php.includes("header('Location: settings.php')")) { console.log('>>> already done'); process.exit(0); }

// Edit 1: add ob_start() right after the permission check
const permOld = `if (get_user_class() < UC_SYSOP)\n    permissiondenied();`;
const permOld2 = `if (get_user_class() < UC_SYSOP)\n\tpermissiondenied();`;
let permAnchor = php.includes(permOld) ? permOld : (php.includes(permOld2) ? permOld2 : null);
if (!permAnchor) {
  // fallback: just match the bare permissiondenied(); (first occurrence)
  permAnchor = `permissiondenied();`;
  if (php.split(permAnchor).length - 1 !== 1) { console.error('ABORT: permissiondenied anchor not unique'); process.exit(2); }
}
php = php.replace(permAnchor, permAnchor + `\nif (!ob_get_level()) { @ob_start(); }`);

// Edit 2: rewrite go_back() to a clean server-side redirect
const gbOld = `function go_back()\n{\n\tglobal $lang_settings;\n\tstdmsg($lang_settings['std_message'], $lang_settings['std_click']."<a class=\\"altlink\\" href=\\"settings.php\\">".$lang_settings['std_here']."</a>".$lang_settings['std_to_go_back']);\n\t// 保存后短暂显示"已保存"提示，然后自动跳回设定主页（用 replace 避免后退时重复提交表单）。\n\techo "<script>setTimeout(function(){ location.replace('settings.php'); }, 800);</script>";\n}`;
const gbNew = `function go_back()\n{\n\twhile (ob_get_level() > 0) { @ob_end_clean(); }\n\theader('Location: settings.php');\n\texit;\n}`;
if (php.includes(gbOld)) {
  php = php.replace(gbOld, gbNew);
  console.log('go_back rewritten (exact)');
} else {
  // fallback: replace just the function signature+open and body lines individually
  const sig = `function go_back()`;
  const idx = php.indexOf(sig);
  if (idx < 0) { console.error('ABORT: go_back not found'); process.exit(2); }
  const closeIdx = php.indexOf('\n}', idx);
  php = php.slice(0, idx) + gbNew + php.slice(closeIdx + 2);
  console.log('go_back rewritten (range fallback)');
}

console.log('save:', (await putFile(REMOTE, php)).slice(0, 50));
const v = await getFile(REMOTE);
console.log(v.includes("header('Location: settings.php')") && v.includes('ob_start()') ? '>>> VERIFIED' : '>>> WARNING verify');
process.exit(0);
