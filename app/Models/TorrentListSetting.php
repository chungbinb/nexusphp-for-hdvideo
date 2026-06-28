<?php

namespace App\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TorrentListSetting extends NexusModel
{
    public $timestamps = false;

    protected $table = 'hdvideo_torrent_settings';

    protected $fillable = ['sticky_count'];

    protected $casts = [
        'sticky_count' => 'integer',
    ];

    public static function ensureSchema(): void
    {
        $conn = (new static)->getConnectionName();
        $schema = Schema::connection($conn);
        if (! $schema->hasTable('hdvideo_torrent_settings')) {
            $schema->create('hdvideo_torrent_settings', function (Blueprint $table) {
                $table->integer('id')->unsigned()->primary();
                $table->integer('sticky_count')->default(5);
                $table->dateTime('updated_at')->nullable();
            });
        }
        if (! DB::connection($conn)->table('hdvideo_torrent_settings')->where('id', 1)->exists()) {
            DB::connection($conn)->table('hdvideo_torrent_settings')->insert([
                'id' => 1, 'sticky_count' => 5, 'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);
        }
    }
}
