<?php
require '../../include/bittorrent.php';
dbconn();
loggedinorreturn();
require_once '../../include/game_control.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = (string)($_POST['game'] ?? '');
    if (!in_array($key, game_presence_allowed_keys(), true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => '无效的游戏。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    game_presence_touch($key, (int)$CURUSER['id']);
    echo json_encode(['ok' => true]);
    exit;
}

$keys = game_presence_allowed_keys();
echo json_encode(['ok' => true, 'counts' => game_presence_counts($keys)], JSON_UNESCAPED_UNICODE);
