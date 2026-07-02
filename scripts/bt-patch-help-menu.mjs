// New-site top nav: rename 规则 dropdown -> 帮助 (non-clickable), move 规则 into submenu,
// add 管理组(staff.php), move 联系管理组 into the submenu; remove the top-right 管理组 button.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/include/functions.php';

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

const ops = [
  { label: 'rulesMenuSelected += contactstaff',
    old: 'in_array($selected, ["rules", "log", "faq"], true)',
    neo: 'in_array($selected, ["rules", "log", "faq", "contactstaff"], true)' },
  { label: 'trigger 规则 -> 帮助 (non-clickable)',
    old: '<a href=\\"rules.php\\">".$normalizeMenuText($lang_functions[\'text_rules\'])."</a>',
    neo: '<a href=\\"javascript:void(0)\\" onclick=\\"return false;\\">帮助</a>' },
  { label: 'add 规则 as first submenu item',
    old: '<ul class=\\"nav-submenu nav-rules-submenu\\">");',
    neo: '<ul class=\\"nav-submenu nav-rules-submenu\\">");\n\t\tprint ("<li" . ($selected == "rules" ? " class=\\"selected\\"" : "") . "><a href=\\"rules.php\\">".$normalizeMenuText($lang_functions[\'text_rules\'])."</a></li>");' },
  { label: 'add 管理组 + move 联系管理组 into submenu',
    old: 'print ("</ul></li>");\n\t\tprint ("<li" . ($selected == "contactstaff" ? " class=\\"selected\\"" : "") . "><a href=\\"contactstaff.php\\">".$normalizeMenuText($lang_functions[\'text_contactstaff\'])."</a></li>");',
    neo: 'print ("<li><a href=\\"staff.php\\">管理组</a></li>");\n\t\tprint ("<li" . ($selected == "contactstaff" ? " class=\\"selected\\"" : "") . "><a href=\\"contactstaff.php\\">".$normalizeMenuText($lang_functions[\'text_contactstaff\'])."</a></li>");\n\t\tprint ("</ul></li>");' },
];

const apply = process.argv.includes('--apply');
console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
console.log('Size:', content.length);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_functions_helpmenu_${stamp}.php`), content, 'utf8');

if (content.includes('return false;\\">帮助</a>') || content.includes('return false;">帮助</a>')) { console.log('\n>>> Already applied. Nothing to do.'); process.exit(0); }

for (const op of ops) {
  const n = content.split(op.old).length - 1;
  if (n !== 1) { console.error(`ABORT [${op.label}]: expected 1, found ${n}.`); process.exit(2); }
  content = content.replace(op.old, op.neo);
  console.log(`ok [${op.label}]`);
}

// op5: remove the top-right 管理组(staff) button block
const sStart = content.indexOf('<a class="top-shortcut-link top-link-game" href="staff.php"');
if (sStart < 0) { console.error('ABORT op5: staff top button not found.'); process.exit(2); }
const lineStart = content.lastIndexOf('\n', sStart);
const closeIdx = content.indexOf('>管理组</span>', sStart);
const aEnd = content.indexOf('</a>', closeIdx) + 4;
if (closeIdx < 0 || aEnd < 4 || aEnd - sStart > 800) { console.error('ABORT op5: staff button bounds unexpected.'); process.exit(2); }
content = content.slice(0, lineStart) + content.slice(aEnd);
console.log('ok [remove top-right 管理组 button]');

if (!apply) { console.log('\n[DRY RUN] re-run with --apply'); process.exit(0); }
console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, content));
const v = await getFile(REMOTE);
const ok = v.includes('>帮助</a>') && v.includes('<a href="staff.php">管理组</a>') && !v.includes('top-link-game" href="staff.php"') && !v.includes('<a href="rules.php">".$normalizeMenuText($lang_functions[\'text_rules\'])."</a><ul');
console.log(ok ? '\n>>> VERIFIED: help menu done.' : '\n>>> WARNING: verify the result.');
process.exit(0);
