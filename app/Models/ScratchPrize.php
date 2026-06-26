<?php

namespace App\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ScratchPrize extends NexusModel
{
    public $timestamps = false;

    protected $table = 'hdvideo_scratch_prizes';

    protected $fillable = ['multiplier', 'weight', 'is_enabled', 'sort'];

    protected $casts = [
        'multiplier' => 'integer',
        'weight' => 'integer',
        'is_enabled' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * Default prize roster, kept in sync with public/games/scratch SC_PRIZES_DEFAULT.
     */
    public static function seedDefaults(): array
    {
        return [
            ['multiplier' => 0, 'weight' => 600, 'sort' => 1],
            ['multiplier' => 1, 'weight' => 220, 'sort' => 2],
            ['multiplier' => 2, 'weight' => 100, 'sort' => 3],
            ['multiplier' => 3, 'weight' => 50, 'sort' => 4],
            ['multiplier' => 5, 'weight' => 20, 'sort' => 5],
            ['multiplier' => 10, 'weight' => 8, 'sort' => 6],
            ['multiplier' => 88, 'weight' => 2, 'sort' => 7],
        ];
    }

    /**
     * The prize table is self-managed (no migration). Create + seed it on demand so
     * the backend works even before the刮刮乐 game page has run.
     */
    public static function ensureSchema(): void
    {
        $schema = Schema::connection((new static)->getConnectionName());
        if (! $schema->hasTable('hdvideo_scratch_prizes')) {
            $schema->create('hdvideo_scratch_prizes', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('multiplier')->unsigned()->default(0);
                $table->integer('weight')->unsigned()->default(1);
                $table->boolean('is_enabled')->default(true);
                $table->integer('sort')->default(0);
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }
        if (static::query()->count() === 0) {
            $now = now()->format('Y-m-d H:i:s');
            foreach (static::seedDefaults() as $d) {
                static::query()->create($d + ['is_enabled' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public static function enabledWeightTotal(): int
    {
        return (int) static::query()->where('is_enabled', 1)->sum('weight');
    }
}
