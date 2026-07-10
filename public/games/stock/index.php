<?php
require '../../../include/bittorrent.php';
dbconn();
loggedinorreturn();
parked();
require_once '../../../include/game_control.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/quote.php';

game_guard('stock');

const HDV_STOCK_HOLDING_TABLE = 'hdvideo_stock_holdings';
const HDV_STOCK_TRADE_TABLE = 'hdvideo_stock_trades';
const HDV_STOCK_BUSINESS_TYPE = 115;

function hdv_stock_money($value): float
{
    return round((float)$value, 1);
}

function hdv_stock_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    sql_query("CREATE TABLE IF NOT EXISTS `" . HDV_STOCK_HOLDING_TABLE . "` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `uid` int unsigned NOT NULL,
        `symbol` varchar(12) NOT NULL,
        `stock_name` varchar(80) NOT NULL DEFAULT '',
        `shares` int unsigned NOT NULL DEFAULT 0,
        `avg_cost` decimal(20,4) NOT NULL DEFAULT 0.0000,
        `created_at` datetime NOT NULL,
        `updated_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_uid_symbol` (`uid`,`symbol`),
        KEY `idx_symbol` (`symbol`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") or sqlerr(__FILE__, __LINE__);
    sql_query("CREATE TABLE IF NOT EXISTS `" . HDV_STOCK_TRADE_TABLE . "` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `order_key` varchar(64) NOT NULL,
        `uid` int unsigned NOT NULL,
        `symbol` varchar(12) NOT NULL,
        `stock_name` varchar(80) NOT NULL DEFAULT '',
        `side` varchar(8) NOT NULL,
        `shares` int unsigned NOT NULL,
        `quote_price` decimal(18,4) NOT NULL,
        `gross_value` decimal(20,1) NOT NULL,
        `fee` decimal(20,1) NOT NULL,
        `ticket_value` decimal(20,1) NOT NULL,
        `realized_profit` decimal(20,1) NOT NULL DEFAULT 0.0,
        `quote_time` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_order_key` (`order_key`),
        KEY `idx_uid_created` (`uid`,`created_at`),
        KEY `idx_symbol_created` (`symbol`,`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") or sqlerr(__FILE__, __LINE__);
    $done = true;
}

function hdv_stock_config(): array
{
    $control = game_control_get('stock');
    $raw = trim((string)($control['stock_symbols'] ?? ''));
    if ($raw === '') $raw = 'SH600519,SZ000001,SH601318,SZ300750,SH600036,SH601899,SZ000858,SH600900';
    $symbols = [];
    foreach (preg_split('/[\s,;，；]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) as $item) {
        $symbol = hdv_stock_normalize_symbol((string)$item);
        if ($symbol !== null) $symbols[$symbol] = $symbol;
    }
    return [
        'symbols' => array_values($symbols),
        'ticket_rate' => max(0.0001, (float)($control['stock_ticket_rate'] ?? 1)),
        'fee_rate' => max(0, min(0.1, (float)($control['stock_fee_rate'] ?? 0.001))),
        'trade_enabled' => (int)($control['stock_trade_enabled'] ?? 1) === 1,
    ];
}

function hdv_stock_holdings(int $uid): array
{
    $rows = [];
    $res = sql_query("SELECT `symbol`,`stock_name`,`shares`,`avg_cost`,`updated_at` FROM `" . HDV_STOCK_HOLDING_TABLE . "` WHERE `uid`={$uid} AND `shares`>0 ORDER BY `updated_at` DESC") or sqlerr(__FILE__, __LINE__);
    while ($row = mysql_fetch_assoc($res)) {
        $row['shares'] = (int)$row['shares'];
        $row['avg_cost'] = (float)$row['avg_cost'];
        $rows[$row['symbol']] = $row;
    }
    return $rows;
}

function hdv_stock_trades(int $uid, int $limit = 20): array
{
    $rows = [];
    $res = sql_query("SELECT `id`,`symbol`,`stock_name`,`side`,`shares`,`quote_price`,`gross_value`,`fee`,`ticket_value`,`realized_profit`,`quote_time`,`created_at` FROM `" . HDV_STOCK_TRADE_TABLE . "` WHERE `uid`={$uid} ORDER BY `id` DESC LIMIT " . max(1, min(50, $limit))) or sqlerr(__FILE__, __LINE__);
    while ($row = mysql_fetch_assoc($res)) {
        foreach (['quote_price', 'gross_value', 'fee', 'ticket_value', 'realized_profit'] as $field) $row[$field] = (float)$row[$field];
        $row['shares'] = (int)$row['shares'];
        $rows[] = $row;
    }
    return $rows;
}

function hdv_stock_portfolio(int $uid, array $quotes, float $ticketRate = 1.0): array
{
    $holdings = hdv_stock_holdings($uid);
    $marketValue = 0.0;
    $costValue = 0.0;
    foreach ($holdings as $symbol => &$holding) {
        $quote = $quotes[$symbol] ?? null;
        $price = is_array($quote) && !empty($quote['available']) ? (float)$quote['price'] : 0.0;
        $holding['price'] = $price;
        $holding['market_value'] = hdv_stock_money($price * $holding['shares'] * $ticketRate);
        $holding['cost_value'] = hdv_stock_money($holding['avg_cost'] * $holding['shares']);
        $holding['profit'] = hdv_stock_money($holding['market_value'] - $holding['cost_value']);
        $holding['profit_percent'] = $holding['cost_value'] > 0 ? round($holding['profit'] / $holding['cost_value'] * 100, 2) : 0.0;
        $marketValue += $holding['market_value'];
        $costValue += $holding['cost_value'];
    }
    unset($holding);
    return [
        'holdings' => array_values($holdings),
        'market_value' => hdv_stock_money($marketValue),
        'cost_value' => hdv_stock_money($costValue),
        'profit' => hdv_stock_money($marketValue - $costValue),
    ];
}

function hdv_stock_mark_tradeable(array $quotes): array
{
    foreach ($quotes as &$quote) $quote['tradeable'] = hdv_stock_quote_is_tradeable($quote);
    unset($quote);
    return $quotes;
}

function hdv_stock_bonus_log(int $uid, float $old, float $delta, float $new, string $comment): void
{
    $now = date('Y-m-d H:i:s');
    sql_query(sprintf(
        "INSERT INTO `bonus_logs` (`business_type`,`uid`,`old_total_value`,`value`,`new_total_value`,`comment`,`created_at`,`updated_at`) VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
        HDV_STOCK_BUSINESS_TYPE, $uid, sqlesc(hdv_stock_money($old)), sqlesc(hdv_stock_money($delta)), sqlesc(hdv_stock_money($new)),
        sqlesc('[股票模拟交易] ' . $comment), sqlesc($now), sqlesc($now)
    )) or throw new RuntimeException('电影票流水写入失败。');
}

function hdv_stock_trade(int $uid, array $config, string $symbol, string $side, int $shares, string $orderKey): array
{
    if (!$config['trade_enabled']) throw new RuntimeException('管理员当前已暂停股票买卖。');
    if (!in_array($symbol, $config['symbols'], true)) throw new RuntimeException('该股票不在当前可交易股票池。');
    if (!in_array($side, ['buy', 'sell'], true)) throw new RuntimeException('买卖方向无效。');
    if ($shares < 100 || $shares > 100000 || $shares % 100 !== 0) throw new RuntimeException('交易数量必须为 100 股的整数倍，单笔最多 100,000 股。');
    if (!preg_match('/^[A-Za-z0-9-]{16,64}$/', $orderKey)) throw new RuntimeException('订单编号无效，请刷新页面重试。');
    $market = hdv_stock_market_state();
    if (!$market['open']) throw new RuntimeException('A 股当前' . $market['label'] . '，只能查看行情，不能成交。');

    $quotes = hdv_stock_fetch_quotes([$symbol], true);
    $quote = $quotes[$symbol] ?? [];
    if (!hdv_stock_quote_is_tradeable($quote)) throw new RuntimeException('实时行情不可用或报价已过期，为保护电影票已拒绝成交。');
    $price = (float)$quote['price'];
    $values = hdv_stock_trade_values($price, $shares, $config['ticket_rate'], $config['fee_rate'], $side);
    $now = date('Y-m-d H:i:s');

    sql_query('START TRANSACTION') or throw new RuntimeException('无法开始交易。');
    try {
        $duplicate = sql_query("SELECT `id` FROM `" . HDV_STOCK_TRADE_TABLE . "` WHERE `order_key`=" . sqlesc($orderKey) . " LIMIT 1 FOR UPDATE") or throw new RuntimeException('订单校验失败。');
        if (mysql_num_rows($duplicate) > 0) throw new RuntimeException('该订单已经处理，请勿重复提交。');

        $userRes = sql_query("SELECT `seedbonus` FROM `users` WHERE `id`={$uid} LIMIT 1 FOR UPDATE") or throw new RuntimeException('用户余额读取失败。');
        $user = mysql_fetch_assoc($userRes);
        if (!$user) throw new RuntimeException('用户不存在。');
        $oldBalance = (float)$user['seedbonus'];

        $holdingRes = sql_query("SELECT * FROM `" . HDV_STOCK_HOLDING_TABLE . "` WHERE `uid`={$uid} AND `symbol`=" . sqlesc($symbol) . " LIMIT 1 FOR UPDATE") or throw new RuntimeException('持仓读取失败。');
        $holding = mysql_fetch_assoc($holdingRes);
        $oldShares = $holding ? (int)$holding['shares'] : 0;
        $oldAverage = $holding ? (float)$holding['avg_cost'] : 0.0;
        $realized = 0.0;

        if ($side === 'buy') {
            $maxOrder = hdv_stock_money($oldBalance * 0.1);
            if ($values['ticket_value'] > $maxOrder) throw new RuntimeException('单笔买入最多使用当前电影票余额的 10%（本次上限 ' . number_format($maxOrder, 1) . '）。');
            if ($oldBalance < $values['ticket_value']) throw new RuntimeException('电影票余额不足。');
            $newShares = $oldShares + $shares;
            $newAverage = hdv_stock_average_cost($oldShares, $oldAverage, $shares, $values['ticket_value']);
            if ($holding) {
                sql_query("UPDATE `" . HDV_STOCK_HOLDING_TABLE . "` SET `stock_name`=" . sqlesc($quote['name']) . ",`shares`={$newShares},`avg_cost`=" . sqlesc($newAverage) . ",`updated_at`=" . sqlesc($now) . " WHERE `id`=" . (int)$holding['id']) or throw new RuntimeException('持仓更新失败。');
            } else {
                sql_query("INSERT INTO `" . HDV_STOCK_HOLDING_TABLE . "` (`uid`,`symbol`,`stock_name`,`shares`,`avg_cost`,`created_at`,`updated_at`) VALUES ({$uid}," . sqlesc($symbol) . ',' . sqlesc($quote['name']) . ",{$newShares}," . sqlesc($newAverage) . ',' . sqlesc($now) . ',' . sqlesc($now) . ')') or throw new RuntimeException('持仓创建失败。');
            }
            $delta = -$values['ticket_value'];
        } else {
            if (!$holding || $oldShares < $shares) throw new RuntimeException('持仓数量不足。');
            $newShares = $oldShares - $shares;
            $realized = hdv_stock_realized_profit($oldAverage, $shares, $values['ticket_value']);
            if ($newShares > 0) {
                sql_query("UPDATE `" . HDV_STOCK_HOLDING_TABLE . "` SET `shares`={$newShares},`updated_at`=" . sqlesc($now) . " WHERE `id`=" . (int)$holding['id']) or throw new RuntimeException('持仓更新失败。');
            } else {
                sql_query("DELETE FROM `" . HDV_STOCK_HOLDING_TABLE . "` WHERE `id`=" . (int)$holding['id']) or throw new RuntimeException('持仓清理失败。');
            }
            $delta = $values['ticket_value'];
        }

        $newBalance = hdv_stock_money($oldBalance + $delta);
        sql_query("UPDATE `users` SET `seedbonus`=" . sqlesc($newBalance) . " WHERE `id`={$uid}") or throw new RuntimeException('电影票结算失败。');
        sql_query("INSERT INTO `" . HDV_STOCK_TRADE_TABLE . "` (`order_key`,`uid`,`symbol`,`stock_name`,`side`,`shares`,`quote_price`,`gross_value`,`fee`,`ticket_value`,`realized_profit`,`quote_time`,`created_at`) VALUES (" .
            sqlesc($orderKey) . ",{$uid}," . sqlesc($symbol) . ',' . sqlesc($quote['name']) . ',' . sqlesc($side) . ",{$shares}," . sqlesc($price) . ',' . sqlesc($values['gross']) . ',' . sqlesc($values['fee']) . ',' . sqlesc($values['ticket_value']) . ',' . sqlesc($realized) . ',' . sqlesc($quote['quote_time']) . ',' . sqlesc($now) . ')') or throw new RuntimeException('成交记录写入失败。');
        $verb = $side === 'buy' ? '买入' : '卖出';
        hdv_stock_bonus_log($uid, $oldBalance, $delta, $newBalance, "{$verb} {$quote['name']} {$symbol} {$shares}股 @" . number_format($price, 2) . '，手续费 ' . number_format($values['fee'], 1));
        sql_query('COMMIT') or throw new RuntimeException('交易提交失败。');
        clear_user_cache($uid);
        $GLOBALS['CURUSER']['seedbonus'] = $newBalance;
        return ['message' => "{$verb}成功：{$quote['name']} {$shares} 股，成交价 " . number_format($price, 2) . '，结算 ' . number_format($values['ticket_value'], 1) . ' 张电影票。', 'wallet' => $newBalance];
    } catch (Throwable $e) {
        @sql_query('ROLLBACK');
        throw $e;
    }
}

function hdv_stock_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

hdv_stock_ensure_schema();
$uid = (int)$CURUSER['id'];
$config = hdv_stock_config();
$holdingSymbols = array_keys(hdv_stock_holdings($uid));
$allSymbols = array_values(array_unique(array_merge($config['symbols'], $holdingSymbols)));

if (isset($_GET['ajax'])) {
    $action = (string)$_GET['ajax'];
    if ($action === 'quotes') {
        $quotes = hdv_stock_mark_tradeable(hdv_stock_fetch_quotes($allSymbols));
        hdv_stock_json(['ok' => true, 'quotes' => array_values($quotes), 'portfolio' => hdv_stock_portfolio($uid, $quotes, $config['ticket_rate']), 'trades' => hdv_stock_trades($uid), 'market' => hdv_stock_market_state(), 'wallet' => (float)$CURUSER['seedbonus']]);
    }
    if ($action === 'quote') {
        $symbol = hdv_stock_normalize_symbol((string)($_GET['symbol'] ?? ''));
        if ($symbol === null) hdv_stock_json(['ok' => false, 'message' => '请输入正确的六位沪深股票代码。'], 422);
        $quotes = hdv_stock_mark_tradeable(hdv_stock_fetch_quotes([$symbol]));
        $quote = $quotes[$symbol] ?? [];
        if (empty($quote['available'])) hdv_stock_json(['ok' => false, 'message' => '未找到该股票或行情暂不可用。'], 404);
        $quote['trade_allowed'] = in_array($symbol, $config['symbols'], true);
        hdv_stock_json(['ok' => true, 'quote' => $quote]);
    }
    hdv_stock_json(['ok' => false, 'message' => '未知请求。'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['token'] ?? '');
    if (empty($_SESSION['hdv_stock_token']) || !hash_equals((string)$_SESSION['hdv_stock_token'], $token)) hdv_stock_json(['ok' => false, 'message' => '页面校验已过期，请刷新后重试。'], 419);
    $lastTrade = (float)($_SESSION['hdv_stock_last_trade'] ?? 0);
    if (microtime(true) - $lastTrade < 1) hdv_stock_json(['ok' => false, 'message' => '操作过快，请稍后再试。'], 429);
    $_SESSION['hdv_stock_last_trade'] = microtime(true);
    try {
        $symbol = hdv_stock_normalize_symbol((string)($_POST['symbol'] ?? ''));
        if ($symbol === null) throw new RuntimeException('股票代码无效。');
        $result = hdv_stock_trade($uid, $config, $symbol, (string)($_POST['side'] ?? ''), (int)($_POST['shares'] ?? 0), (string)($_POST['order_key'] ?? ''));
        hdv_stock_json(['ok' => true] + $result);
    } catch (Throwable $e) {
        hdv_stock_json(['ok' => false, 'message' => $e->getMessage()], 422);
    }
}

if (empty($_SESSION['hdv_stock_token'])) $_SESSION['hdv_stock_token'] = bin2hex(random_bytes(24));
$quotes = hdv_stock_mark_tradeable(hdv_stock_fetch_quotes($allSymbols));
$portfolio = hdv_stock_portfolio($uid, $quotes, $config['ticket_rate']);
$trades = hdv_stock_trades($uid);
$market = hdv_stock_market_state();
$initial = [
    'quotes' => array_values($quotes), 'portfolio' => $portfolio, 'trades' => $trades, 'market' => $market,
    'wallet' => (float)$CURUSER['seedbonus'], 'symbols' => $config['symbols'], 'ticketRate' => $config['ticket_rate'],
    'feeRate' => $config['fee_rate'], 'tradeEnabled' => $config['trade_enabled'], 'token' => $_SESSION['hdv_stock_token'],
];

$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;
stdhead('股票模拟交易');
?>
<style>
body.game-page{background:color-mix(in srgb,var(--bili-bg,#f1f5f9) 88%,#07111f)!important}.stk{--p:var(--bili-primary,#00aeec);--a:var(--bili-accent,#fb7299);--surface:color-mix(in srgb,var(--bili-surface,#fff) 94%,#eef6ff);--soft:color-mix(in srgb,var(--p) 8%,var(--surface));--line:color-mix(in srgb,var(--p) 20%,transparent);--text:var(--bili-text,#17233b);max-width:1220px;margin:0 auto;padding:18px;color:var(--text)}
.stk *{box-sizing:border-box}.stk a{color:var(--p)}.stk-top{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:14px}.stk-title{display:flex;align-items:center;gap:12px}.stk-mark{width:48px;height:48px;border-radius:14px;display:grid;place-items:center;color:#fff;background:linear-gradient(145deg,var(--p),var(--a));box-shadow:0 10px 25px color-mix(in srgb,var(--p) 25%,transparent)}.stk-mark svg{width:27px;height:27px;fill:none;stroke:currentColor;stroke-width:2}.stk h1{margin:0!important;font-size:28px!important}.stk-sub{margin-top:4px;color:#63738a}.stk-wallet{padding:10px 14px;border:1px solid var(--line);border-radius:12px;background:var(--surface);white-space:nowrap}.stk-wallet b{color:var(--p);font-size:18px}
.stk-status{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 14px;margin-bottom:14px;border:1px solid var(--line);border-radius:12px;background:var(--soft)}.stk-status-left{display:flex;align-items:center;gap:8px}.stk-dot{width:9px;height:9px;border-radius:50%;background:#94a3b8}.stk-status.open .stk-dot{background:#e23b4d;box-shadow:0 0 0 5px rgba(226,59,77,.12)}.stk-status strong{color:var(--p)}.stk-muted{color:#68788f;font-size:12px}
.stk-metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:14px}.stk-metric,.stk-card{border:1px solid var(--line);border-radius:14px;background:var(--surface);box-shadow:0 8px 24px rgba(15,35,65,.06)}.stk-metric{padding:15px}.stk-metric span{display:block;color:#68788f;font-size:12px}.stk-metric b{display:block;margin-top:7px;font-size:20px}.up{color:#df3045!important}.down{color:#139b63!important}.flat{color:#65758b!important}
.stk-layout{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(310px,.8fr);gap:14px}.stk-card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 16px;border-bottom:1px solid var(--line)}.stk-card-head h2{margin:0!important;font-size:17px!important}.stk-search{display:flex;gap:7px}.stk-search input{width:160px;min-height:38px;border:1px solid var(--line);border-radius:9px;padding:0 10px;background:var(--surface);color:var(--text)}.stk-btn{min-height:40px;border:1px solid var(--line);border-radius:9px;padding:0 14px;background:var(--soft);color:var(--p);font-weight:800;cursor:pointer;transition:background .2s,border-color .2s}.stk-btn:hover{border-color:var(--p)}.stk-btn:focus-visible,.stk-quote:focus-visible,.stk-tab:focus-visible{outline:3px solid color-mix(in srgb,var(--p) 36%,transparent);outline-offset:2px}.stk-btn:disabled{opacity:.45;cursor:not-allowed}
.stk-quotes{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:13px}.stk-quote{position:relative;text-align:left;border:2px solid transparent;border-radius:12px;padding:12px;background:var(--soft);color:var(--text);cursor:pointer;transition:border-color .2s,background .2s}.stk-quote:hover{border-color:var(--line)}.stk-quote.selected{border-color:var(--p);background:color-mix(in srgb,var(--p) 13%,var(--surface));box-shadow:0 0 0 3px color-mix(in srgb,var(--p) 12%,transparent)}.stk-qtop,.stk-qprice{display:flex;align-items:center;justify-content:space-between;gap:8px}.stk-qtop strong{font-size:14px}.stk-code{color:#718197;font:12px ui-monospace,SFMono-Regular,Consolas,monospace}.stk-qprice{margin-top:9px}.stk-qprice b{font-size:21px}.stk-range{height:4px;margin-top:10px;border-radius:4px;background:rgba(110,130,155,.2);overflow:hidden}.stk-range i{display:block;height:100%;background:var(--p);border-radius:inherit}.stk-qtime{margin-top:7px;color:#7a899c;font-size:11px}
.stk-trade{padding:15px}.stk-selected{padding:13px;border-radius:12px;background:var(--soft);border:1px solid var(--line)}.stk-selected h3{margin:0 0 6px!important;font-size:18px!important}.stk-selected-price{display:flex;align-items:end;justify-content:space-between;gap:10px}.stk-selected-price b{font-size:28px}.stk-tabs{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:14px 0}.stk-tab{min-height:43px;border:2px solid transparent;border-radius:10px;background:var(--soft);font-weight:900;cursor:pointer}.stk-tab.buy.active{border-color:#df3045;color:#df3045;background:rgba(223,48,69,.08)}.stk-tab.sell.active{border-color:#139b63;color:#139b63;background:rgba(19,155,99,.08)}.stk-field{margin-top:12px}.stk-field label{display:flex;justify-content:space-between;margin-bottom:6px;color:#607188;font-size:12px;font-weight:700}.stk-field input{width:100%;height:45px;border:1px solid var(--line);border-radius:10px;padding:0 12px;background:var(--surface);color:var(--text);font-size:16px}.stk-chips{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:8px}.stk-chip{min-height:34px;border:1px solid var(--line);border-radius:8px;background:var(--soft);color:var(--text);cursor:pointer}.stk-estimate{margin:13px 0;padding:11px;border-radius:10px;background:var(--soft);font-size:12px;line-height:1.8}.stk-submit{width:100%;height:46px;border:0;border-radius:10px;color:#fff;font-size:15px;font-weight:900;cursor:pointer}.stk-submit.buy{background:#df3045}.stk-submit.sell{background:#139b63}.stk-notice{margin-top:10px;color:#6e7d91;font-size:11px;line-height:1.55}.stk-message{display:none;margin:0 15px 15px;padding:10px 12px;border-radius:9px;font-size:13px}.stk-message.ok{display:block;background:rgba(19,155,99,.11);color:#08794b;border:1px solid rgba(19,155,99,.25)}.stk-message.err{display:block;background:rgba(223,48,69,.1);color:#bf2035;border:1px solid rgba(223,48,69,.25)}
.stk-bottom{display:grid;grid-template-columns:1.35fr 1fr;gap:14px;margin-top:14px}.stk-table-wrap{overflow:auto}.stk table{width:100%;border-collapse:collapse}.stk th,.stk td{padding:11px 13px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}.stk th{color:#6a7990;font-size:12px;background:var(--soft)}.stk td{font-size:13px}.stk td:last-child,.stk th:last-child{text-align:right}.stk-empty{padding:28px!important;text-align:center!important;color:#78879a}.stk-rules{margin-top:14px;padding:15px 17px;line-height:1.8}.stk-rules h2{margin:0 0 8px!important;font-size:16px!important}.stk-rules ul{margin:0;padding-left:20px}.stk-source{font-weight:700;color:var(--p)}
@media(max-width:900px){.stk-metrics{grid-template-columns:repeat(2,1fr)}.stk-layout,.stk-bottom{grid-template-columns:1fr}.stk-quotes{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.stk{padding:10px}.stk-top{align-items:flex-start;flex-direction:column}.stk-wallet{width:100%}.stk-status{align-items:flex-start;flex-direction:column}.stk-metrics{gap:8px}.stk-metric{padding:12px}.stk-metric b{font-size:17px}.stk-card-head{align-items:flex-start;flex-direction:column}.stk-search{width:100%}.stk-search input{flex:1;width:auto}.stk-quotes{grid-template-columns:1fr}.stk h1{font-size:23px!important}.stk th,.stk td{padding:9px 10px}}
@media(prefers-reduced-motion:reduce){.stk *{scroll-behavior:auto!important;transition:none!important}}
</style>
<div class="stk">
 <div class="stk-top">
  <div class="stk-title"><span class="stk-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 19V9m6 10V5m6 14v-7m4 7H2"/><path d="m3 7 5-4 5 5 7-6"/></svg></span><div><a href="/games/">返回游戏列表</a><h1>股票模拟交易 <small>内测中 v0.1</small></h1><div class="stk-sub">真实 A 股行情定价，使用站内电影票进行虚拟买卖</div></div></div>
  <div class="stk-wallet">电影票余额 <b id="wallet"></b></div>
 </div>
 <div class="stk-status <?php echo $market['open'] ? 'open' : '' ?>"><div class="stk-status-left"><span class="stk-dot"></span><strong id="marketLabel"></strong><span class="stk-muted" id="marketTime"></span></div><div class="stk-muted">行情源：<span class="stk-source">腾讯证券行情</span> · 约每 15 秒刷新 · 行情仅用于站内娱乐</div></div>
 <div class="stk-metrics" id="metrics"></div>
 <div class="stk-layout">
  <section class="stk-card">
   <div class="stk-card-head"><h2>实时行情</h2><form class="stk-search" id="searchForm"><label class="sr-only" for="stockSearch">股票代码</label><input id="stockSearch" inputmode="numeric" maxlength="8" placeholder="输入六位股票代码"><button class="stk-btn" type="submit">查询</button></form></div>
   <div class="stk-quotes" id="quotes"></div>
  </section>
  <aside class="stk-card">
   <div class="stk-card-head"><h2>虚拟买卖</h2><span class="stk-muted">100 股 / 手</span></div>
   <div class="stk-trade">
    <div class="stk-selected" id="selected"></div>
    <div class="stk-tabs"><button type="button" class="stk-tab buy active" data-side="buy">买入</button><button type="button" class="stk-tab sell" data-side="sell">卖出</button></div>
    <form id="tradeForm">
     <div class="stk-field"><label for="shares"><span>交易股数</span><span id="availableShares"></span></label><input id="shares" name="shares" type="number" min="100" max="100000" step="100" value="100" inputmode="numeric"></div>
     <div class="stk-chips"><button type="button" class="stk-chip" data-shares="100">100</button><button type="button" class="stk-chip" data-shares="500">500</button><button type="button" class="stk-chip" data-shares="1000">1,000</button><button type="button" class="stk-chip" data-shares="5000">5,000</button></div>
     <div class="stk-estimate" id="estimate"></div>
     <button class="stk-submit buy" id="submitTrade" type="submit">确认买入</button>
    </form>
    <div class="stk-notice">成交价由服务器重新获取，前端显示价格不能作为结算依据。单笔买入最多使用当前电影票余额的 10%。</div>
   </div>
   <div class="stk-message" id="message" role="status" aria-live="polite"></div>
  </aside>
 </div>
 <div class="stk-bottom">
  <section class="stk-card"><div class="stk-card-head"><h2>我的持仓</h2><span class="stk-muted">市值按最新可用行情估算</span></div><div class="stk-table-wrap"><table><thead><tr><th>股票</th><th>持仓</th><th>成本/股</th><th>现价</th><th>市值</th><th>浮动盈亏</th></tr></thead><tbody id="holdings"></tbody></table></div></section>
  <section class="stk-card"><div class="stk-card-head"><h2>最近成交</h2><span class="stk-muted">电影票流水同步记录</span></div><div class="stk-table-wrap"><table><thead><tr><th>时间</th><th>方向</th><th>股票</th><th>股数</th><th>结算</th></tr></thead><tbody id="trades"></tbody></table></div></section>
 </div>
 <section class="stk-card stk-rules"><h2>交易说明</h2><ul><li>目前接入沪深 A 股真实行情；上涨使用红色、下跌使用绿色，所有资产均为站内虚拟电影票。</li><li>只在 A 股正常交易时段开放成交：工作日 09:30–11:30、13:00–15:00（北京时间）；休市期间仍可查看最新行情与持仓。</li><li>买入数量必须为 100 股的整数倍，不支持融资、做空和电影票以外的资产；每笔按后台费率收取手续费，最低 1 张电影票。</li><li>外部行情异常、报价过期或管理员暂停交易时，系统会保护性拒绝成交。本站行情不构成任何投资建议。</li></ul></section>
</div>
<script>
const stockState=<?php echo json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let selectedSymbol=stockState.symbols[0]||'',side='buy',busy=false,extraQuotes={};
const el=id=>document.getElementById(id),money=v=>Number(v||0).toLocaleString('zh-CN',{minimumFractionDigits:1,maximumFractionDigits:1}),price=v=>Number(v||0).toLocaleString('zh-CN',{minimumFractionDigits:2,maximumFractionDigits:2}),esc=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
function quoteMap(){const map={};[...stockState.quotes,...Object.values(extraQuotes)].forEach(q=>map[q.symbol]=q);return map}
function cls(v){return Number(v)>0?'up':Number(v)<0?'down':'flat'}
function holding(symbol){return stockState.portfolio.holdings.find(h=>h.symbol===symbol)||null}
function render(){
 const quotes=quoteMap(),p=stockState.portfolio,w=Number(stockState.wallet||0),total=w+Number(p.market_value||0);
 el('wallet').textContent=money(w);el('marketLabel').textContent=stockState.market.label;el('marketTime').textContent='北京时间 '+stockState.market.time;document.querySelector('.stk-status').classList.toggle('open',!!stockState.market.open);
 el('metrics').innerHTML=`<div class="stk-metric"><span>电影票余额</span><b>${money(w)}</b></div><div class="stk-metric"><span>股票市值</span><b>${money(p.market_value)}</b></div><div class="stk-metric"><span>总资产</span><b>${money(total)}</b></div><div class="stk-metric"><span>持仓浮动盈亏</span><b class="${cls(p.profit)}">${Number(p.profit)>=0?'+':''}${money(p.profit)}</b></div>`;
 const ordered=[...stockState.symbols,...Object.keys(extraQuotes).filter(s=>!stockState.symbols.includes(s))];
 el('quotes').innerHTML=ordered.map(s=>{const q=quotes[s]||{symbol:s,code:s.slice(2),available:false},range=q.high>q.low?Math.max(0,Math.min(100,(q.price-q.low)/(q.high-q.low)*100)):50;return `<button type="button" class="stk-quote ${s===selectedSymbol?'selected':''}" data-symbol="${esc(s)}" aria-pressed="${s===selectedSymbol}"><div class="stk-qtop"><strong>${esc(q.name||'行情加载中')}</strong><span class="stk-code">${esc(q.code||s.slice(2))}</span></div><div class="stk-qprice"><b class="${cls(q.change)}">${q.available?price(q.price):'--'}</b><span class="${cls(q.change)}">${q.available?(Number(q.change)>=0?'+':'')+price(q.change)+' ('+(Number(q.percent)>=0?'+':'')+Number(q.percent).toFixed(2)+'%)':'暂不可用'}</span></div><div class="stk-range"><i style="width:${range}%"></i></div><div class="stk-qtime">${q.stale?'行情源暂不可用 · 显示缓存':'更新 '+esc(q.quote_time||'--')}</div></button>`}).join('');
 const q=quotes[selectedSymbol]||{},h=holding(selectedSymbol),allowed=stockState.symbols.includes(selectedSymbol);
 el('selected').innerHTML=`<h3>${esc(q.name||'请选择股票')} <span class="stk-code">${esc(q.code||'')}</span></h3><div class="stk-selected-price"><b class="${cls(q.change)}">${q.available?price(q.price):'--'}</b><span class="${cls(q.change)}">${q.available?(Number(q.percent)>=0?'+':'')+Number(q.percent).toFixed(2)+'%':''}</span></div>${allowed?'':'<div class="stk-muted" style="margin-top:7px">该股票仅供查询，未加入后台交易股票池</div>'}`;
 document.querySelectorAll('.stk-tab').forEach(b=>b.classList.toggle('active',b.dataset.side===side));el('availableShares').textContent=side==='sell'?`可卖 ${Number(h?.shares||0).toLocaleString()} 股`:'100 股起买';
 const shares=Math.max(0,Number(el('shares').value||0)),gross=Number(q.price||0)*shares*Number(stockState.ticketRate),fee=Math.max(1,gross*Number(stockState.feeRate)),settle=side==='sell'?gross-fee:gross+fee;
 el('estimate').innerHTML=`实时价 <b>${q.available?price(q.price):'--'}</b><br>成交金额约 <b>${money(gross)}</b> 电影票 · 手续费约 <b>${money(fee)}</b><br>${side==='buy'?'预计扣除':'预计获得'} <b>${money(settle)}</b> 电影票`;
 const submit=el('submitTrade');submit.className='stk-submit '+side;submit.textContent=busy?'处理中…':'确认'+(side==='buy'?'买入':'卖出');submit.disabled=busy||!q.available||!q.tradeable||!stockState.tradeEnabled||!allowed;
 el('holdings').innerHTML=p.holdings.length?p.holdings.map(x=>`<tr><td><b>${esc(x.stock_name)}</b><br><span class="stk-code">${esc(x.symbol)}</span></td><td>${Number(x.shares).toLocaleString()}</td><td>${price(x.avg_cost)}</td><td>${x.price?price(x.price):'--'}</td><td>${money(x.market_value)}</td><td class="${cls(x.profit)}">${Number(x.profit)>=0?'+':''}${money(x.profit)}<br><small>${Number(x.profit_percent).toFixed(2)}%</small></td></tr>`).join(''):'<tr><td class="stk-empty" colspan="6">暂无持仓，选择股票后可使用电影票买入</td></tr>';
 el('trades').innerHTML=stockState.trades.length?stockState.trades.map(t=>`<tr><td>${esc(t.created_at.slice(5,16))}</td><td class="${t.side==='buy'?'up':'down'}">${t.side==='buy'?'买入':'卖出'}</td><td>${esc(t.stock_name)}<br><span class="stk-code">${esc(t.symbol)}</span></td><td>${Number(t.shares).toLocaleString()}</td><td>${money(t.ticket_value)}</td></tr>`).join(''):'<tr><td class="stk-empty" colspan="5">暂无成交记录</td></tr>';
}
async function refresh(){try{const r=await fetch('/games/stock/?ajax=quotes',{credentials:'same-origin'}),d=await r.json();if(d.ok){stockState.quotes=d.quotes;stockState.portfolio=d.portfolio;stockState.trades=d.trades;stockState.market=d.market;stockState.wallet=d.wallet;render()}}catch(e){}}
document.addEventListener('click',e=>{const q=e.target.closest('.stk-quote');if(q){selectedSymbol=q.dataset.symbol;render()}const tab=e.target.closest('.stk-tab');if(tab){side=tab.dataset.side;render()}const chip=e.target.closest('.stk-chip');if(chip){el('shares').value=chip.dataset.shares;render()}});
el('shares').addEventListener('input',render);
el('searchForm').addEventListener('submit',async e=>{e.preventDefault();const value=el('stockSearch').value.trim();if(!/^\d{6}$/.test(value)){show('请输入六位股票代码。',false);return}try{const r=await fetch('/games/stock/?ajax=quote&symbol='+encodeURIComponent(value),{credentials:'same-origin'}),d=await r.json();if(!d.ok)throw new Error(d.message);extraQuotes[d.quote.symbol]=d.quote;selectedSymbol=d.quote.symbol;render()}catch(err){show(err.message||'查询失败。',false)}});
function orderKey(){return (crypto.randomUUID?crypto.randomUUID():Date.now()+'-'+Math.random().toString(36).slice(2)+'-'+Math.random().toString(36).slice(2))}
function show(message,ok){const m=el('message');m.className='stk-message '+(ok?'ok':'err');m.textContent=message}
el('tradeForm').addEventListener('submit',async e=>{e.preventDefault();if(busy)return;busy=true;render();const body=new URLSearchParams({token:stockState.token,symbol:selectedSymbol,side,shares:el('shares').value,order_key:orderKey()});try{const r=await fetch('/games/stock/',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body}),d=await r.json();if(!d.ok)throw new Error(d.message);show(d.message,true);await refresh()}catch(err){show(err.message||'交易失败。',false)}finally{busy=false;render()}});
render();setInterval(refresh,15000);
</script>
<?php stdfoot();
