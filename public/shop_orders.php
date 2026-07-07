<?php
require "../include/bittorrent.php";
dbconn();
loggedinorreturn();

\App\Models\ShopOrder::ensureSchema();
if (!\App\Models\ShopSetting::canEnter($CURUSER)) {
    stdhead("我的商城订单");
    stdmsg("商城暂未开放", "当前商城仅对指定用户等级开放。");
    stdfoot();
    exit;
}

function shop_orders_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$orders = \App\Models\ShopOrder::query()
    ->where('uid', (int)$CURUSER['id'])
    ->orderByDesc('id')
    ->limit(100)
    ->get();

stdhead("我的商城订单");
?>
<style>
.shop-orders{max-width:1100px;margin:0 auto 32px;padding:0 12px;}
.shop-orders-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:12px 0 16px;}
.shop-orders-title{font-size:22px;font-weight:800;color:var(--bili-text,#18191c);}
.shop-orders-back{display:inline-flex;align-items:center;justify-content:center;min-width:92px;height:36px;box-sizing:border-box;padding:0 16px;border:1px solid var(--bili-primary,#00aeec);border-radius:8px;background:var(--bili-surface,#fff);color:var(--bili-primary,#00aeec) !important;text-decoration:none;font-size:13px;font-weight:800;white-space:nowrap;box-shadow:0 4px 12px rgba(0,174,236,.12);}
.shop-orders-back:hover,.shop-orders-back:visited{color:var(--bili-primary,#00aeec) !important;text-decoration:none;}
.shop-orders-table{width:100%;border-collapse:separate;border-spacing:0;background:var(--bili-surface,#fff);border:1px solid var(--bili-border,#e6e9ef);border-radius:8px;overflow:hidden;}
.shop-orders-table th,.shop-orders-table td{padding:10px 11px;border-bottom:1px solid var(--bili-border,#e6e9ef);text-align:left;font-size:12px;}
.shop-orders-table th{background:var(--bili-surface-soft,#f2f3f5);color:var(--bili-text-secondary,#61666d);font-weight:800;}
.shop-orders-table tr:last-child td{border-bottom:none;}
.shop-status{display:inline-flex;border-radius:999px;padding:3px 8px;background:var(--bili-surface-soft,#f2f3f5);color:var(--bili-primary,#00aeec);font-weight:800;}
.shop-empty{padding:22px;border:1px dashed var(--bili-border,#e6e9ef);border-radius:8px;text-align:center;color:var(--bili-text-secondary,#61666d);background:var(--bili-surface,#fff);}
html[data-site-theme="night"] .shop-orders-table,html[data-site-theme="night"] .shop-empty{background:#0e1728;border-color:rgba(116,145,196,.28);}
html[data-site-theme="night"] .shop-orders-title{color:#eaf1ff;}
html[data-site-theme="night"] .shop-orders-back{background:#0e1728;}
html[data-site-theme="night"] .shop-orders-table th{background:#16223a;color:#9fb0c8;}
html[data-site-theme="night"] .shop-orders-table td{border-color:rgba(116,145,196,.24);color:#d9e2f4;}
@media (max-width:760px){.shop-orders-head{align-items:flex-start}.shop-orders-back{min-width:104px;height:38px;padding:0 18px;font-size:13px}.shop-orders-table{display:block;overflow-x:auto}.shop-orders{padding:0 10px 70px;}}
</style>
<div class="shop-orders">
	<div class="shop-orders-head">
		<div class="shop-orders-title">我的商城订单</div>
		<a class="shop-orders-back" href="shop.php">返回商城</a>
	</div>
<?php if ($orders->isEmpty()) { ?>
	<div class="shop-empty">暂时还没有商城订单。</div>
<?php } else { ?>
	<table class="shop-orders-table">
		<tr>
			<th>订单号</th>
			<th>商品</th>
			<th>类型</th>
			<th>金额</th>
			<th>状态</th>
			<th>下单时间</th>
			<th>备注</th>
		</tr>
<?php foreach ($orders as $order) { ?>
		<tr>
			<td><?php echo shop_orders_h($order->order_no) ?></td>
			<td><?php echo shop_orders_h($order->product_name) ?></td>
			<td><?php echo shop_orders_h($order->product_type_text) ?></td>
			<td><?php echo number_format((float)$order->total_price, 1) ?></td>
			<td><span class="shop-status"><?php echo shop_orders_h($order->status_text) ?></span></td>
			<td><?php echo shop_orders_h($order->created_at) ?></td>
			<td><?php echo nl2br(shop_orders_h($order->note)) ?></td>
		</tr>
<?php } ?>
	</table>
<?php } ?>
</div>
<?php
stdfoot();
