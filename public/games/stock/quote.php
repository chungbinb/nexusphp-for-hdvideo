<?php

require_once __DIR__ . '/engine.php';

function hdv_stock_decode_provider(string $body): string
{
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($body, 'UTF-8', 'GB18030');
    }
    if (function_exists('iconv')) {
        $converted = @iconv('GB18030', 'UTF-8//IGNORE', $body);
        if ($converted !== false) return $converted;
    }
    return $body;
}

function hdv_stock_http_get(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_USERAGENT => 'HDV-Virtual-Stock/0.1',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return is_string($body) && $code >= 200 && $code < 300 ? $body : null;
    }
    if (class_exists('GuzzleHttp\\Client')) {
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 6, 'connect_timeout' => 3, 'verify' => true]);
            $response = $client->get($url, ['headers' => ['User-Agent' => 'HDV-Virtual-Stock/0.1']]);
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) return (string)$response->getBody();
        } catch (Throwable $e) {}
    }
    $context = stream_context_create(['http' => [
        'timeout' => 6,
        'follow_location' => 1,
        'user_agent' => 'HDV-Virtual-Stock/0.1',
    ]]);
    $body = @file_get_contents($url, false, $context);
    return is_string($body) ? $body : null;
}

function hdv_stock_parse_quotes(string $body): array
{
    $quotes = [];
    $body = hdv_stock_decode_provider($body);
    if (!preg_match_all('/v_((?:sh|sz)\d{6})="([^"]*)";?/i', $body, $matches, PREG_SET_ORDER)) return $quotes;
    foreach ($matches as $match) {
        $fields = explode('~', $match[2]);
        $symbol = strtoupper(substr($match[1], 0, 2)) . substr($match[1], 2);
        $price = isset($fields[3]) ? (float)$fields[3] : 0.0;
        $stamp = (string)($fields[30] ?? '');
        $quoteTime = preg_match('/^\d{14}$/', $stamp)
            ? substr($stamp, 0, 4) . '-' . substr($stamp, 4, 2) . '-' . substr($stamp, 6, 2) . ' ' . substr($stamp, 8, 2) . ':' . substr($stamp, 10, 2) . ':' . substr($stamp, 12, 2)
            : '';
        $quotes[$symbol] = [
            'symbol' => $symbol,
            'code' => (string)($fields[2] ?? substr($symbol, 2)),
            'name' => trim((string)($fields[1] ?? '')),
            'price' => $price,
            'previous_close' => (float)($fields[4] ?? 0),
            'open' => (float)($fields[5] ?? 0),
            'volume' => (int)($fields[6] ?? 0),
            'change' => (float)($fields[31] ?? 0),
            'percent' => (float)($fields[32] ?? 0),
            'high' => (float)($fields[33] ?? 0),
            'low' => (float)($fields[34] ?? 0),
            'quote_time' => $quoteTime,
            'available' => $price > 0 && trim((string)($fields[1] ?? '')) !== '',
            'stale' => false,
            'fetched_at' => time(),
            'source' => '腾讯证券行情',
        ];
    }
    return $quotes;
}

function hdv_stock_redis()
{
    try {
        return \Nexus\Database\NexusDB::redis();
    } catch (Throwable $e) {
        return null;
    }
}

function hdv_stock_fetch_quotes(array $symbols, bool $force = false): array
{
    $normalized = [];
    foreach ($symbols as $raw) {
        $symbol = hdv_stock_normalize_symbol((string)$raw);
        if ($symbol !== null) $normalized[$symbol] = $symbol;
    }
    $symbols = array_values($normalized);
    if (!$symbols) return [];

    $redis = hdv_stock_redis();
    $result = [];
    $missing = [];
    foreach ($symbols as $symbol) {
        $cached = null;
        if (!$force && $redis) {
            try { $cached = $redis->get('game:stock:quote:' . $symbol); } catch (Throwable $e) { $cached = null; }
        }
        $decoded = is_string($cached) ? json_decode($cached, true) : null;
        if (is_array($decoded)) $result[$symbol] = $decoded; else $missing[] = $symbol;
    }
    if ($missing) {
        $providerSymbols = array_map('hdv_stock_provider_symbol', $missing);
        $url = 'https://qt.gtimg.cn/q=' . implode(',', array_map('rawurlencode', $providerSymbols));
        $body = hdv_stock_http_get($url);
        $fresh = is_string($body) ? hdv_stock_parse_quotes($body) : [];
        foreach ($missing as $symbol) {
            if (isset($fresh[$symbol]) && !empty($fresh[$symbol]['available'])) {
                $result[$symbol] = $fresh[$symbol];
                if ($redis) {
                    try {
                        $json = json_encode($fresh[$symbol], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $redis->setex('game:stock:quote:' . $symbol, 15, $json);
                        $redis->setex('game:stock:last:' . $symbol, 86400 * 7, $json);
                    } catch (Throwable $e) {}
                }
            } else {
                $last = null;
                if ($redis) {
                    try { $last = $redis->get('game:stock:last:' . $symbol); } catch (Throwable $e) { $last = null; }
                }
                $fallback = is_string($last) ? json_decode($last, true) : null;
                if (is_array($fallback)) {
                    $fallback['stale'] = true;
                    $result[$symbol] = $fallback;
                } else {
                    $result[$symbol] = ['symbol' => $symbol, 'code' => substr($symbol, 2), 'name' => '', 'price' => 0, 'available' => false, 'stale' => true, 'source' => '腾讯证券行情'];
                }
            }
        }
    }
    $ordered = [];
    foreach ($symbols as $symbol) $ordered[$symbol] = $result[$symbol];
    return $ordered;
}

function hdv_stock_kline_period(string $raw): ?string
{
    $period = strtolower(trim($raw));
    return in_array($period, ['day', 'week', 'month'], true) ? $period : null;
}

function hdv_stock_parse_kline(string $body, string $symbol, string $period): array
{
    $period = hdv_stock_kline_period($period);
    if ($period === null) return [];
    $symbol = hdv_stock_normalize_symbol($symbol);
    if ($symbol === null) return [];
    $decoded = json_decode($body, true);
    $provider = hdv_stock_provider_symbol($symbol);
    $node = is_array($decoded) ? ($decoded['data'][$provider] ?? null) : null;
    if (!is_array($node)) return [];
    $rows = $node['qfq' . $period] ?? $node[$period] ?? null;
    if (!is_array($rows)) return [];
    $items = [];
    $closeWindow = [];
    foreach ($rows as $row) {
        if (!is_array($row) || count($row) < 6 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$row[0])) continue;
        $open = (float)$row[1]; $close = (float)$row[2]; $high = (float)$row[3]; $low = (float)$row[4];
        if ($open <= 0 || $close <= 0 || $high <= 0 || $low <= 0) continue;
        $closeWindow[] = $close;
        $count = count($closeWindow);
        $average = function (int $size) use (&$closeWindow, $count): ?float {
            if ($count < $size) return null;
            return round(array_sum(array_slice($closeWindow, -$size)) / $size, 4);
        };
        $items[] = [
            'date' => (string)$row[0], 'open' => $open, 'close' => $close, 'high' => $high, 'low' => $low,
            'volume' => (float)$row[5], 'ma5' => $average(5), 'ma10' => $average(10), 'ma20' => $average(20),
        ];
    }
    return $items;
}

function hdv_stock_fetch_kline(string $symbol, string $period = 'day', int $limit = 120, bool $force = false): array
{
    $symbol = hdv_stock_normalize_symbol($symbol);
    $period = hdv_stock_kline_period($period);
    $limit = max(30, min(240, $limit));
    if ($symbol === null || $period === null) return ['items' => [], 'stale' => true];
    $redis = hdv_stock_redis();
    $cacheKey = 'game:stock:kline:' . $symbol . ':' . $period . ':' . $limit;
    if (!$force && $redis) {
        try {
            $cached = $redis->get($cacheKey);
            $decoded = is_string($cached) ? json_decode($cached, true) : null;
            if (is_array($decoded) && !empty($decoded['items'])) return $decoded;
        } catch (Throwable $e) {}
    }
    $provider = hdv_stock_provider_symbol($symbol);
    $url = 'https://web.ifzq.gtimg.cn/appstock/app/fqkline/get?param=' . rawurlencode($provider . ',' . $period . ',,,' . $limit . ',qfq');
    $body = hdv_stock_http_get($url);
    $items = is_string($body) ? hdv_stock_parse_kline($body, $symbol, $period) : [];
    if ($items) {
        $result = ['symbol' => $symbol, 'period' => $period, 'items' => $items, 'stale' => false, 'fetched_at' => time(), 'source' => '腾讯证券行情'];
        if ($redis) {
            try {
                $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $ttl = $period === 'day' ? 60 : 1800;
                $redis->setex($cacheKey, $ttl, $json);
                $redis->setex($cacheKey . ':last', 86400 * 7, $json);
            } catch (Throwable $e) {}
        }
        return $result;
    }
    if ($redis) {
        try {
            $last = $redis->get($cacheKey . ':last');
            $fallback = is_string($last) ? json_decode($last, true) : null;
            if (is_array($fallback) && !empty($fallback['items'])) {
                $fallback['stale'] = true;
                return $fallback;
            }
        } catch (Throwable $e) {}
    }
    return ['symbol' => $symbol, 'period' => $period, 'items' => [], 'stale' => true, 'source' => '腾讯证券行情'];
}
