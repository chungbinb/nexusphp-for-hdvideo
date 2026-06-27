<?php

namespace App\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScratchPrize extends NexusModel
{
    public $timestamps = false;

    protected $table = 'hdvideo_scratch_items';

    protected $fillable = ['name', 'reward_type', 'amount', 'weight', 'is_enabled', 'sort'];

    protected $casts = [
        'amount' => 'integer',
        'weight' => 'integer',
        'is_enabled' => 'boolean',
        'sort' => 'integer',
    ];

    const DEFAULT_COST = 500;

    public static function rewardTypes(): array
    {
        return [
            'none' => '谢谢惠顾（无奖励）',
            'bonus' => '电影票',
            'upload' => '上传量（GB）',
            'download' => '下载量减免（GB）',
            'item' => '实物/卡类（人工发放，如改名卡、彩色昵称）',
        ];
    }

    /**
     * Default prize roster, kept in sync with public/games/scratch SC_ITEMS_DEFAULT.
     */
    public static function seedDefaults(): array
    {
        return [
            ['name' => '谢谢惠顾', 'reward_type' => 'none', 'amount' => 0, 'weight' => 520, 'sort' => 1],
            ['name' => '500电影票', 'reward_type' => 'bonus', 'amount' => 500, 'weight' => 250, 'sort' => 2],
            ['name' => '1000电影票', 'reward_type' => 'bonus', 'amount' => 1000, 'weight' => 130, 'sort' => 3],
            ['name' => '2000电影票', 'reward_type' => 'bonus', 'amount' => 2000, 'weight' => 55, 'sort' => 4],
            ['name' => '50G上传量', 'reward_type' => 'upload', 'amount' => 50, 'weight' => 35, 'sort' => 5],
            ['name' => '改名卡', 'reward_type' => 'item', 'amount' => 0, 'weight' => 8, 'sort' => 6],
            ['name' => '彩色昵称', 'reward_type' => 'item', 'amount' => 0, 'weight' => 2, 'sort' => 7],
        ];
    }

    /**
     * Self-managed tables (no migration). Create + seed prizes and the cost config
     * on demand so the backend works even before the刮刮乐 game page has run.
     */
    public static function ensureSchema(): void
    {
        $conn = (new static)->getConnectionName();
        $schema = Schema::connection($conn);
        if (! $schema->hasTable('hdvideo_scratch_items')) {
            $schema->create('hdvideo_scratch_items', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 60)->default('');
                $table->string('reward_type', 20)->default('none');
                $table->bigInteger('amount')->default(0);
                $table->integer('weight')->unsigned()->default(1);
                $table->boolean('is_enabled')->default(true);
                $table->integer('sort')->default(0);
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }
        if (! $schema->hasTable('hdvideo_scratch_config')) {
            $schema->create('hdvideo_scratch_config', function (Blueprint $table) {
                $table->string('name', 40)->primary();
                $table->string('value', 255)->default('');
            });
        }
        // drop the obsolete multiplier-based prize table from the previous design
        if ($schema->hasTable('hdvideo_scratch_prizes')) {
            $schema->drop('hdvideo_scratch_prizes');
        }
        if (static::query()->count() === 0) {
            $now = now()->format('Y-m-d H:i:s');
            foreach (static::seedDefaults() as $d) {
                static::query()->create($d + ['is_enabled' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        if (! DB::connection($conn)->table('hdvideo_scratch_config')->where('name', 'cost')->exists()) {
            DB::connection($conn)->table('hdvideo_scratch_config')->insert(['name' => 'cost', 'value' => (string) self::DEFAULT_COST]);
        }
        if (! DB::connection($conn)->table('hdvideo_scratch_config')->where('name', 'daily_limit')->exists()) {
            DB::connection($conn)->table('hdvideo_scratch_config')->insert(['name' => 'daily_limit', 'value' => '0']);
        }
        if (! DB::connection($conn)->table('hdvideo_scratch_config')->where('name', 'cooldown')->exists()) {
            DB::connection($conn)->table('hdvideo_scratch_config')->insert(['name' => 'cooldown', 'value' => '2']);
        }
    }

    public static function getCost(): int
    {
        self::ensureSchema();
        $row = DB::connection((new static)->getConnectionName())
            ->table('hdvideo_scratch_config')->where('name', 'cost')->first();
        return $row ? max(0, (int) $row->value) : self::DEFAULT_COST;
    }

    public static function setCost(int $v): void
    {
        self::ensureSchema();
        DB::connection((new static)->getConnectionName())
            ->table('hdvideo_scratch_config')
            ->updateOrInsert(['name' => 'cost'], ['value' => (string) max(0, $v)]);
    }

    /** Daily scratch cap per user; 0 = unlimited. */
    public static function getDailyLimit(): int
    {
        self::ensureSchema();
        $row = DB::connection((new static)->getConnectionName())
            ->table('hdvideo_scratch_config')->where('name', 'daily_limit')->first();
        return $row ? max(0, (int) $row->value) : 0;
    }

    public static function setDailyLimit(int $v): void
    {
        self::ensureSchema();
        DB::connection((new static)->getConnectionName())
            ->table('hdvideo_scratch_config')
            ->updateOrInsert(['name' => 'daily_limit'], ['value' => (string) max(0, $v)]);
    }

    /** Minimum seconds between two scratches per user; 0 = no cooldown. */
    public static function getCooldown(): int
    {
        self::ensureSchema();
        $row = DB::connection((new static)->getConnectionName())
            ->table('hdvideo_scratch_config')->where('name', 'cooldown')->first();
        return $row ? max(0, (int) $row->value) : 2;
    }

    public static function setCooldown(int $v): void
    {
        self::ensureSchema();
        DB::connection((new static)->getConnectionName())
            ->table('hdvideo_scratch_config')
            ->updateOrInsert(['name' => 'cooldown'], ['value' => (string) max(0, $v)]);
    }

    public static function enabledWeightTotal(): int
    {
        return (int) static::query()->where('is_enabled', 1)->sum('weight');
    }

    public static function amountLabel(self $record): string
    {
        switch ($record->reward_type) {
            case 'bonus':
                return number_format($record->amount) . ' 电影票';
            case 'upload':
                return $record->amount . ' G 上传量';
            case 'download':
                return $record->amount . ' G 下载减免';
            default:
                return '—';
        }
    }
}
