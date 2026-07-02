// Redesign settings.php into a top-tab interface: tabs on top, section content below.
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

let php = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_settings_tabs_${stamp}.php`), php, 'utf8');
if (php.includes('qd_settings_tabs')) { console.log('>>> already done'); process.exit(0); }

// Edit 1: $action reads POST, then GET (view-only), default basicsettings
const actOld = `$action = isset($_POST['action']) ? $_POST['action'] : 'showmenu';`;
const actNew = `$action = 'basicsettings';\nif (isset($_POST['action'])) {\n\t$action = $_POST['action'];\n} elseif (isset($_GET['action']) && strpos((string)$_GET['action'], 'savesettings') !== 0) {\n\t$action = $_GET['action'];\n}`;
if (php.split(actOld).length - 1 !== 1) { console.error('ABORT: action anchor'); process.exit(2); }
php = php.replace(actOld, actNew);

// Edit 2: go_back -> redirect to the saved section + define qd_settings_tabs()
const gbOld = `function go_back()\n{\n\twhile (ob_get_level() > 0) { @ob_end_clean(); }\n\theader('Location: settings.php');\n\texit;\n}`;
const gbNew = `function go_back()\n{\n\tglobal $action;\n\t$view = preg_replace('/^savesettings_/', '', (string)$action) . 'settings';\n\tif (!preg_match('/^[a-z]+settings$/', $view)) { $view = 'basicsettings'; }\n\twhile (ob_get_level() > 0) { @ob_end_clean(); }\n\theader('Location: settings.php?action=' . urlencode($view));\n\texit;\n}\n\nfunction qd_settings_tabs($active)\n{\n\tglobal $lang_settings;\n\t$tabs = array(\n\t\t'basicsettings' => $lang_settings['row_basic_settings'] ?? '基础设定',\n\t\t'mainsettings' => $lang_settings['row_main_settings'] ?? '主要设定',\n\t\t'smtpsettings' => $lang_settings['row_smtp_settings'] ?? 'SMTP',\n\t\t'securitysettings' => $lang_settings['row_security_settings'] ?? '安全',\n\t\t'authoritysettings' => $lang_settings['row_authority_settings'] ?? '权限',\n\t\t'tweaksettings' => $lang_settings['row_tweak_settings'] ?? '优化',\n\t\t'bonussettings' => $lang_settings['row_bonus_settings'] ?? '魔力',\n\t\t'accountsettings' => $lang_settings['row_account_settings'] ?? '账号',\n\t\t'torrentsettings' => $lang_settings['row_torrents_settings'] ?? '种子',\n\t\t'attachmentsettings' => $lang_settings['row_attachment_settings'] ?? '附件',\n\t\t'advertisementsettings' => $lang_settings['row_advertisement_settings'] ?? '广告',\n\t\t'miscsettings' => $lang_settings['row_misc_settings'] ?? '杂项',\n\t);\n\t$activeView = (strpos((string)$active, 'savesettings_') === 0) ? (preg_replace('/^savesettings_/', '', (string)$active) . 'settings') : (string)$active;\n\t$h = '<div class="qd-settings-tabs">';\n\tforeach ($tabs as $act => $label) {\n\t\t$on = ($activeView === $act) ? ' is-active' : '';\n\t\t$h .= '<a class="qd-settings-tab' . $on . '" href="settings.php?action=' . $act . '">' . htmlspecialchars(trim(strip_tags((string)$label))) . '</a>';\n\t}\n\t$h .= '</div>';\n\treturn $h;\n}`;
if (php.split(gbOld).length - 1 !== 1) { console.error('ABORT: go_back anchor'); process.exit(2); }
php = php.replace(gbOld, gbNew);

// Edit 3: inject the tab bar after every settings stdhead
let injected = 0;
php = php.replace(/stdhead\(\$lang_settings\['[^']+'\]\);/g, (m) => { injected++; return m + "\n\techo qd_settings_tabs($action);"; });
console.log('stdhead injections:', injected);

console.log('save:', (await putFile(REMOTE, php)).slice(0, 50));
const v = await getFile(REMOTE);
console.log(v.includes('function qd_settings_tabs') && v.includes('echo qd_settings_tabs($action);') && v.includes("header('Location: settings.php?action='") ? '>>> VERIFIED' : '>>> WARNING verify');
process.exit(0);
