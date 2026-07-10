<?php
require '../../include/bittorrent.php';
dbconn();
loggedinorreturn();
require_once '../../include/game_control.php';

$destinations = [
    'lucky-draw' => '/plugin/lucky-draw',
];
$key = (string)($_GET['game'] ?? '');
if (!isset($destinations[$key])) {
    header('Location: /games/');
    exit;
}
game_presence_touch($key, (int)$CURUSER['id']);
header('Location: ' . $destinations[$key]);
exit;
