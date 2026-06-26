<?php

namespace App\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class GameHallControl extends NexusModel
{
    public $timestamps = false;

    protected $table = 'game_hall_controls';

    protected $fillable = ['game_key', 'name', 'is_open', 'min_class', 'sort'];

    protected $casts = [
        'is_open' => 'boolean',
        'min_class' => 'integer',
        'sort' => 'integer',
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
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
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
