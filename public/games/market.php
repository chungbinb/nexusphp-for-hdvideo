<?php
// Neutral endpoint name avoids browser content blockers that classify XHR URLs
// containing "/stock/" as third-party market trackers. The implementation stays
// in the stock module so page and endpoint share all validation and settlement.
try {
    require __DIR__ . '/stock/index.php';
} catch (Throwable $e) {
    error_log('[HDV_STOCK_ENDPOINT] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => '股票服务暂时不可用，请稍后再试。'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
