<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        \App\Models\FreeleechPoolSetting::ensureSchema();
    }

    public function down(): void
    {
        Schema::dropIfExists('hdvideo_freeleech_pool_contributions');
        Schema::dropIfExists('hdvideo_freeleech_pool_campaigns');
        Schema::dropIfExists('hdvideo_freeleech_pool_settings');
    }
};
