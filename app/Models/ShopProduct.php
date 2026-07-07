<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ShopProduct extends NexusModel
{
    public $timestamps = false;

    protected $table = 'hdvideo_shop_products';

    protected $fillable = [
        'type', 'name', 'description', 'price', 'stock', 'enabled', 'sort', 'metadata', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'price' => 'float',
        'stock' => 'integer',
        'enabled' => 'boolean',
        'sort' => 'integer',
        'metadata' => 'array',
    ];

    const TYPE_RENAME_CARD = 'rename_card';
    const TYPE_INVITE = 'invite';
    const TYPE_MEDAL = 'medal';
    const TYPE_TRAFFIC = 'traffic';
    const TYPE_MEMBER_BENEFIT = 'member_benefit';

    public static function typeOptions(): array
    {
        return ShopCategory::options(false);
    }

    public static function seedDefaults(): array
    {
        return [
            ['type' => 'props', 'name' => '改名卡', 'description' => '购买后生成待处理订单，由管理组确认后发放改名权益。', 'price' => 100000, 'stock' => null, 'enabled' => 1, 'sort' => 10],
            ['type' => 'props', 'name' => '邀请名额', 'description' => '购买后生成待处理订单，由管理组确认后增加邀请名额。', 'price' => 500, 'stock' => null, 'enabled' => 1, 'sort' => 20],
            ['type' => self::TYPE_MEDAL, 'name' => '勋章', 'description' => '购买后生成待处理订单，由管理组按商品备注发放指定勋章。', 'price' => 10000, 'stock' => null, 'enabled' => 1, 'sort' => 30],
            ['type' => self::TYPE_TRAFFIC, 'name' => '流量包', 'description' => '购买后生成待处理订单，由管理组按商品备注发放上传量或下载减免。', 'price' => 2000, 'stock' => null, 'enabled' => 1, 'sort' => 40],
            ['type' => self::TYPE_MEMBER_BENEFIT, 'name' => '会员权益', 'description' => '购买后生成待处理订单，由管理组按商品备注开通对应权益。', 'price' => 20000, 'stock' => null, 'enabled' => 1, 'sort' => 50],
        ];
    }

    public static function ensureSchema(): void
    {
        if (defined('IN_NEXUS') && IN_NEXUS) {
            ShopSetting::ensureSchema();
            ShopCategory::ensureSchema();
            ShopOrder::ensureSchemaOnly();
            sql_query("CREATE TABLE IF NOT EXISTS `hdvideo_shop_products` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `type` VARCHAR(40) NOT NULL DEFAULT '" . self::TYPE_MEMBER_BENEFIT . "',
                `name` VARCHAR(100) NOT NULL DEFAULT '',
                `description` TEXT NULL,
                `price` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
                `stock` INT NULL DEFAULT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `sort` INT NOT NULL DEFAULT 0,
                `metadata` TEXT NULL,
                `created_at` DATETIME NULL DEFAULT NULL,
                `updated_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `hdvideo_shop_products_type_index` (`type`),
                KEY `hdvideo_shop_products_enabled_index` (`enabled`),
                KEY `hdvideo_shop_products_sort_index` (`sort`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci") or sqlerr(__FILE__, __LINE__);
            if (get_row_count('hdvideo_shop_products') == 0) {
                $now = sqlesc(date('Y-m-d H:i:s'));
                foreach (static::seedDefaults() as $item) {
                    $stock = $item['stock'] === null ? 'NULL' : (string)(int)$item['stock'];
                    sql_query("INSERT INTO `hdvideo_shop_products`
                        (`type`, `name`, `description`, `price`, `stock`, `enabled`, `sort`, `metadata`, `created_at`, `updated_at`)
                        VALUES (" . sqlesc($item['type']) . ", " . sqlesc($item['name']) . ", " . sqlesc($item['description']) . ", " . sqlesc((float)$item['price']) . ", $stock, " . (int)$item['enabled'] . ", " . (int)$item['sort'] . ", NULL, $now, $now)") or sqlerr(__FILE__, __LINE__);
                }
            }
            foreach (ShopCategory::legacyTypeMap() as $legacyType => $categoryCode) {
                sql_query("UPDATE `hdvideo_shop_products` SET `type` = " . sqlesc($categoryCode) . " WHERE `type` = " . sqlesc($legacyType)) or sqlerr(__FILE__, __LINE__);
            }
            return;
        }

        ShopSetting::ensureSchema();
        ShopCategory::ensureSchema();
        ShopOrder::ensureSchemaOnly();
        $schema = Schema::connection((new static)->getConnectionName());
        if (! $schema->hasTable('hdvideo_shop_products')) {
            $schema->create('hdvideo_shop_products', function (Blueprint $table) {
                $table->increments('id');
                $table->string('type', 40)->default(self::TYPE_MEMBER_BENEFIT);
                $table->string('name', 100)->default('');
                $table->text('description')->nullable();
                $table->decimal('price', 16, 2)->default(0);
                $table->integer('stock')->nullable();
                $table->boolean('enabled')->default(true);
                $table->integer('sort')->default(0);
                $table->json('metadata')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }
        if (static::query()->count() === 0) {
            $now = now()->format('Y-m-d H:i:s');
            foreach (static::seedDefaults() as $item) {
                static::query()->create($item + ['created_at' => $now, 'updated_at' => $now]);
            }
        }
        foreach (ShopCategory::legacyTypeMap() as $legacyType => $categoryCode) {
            static::query()->where('type', $legacyType)->update(['type' => $categoryCode]);
        }
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ShopOrder::class, 'product_id');
    }

    public function getTypeTextAttribute(): string
    {
        return ShopCategory::labelForCode((string)$this->type);
    }

    public function getStockTextAttribute(): string
    {
        return $this->stock === null ? '不限' : (string)$this->stock;
    }
}
