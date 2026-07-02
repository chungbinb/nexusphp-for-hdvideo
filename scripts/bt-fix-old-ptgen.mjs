// Fix old-site PTGen.listRatings nullable imdbLink (same as new-site fix) + remove temp debug from old torrents.php.
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
const getFile = async (p) => { const j = JSON.parse(await call('/files?action=GetFileBody', { action: 'GetFileBody', path: p })); if (!j.status) throw new Error('get failed ' + p); return j.data; };
const putFile = (p, c) => call('/files?action=SaveFileBody', { action: 'SaveFileBody', path: p, data: c, encoding: 'utf-8' });

// 1) PTGen.php
const PG = '/www/wwwroot/hdvideo.top/old/nexus/PTGen/PTGen.php';
let pg = await getFile(PG);
const oSig = `public function listRatings(array $ptGenData, string $imdbLink, string $desc = ''): array\n    {\n        $results = [];`;
const nSig = `public function listRatings(array $ptGenData, ?string $imdbLink = null, string $desc = ''): array\n    {\n        $imdbLink = (string)$imdbLink;\n        $results = [];`;
if (pg.includes('?string $imdbLink')) {
  console.log('PTGen: already nullable');
} else if (pg.includes(oSig)) {
  pg = pg.replace(oSig, nSig);
  console.log('PTGen save:', (await putFile(PG, pg)).slice(0, 50));
  const v = await getFile(PG);
  console.log('PTGen fixed:', v.includes('?string $imdbLink = null') && v.includes('$imdbLink = (string)$imdbLink;'));
} else {
  console.log('PTGen: anchor NOT found — needs manual check');
}

// 2) remove debug from old torrents.php
const TP = '/www/wwwroot/hdvideo.top/old/public/torrents.php';
let tp = await getFile(TP);
const lines = tp.split(/\r?\n/);
const kept = lines.filter(l => !l.includes('qd_dbg.txt'));
if (kept.length < lines.length) {
  console.log('torrents debug save:', (await putFile(TP, kept.join('\n'))).slice(0, 50));
  console.log('removed lines:', lines.length - kept.length);
} else {
  console.log('torrents: no debug line found');
}
process.exit(0);
