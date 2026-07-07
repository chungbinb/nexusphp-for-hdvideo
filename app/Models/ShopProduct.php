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
        return [
            self::TYPE_RENAME_CARD => '改名卡',
            self::TYPE_INVITE => '邀请名额',
            self::TYPE_MEDAL => '勋章',
            self::TYPE_TRAFFIC => '流量包',
            self::TYPE_MEMBER_BENEFIT => '会员权益',
        ];
    }

    public static function seedDefaults(): array
    {
        return [
            ['type' => self::TYPE_RENAME_CARD, 'name' => '改名卡', 'description' => '购买后生成待处理订单，由管理组确认后发放改名权益。', 'price' => 100000, 'stock' => null, 'enabled' => 1, 'sort' => 10],
            ['type' => self::TYPE_INVITE, 'name' => '邀请名额', 'description' => '购买后生成待处理订单，由管理组确认后增加邀请名额。', 'price' => 500, 'stock' => null, 'enabled' => 1, 'sort' => 20],
            ['type' => self::TYPE_MEDAL, 'name' => '勋章', 'description' => '购买后生成待处理订单，由管理组按商品备注发放指定勋章。', 'price' => 10000, 'stock' => null, 'enabled' => 1, 'sort' => 30],
            ['type' => self::TYPE_TRAFFIC, 'name' => '流量包', 'description' => '购买后生成待处理订单，由管理组按商品备注发放上传量或下载减免。', 'price' => 2000, 'stock' => null, 'enabled' => 1, 'sort' => 40],
            ['type' => self::TYPE_MEMBER_BENEFIT, 'name' => '会员权益', 'description' => '购买后生成待处理订单，由管理组按商品备注开通对应权益。', 'price' => 20000, 'stock' => null, 'enabled' => 1, 'sort' => 50],
        ];
    }

    public static function ensureSchema(): void
    {
        ShopSetting::ensureSchema();
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
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ShopOrder::class, 'product_id');
    }

    public function getTypeTextAttribute(): string
    {
        return self::typeOptions()[$this->type] ?? $this->type;
    }

    public function getStockTextAttribute(): string
    {
        return $this->stock === null ? '不限' : (string)$this->stock;
    }
}
