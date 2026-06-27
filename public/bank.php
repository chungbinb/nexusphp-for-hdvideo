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

if (in_array($action, ['deposit', 'withdraw', 'deposit_fix', 'withdraw_fix', 'repay'], true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'error' => '请求方式错误。']);
        exit;
    }
    [$status, $err] = bank_do($uid, $action, $_POST['amount'] ?? 0, $_POST['term'] ?? 0);
    echo json_encode($err !== '' ? ['ok' => false, 'error' => $err] : (['ok' => true] + $status), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'borrow') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'error' => '请求方式错误。']); exit; }
    [$status, $err] = bank_apply_loan($uid, $_POST['amount'] ?? 0, $_POST['term'] ?? 0, $_POST['guarantors'] ?? '');
    echo json_encode($err !== '' ? ['ok' => false, 'error' => $err] : (['ok' => true] + $status), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'cancel_app') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'error' => '请求方式错误。']); exit; }
    [$status, $err] = bank_cancel_app($uid);
    echo json_encode($err !== '' ? ['ok' => false, 'error' => $err] : (['ok' => true] + $status), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'guarantee_agree' || $action === 'guarantee_reject') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'error' => '请求方式错误。']); exit; }
    [$status, $err] = bank_respond_guarantee($uid, $_POST['app_id'] ?? 0, $action === 'guarantee_agree');
    echo json_encode($err !== '' ? ['ok' => false, 'error' => $err] : (['ok' => true] + $status), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'transfer') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'error' => '请求方式错误。']); exit; }
    [$status, $err] = bank_transfer($uid, $_POST['to'] ?? '', $_POST['amount'] ?? 0);
    echo json_encode($err !== '' ? ['ok' => false, 'error' => $err] : (['ok' => true] + $status), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'buy_insurance') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'error' => '请求方式错误。']); exit; }
    [$status, $err] = bank_buy_insurance($uid);
    echo json_encode($err !== '' ? ['ok' => false, 'error' => $err] : (['ok' => true] + $status), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'apply_request') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'error' => '请求方式错误。']); exit; }
    [$status, $err] = bank_request($uid, $_POST['type'] ?? '', $_POST['reason'] ?? '');
    echo json_encode($err !== '' ? ['ok' => false, 'error' => $err] : (['ok' => true] + $status), JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'error' => '未知操作。']);
