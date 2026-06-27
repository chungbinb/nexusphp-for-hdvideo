<?php
require "../include/bittorrent.php";
dbconn();
loggedinorreturn();
require_once "../include/bank.php";

header('Content-Type: application/json');
$uid = (int)$CURUSER['id'];
$action = $_REQUEST['action'] ?? 'status';

if ($action === 'status') {
    echo json_encode(['ok' => true] + bank_status($uid), JSON_UNESCAPED_UNICODE);
    exit;
}

if (in_array($action, ['deposit', 'withdraw', 'borrow', 'repay'], true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'error' => '请求方式错误。']);
        exit;
    }
    [$status, $err] = bank_do($uid, $action, $_POST['amount'] ?? 0);
    if ($err !== '') {
        echo json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true] + $status, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'error' => '未知操作。']);
