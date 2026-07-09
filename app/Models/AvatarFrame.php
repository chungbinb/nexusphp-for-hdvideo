<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AvatarFrame extends NexusModel
{
    public $timestamps = false;

    protected $table = 'hdvideo_avatar_frames';

    protected static bool $schemaEnsured = false;

    protected $fillable = [
        'code', 'name', 'description', 'image_url', 'css_class', 'price', 'is_free', 'enabled',
        'bonus_type', 'bonus_value', 'sort', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'price' => 'float',
        'is_free' => 'boolean',
        'enabled' => 'boolean',
        'bonus_value' => 'float',
        'sort' => 'integer',
    ];

    const BONUS_NONE = 'none';
    const BONUS_UPLOAD = 'upload';
    const BONUS_SHARE_RATIO = 'share_ratio';
    const BONUS_SEED_POINTS = 'seed_points';

    public static function bonusTypeOptions(): array
    {
        return [
            self::BONUS_NONE => '无加成',
            self::BONUS_UPLOAD => '上传量加成',
            self::BONUS_SHARE_RATIO => '分享率加成',
            self::BONUS_SEED_POINTS => '做种积分加成',
        ];
    }

    public static function defaults(): array
    {
        return [
            [
                'code' => 'fresh_leaf',
                'name' => '清新叶环',
                'description' => '基础免费头像挂件，无属性加成。',
                'image_url' => '',
                'css_class' => 'fresh_leaf',
                'price' => 0,
                'is_free' => 1,
                'enabled' => 1,
                'bonus_type' => self::BONUS_NONE,
                'bonus_value' => 0,
                'sort' => 10,
            ],
            [
                'code' => 'sky_badge',
                'name' => '晴空徽环',
                'description' => '基础免费头像挂件，无属性加成。',
                'image_url' => '',
                'css_class' => 'sky_badge',
                'price' => 0,
                'is_free' => 1,
                'enabled' => 1,
                'bonus_type' => self::BONUS_NONE,
                'bonus_value' => 0,
                'sort' => 20,
            ],
            [
                'code' => 'starlight_boost',
                'name' => '星光加成挂件',
                'description' => '付费头像挂件，默认提供做种积分加成；后台可改为上传量或分享率加成。',
                'image_url' => '',
                'css_class' => 'starlight_boost',
                'price' => 5000,
                'is_free' => 0,
                'enabled' => 1,
                'bonus_type' => self::BONUS_SEED_POINTS,
                'bonus_value' => 0.05,
                'sort' => 30,
            ],
        ];
    }

    public static function ensureSchema(): void
    {
        if (static::$schemaEnsured) {
            return;
        }
        if (defined('IN_NEXUS') && IN_NEXUS) {
            UserAvatarFrame::ensureSchemaOnly();
            ShopCategory::ensureSchema();
            sql_query("CREATE TABLE IF NOT EXISTS `hdvideo_avatar_frames` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `code` VARCHAR(60) NOT NULL,
                `name` VARCHAR(100) NOT NULL DEFAULT '',
                `description` TEXT NULL,
                `image_url` VARCHAR(255) NOT NULL DEFAULT '',
                `css_class` VARCHAR(60) NOT NULL DEFAULT '',
                `price` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
                `is_free` TINYINT(1) NOT NULL DEFAULT 0,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `bonus_type` VARCHAR(30) NOT NULL DEFAULT 'none',
                `bonus_value` DECIMAL(10,5) NOT NULL DEFAULT 0.00000,
                `sort` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME NULL DEFAULT NULL,
                `updated_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `hdvideo_avatar_frames_code_unique` (`code`),
                KEY `hdvideo_avatar_frames_enabled_index` (`enabled`),
                KEY `hdvideo_avatar_frames_sort_index` (`sort`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci") or sqlerr(__FILE__, __LINE__);
            static::seedMissingDefaultsForLegacy();
            static::$schemaEnsured = true;
            return;
        }

        UserAvatarFrame::ensureSchemaOnly();
        ShopCategory::ensureSchema();
        $schema = Schema::connection((new static)->getConnectionName());
        if (! $schema->hasTable('hdvideo_avatar_frames')) {
            $schema->create('hdvideo_avatar_frames', function (Blueprint $table) {
                $table->increments('id');
                $table->string('code', 60)->unique();
                $table->string('name', 100)->default('');
                $table->text('description')->nullable();
                $table->string('image_url', 255)->default('');
                $table->string('css_class', 60)->default('');
                $table->decimal('price', 16, 2)->default(0);
                $table->boolean('is_free')->default(false);
                $table->boolean('enabled')->default(true)->index();
                $table->string('bonus_type', 30)->default(self::BONUS_NONE);
                $table->decimal('bonus_value', 10, 5)->default(0);
                $table->integer('sort')->default(0)->index();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }
        static::seedMissingDefaultsForLaravel();
        static::$schemaEnsured = true;
    }

    public static function activeOptions(): array
    {
        static::ensureSchema();
        return static::query()->where('enabled', 1)->orderBy('sort')->orderBy('id')->pluck('name', 'id')->all();
    }

    public static function wearingForUser(int $uid): ?self
    {
        if ($uid <= 0) {
            return null;
        }
        static::ensureSchema();
        $owned = UserAvatarFrame::query()
            ->with('frame')
            ->where('uid', $uid)
            ->where('status', UserAvatarFrame::STATUS_WEARING)
            ->first();
        if (! $owned || ! $owned->frame || ! $owned->frame->enabled) {
            return null;
        }
        return $owned->frame;
    }

    public static function userBonusFactor(int $uid, string $bonusType): float
    {
        try {
            $frame = static::wearingForUser($uid);
        } catch (\Throwable $e) {
            return 0.0;
        }
        if (! $frame || $frame->bonus_type !== $bonusType) {
            return 0.0;
        }
        return max(0.0, (float)$frame->bonus_value);
    }

    public function owners(): HasMany
    {
        return $this->hasMany(UserAvatarFrame::class, 'frame_id');
    }

    public function getBonusTextAttribute(): string
    {
        if ($this->bonus_type === self::BONUS_NONE || (float)$this->bonus_value <= 0) {
            return '无加成';
        }
        $label = self::bonusTypeOptions()[$this->bonus_type] ?? $this->bonus_type;
        return $label . ' +' . rtrim(rtrim(number_format((float)$this->bonus_value * 100, 2), '0'), '.') . '%';
    }

    protected static function seedMissingDefaultsForLegacy(): void
    {
        $now = sqlesc(date('Y-m-d H:i:s'));
        foreach (static::defaults() as $item) {
            $exists = get_row_count('hdvideo_avatar_frames', "WHERE code = " . sqlesc($item['code']));
            if ($exists == 0) {
                sql_query("INSERT INTO `hdvideo_avatar_frames`
                    (`code`, `name`, `description`, `image_url`, `css_class`, `price`, `is_free`, `enabled`, `bonus_type`, `bonus_value`, `sort`, `created_at`, `updated_at`)
                    VALUES (" . sqlesc($item['code']) . ", " . sqlesc($item['name']) . ", " . sqlesc($item['description']) . ", " . sqlesc($item['image_url']) . ", " . sqlesc($item['css_class']) . ", " . sqlesc((float)$item['price']) . ", " . (int)$item['is_free'] . ", " . (int)$item['enabled'] . ", " . sqlesc($item['bonus_type']) . ", " . sqlesc((float)$item['bonus_value']) . ", " . (int)$item['sort'] . ", $now, $now)") or sqlerr(__FILE__, __LINE__);
            }
        }
        $exists = get_row_count('hdvideo_shop_categories', "WHERE code = 'avatar_frame'");
        if ($exists == 0) {
            sql_query("INSERT INTO `hdvideo_shop_categories` (`code`, `name`, `enabled`, `sort`, `created_at`, `updated_at`)
                VALUES ('avatar_frame', '头像挂件', 1, 25, $now, $now)") or sqlerr(__FILE__, __LINE__);
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
        ShopCategory::query()->firstOrCreate(
            ['code' => 'avatar_frame'],
            ['name' => '头像挂件', 'enabled' => 1, 'sort' => 25, 'created_at' => $now, 'updated_at' => $now]
        );
    }
}
