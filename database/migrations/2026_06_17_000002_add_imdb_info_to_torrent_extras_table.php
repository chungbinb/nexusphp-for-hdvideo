<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('torrent_extras', function (Blueprint $table) {
            if (!Schema::hasColumn('torrent_extras', 'imdb_info')) {
                $table->mediumText('imdb_info')->nullable()->after('pt_gen');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('torrent_extras', function (Blueprint $table) {
            if (Schema::hasColumn('torrent_extras', 'imdb_info')) {
                $table->dropColumn('imdb_info');
            }
        });
    }
};
