<?php

namespace App\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TorrentListSetting extends NexusModel
{
    public $timestamps = false;

    protected $table = 'hdvideo_torrent_settings';

    protected $fillable = [
        'sticky_count', 'source_tag_id', 'auto_sticky_days', 'official_free_hours', 'normal_free_hours',
        'bonus_sticky_cost', 'bonus_sticky_days', 'bonus_free_cost', 'bonus_free_hours', 'bonus_promotion_enabled',
    ];

    protected $casts = [
        'sticky_count' => 'integer',
        'source_tag_id' => 'integer',
        'auto_sticky_days' => 'integer',
        'official_free_hours' => 'integer',
        'normal_free_hours' => 'integer',
        'bonus_sticky_cost' => 'float',
        'bonus_sticky_days' => 'integer',
        'bonus_free_cost' => 'float',
        'bonus_free_hours' => 'integer',
        'bonus_promotion_enabled' => 'boolean',
    ];

    public static function ensureSchema(): void
    {
        $conn = (new static)->getConnectionName();
        $schema = Schema::connection($conn);
        if (! $schema->hasTable('hdvideo_torrent_settings')) {
            $schema->create('hdvideo_torrent_settings', function (Blueprint $table) {
                $table->integer('id')->unsigned()->primary();
                $table->integer('sticky_count')->default(5);
                $table->integer('source_tag_id')->unsigned()->default(8);
                $table->integer('auto_sticky_days')->unsigned()->default(5);
                $table->integer('official_free_hours')->unsigned()->default(24);
                $table->integer('normal_free_hours')->unsigned()->default(12);
                $table->decimal('bonus_sticky_cost', 12, 1)->default(10000);
                $table->integer('bonus_sticky_days')->unsigned()->default(5);
                $table->decimal('bonus_free_cost', 12, 1)->default(10000);
                $table->integer('bonus_free_hours')->unsigned()->default(12);
                $table->boolean('bonus_promotion_enabled')->default(true);
                $table->dateTime('updated_at')->nullable();
            });
        } else {
            $columns = [
                'source_tag_id' => fn (Blueprint $table) => $table->integer('source_tag_id')->unsigned()->default(8),
                'auto_sticky_days' => fn (Blueprint $table) => $table->integer('auto_sticky_days')->unsigned()->default(5),
                'official_free_hours' => fn (Blueprint $table) => $table->integer('official_free_hours')->unsigned()->default(24),
                'normal_free_hours' => fn (Blueprint $table) => $table->integer('normal_free_hours')->unsigned()->default(12),
                'bonus_sticky_cost' => fn (Blueprint $table) => $table->decimal('bonus_sticky_cost', 12, 1)->default(10000),
                'bonus_sticky_days' => fn (Blueprint $table) => $table->integer('bonus_sticky_days')->unsigned()->default(5),
                'bonus_free_cost' => fn (Blueprint $table) => $table->decimal('bonus_free_cost', 12, 1)->default(10000),
                'bonus_free_hours' => fn (Blueprint $table) => $table->integer('bonus_free_hours')->unsigned()->default(12),
                'bonus_promotion_enabled' => fn (Blueprint $table) => $table->boolean('bonus_promotion_enabled')->default(true),
            ];
            foreach ($columns as $name => $definition) {
                if (! $schema->hasColumn('hdvideo_torrent_settings', $name)) {
                    $schema->table('hdvideo_torrent_settings', $definition);
                }
            }
        }
        if (! DB::connection($conn)->table('hdvideo_torrent_settings')->where('id', 1)->exists()) {
            DB::connection($conn)->table('hdvideo_torrent_settings')->insert([
                'id' => 1, 'sticky_count' => 5, 'source_tag_id' => 8, 'auto_sticky_days' => 5,
                'official_free_hours' => 24, 'normal_free_hours' => 12,
                'bonus_sticky_cost' => 10000, 'bonus_sticky_days' => 5,
                'bonus_free_cost' => 10000, 'bonus_free_hours' => 12,
                'bonus_promotion_enabled' => 1, 'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);
        }
    }
}
