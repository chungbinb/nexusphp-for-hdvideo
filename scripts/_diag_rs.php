<?php
// TEMP diagnostic for region/style upload sync. Token-protected. DELETE after use.
$TOKEN = 'rsdiag_7Kq2play9Zx';
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['k'] ?? '') !== $TOKEN) { http_response_code(404); echo "not found"; exit; }

require_once("../include/bittorrent.php");
dbconn();

function dump($label, $v) {
    echo "==== $label ====\n";
    if (is_array($v)) { echo var_export($v, true) . "\n\n"; }
    else { echo (string)$v . "\n\n"; }
}

// 1) raw settings table
echo "==== settings table (raw SQL) ====\n";
$res = sql_query("SELECT name, autoload, value FROM settings WHERE name LIKE 'torrent_region_style%' ORDER BY name");
while ($r = mysql_fetch_assoc($res)) {
    echo $r['name'] . " | autoload=" . $r['autoload'] . " | value=" . str_replace("\n", "\\n", (string)$r['value']) . "\n";
}
echo "\n";

// 2) cached vs uncached reads
dump("get_setting('regions') [cached]", get_setting('torrent_region_style.regions', '(empty)'));
dump("get_setting_from_db('regions') [uncached]", get_setting_from_db('torrent_region_style.regions', '(empty)'));
dump("get_setting('styles') [cached]", get_setting('torrent_region_style.styles', '(empty)'));
dump("get_setting_from_db('styles') [uncached]", get_setting_from_db('torrent_region_style.styles', '(empty)'));

// 3) what the seeding function actually parses (tells us if OPcache serves patched code)
if (function_exists('hdvideo_region_style_option_names')) {
    dump("hdvideo_region_style_option_names('regions')", hdvideo_region_style_option_names('regions'));
    dump("hdvideo_region_style_option_names('styles')", hdvideo_region_style_option_names('styles'));
} else {
    echo "hdvideo_region_style_option_names NOT DEFINED\n\n";
}

// 3b) is the running functions.php the patched one? (reflect source lines)
try {
    $rf = new ReflectionFunction('hdvideo_region_style_option_names');
    $file = $rf->getFileName();
    $lines = file($file);
    $src = implode('', array_slice($lines, $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1));
    echo "==== running hdvideo_region_style_option_names source (file=$file) ====\n$src\n\n";
} catch (\Throwable $e) { echo "reflect error: " . $e->getMessage() . "\n\n"; }

// 4) tables
foreach (['torrent_regions', 'torrent_styles'] as $t) {
    echo "==== table $t ====\n";
    $res = @sql_query("SELECT id, name, sort_index, enabled FROM `$t` ORDER BY sort_index DESC, id ASC");
    if (!$res) { echo "(query failed / table missing)\n\n"; continue; }
    $n = 0;
    while ($r = mysql_fetch_assoc($res)) { echo $r['id'] . "\t" . $r['name'] . "\tsort=" . $r['sort_index'] . "\tenabled=" . $r['enabled'] . "\n"; $n++; }
    echo "rows=$n\n\n";
}

// 5) enabled/required + opcache
dump("hdvideo_region_style_enabled()", function_exists('hdvideo_region_style_enabled') ? (hdvideo_region_style_enabled() ? 'true' : 'false') : 'N/A');
dump("hdvideo_region_style_required()", function_exists('hdvideo_region_style_required') ? (hdvideo_region_style_required() ? 'true' : 'false') : 'N/A');

echo "==== opcache ====\n";
if (function_exists('opcache_get_configuration')) {
    $cfg = opcache_get_configuration();
    echo "opcache.enable=" . var_export($cfg['directives']['opcache.enable'] ?? null, true) . "\n";
    echo "opcache.validate_timestamps=" . var_export($cfg['directives']['opcache.validate_timestamps'] ?? null, true) . "\n";
    echo "opcache.revalidate_freq=" . var_export($cfg['directives']['opcache.revalidate_freq'] ?? null, true) . "\n";
    if (function_exists('opcache_get_status')) {
        $st = @opcache_get_status(false);
        $scripts = $st['scripts'] ?? [];
        foreach ($scripts as $path => $info) {
            if (strpos($path, 'functions.php') !== false) {
                echo "cached: $path ts=" . ($info['timestamp'] ?? '?') . " (file mtime=" . @filemtime($path) . ")\n";
            }
        }
    }
} else { echo "opcache not available\n"; }
echo "\nDONE\n";
