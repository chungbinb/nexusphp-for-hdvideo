<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!\Nexus\Database\NexusDB::hasColumn('posts', 'reply_to_post_id')) {
            Schema::table('posts', function (Blueprint $table) {
                $table->unsignedMediumInteger('reply_to_post_id')->default(0)->after('userid')->index();
            });
        }
    }

    public function down(): void
    {
        if (\Nexus\Database\NexusDB::hasColumn('posts', 'reply_to_post_id')) {
            Schema::table('posts', function (Blueprint $table) {
                $table->dropColumn('reply_to_post_id');
            });
        }
    }
};
