<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ShopCategory extends NexusModel
{
    public $timestamps = false;

    protected $table = 'hdvideo_shop_categories';

    protected $fillable = [
        'code', 'name', 'enabled', 'sort', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'sort' => 'integer',
    ];

    public static function defaults(): array
    {
        return [
            ['code' => 'props', 'name' => '道具', 'enabled' => 1, 'sort' => 10],
            ['code' => 'medal', 'name' => '勋章', 'enabled' => 1, 'sort' => 20],
            ['code' => 'traffic', 'name' => '流量包', 'enabled' => 1, 'sort' => 30],
            ['code' => 'member_benefit', 'name' => '会员权益', 'enabled' => 1, 'sort' => 40],
        ];
    }

    public static function legacyTypeMap(): array
    {
        return [
            ShopProduct::TYPE_RENAME_CARD => 'props',
            ShopProduct::TYPE_INVITE => 'props',
        ];
    }

    public static function ensureSchema(): void
    {
        if (defined('IN_NEXUS') && IN_NEXUS) {
            sql_query("CREATE TABLE IF NOT EXISTS `hdvideo_shop_categories` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `code` VARCHAR(40) NOT NULL,
                `name` VARCHAR(100) NOT NULL DEFAULT '',
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `sort` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME NULL DEFAULT NULL,
                `updated_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `hdvideo_shop_categories_code_unique` (`code`),
                KEY `hdvideo_shop_categories_enabled_index` (`enabled`),
                KEY `hdvideo_shop_categories_sort_index` (`sort`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci") or sqlerr(__FILE__, __LINE__);
            static::seedMissingDefaultsForLegacy();
            return;
        }

        $schema = Schema::connection((new static)->getConnectionName());
        if (! $schema->hasTable('hdvideo_shop_categories')) {
            $schema->create('hdvideo_shop_categories', function (Blueprint $table) {
                $table->increments('id');
                $table->string('code', 40)->unique();
                $table->string('name', 100)->default('');
                $table->boolean('enabled')->default(true)->index();
                $table->integer('sort')->default(0)->index();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }
        static::seedMissingDefaultsForLaravel();
    }

    public static function options(bool $enabledOnly = false): array
    {
        static::ensureSchema();
        $query = static::query()->orderBy('sort')->orderBy('id');
        if ($enabledOnly) {
            $query->where('enabled', 1);
        }
        return $query->pluck('name', 'code')->all();
    }

    public static function labelForCode(string $code): string
    {
        $options = static::options(false);
        if (isset($options[$code])) {
            return $options[$code];
        }
        $legacy = static::legacyTypeMap();
        if (isset($legacy[$code], $options[$legacy[$code]])) {
            return $options[$legacy[$code]];
        }
        return $code;
    }

    public function products(): HasMany
    {
        return $this->hasMany(ShopProduct::class, 'type', 'code');
    }

    protected static function seedMissingDefaultsForLegacy(): void
    {
        $now = sqlesc(date('Y-m-d H:i:s'));
        foreach (static::defaults() as $item) {
            $exists = get_row_count('hdvideo_shop_categories', "WHERE code = " . sqlesc($item['code']));
            if ($exists == 0) {
                sql_query("INSERT INTO `hdvideo_shop_categories` (`code`, `name`, `enabled`, `sort`, `created_at`, `updated_at`)
                    VALUES (" . sqlesc($item['code']) . ", " . sqlesc($item['name']) . ", " . (int)$item['enabled'] . ", " . (int)$item['sort'] . ", $now, $now)") or sqlerr(__FILE__, __LINE__);
            }
        }
    }

    protected static function seedMissingDefaultsForLaravel(): void
    {
        $now = now()->format('Y-m-d H:i:s');
        foreach (static::defaults() as $item) {
            static::query()->firstOrCreate(
                ['code' => $item['code']],
                $item + ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }
}
