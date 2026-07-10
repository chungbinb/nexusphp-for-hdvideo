<?php

namespace App\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class GameHallControl extends NexusModel
{
    public $timestamps = false;

    protected $table = 'game_hall_controls';

    protected $fillable = ['game_key', 'name', 'is_open', 'min_class', 'sort', 'bot_difficulty'];

    protected $casts = [
        'is_open' => 'boolean',
        'min_class' => 'integer',
        'sort' => 'integer',
        'bot_difficulty' => 'string',
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
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }
        if (! $schema->hasColumn('game_hall_controls', 'bot_difficulty')) {
            $schema->table('game_hall_controls', function (Blueprint $table) {
                $table->string('bot_difficulty', 16)->default('simple')->after('sort');
            });
        }
        $now = now()->format('Y-m-d H:i:s');
        foreach (static::seedDefaults() as $d) {
            static::query()->firstOrCreate(
                ['game_key' => $d['game_key']],
                $d + ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }
}
