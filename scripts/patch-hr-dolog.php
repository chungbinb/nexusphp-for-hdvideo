<?php
/**
 * Re-apply the HitAndRun plugin do_log() fix after composer install/update.
 *
 * Upstream xiaomlove/nexusphp-hit-and-run interpolates the PDOStatement returned by
 * NexusDB::statement() directly into a string in do_log(), which throws
 * "Object of class PDOStatement could not be converted to string" on every settings
 * save (saveSetting -> do_action('nexus_setting_update') -> actionSyncToNoPrefix()).
 *
 * vendor/ is gitignored and excluded from deploy, so this script (wired into composer's
 * post-install-cmd / post-update-cmd) keeps the fix durable on every server. Idempotent.
 */

$target = dirname(__DIR__) . '/vendor/xiaomlove/nexusphp-hit-and-run/src/HitAndRunRepository.php';

if (!is_file($target)) {
    fwrite(STDOUT, "[patch-hr-dolog] target not found, skipping: $target\n");
    exit(0);
}

$src = file_get_contents($target);
$old = 'do_log("sql: $sql, result: $result");';
$new = 'do_log("sql: $sql, result: " . (is_object($result) ? get_class($result) : var_export($result, true)));';

if (strpos($src, $old) === false) {
    fwrite(STDOUT, "[patch-hr-dolog] already patched (or pattern changed), nothing to do\n");
    exit(0);
}

$count = 0;
$src = str_replace($old, $new, $src, $count);

if (file_put_contents($target, $src) === false) {
    fwrite(STDERR, "[patch-hr-dolog] FAILED to write $target\n");
    exit(1);
}

fwrite(STDOUT, "[patch-hr-dolog] applied PDOStatement do_log fix ($count occurrence(s))\n");
exit(0);
