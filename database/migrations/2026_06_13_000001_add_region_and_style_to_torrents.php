<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $styles = [
        '短片', '喜剧', '动作', '科幻', '惊悚',
        '剧情', '爱情', '恐怖', '犯罪', '悬疑',
    ];

    private array $regions = [
        '中国大陆', '美国', '韩国', '英国', '泰国',
        '中国港台', '日本', '法国', '德国', '意大利',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('torrent_regions')) {
            Schema::create('torrent_regions', function (Blueprint $table) {
                $table->smallIncrements('id');
                $table->string('name', 64)->unique();
                $table->integer('sort_index')->default(0)->index();
                $table->boolean('enabled')->default(true)->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('torrent_styles')) {
            Schema::create('torrent_styles', function (Blueprint $table) {
                $table->smallIncrements('id');
                $table->string('name', 64)->unique();
                $table->integer('sort_index')->default(0)->index();
                $table->boolean('enabled')->default(true)->index();
                $table->timestamps();
            });
        }

        if (!\Nexus\Database\NexusDB::hasColumn('torrents', 'region')) {
            Schema::table('torrents', function (Blueprint $table) {
                $table->unsignedSmallInteger('region')->default(0)->after('category')->index();
            });
        }

        if (!Schema::hasTable('torrent_style_torrent')) {
            Schema::create('torrent_style_torrent', function (Blueprint $table) {
                $table->id();
                $table->unsignedMediumInteger('torrent_id')->index();
                $table->unsignedSmallInteger('style_id')->index();
                $table->timestamps();
                $table->unique(['torrent_id', 'style_id']);
            });
        }

        $this->seedOptions('torrent_styles', $this->styles);
        $this->seedOptions('torrent_regions', $this->regions);
    }

    public function down(): void
    {
        Schema::dropIfExists('torrent_style_torrent');
        if (\Nexus\Database\NexusDB::hasColumn('torrents', 'region')) {
            Schema::table('torrents', function (Blueprint $table) {
                $table->dropColumn('region');
            });
        }
        Schema::dropIfExists('torrent_styles');
        Schema::dropIfExists('torrent_regions');
    }

    private function seedOptions(string $table, array $names): void
    {
        $now = now();
        foreach ($names as $index => $name) {
            DB::table($table)->updateOrInsert(
                ['name' => $name],
                [
                    'sort_index' => count($names) - $index,
                    'enabled' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
};
