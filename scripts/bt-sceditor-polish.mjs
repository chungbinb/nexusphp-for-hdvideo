// Polish SCEditor: auto-height (no blank), wrap content (no h-scroll), Chinese tooltips.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const UPLOAD = '/www/wwwroot/hdvideo.top/public/upload.php';
const CONTENT_CSS = '/www/wwwroot/hdvideo.top/public/vendor/sceditor/themes/content/default.min.css';
const localContentCss = path.join(repoRoot, 'public', 'vendor', 'sceditor', 'themes', 'content', 'default.min.css');

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
    }, (res) => { let d = ''; res.on('data', (c) => (d += c)); res.on('end', () => resolve(d)); });
    req.on('error', reject); req.on('timeout', () => req.destroy(new Error('timeout')));
    req.write(body); req.end();
  });
}
const getFile = async (p) => { const j = JSON.parse(await call('/files?action=GetFileBody', { action: 'GetFileBody', path: p })); if (!j.status) throw new Error('get failed ' + p); return j.data; };
const putFile = (p, c) => call('/files?action=SaveFileBody', { action: 'SaveFileBody', path: p, data: c, encoding: 'utf-8' });

// 1) content CSS: wrap + no horizontal scroll
const WRAP = `\n/* qd-wrap */\nhtml,body{overflow-x:hidden!important;}\nbody{word-wrap:break-word!important;overflow-wrap:break-word!important;word-break:break-word!important;}\n`;
let css = fs.readFileSync(localContentCss, 'utf8');
if (!css.includes('qd-wrap')) {
  css += WRAP;
  fs.writeFileSync(localContentCss, css, 'utf8');
}
console.log('content css ->', (await putFile(CONTENT_CSS, css)).slice(0, 60));

// 2) upload.php: options (autoExpand + height + v=4) and Chinese tooltips
let up = await getFile(UPLOAD);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_upload_polish_${stamp}.php`), up, 'utf8');

const optOld = `style:'/vendor/sceditor/themes/content/default.min.css?v=3',width:'100%',height:420,resizeWidth:false,emoticonsEnabled:false`;
const optNeo = `style:'/vendor/sceditor/themes/content/default.min.css?v=4',width:'100%',height:240,autoExpand:true,resizeWidth:false,emoticonsEnabled:false`;
const ttAnchor = `window.__sceDescr=inst;`;
const ttBlock = `window.__sceDescr=inst;var TT={bold:'粗体',italic:'斜体',underline:'下划线',strike:'删除线',subscript:'下标',superscript:'上标',left:'左对齐',center:'居中',right:'右对齐',justify:'两端对齐',font:'字体',size:'字号',color:'文字颜色',removeformat:'清除格式',bulletlist:'无序列表',orderedlist:'有序列表',indent:'增加缩进',outdent:'减少缩进',table:'插入表格',code:'代码块',quote:'引用',horizontalrule:'水平分割线',image:'插入图片',link:'插入链接',unlink:'取消链接',email:'邮箱',youtube:'插入视频',emoticon:'表情',date:'日期',time:'时间',print:'打印',maximize:'全屏',source:'源码/纯文本'};setTimeout(function(){var bs=document.querySelectorAll('.sceditor-button');for(var k=0;k<bs.length;k++){var c=bs[k].getAttribute('data-sceditor-command');if(c&&TT[c]){bs[k].setAttribute('title',TT[c]);bs[k].setAttribute('aria-label',TT[c]);}}},0);`;

let changed = false;
if (up.includes(optOld)) { up = up.replace(optOld, optNeo); changed = true; console.log('applied: options'); }
else if (up.includes(optNeo)) { console.log('options already set'); }
else { console.error('ABORT: options anchor not found'); process.exit(2); }

if (!up.includes('var TT={bold:')) {
  const n = up.split(ttAnchor).length - 1;
  if (n !== 1) { console.error(`ABORT: tooltip anchor count ${n}`); process.exit(2); }
  up = up.replace(ttAnchor, ttBlock); changed = true; console.log('applied: tooltips');
} else { console.log('tooltips already set'); }

if (changed) { console.log('upload.php ->', (await putFile(UPLOAD, up)).slice(0, 60)); }
const v = await getFile(UPLOAD);
console.log(v.includes('autoExpand:true') && v.includes('var TT={bold:') ? '>>> VERIFIED' : '>>> WARNING verify');
process.exit(0);
