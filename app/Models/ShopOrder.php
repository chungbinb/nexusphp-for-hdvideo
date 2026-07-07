<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ShopOrder extends NexusModel
{
    public $timestamps = false;

    protected $table = 'hdvideo_shop_orders';

    protected $fillable = [
        'order_no', 'uid', 'product_id', 'product_snapshot', 'quantity', 'unit_price', 'total_price',
        'status', 'note', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'uid' => 'integer',
        'product_id' => 'integer',
        'product_snapshot' => 'array',
        'quantity' => 'integer',
        'unit_price' => 'float',
        'total_price' => 'float',
    ];

    const STATUS_PENDING_DELIVERY = 'pending_delivery';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REFUNDED = 'refunded';

    public static function statusOptions(): array
    {
        return [
            self::STATUS_PENDING_DELIVERY => '待发放',
            self::STATUS_DELIVERED => '已发放',
            self::STATUS_CANCELLED => '已取消',
            self::STATUS_REFUNDED => '已退款',
        ];
    }

    public static function ensureSchemaOnly(): void
    {
        if (defined('IN_NEXUS') && IN_NEXUS) {
            sql_query("CREATE TABLE IF NOT EXISTS `hdvideo_shop_orders` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_no` VARCHAR(32) NOT NULL,
                `uid` INT UNSIGNED NOT NULL,
                `product_id` INT UNSIGNED NULL DEFAULT NULL,
                `product_snapshot` TEXT NULL,
                `quantity` INT NOT NULL DEFAULT 1,
                `unit_price` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
                `total_price` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
                `status` VARCHAR(30) NOT NULL DEFAULT '" . self::STATUS_PENDING_DELIVERY . "',
                `note` TEXT NULL,
                `created_at` DATETIME NULL DEFAULT NULL,
                `updated_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `hdvideo_shop_orders_order_no_unique` (`order_no`),
                KEY `hdvideo_shop_orders_uid_index` (`uid`),
                KEY `hdvideo_shop_orders_product_id_index` (`product_id`),
                KEY `hdvideo_shop_orders_status_index` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci") or sqlerr(__FILE__, __LINE__);
            return;
        }

        $schema = Schema::connection((new static)->getConnectionName());
        if (! $schema->hasTable('hdvideo_shop_orders')) {
            $schema->create('hdvideo_shop_orders', function (Blueprint $table) {
                $table->increments('id');
                $table->string('order_no', 32)->unique();
                $table->integer('uid')->unsigned()->index();
                $table->integer('product_id')->unsigned()->nullable()->index();
                $table->json('product_snapshot')->nullable();
                $table->integer('quantity')->default(1);
                $table->decimal('unit_price', 16, 2)->default(0);
                $table->decimal('total_price', 16, 2)->default(0);
                $table->string('status', 30)->default(self::STATUS_PENDING_DELIVERY)->index();
                $table->text('note')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }
    }

    public static function ensureSchema(): void
    {
        ShopSetting::ensureSchema();
        ShopProduct::ensureSchema();
        self::ensureSchemaOnly();
    }

    public static function makeOrderNo(): string
    {
        return 'S' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ShopProduct::class, 'product_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uid');
    }

    public function getStatusTextAttribute(): string
    {
        return self::statusOptions()[$this->status] ?? $this->status;
    }

    public function getProductNameAttribute(): string
    {
        $snapshot = $this->product_snapshot ?: [];
        return (string)($snapshot['name'] ?? ($this->product->name ?? ''));
    }

    public function getProductTypeTextAttribute(): string
    {
        $snapshot = $this->product_snapshot ?: [];
        $type = (string)($snapshot['type'] ?? ($this->product->type ?? ''));
        return ShopCategory::labelForCode($type);
    }
}
