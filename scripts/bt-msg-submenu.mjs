// Turn the side-bar 消息 button into a left-flyout submenu (inbox/outbox/staff/contact/rss).
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

const OLD = `	<a class="qd-side-btn" href="messages.php" title="消息">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2.5"></rect><path d="M3.5 6.5l8.5 6 8.5-6"></path></svg>
		<span class="qd-side-text">消息</span>
<?php if (isset($topUnreadCount) && $topUnreadCount > 0) { ?>		<span class="qd-side-badge"><?php echo $topUnreadCount > 99 ? '99+' : $topUnreadCount ?></span>
<?php } ?>	</a>`;

const NEO = `	<div class="qd-side-msg-wrap">
	<a class="qd-side-btn" href="messages.php" title="消息">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2.5"></rect><path d="M3.5 6.5l8.5 6 8.5-6"></path></svg>
		<span class="qd-side-text">消息</span>
<?php if (isset($topUnreadCount) && $topUnreadCount > 0) { ?>		<span class="qd-side-badge"><?php echo $topUnreadCount > 99 ? '99+' : $topUnreadCount ?></span>
<?php } ?>	</a>
		<div class="qd-side-submenu">
			<a href="messages.php"><span><?php echo htmlspecialchars($topInboxLabel ?? '收件箱') ?></span><b><?php echo (int)($messages ?? 0) ?></b></a>
			<a href="messages.php?action=viewmailbox&amp;box=-1"><span><?php echo htmlspecialchars($topOutboxLabel ?? '发件箱') ?></span><b><?php echo (int)($outmessages ?? 0) ?></b></a>
<?php if (function_exists('user_can') && user_can('cheatmanage')) { ?>			<a href="cheaterbox.php"><span><?php echo htmlspecialchars($lang_functions['title_cheaterbox'] ?? '作弊者') ?></span><b><?php echo (int)($topCheaterCount ?? 0) ?></b></a>
<?php } if (function_exists('user_can') && user_can('staffmem')) { ?>			<a href="reports.php"><span><?php echo htmlspecialchars($lang_functions['title_reportbox'] ?? '举报信箱') ?></span><b><?php echo (int)($topReportCount ?? 0) ?></b></a>
<?php } if ((isset($topStaffMessageCount) && $topStaffMessageCount > 0) || (function_exists('user_can') && user_can('staffmem'))) { ?>			<a href="staffbox.php"><span><?php echo htmlspecialchars($lang_functions['title_staffbox'] ?? 'Staff Box') ?></span><b><?php echo (int)($topStaffMessageCount ?? 0) ?></b></a>
<?php } if (!empty($showTopContactStaff)) { ?>			<a href="contactstaff.php"><span><?php echo htmlspecialchars($topContactStaffLabel ?? '联系管理组') ?></span></a>
<?php } ?>			<a href="getrss.php"><span><?php echo htmlspecialchars($lang_functions['title_get_rss'] ?? 'RSS订阅') ?></span></a>
		</div>
	</div>`;

let php = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_functions_msgsub_${stamp}.php`), php, 'utf8');
if (php.includes('qd-side-msg-wrap')) { console.log('>>> already added'); process.exit(0); }
const n = php.split(OLD).length - 1;
if (n !== 1) { console.error(`ABORT: anchor count ${n}`); process.exit(2); }
php = php.replace(OLD, NEO);
console.log('save:', (await putFile(REMOTE, php)).slice(0, 50));
const v = await getFile(REMOTE);
console.log(v.includes('qd-side-msg-wrap') && v.includes('qd-side-submenu') ? '>>> VERIFIED' : '>>> WARNING verify');
process.exit(0);
