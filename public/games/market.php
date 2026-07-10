<?php
// Neutral endpoint name avoids browser content blockers that classify XHR URLs
// containing "/stock/" as third-party market trackers. The implementation stays
// in the stock module so page and endpoint share all validation and settlement.
try {
    require __DIR__ . '/stock/index.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => $e->getMessage(), 'source' => basename($e->getFile()) . ':' . $e->getLine()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
