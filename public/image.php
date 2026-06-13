<?php
require_once("../include/bittorrent.php");
dbconn();

$action = $_GET['action'] ?? '';
$imagehash = $_GET['imagehash'] ?? '';

if (!in_array($action, ['regimage', 'regimage_refresh'], true)) {
    http_response_code(404);
    exit('Invalid captcha action');
}

$driver = captcha_manager()->driver('image');

if ($action === 'regimage_refresh') {
    if (!method_exists($driver, 'issuePayload')) {
        http_response_code(404);
        exit('Captcha driver does not support image refresh');
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($driver->issuePayload((string) ($_GET['secret'] ?? '')));
    exit;
}

if (!method_exists($driver, 'outputImage')) {
    http_response_code(404);
    exit('Captcha driver does not support image rendering');
}

$driver->outputImage($imagehash);

?>
