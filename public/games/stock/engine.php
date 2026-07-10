<?php

/** Pure helpers for the HDV virtual A-share market. */

function hdv_stock_normalize_symbol(string $raw): ?string
{
    $value = strtoupper(trim($raw));
    $value = preg_replace('/[^A-Z0-9.]/', '', $value);
    if (preg_match('/^(SH|SZ)(\d{6})$/', $value, $m)) {
        return $m[1] . $m[2];
    }
    if (preg_match('/^(\d{6})\.(SH|SZ)$/', $value, $m)) {
        return $m[2] . $m[1];
    }
    if (preg_match('/^(\d{6})$/', $value, $m)) {
        $prefix = in_array($m[1][0], ['5', '6', '9'], true) ? 'SH' : 'SZ';
        return $prefix . $m[1];
    }
    return null;
}
function hdv_stock_provider_symbol(string $symbol): string
{
    return strtolower(substr($symbol, 0, 2)) . substr($symbol, 2);
}

function hdv_stock_market_state(?DateTimeImmutable $now = null): array
{
    $zone = new DateTimeZone('Asia/Shanghai');
    $now = $now ? $now->setTimezone($zone) : new DateTimeImmutable('now', $zone);
    $weekday = (int)$now->format('N');
    $hm = (int)$now->format('Hi');
    $isTradingDay = $weekday <= 5;
    $isMorning = $hm >= 930 && $hm < 1130;
    $isAfternoon = $hm >= 1300 && $hm < 1500;
    $open = $isTradingDay && ($isMorning || $isAfternoon);
    if (!$isTradingDay) {
        $label = '周末休市';
    } elseif ($hm < 930) {
        $label = '未开盘';
    } elseif ($hm >= 1130 && $hm < 1300) {
        $label = '午间休市';
    } elseif ($hm >= 1500) {
        $label = '已收盘';
    } else {
        $label = '交易中';
    }
    return ['open' => $open, 'label' => $label, 'time' => $now->format('Y-m-d H:i:s')];
}

function hdv_stock_trade_values(float $price, int $shares, float $ticketRate, float $feeRate, string $side): array
{
    $gross = round($price * $shares * $ticketRate, 1);
    $fee = round(max(1, $gross * $feeRate), 1);
    $ticketValue = $side === 'sell' ? round($gross - $fee, 1) : round($gross + $fee, 1);
    return ['gross' => $gross, 'fee' => $fee, 'ticket_value' => $ticketValue];
}

function hdv_stock_average_cost(int $oldShares, float $oldAverage, int $buyShares, float $buyTicketValue): float
{
    $newShares = $oldShares + $buyShares;
    if ($newShares <= 0) return 0.0;
    return round((($oldShares * $oldAverage) + $buyTicketValue) / $newShares, 4);
}

function hdv_stock_realized_profit(float $averageCost, int $shares, float $sellTicketValue): float
{
    return round($sellTicketValue - ($averageCost * $shares), 1);
}

function hdv_stock_quote_is_tradeable(array $quote, ?DateTimeImmutable $now = null): bool
{
    if (empty($quote['available']) || !empty($quote['stale']) || (float)($quote['price'] ?? 0) <= 0) return false;
    $market = hdv_stock_market_state($now);
    if (!$market['open']) return false;
    $quoteTime = (string)($quote['quote_time'] ?? '');
    if ($quoteTime === '') return false;
    try {
        $zone = new DateTimeZone('Asia/Shanghai');
        $qt = new DateTimeImmutable($quoteTime, $zone);
        $current = $now ? $now->setTimezone($zone) : new DateTimeImmutable('now', $zone);
        return abs($current->getTimestamp() - $qt->getTimestamp()) <= 900;
    } catch (Throwable $e) {
        return false;
    }
}

