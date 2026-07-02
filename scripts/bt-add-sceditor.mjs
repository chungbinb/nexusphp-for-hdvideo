// Add SCEditor WYSIWYG to the upload-page description editor (default WYSIWYG + source toggle), scoped to upload.php.
import fs from 'node:fs';
import crypto from 'node:crypto';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(__dirname);
const configPath = path.resolve(repoRoot, '..', '正式站点文件', '.btpanel.local.prod.json');
const REMOTE = '/www/wwwroot/hdvideo.top/public/upload.php';

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

const OLD = `\\Nexus\\Nexus::js($customFieldJs, 'footer', false);`;
const BLOCK = `
?>
<link rel="stylesheet" href="/vendor/sceditor/themes/default.min.css?v=3">
<script src="/vendor/sceditor/sceditor.min.js?v=3"></script>
<script src="/vendor/sceditor/formats/bbcode.js?v=3"></script>
<script src="/vendor/sceditor/icons/monocons.js?v=3"></script>
<script>
/* SCEditor WYSIWYG for the 简介 editor (upload page only). Toolbar 'source' = 纯文本/源码 toggle. */
(function(){
  function boot(){
    var ta=document.getElementById('descr');
    if(!ta||typeof sceditor==='undefined'||ta.getAttribute('data-sce')==='1')return;
    try{
      sceditor.create(ta,{format:'bbcode',icons:'monocons',style:'/vendor/sceditor/themes/content/default.min.css?v=3',width:'100%',height:420,resizeWidth:false,emoticonsEnabled:false,toolbar:'bold,italic,underline,strike,subscript,superscript|left,center,right,justify|font,size,color,removeformat|bulletlist,orderedlist,indent,outdent|table|code,quote|horizontalrule,image,link,unlink,youtube|maximize,source'});
      var inst=sceditor.instance(ta);
      if(!inst)return;
      ta.setAttribute('data-sce','1');
      window.__sceDescr=inst;
      var wrap=ta.closest('.nexus-bbcode-editor');
      if(wrap){var tb=wrap.querySelector('.nexus-bbcode-toolbar');if(tb){tb.style.display='none';}var sm=wrap.querySelector('.nexus-bbcode-smilies');if(sm){sm.style.display='none';}}
      function toTextarea(){try{inst.updateOriginal();}catch(e){try{ta.value=inst.val();}catch(_){}}}
      function toEditor(){try{inst.val(ta.value);}catch(e){}}
      if(typeof window.doInsert==='function'){var _di=window.doInsert;window.doInsert=function(o,c,s){toTextarea();var r=_di(o,c,s);toEditor();return r;};}
      if(typeof window.clearContent==='function'){var _cc=window.clearContent;window.clearContent=function(){_cc();toEditor();};}
      if(typeof window.textBBCodePreview==='function'){var _pv=window.textBBCodePreview;window.textBBCodePreview=function(){toTextarea();return _pv();};}
      var form=document.getElementById('compose');
      if(form&&form.addEventListener){form.addEventListener('submit',toTextarea,true);}
    }catch(e){if(window.console){console.warn('SCEditor init failed',e);}}
  }
  if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',boot);}else{boot();}
})();
</script>
<?php
`;
const NEO = OLD + BLOCK;

console.log('Fetching:', REMOTE);
let content = await getFile(REMOTE);
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
fs.writeFileSync(path.join(__dirname, `_backup_upload_sceditor_${stamp}.php`), content, 'utf8');
if (content.includes('SCEditor WYSIWYG for the')) { console.log('>>> Already added.'); process.exit(0); }
const n = content.split(OLD).length - 1;
if (n !== 1) { console.error(`ABORT: expected 1 match for anchor, found ${n}.`); process.exit(2); }
content = content.replace(OLD, NEO);
console.log('Uploading...');
console.log('resp:', await putFile(REMOTE, content));
const v = await getFile(REMOTE);
console.log(v.includes('sceditor.create(ta') ? '>>> VERIFIED: SCEditor added to upload page.' : '>>> WARNING: verify.');
process.exit(0);
