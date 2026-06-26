<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('torrents_state', function (Blueprint $table) {
            if (!Schema::hasColumn('torrents_state', 'official_sp_state')) {
                // 1 = \App\Models\Torrent::PROMOTION_NORMAL (no official-group promotion)
                $table->integer('official_sp_state')->default(1)->after('global_sp_state');
            }
        });
    }

    public function down(): void
    {
        Schema::table('torrents_state', function (Blueprint $table) {
            if (Schema::hasColumn('torrents_state', 'official_sp_state')) {
                $table->dropColumn('official_sp_state');
            }
        });
    }
};
