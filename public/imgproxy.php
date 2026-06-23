<?php
/**
 * Same-origin image proxy for external poster hosts (douban / amazon-imdb).
 * Bypasses cross-origin hotlink protection + Firefox OpaqueResponseBlocking by
 * fetching server-side (with a host-friendly Referer) and serving same-origin + cached.
 * SSRF-safe: only whitelisted image hosts are allowed.
 */

$u = isset($_GET['u']) ? trim((string)$_GET['u']) : '';
if ($u === '' || strlen($u) > 1024) {
    http_response_code(400);
    exit;
}
$parts = @parse_url($u);
if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
    http_response_code(400);
    exit;
}
$scheme = strtolower($parts['scheme']);
$host = strtolower($parts['host']);
if ($scheme !== 'https' && $scheme !== 'http') {
    http_response_code(400);
    exit;
}

// strict host whitelist (suffix match)
$allowedSuffixes = ['doubanio.com', 'douban.com', 'media-amazon.com', 'ssl-images-amazon.com', 'tmdb.org'];
$allowed = false;
foreach ($allowedSuffixes as $suffix) {
    if ($host === $suffix || substr($host, -(strlen($suffix) + 1)) === '.' . $suffix) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    http_response_code(403);
    exit;
}

$cacheDir = __DIR__ . '/pic/imgproxy_cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
$key = sha1($u);
$cacheFile = $cacheDir . '/' . $key;
$typeFile = $cacheFile . '.type';
$ttl = 14 * 24 * 3600;

if (is_file($cacheFile) && filesize($cacheFile) > 0 && (time() - filemtime($cacheFile) < $ttl)) {
    $ctype = is_file($typeFile) ? trim((string)@file_get_contents($typeFile)) : 'image/jpeg';
    if (stripos($ctype, 'image/') !== 0) {
        $ctype = 'image/jpeg';
    }
    header('Content-Type: ' . $ctype);
    header('Cache-Control: public, max-age=1209600, immutable');
    header('X-Img-Cache: HIT');
    readfile($cacheFile);
    exit;
}

$referer = (strpos($host, 'douban') !== false) ? 'https://movie.douban.com/' : 'https://www.imdb.com/';
$ch = curl_init($u);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_REFERER => $referer,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HEADER => false,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
]);
$body = curl_exec($ch);
$ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false || $code !== 200 || stripos($ctype, 'image/') !== 0 || strlen($body) < 100) {
    http_response_code(404);
    exit;
}

@file_put_contents($cacheFile, $body, LOCK_EX);
@file_put_contents($typeFile, $ctype, LOCK_EX);

header('Content-Type: ' . $ctype);
header('Cache-Control: public, max-age=1209600, immutable');
header('X-Img-Cache: MISS');
echo $body;
