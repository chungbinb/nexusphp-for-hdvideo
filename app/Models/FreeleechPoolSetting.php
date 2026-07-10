<?php

namespace App\Models;

use App\Services\FreeleechPoolService;
use Nexus\Database\NexusDB;

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
        static $done = false;
        if ($done) return;
        if (!NexusDB::hasTable('hdvideo_freeleech_pool_settings')) {
            NexusDB::statement("CREATE TABLE `hdvideo_freeleech_pool_settings` (
                `id` int unsigned NOT NULL, `enabled` tinyint(1) NOT NULL DEFAULT 1,
                `goal` decimal(20,1) NOT NULL DEFAULT 1000000.0, `duration_hours` int unsigned NOT NULL DEFAULT 24,
                `min_contribution` decimal(20,1) NOT NULL DEFAULT 100.0, `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!NexusDB::hasTable('hdvideo_freeleech_pool_campaigns')) {
            NexusDB::statement("CREATE TABLE `hdvideo_freeleech_pool_campaigns` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT, `goal` decimal(20,1) NOT NULL,
                `collected` decimal(20,1) NOT NULL DEFAULT 0.0, `duration_hours` int unsigned NOT NULL,
                `status` varchar(16) NOT NULL DEFAULT 'collecting', `activated_at` datetime DEFAULT NULL,
                `ends_at` datetime DEFAULT NULL, `created_at` datetime NOT NULL, `updated_at` datetime NOT NULL,
                PRIMARY KEY (`id`), KEY `idx_status` (`status`), KEY `idx_ends_at` (`ends_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!NexusDB::hasTable('hdvideo_freeleech_pool_contributions')) {
            NexusDB::statement("CREATE TABLE `hdvideo_freeleech_pool_contributions` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT, `campaign_id` bigint unsigned NOT NULL,
                `uid` int unsigned NOT NULL, `amount` decimal(20,1) NOT NULL, `created_at` datetime NOT NULL,
                PRIMARY KEY (`id`), KEY `idx_campaign` (`campaign_id`), KEY `idx_uid` (`uid`),
                KEY `idx_created_at` (`created_at`), KEY `idx_campaign_uid` (`campaign_id`,`uid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        if (!NexusDB::table('hdvideo_freeleech_pool_settings')->where('id', 1)->exists()) {
            NexusDB::table('hdvideo_freeleech_pool_settings')->insert([
                'id' => 1,
                'enabled' => 1,
                'goal' => 1000000,
                'duration_hours' => 24,
                'min_contribution' => 100,
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);
        }
        $done = true;
    }
}
