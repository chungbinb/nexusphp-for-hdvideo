// Convert advanced-search modal into an inline collapsible panel between search bar and quick-filters.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/public/torrents.php';
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
  { name: 'inline-container',
    old: `print('<div class="torrent-quick-filters">' . implode('', $groups) . '</div>');`,
    neo: `print('<div id="qd-adv-inline" class="qd-adv-inline" hidden></div>');\n\tprint('<div class="torrent-quick-filters">' . implode('', $groups) . '</div>');` },
  { name: 'lookup-relocate',
    old: `\tvar trigger = document.querySelector('.top-advanced-search-trigger');\n\tvar modal = document.getElementById('torrent-advanced-search-modal');\n\tif (!trigger || !modal) {\n\t\treturn;\n\t}\n\n\tvar closeNodes = modal.querySelectorAll('[data-advanced-search-close]');\n\tvar filterWrap = modal.querySelector('#ksearchboxmain');\n\tvar filterIcon = modal.querySelector('#picsearchboxmain');\n\tvar previousOverflow = '';`,
    neo: `\tvar modal = document.getElementById('torrent-advanced-search-modal');\n\tvar dialog = modal ? modal.querySelector('.torrent-advanced-search-dialog') : null;\n\tvar inline = document.getElementById('qd-adv-inline');\n\tif (!modal || !dialog || !inline) {\n\t\treturn;\n\t}\n\tinline.appendChild(dialog);\n\tmodal.style.display = 'none';\n\n\tvar closeNodes = dialog.querySelectorAll('[data-advanced-search-close]');\n\tvar filterWrap = dialog.querySelector('#ksearchboxmain');\n\tvar filterIcon = dialog.querySelector('#picsearchboxmain');` },
  { name: 'openModal',
    old: `\tfunction openModal() {\n\t\texpandFilterPanel();\n\t\tmodal.setAttribute('aria-hidden', 'false');\n\t\tbody.classList.add('searchbox-modal-open');\n\t\tpreviousOverflow = body.style.overflow;\n\t\tbody.style.overflow = 'hidden';\n\t\tvar input = modal.querySelector('#searchinput') || modal.querySelector('input[name="search"]');`,
    neo: `\tfunction openModal() {\n\t\texpandFilterPanel();\n\t\tinline.hidden = false;\n\t\tvar input = dialog.querySelector('#searchinput') || dialog.querySelector('input[name="search"]');` },
  { name: 'closeModal',
    old: `\tfunction closeModal() {\n\t\tmodal.setAttribute('aria-hidden', 'true');\n\t\tbody.classList.remove('searchbox-modal-open');\n\t\tbody.style.overflow = previousOverflow || '';\n\t}`,
    neo: `\tfunction closeModal() {\n\t\tinline.hidden = true;\n\t}` },
  { name: 'toggle-binding',
    old: `\t\t\te.preventDefault();\n\t\t\topenModal();\n\t\t});\n\t});`,
    neo: `\t\t\te.preventDefault();\n\t\t\tif (inline.hidden) { openModal(); } else { closeModal(); }\n\t\t});\n\t});` },
  { name: 'esc',
    old: `\t\tif (e.key === 'Escape' && body.classList.contains('searchbox-modal-open')) {`,
    neo: `\t\tif (e.key === 'Escape' && !inline.hidden) {` },
];

let php = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_torrents_advinline_${stamp}.php`), php, 'utf8');
if (php.includes('qd-adv-inline')) { console.log('>>> already done'); process.exit(0); }
for (const e of EDITS) { const n = php.split(e.old).length - 1; if (n !== 1) { console.error(`ABORT [${e.name}]: count ${n}`); process.exit(2); } }
for (const e of EDITS) { php = php.replace(e.old, e.neo); console.log('applied:', e.name); }
console.log('save:', (await putFile(REMOTE, php)).slice(0, 50));
const v = await getFile(REMOTE);
console.log(v.includes('qd-adv-inline') && v.includes('inline.appendChild(dialog)') ? '>>> VERIFIED' : '>>> WARNING verify');
process.exit(0);
