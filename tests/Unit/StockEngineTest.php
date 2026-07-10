<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/public/games/stock/engine.php';
require_once dirname(__DIR__, 2) . '/public/games/stock/quote.php';

class StockEngineTest extends TestCase
{
    public function testNormalizesShanghaiAndShenzhenSymbols(): void
    {
        $this->assertSame('SH600519', hdv_stock_normalize_symbol('600519'));
        $this->assertSame('SZ000001', hdv_stock_normalize_symbol('000001.sz'));
        $this->assertSame('SZ300750', hdv_stock_normalize_symbol('sz300750'));
        $this->assertNull(hdv_stock_normalize_symbol('AAPL'));
    }

    public function testCalculatesBuyAndSellTicketSettlement(): void
    {
        $buy = hdv_stock_trade_values(10.50, 100, 1, 0.001, 'buy');
        $sell = hdv_stock_trade_values(10.50, 100, 1, 0.001, 'sell');
        $this->assertSame(['gross' => 1050.0, 'fee' => 1.1, 'ticket_value' => 1051.1], $buy);
        $this->assertSame(['gross' => 1050.0, 'fee' => 1.1, 'ticket_value' => 1048.9], $sell);
    }

    public function testCalculatesAverageCostAndRealizedProfit(): void
    {
        $average = hdv_stock_average_cost(100, 10.1, 100, 1210.0);
        $this->assertSame(11.1, $average);
        $this->assertSame(90.0, hdv_stock_realized_profit(11.1, 100, 1200.0));
    }

    public function testMarketHoursExcludeLunchBreakAndWeekend(): void
    {
        $zone = new DateTimeZone('Asia/Shanghai');
        $this->assertTrue(hdv_stock_market_state(new DateTimeImmutable('2026-07-10 10:00:00', $zone))['open']);
        $this->assertFalse(hdv_stock_market_state(new DateTimeImmutable('2026-07-10 12:00:00', $zone))['open']);
        $this->assertFalse(hdv_stock_market_state(new DateTimeImmutable('2026-07-11 10:00:00', $zone))['open']);
    }

    public function testParsesTencentQuotePayload(): void
    {
        $fields = array_fill(0, 35, '');
        $fields[0] = '1'; $fields[1] = '贵州茅台'; $fields[2] = '600519'; $fields[3] = '1204.98';
        $fields[4] = '1182.19'; $fields[5] = '1182.20'; $fields[6] = '52213'; $fields[30] = '20260710161445';
        $fields[31] = '22.79'; $fields[32] = '1.93'; $fields[33] = '1204.98'; $fields[34] = '1170.28';
        $payload = 'v_sh600519="' . implode('~', $fields) . '";';
        $quotes = hdv_stock_parse_quotes($payload);
        $this->assertArrayHasKey('SH600519', $quotes);
        $this->assertSame('贵州茅台', $quotes['SH600519']['name']);
        $this->assertSame(1204.98, $quotes['SH600519']['price']);
        $this->assertSame('2026-07-10 16:14:45', $quotes['SH600519']['quote_time']);
    }

    public function testParsesKlineAndCalculatesMovingAverages(): void
    {
        $rows = [];
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = [sprintf('2026-06-%02d', $i), (string)$i, (string)($i + 0.5), (string)($i + 1), (string)($i - 0.5), (string)($i * 1000)];
        }
        $payload = json_encode(['data' => ['sh600519' => ['qfqday' => $rows]]]);
        $items = hdv_stock_parse_kline($payload, 'SH600519', 'day');
        $this->assertCount(20, $items);
        $this->assertNull($items[3]['ma5']);
        $this->assertSame(18.5, $items[19]['ma5']);
        $this->assertSame(16.0, $items[19]['ma10']);
        $this->assertSame(11.0, $items[19]['ma20']);
    }
}
