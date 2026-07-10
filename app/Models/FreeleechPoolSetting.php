<?php

namespace App\Models;

use App\Services\FreeleechPoolService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FreeleechPoolSetting extends NexusModel
{
    public $timestamps = false;

    protected $table = 'hdvideo_freeleech_pool_settings';

    protected $fillable = ['enabled', 'goal', 'duration_hours', 'min_contribution', 'updated_at'];

    protected $casts = [
        'enabled' => 'boolean',
        'goal' => 'float',
        'duration_hours' => 'integer',
        'min_contribution' => 'float',
    ];

    protected static function booted(): void
    {
        static::saving(function (FreeleechPoolSetting $setting) {
            $setting->updated_at = now()->format('Y-m-d H:i:s');
        });
        static::saved(function (FreeleechPoolSetting $setting) {
            FreeleechPoolService::syncCollectingCampaign($setting);
        });
    }

    public static function ensureSchema(): void
    {
        $conn = (new static)->getConnectionName();
        $schema = Schema::connection($conn);

        if (!$schema->hasTable('hdvideo_freeleech_pool_settings')) {
            $schema->create('hdvideo_freeleech_pool_settings', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->boolean('enabled')->default(true);
                $table->decimal('goal', 20, 1)->default(1000000);
                $table->unsignedInteger('duration_hours')->default(24);
                $table->decimal('min_contribution', 20, 1)->default(100);
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (!$schema->hasTable('hdvideo_freeleech_pool_campaigns')) {
            $schema->create('hdvideo_freeleech_pool_campaigns', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->decimal('goal', 20, 1);
                $table->decimal('collected', 20, 1)->default(0);
                $table->unsignedInteger('duration_hours');
                $table->string('status', 16)->default('collecting')->index();
                $table->dateTime('activated_at')->nullable();
                $table->dateTime('ends_at')->nullable()->index();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
            });
        }

        if (!$schema->hasTable('hdvideo_freeleech_pool_contributions')) {
            $schema->create('hdvideo_freeleech_pool_contributions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('campaign_id')->index();
                $table->unsignedInteger('uid')->index();
                $table->decimal('amount', 20, 1);
                $table->dateTime('created_at')->index();
                $table->index(['campaign_id', 'uid']);
            });
        }

        if (!DB::connection($conn)->table('hdvideo_freeleech_pool_settings')->where('id', 1)->exists()) {
            DB::connection($conn)->table('hdvideo_freeleech_pool_settings')->insert([
                'id' => 1,
                'enabled' => 1,
                'goal' => 1000000,
                'duration_hours' => 24,
                'min_contribution' => 100,
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);
        }
    }
}
