<?php

namespace App\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShopSetting extends NexusModel
{
    public $timestamps = false;

    protected $table = 'hdvideo_shop_settings';

    protected $fillable = ['enabled', 'min_class'];

    protected $casts = [
        'enabled' => 'boolean',
        'min_class' => 'integer',
    ];

    public static function classOptions(): array
    {
        return [
            0 => '所有用户 (0)',
            1 => '普通用户 (1)',
            2 => 'Power User (2)',
            3 => 'Elite User (3)',
            4 => 'Crazy User (4)',
            5 => 'Insane User (5)',
            6 => 'Veteran User (6)',
            7 => 'Extreme User (7)',
            8 => 'Ultimate User (8)',
            9 => 'Nexus Master (9)',
            10 => '贵宾 (10)',
            13 => '版主 (13)',
            14 => '管理员 (14)',
            15 => '维护/开发 (15)',
            16 => '主管 (16)',
        ];
    }

    public static function ensureSchema(): void
    {
        if (defined('IN_NEXUS') && IN_NEXUS) {
            sql_query("CREATE TABLE IF NOT EXISTS `hdvideo_shop_settings` (
                `id` INT UNSIGNED NOT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `min_class` INT NOT NULL DEFAULT 14,
                `updated_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci") or sqlerr(__FILE__, __LINE__);
            $exists = get_row_count('hdvideo_shop_settings', 'WHERE id = 1');
            if (!$exists) {
                $minClass = defined('UC_ADMINISTRATOR') ? UC_ADMINISTRATOR : 14;
                sql_query("INSERT INTO `hdvideo_shop_settings` (`id`, `enabled`, `min_class`, `updated_at`) VALUES (1, 1, " . (int)$minClass . ", " . sqlesc(date('Y-m-d H:i:s')) . ")") or sqlerr(__FILE__, __LINE__);
            }
            return;
        }

        $conn = (new static)->getConnectionName();
        $schema = Schema::connection($conn);
        if (! $schema->hasTable('hdvideo_shop_settings')) {
            $schema->create('hdvideo_shop_settings', function (Blueprint $table) {
                $table->integer('id')->unsigned()->primary();
                $table->boolean('enabled')->default(true);
                $table->integer('min_class')->default(14);
                $table->dateTime('updated_at')->nullable();
            });
        }
        if (! DB::connection($conn)->table('hdvideo_shop_settings')->where('id', 1)->exists()) {
            DB::connection($conn)->table('hdvideo_shop_settings')->insert([
                'id' => 1,
                'enabled' => 1,
                'min_class' => defined('UC_ADMINISTRATOR') ? UC_ADMINISTRATOR : 14,
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);
        }
    }

    public static function current(): self
    {
        self::ensureSchema();
        return self::query()->findOrFail(1);
    }

    public static function canEnter(?array $user = null): bool
    {
        if (empty($user)) {
            return false;
        }
        $setting = self::current();
        return (bool)$setting->enabled && (int)($user['class'] ?? 0) >= (int)$setting->min_class;
    }
}
