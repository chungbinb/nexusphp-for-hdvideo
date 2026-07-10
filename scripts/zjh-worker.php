<?php

declare(strict_types=1);

define('ZJH_RUNTIME_ONLY', true);
require dirname(__DIR__) . '/public/games/zjh/index.php';

$redis = zjh_redis();
$leaderKey = 'zjh:worker:leader';
$token = bin2hex(random_bytes(16));
$once = in_array('--once', $argv, true);

if (!$redis->set($leaderKey, $token, ['nx', 'ex' => 5])) {
    fwrite(STDOUT, "ZJH worker is already running.\n");
    exit(0);
}

$running = true;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });

try {
    $rebuilt = zjh_rebuild_deadlines();
    fwrite(STDOUT, sprintf("[%s] ZJH worker started, restored %d active rooms.\n", date('Y-m-d H:i:s'), $rebuilt));
    do {
        if ((string)$redis->get($leaderKey) !== $token) break;
        $redis->expire($leaderKey, 5);
        $processed = zjh_process_due_rooms(100);
        if ($processed > 0) fwrite(STDOUT, sprintf("[%s] processed %d due rooms.\n", date('Y-m-d H:i:s'), $processed));
        if ($once) break;
        usleep(250000);
    } while ($running);
} finally {
    if ((string)$redis->get($leaderKey) === $token) $redis->del($leaderKey);
    fwrite(STDOUT, sprintf("[%s] ZJH worker stopped.\n", date('Y-m-d H:i:s')));
}
