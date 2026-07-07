<?php
require "../include/bittorrent.php";
dbconn();
loggedinorreturn();

\App\Models\ShopProduct::ensureSchema();
$shopSetting = \App\Models\ShopSetting::current();
if (!\App\Models\ShopSetting::canEnter($CURUSER)) {
    stdhead("商城");
    stdmsg("商城暂未开放", "当前商城仅对指定用户等级开放。");
    stdfoot();
    exit;
}

function shop_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function shop_buy_product(int $productId): \App\Models\ShopOrder {
    global $CURUSER;
    return \Nexus\Database\NexusDB::transaction(function () use ($productId, $CURUSER) {
        $product = \App\Models\ShopProduct::query()
            ->where('enabled', 1)
            ->where('id', $productId)
            ->lockForUpdate()
            ->first();
        if (!$product) {
            throw new RuntimeException("商品不存在或已下架。");
        }
        if ($product->stock !== null && (int)$product->stock < 1) {
            throw new RuntimeException("商品库存不足。");
        }
        $user = \App\Models\User::query()->where('id', (int)$CURUSER['id'])->lockForUpdate()->firstOrFail();
        $oldBonus = (float)$user->seedbonus;
        $total = round((float)$product->price, 2);
        if ($total > 0 && $oldBonus < $total) {
            throw new RuntimeException("电影票不足，无法购买。");
        }
        $newBonus = round($oldBonus - $total, 2);
        $user->seedbonus = $newBonus;
        $user->save();

        if ($product->stock !== null) {
            $product->stock = max(0, (int)$product->stock - 1);
            $product->save();
        }

        $now = date('Y-m-d H:i:s');
        $order = \App\Models\ShopOrder::query()->create([
            'order_no' => \App\Models\ShopOrder::makeOrderNo(),
            'uid' => (int)$CURUSER['id'],
            'product_id' => (int)$product->id,
            'product_snapshot' => [
                'id' => (int)$product->id,
                'type' => (string)$product->type,
                'name' => (string)$product->name,
                'description' => (string)$product->description,
                'price' => $total,
            ],
            'quantity' => 1,
            'unit_price' => $total,
            'total_price' => $total,
            'status' => \App\Models\ShopOrder::STATUS_PENDING_DELIVERY,
            'note' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($total > 0) {
            \App\Models\BonusLogs::add(
                (int)$CURUSER['id'],
                $oldBonus,
                -$total,
                $newBonus,
                "商城购买：{$product->name}（订单 {$order->order_no}）",
                \App\Models\BonusLogs::BUSINESS_TYPE_SHOP
            );
        }

        return $order;
    });
}

function shop_buy_medal(int $medalId): void {
    global $CURUSER;
    $rep = new \App\Repositories\BonusRepository();
    $rep->consumeToBuyMedal((int)$CURUSER['id'], $medalId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buy') {
    try {
        $order = shop_buy_product((int)($_POST['product_id'] ?? 0));
        stdhead("商城");
        stdmsg("购买成功", "订单 {$order->order_no} 已生成，商品权益将由管理组处理。<br><a href=\"shop_orders.php\">查看我的订单</a> | <a href=\"shop.php\">继续逛商城</a>");
        stdfoot();
        exit;
    } catch (Throwable $e) {
        stdhead("商城");
        stdmsg("购买失败", shop_h($e->getMessage()) . "<br><a href=\"shop.php\">返回商城</a>");
        stdfoot();
        exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buy_medal') {
    try {
        shop_buy_medal((int)($_POST['medal_id'] ?? 0));
        stdhead("商城");
        stdmsg("购买成功", "勋章已购买并发放到账户。<br><a href=\"shop.php?cat=medal\">继续逛勋章</a> | <a href=\"medal.php\">查看我的勋章</a>");
        stdfoot();
        exit;
    } catch (Throwable $e) {
        stdhead("商城");
        stdmsg("购买失败", shop_h($e->getMessage()) . "<br><a href=\"shop.php?cat=medal\">返回勋章</a>");
        stdfoot();
        exit;
    }
}

$products = \App\Models\ShopProduct::query()
    ->where('enabled', 1)
    ->orderBy('sort')
    ->orderBy('id')
    ->get()
    ->groupBy('type');
$shopCategories = \App\Models\ShopCategory::query()
    ->where('enabled', 1)
    ->orderBy('sort')
    ->orderBy('id')
    ->get()
    ->keyBy('code');
$shopMenuCategories = $shopCategories->all();
$activeCategory = (string)($_GET['cat'] ?? '');
$requestedType = (string)($_GET['type'] ?? '');
if (($activeCategory === '' || !isset($shopMenuCategories[$activeCategory])) && $requestedType !== '') {
    $legacyTypeMap = \App\Models\ShopCategory::legacyTypeMap();
    $activeCategory = $legacyTypeMap[$requestedType] ?? $requestedType;
}
if ($activeCategory === '' || !isset($shopMenuCategories[$activeCategory])) {
    $activeCategory = array_key_first($shopMenuCategories) ?: '';
}
$activeItems = [];
if ($activeCategory !== '') {
    $typeProducts = $products->get($activeCategory);
    if ($typeProducts && !$typeProducts->isEmpty()) {
        foreach ($typeProducts as $product) {
            $activeItems[] = $product;
        }
    }
}
$isMedalCategory = $activeCategory === 'medal';
$activeMedals = collect();
$ownedMedalIds = collect();
if ($isMedalCategory) {
    $activeMedals = \App\Models\Medal::query()
        ->where('display_on_medal_page', 1)
        ->orderBy('priority', 'desc')
        ->orderBy('id', 'desc')
        ->get();
    $user = \App\Models\User::query()->findOrFail((int)$CURUSER['id']);
    $ownedMedalIds = $user->valid_medals->pluck('id')->flip();
}

stdhead("商城");
?>
<style>
.shop-page{max-width:1180px;margin:0 auto 32px;padding:0 12px;}
.shop-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin:12px 0 18px;}
.shop-title{font-size:24px;font-weight:800;color:var(--bili-text,#18191c);}
.shop-wallet{font-size:13px;color:var(--bili-text-secondary,#61666d);}
.shop-wallet b{color:var(--bili-primary,#00aeec);font-size:17px;}
.shop-actions a{display:inline-flex;align-items:center;justify-content:center;min-width:92px;height:36px;box-sizing:border-box;padding:0 16px;border:1px solid var(--bili-primary,#00aeec);border-radius:8px;background:var(--bili-surface,#fff);color:var(--bili-primary,#00aeec);text-decoration:none;font-size:13px;font-weight:800;white-space:nowrap;box-shadow:0 4px 12px rgba(0,174,236,.12);}
.shop-actions a:hover{background:var(--bili-primary,#00aeec);color:#fff;text-decoration:none;}
.shop-category-menu{display:flex;gap:8px;align-items:center;margin:0 0 16px;padding:8px;border:1px solid var(--bili-border,#e6e9ef);border-radius:8px;background:var(--bili-surface,#fff);overflow-x:auto;scrollbar-width:thin;}
.shop-category-tab{display:inline-flex;align-items:center;justify-content:center;min-width:96px;height:38px;padding:0 15px;border-radius:7px;color:var(--bili-text-secondary,#61666d);font-weight:800;text-decoration:none;white-space:nowrap;transition:background .15s ease,color .15s ease,box-shadow .15s ease;}
.shop-category-tab:hover{background:var(--bili-surface-soft,#f2f3f5);color:var(--bili-primary,#00aeec);text-decoration:none;}
.shop-category-tab.on,.shop-category-tab.on:hover,.shop-category-tab.on:visited{background:var(--bili-primary,#00aeec);color:#fff !important;box-shadow:0 6px 16px rgba(0,174,236,.22);}
.shop-section{margin:0 0 22px;}
.shop-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px;}
.shop-card{border:1px solid var(--bili-border,#e6e9ef);border-radius:8px;background:var(--bili-surface,#fff);padding:14px;min-height:154px;display:flex;flex-direction:column;gap:10px;}
.shop-medal-card{min-height:246px;}
.shop-medal-image{height:82px;display:flex;align-items:center;justify-content:center;background:transparent;border-radius:8px;overflow:hidden;}
.shop-medal-image img{max-width:74px;max-height:74px;display:block;}
.shop-card-title{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;font-size:16px;font-weight:800;color:var(--bili-text,#18191c);}
.shop-badge{font-size:11px;font-weight:700;border-radius:999px;padding:3px 7px;background:var(--bili-surface-soft,#f2f3f5);color:var(--bili-primary,#00aeec);white-space:nowrap;}
.shop-desc{font-size:12px;line-height:1.55;color:var(--bili-text-secondary,#61666d);min-height:38px;}
.shop-medal-spec{display:flex;gap:6px;flex-wrap:wrap;font-size:11px;color:var(--bili-text-secondary,#61666d);}
.shop-medal-spec span{padding:3px 7px;border-radius:999px;background:var(--bili-surface-soft,#f2f3f5);}
.shop-meta{display:flex;flex-direction:column;align-items:stretch;gap:8px;margin-top:auto;}
.shop-price{font-size:18px;font-weight:900;color:var(--bili-primary,#00aeec);}
.shop-price span{font-size:11px;font-weight:600;color:var(--bili-text-muted,#9499a0);}
.shop-meta form{display:flex;justify-content:flex-end;}
.shop-buy{border:none;border-radius:8px;background:var(--bili-primary,#00aeec);color:#fff;padding:8px 13px;font-weight:800;cursor:pointer;}
.shop-buy:hover{background:var(--bili-primary-hover,#38bff2);}
.shop-buy:disabled{background:var(--bili-surface-soft,#f2f3f5);color:var(--bili-text-muted,#9499a0);cursor:not-allowed;}
.shop-empty{padding:22px;border:1px dashed var(--bili-border,#e6e9ef);border-radius:8px;text-align:center;color:var(--bili-text-secondary,#61666d);background:var(--bili-surface,#fff);}
html[data-site-theme="night"] .shop-card,html[data-site-theme="night"] .shop-empty{background:#0e1728;border-color:rgba(116,145,196,.28);}
html[data-site-theme="night"] .shop-medal-spec span{background:rgba(116,145,196,.16);}
html[data-site-theme="night"] .shop-category-menu{background:#0e1728;border-color:rgba(116,145,196,.28);}
html[data-site-theme="night"] .shop-category-tab{color:#9fb0c8;}
html[data-site-theme="night"] .shop-category-tab:hover{background:rgba(116,145,196,.16);color:#eaf1ff;}
html[data-site-theme="night"] .shop-actions a{background:#0e1728;}
html[data-site-theme="night"] .shop-title,html[data-site-theme="night"] .shop-card-title{color:#eaf1ff;}
html[data-site-theme="night"] .shop-desc,html[data-site-theme="night"] .shop-wallet{color:#9fb0c8;}
@media (max-width:700px){.shop-head{align-items:flex-start;flex-direction:column}.shop-actions a{min-width:104px;height:38px;padding:0 18px;font-size:13px}.shop-category-menu{margin-left:-2px;margin-right:-2px;padding:7px}.shop-category-tab{min-width:auto;height:36px;padding:0 13px}.shop-grid{grid-template-columns:1fr}.shop-page{padding:0 10px 70px;}}
</style>
<div class="shop-page">
	<div class="shop-head">
		<div>
			<div class="shop-title">商城</div>
			<div class="shop-wallet">当前电影票 <b><?php echo number_format((float)$CURUSER['seedbonus'], 1) ?></b></div>
		</div>
		<div class="shop-actions"><a href="shop_orders.php">我的订单</a></div>
	</div>
<?php if (!$shopMenuCategories) { ?>
	<div class="shop-empty">暂无上架商品。</div>
<?php } else { ?>
	<div class="shop-category-menu" role="tablist" aria-label="商城分类">
<?php foreach ($shopMenuCategories as $categoryCode => $category) { ?>
		<a class="shop-category-tab<?php echo $categoryCode === $activeCategory ? ' on' : '' ?>" href="shop.php?cat=<?php echo urlencode($categoryCode) ?>" role="tab" aria-selected="<?php echo $categoryCode === $activeCategory ? 'true' : 'false' ?>"><?php echo shop_h($category->name) ?></a>
<?php } ?>
	</div>
	<div class="shop-section">
<?php if ($isMedalCategory) { ?>
<?php if ($activeMedals->isEmpty()) { ?>
		<div class="shop-empty">当前分类暂无上架商品。</div>
<?php } else { ?>
		<div class="shop-grid">
<?php foreach ($activeMedals as $medal) {
    $buttonText = '购买';
    $disabled = '';
    try {
        $medal->checkCanBeBuy();
        if ($ownedMedalIds->has($medal->id)) {
            $buttonText = '已拥有';
            $disabled = ' disabled';
        } elseif ((float)$CURUSER['seedbonus'] < (float)$medal->price) {
            $buttonText = '电影票不足';
            $disabled = ' disabled';
        }
    } catch (Throwable $e) {
        $buttonText = $e->getMessage();
        $disabled = ' disabled';
    }
?>
			<div class="shop-card shop-medal-card">
				<div class="shop-medal-image"><img src="<?php echo shop_h($medal->image_large) ?>" alt="<?php echo shop_h($medal->name) ?>" loading="lazy"></div>
				<div class="shop-card-title">
					<span><?php echo shop_h($medal->name) ?></span>
					<span class="shop-badge"><?php echo shop_h($medal->inventory_text) ?></span>
				</div>
				<div class="shop-desc"><?php echo nl2br(shop_h($medal->description ?: '暂无说明')) ?></div>
				<div class="shop-medal-spec">
					<span><?php echo shop_h($medal->duration_text) ?></span>
					<span>加成 <?php echo shop_h((string)(($medal->bonus_addition_factor ?? 0) * 100)) ?>%</span>
				</div>
				<div class="shop-meta">
					<div class="shop-price">售价：<?php echo number_format((float)$medal->price, 1) ?> <span>电影票</span></div>
					<form method="post" action="shop.php?cat=medal">
						<input type="hidden" name="action" value="buy_medal">
						<input type="hidden" name="medal_id" value="<?php echo (int)$medal->id ?>">
						<button class="shop-buy" type="submit"<?php echo $disabled ?>><?php echo shop_h($buttonText) ?></button>
					</form>
				</div>
			</div>
<?php } ?>
		</div>
<?php } ?>
<?php } elseif (!$activeItems) { ?>
		<div class="shop-empty">当前分类暂无上架商品。</div>
<?php } else { ?>
		<div class="shop-grid">
<?php foreach ($activeItems as $product) { ?>
			<div class="shop-card">
				<div class="shop-card-title">
					<span><?php echo shop_h($product->name) ?></span>
					<span class="shop-badge"><?php echo shop_h($product->stock_text) ?></span>
				</div>
				<div class="shop-desc"><?php echo nl2br(shop_h($product->description ?: '暂无说明')) ?></div>
				<div class="shop-meta">
					<div class="shop-price">售价：<?php echo number_format((float)$product->price, 1) ?> <span>电影票</span></div>
					<form method="post" action="shop.php?cat=<?php echo urlencode($activeCategory) ?>">
						<input type="hidden" name="action" value="buy">
						<input type="hidden" name="product_id" value="<?php echo (int)$product->id ?>">
						<button class="shop-buy" type="submit">购买</button>
					</form>
				</div>
			</div>
<?php } ?>
		</div>
<?php } ?>
	</div>
<?php } ?>
</div>
<?php
stdfoot();
