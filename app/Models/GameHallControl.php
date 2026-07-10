<?php

namespace App\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class GameHallControl extends NexusModel
{
    public $timestamps = false;

    protected $table = 'game_hall_controls';

    protected $fillable = ['game_key', 'name', 'is_open', 'min_class', 'sort', 'bot_difficulty', 'stock_symbols', 'stock_ticket_rate', 'stock_fee_rate', 'stock_trade_enabled'];

    protected $casts = [
        'is_open' => 'boolean',
        'min_class' => 'integer',
        'sort' => 'integer',
        'bot_difficulty' => 'string',
        'stock_ticket_rate' => 'float',
        'stock_fee_rate' => 'float',
        'stock_trade_enabled' => 'boolean',
    ];

    /**
     * Default game roster, kept in sync with include/game_control.php.
     */
    public static function seedDefaults(): array
    {
        return [
            ['game_key' => 'big-small', 'name' => '压大小', 'is_open' => 1, 'min_class' => 15, 'sort' => 1],
            ['game_key' => 'sports', 'name' => '菠菜系统', 'is_open' => 1, 'min_class' => 15, 'sort' => 2],
            ['game_key' => 'ddz', 'name' => '斗地主', 'is_open' => 1, 'min_class' => 15, 'sort' => 3],
            ['game_key' => 'scratch', 'name' => '刮刮乐', 'is_open' => 1, 'min_class' => 15, 'sort' => 4],
            ['game_key' => 'quiz', 'name' => '答题挑战', 'is_open' => 0, 'min_class' => 15, 'sort' => 5],
            ['game_key' => 'chest', 'name' => '签到宝箱', 'is_open' => 1, 'min_class' => 15, 'sort' => 6],
            ['game_key' => 'blackjack', 'name' => '二十一点', 'is_open' => 1, 'min_class' => 15, 'sort' => 7],
            ['game_key' => 'slots', 'name' => '老虎机', 'is_open' => 1, 'min_class' => 15, 'sort' => 8],
            ['game_key' => 'plinko', 'name' => 'Plinko弹珠', 'is_open' => 1, 'min_class' => 15, 'sort' => 9],
            ['game_key' => 'hilo', 'name' => '猜高低', 'is_open' => 1, 'min_class' => 15, 'sort' => 10],
            ['game_key' => 'moviequiz', 'name' => '猜电影', 'is_open' => 0, 'min_class' => 15, 'sort' => 11],
            ['game_key' => 'poker', 'name' => '德州扑克', 'is_open' => 1, 'min_class' => 15, 'sort' => 12],
            ['game_key' => 'zjh', 'name' => '炸金花', 'is_open' => 1, 'min_class' => 15, 'sort' => 13, 'bot_difficulty' => 'simple'],
            ['game_key' => 'stock', 'name' => '股票模拟交易', 'is_open' => 1, 'min_class' => 15, 'sort' => 14,
                'stock_symbols' => 'SH600519,SZ000001,SH601318,SZ300750,SH600036,SH601899,SZ000858,SH600900',
                'stock_ticket_rate' => 1, 'stock_fee_rate' => 0.001, 'stock_trade_enabled' => 1],
        ];
    }

    /**
     * The control table is self-managed (no migration). Create + seed it on demand
     * so the Filament backend works even before any legacy game page has run.
     */
    public static function ensureSchema(): void
    {
        $schema = Schema::connection((new static)->getConnectionName());
        if (! $schema->hasTable('game_hall_controls')) {
            $schema->create('game_hall_controls', function (Blueprint $table) {
                $table->increments('id');
                $table->string('game_key', 40)->unique();
                $table->string('name', 60)->default('');
                $table->boolean('is_open')->default(true);
                $table->integer('min_class')->default(15);
                $table->integer('sort')->default(0);
                $table->string('bot_difficulty', 16)->default('simple');
                $table->text('stock_symbols')->nullable();
                $table->decimal('stock_ticket_rate', 12, 4)->default(1);
                $table->decimal('stock_fee_rate', 8, 6)->default(0.001);
                $table->boolean('stock_trade_enabled')->default(true);
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }
        if (! $schema->hasColumn('game_hall_controls', 'bot_difficulty')) {
            $schema->table('game_hall_controls', function (Blueprint $table) {
                $table->string('bot_difficulty', 16)->default('simple')->after('sort');
            });
        }
        if (! $schema->hasColumn('game_hall_controls', 'stock_symbols')) {
            $schema->table('game_hall_controls', function (Blueprint $table) {
                $table->text('stock_symbols')->nullable()->after('bot_difficulty');
            });
        }
        if (! $schema->hasColumn('game_hall_controls', 'stock_ticket_rate')) {
            $schema->table('game_hall_controls', function (Blueprint $table) {
                $table->decimal('stock_ticket_rate', 12, 4)->default(1)->after('stock_symbols');
            });
        }
        if (! $schema->hasColumn('game_hall_controls', 'stock_fee_rate')) {
            $schema->table('game_hall_controls', function (Blueprint $table) {
                $table->decimal('stock_fee_rate', 8, 6)->default(0.001)->after('stock_ticket_rate');
            });
        }
        if (! $schema->hasColumn('game_hall_controls', 'stock_trade_enabled')) {
            $schema->table('game_hall_controls', function (Blueprint $table) {
                $table->boolean('stock_trade_enabled')->default(true)->after('stock_fee_rate');
            });
        }
        $now = now()->format('Y-m-d H:i:s');
        foreach (static::seedDefaults() as $d) {
            static::query()->firstOrCreate(
                ['game_key' => $d['game_key']],
                $d + ['created_at' => $now, 'updated_at' => $now]
            );
        }
        $stockDefaults = collect(static::seedDefaults())->firstWhere('game_key', 'stock');
        static::query()->where('game_key', 'stock')->where(function ($query) {
            $query->whereNull('stock_symbols')->orWhere('stock_symbols', '');
        })->update(['stock_symbols' => $stockDefaults['stock_symbols']]);
    }
}
