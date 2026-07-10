<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        \App\Services\TorrentPromotionService::ensureSchema();
    }

    public function down(): void
    {
        Schema::dropIfExists('hdvideo_torrent_bonus_promotions');
    }
};
