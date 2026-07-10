<?php

namespace App\Services;

use App\Models\BonusLogs;
use App\Models\FreeleechPoolSetting;
use Carbon\Carbon;
use Nexus\Database\NexusDB;
use RuntimeException;

class FreeleechPoolService
{
    public const CACHE_KEY = 'hdvideo_freeleech_pool:active_until';

    public static function activeUntil(): int
    {
        try {
            $redis = NexusDB::redis();
            $cached = $redis->get(self::CACHE_KEY);
            if ($cached !== false && $cached !== null) {
                $until = (int)$cached;
                return $until > time() ? $until : 0;
            }
        } catch (\Throwable $e) {
            $redis = null;
        }

        $until = 0;
        try {
            $setting = NexusDB::table('hdvideo_freeleech_pool_settings')->where('id', 1)->first();
            if ($setting && (int)$setting->enabled === 1) {
                $campaign = NexusDB::table('hdvideo_freeleech_pool_campaigns')
                    ->where('status', 'active')
                    ->where('ends_at', '>', now()->format('Y-m-d H:i:s'))
                    ->orderByDesc('id')
                    ->first();
                if ($campaign && $campaign->ends_at) $until = Carbon::parse($campaign->ends_at)->timestamp;
            }
        } catch (\Throwable $e) {
            $until = 0;
        }

        try {
            if ($until > time()) $redis->setex(self::CACHE_KEY, max(1, $until - time()), $until);
            else $redis->setex(self::CACHE_KEY, 60, 0);
        } catch (\Throwable $e) {
        }
        return $until > time() ? $until : 0;
    }

    public static function isActive(): bool
    {
        return self::activeUntil() > time();
    }

    public static function refreshActivationCache(): void
    {
        try { NexusDB::redis()->del(self::CACHE_KEY); } catch (\Throwable $e) {}
        self::activeUntil();
    }

    public static function syncCollectingCampaign(FreeleechPoolSetting $setting): void
    {
        try {
            NexusDB::transaction(function () use ($setting) {
                $campaign = NexusDB::table('hdvideo_freeleech_pool_campaigns')
                    ->where('status', 'collecting')->orderByDesc('id')->lockForUpdate()->first();
                if (!$campaign) return;
                $goal = max(1, round((float)$setting->goal, 1));
                $duration = max(1, (int)$setting->duration_hours);
                $values = ['goal' => $goal, 'duration_hours' => $duration, 'updated_at' => now()->format('Y-m-d H:i:s')];
                if ((int)$setting->enabled === 1 && (float)$campaign->collected >= $goal) {
                    $values['status'] = 'active';
                    $values['activated_at'] = now()->format('Y-m-d H:i:s');
                    $values['ends_at'] = now()->addHours($duration)->format('Y-m-d H:i:s');
                }
                NexusDB::table('hdvideo_freeleech_pool_campaigns')->where('id', $campaign->id)->update($values);
            });
        } catch (\Throwable $e) {
        }
        self::refreshActivationCache();
    }

    public static function status(int $uid = 0): array
    {
        FreeleechPoolSetting::ensureSchema();
        $campaign = self::ensureCurrentCampaign();
        $setting = NexusDB::table('hdvideo_freeleech_pool_settings')->where('id', 1)->first();
        $enabled = $setting && (int)$setting->enabled === 1;
        $goal = (float)($campaign->goal ?? $setting->goal ?? 1000000);
        $collected = (float)($campaign->collected ?? 0);
        $active = $enabled && $campaign && $campaign->status === 'active' && $campaign->ends_at && Carbon::parse($campaign->ends_at)->isFuture();

        $top = [];
        $recent = [];
        $myTotal = 0;
        if ($campaign) {
            $top = NexusDB::table('hdvideo_freeleech_pool_contributions as c')
                ->join('users as u', 'u.id', '=', 'c.uid')
                ->where('c.campaign_id', $campaign->id)
                ->groupBy('c.uid', 'u.username')
                ->orderByDesc(NexusDB::raw('SUM(c.amount)'))
                ->limit(10)
                ->get(['c.uid', 'u.username', NexusDB::raw('SUM(c.amount) as amount')])->map(fn($row) => (array)$row)->all();
            $recent = NexusDB::table('hdvideo_freeleech_pool_contributions as c')
                ->join('users as u', 'u.id', '=', 'c.uid')
                ->where('c.campaign_id', $campaign->id)
                ->orderByDesc('c.id')->limit(12)
                ->get(['u.username', 'c.amount', 'c.created_at'])->map(fn($row) => (array)$row)->all();
            if ($uid > 0) $myTotal = (float)NexusDB::table('hdvideo_freeleech_pool_contributions')->where('campaign_id', $campaign->id)->where('uid', $uid)->sum('amount');
        }

        return [
            'enabled' => $enabled,
            'campaign' => $campaign ? (array)$campaign : null,
            'active' => $active,
            'goal' => $goal,
            'collected' => $collected,
            'remaining' => max(0, $goal - $collected),
            'percent' => $goal > 0 ? min(100, round($collected / $goal * 100, 2)) : 0,
            'active_until' => $active ? Carbon::parse($campaign->ends_at)->timestamp : 0,
            'duration_hours' => (int)($campaign->duration_hours ?? $setting->duration_hours ?? 24),
            'min_contribution' => (float)($setting->min_contribution ?? 100),
            'my_total' => $myTotal,
            'top' => $top,
            'recent' => $recent,
        ];
    }

    public static function contribute(int $uid, float $requested): array
    {
        FreeleechPoolSetting::ensureSchema();
        $requested = round($requested, 1);
        if (!is_finite($requested) || $requested <= 0) throw new RuntimeException('请输入有效的投放魔力。');

        $result = NexusDB::transaction(function () use ($uid, $requested) {
            $setting = NexusDB::table('hdvideo_freeleech_pool_settings')->where('id', 1)->lockForUpdate()->first();
            if (!$setting || (int)$setting->enabled !== 1) throw new RuntimeException('站免池当前未开放。');
            $min = max(0.1, (float)$setting->min_contribution);
            if ($requested < $min) throw new RuntimeException('单次至少投放 ' . number_format($min, 1) . ' 魔力。');

            $campaign = self::ensureCurrentCampaignLocked($setting);
            if (!$campaign || $campaign->status !== 'collecting') throw new RuntimeException('全站 Free 已经开启，当前无需继续投放。');
            $remaining = max(0, (float)$campaign->goal - (float)$campaign->collected);
            if ($remaining <= 0) throw new RuntimeException('本轮站免池已经达标。');
            $accepted = round(min($requested, $remaining), 1);

            $user = NexusDB::table('users')->where('id', $uid)->lockForUpdate()->first(['seedbonus']);
            if (!$user) throw new RuntimeException('用户不存在。');
            $old = (float)$user->seedbonus;
            if ($old < $accepted) throw new RuntimeException('魔力余额不足。');
            $new = round($old - $accepted, 1);
            $now = now();
            NexusDB::table('users')->where('id', $uid)->update(['seedbonus' => NexusDB::raw('seedbonus - ' . $accepted)]);
            NexusDB::table('hdvideo_freeleech_pool_contributions')->insert([
                'campaign_id' => $campaign->id, 'uid' => $uid, 'amount' => $accepted, 'created_at' => $now->format('Y-m-d H:i:s'),
            ]);
            $newCollected = round((float)$campaign->collected + $accepted, 1);
            $activated = $newCollected >= (float)$campaign->goal;
            $campaignValues = ['collected' => $newCollected, 'updated_at' => $now->format('Y-m-d H:i:s')];
            if ($activated) {
                $campaignValues['status'] = 'active';
                $campaignValues['activated_at'] = $now->format('Y-m-d H:i:s');
                $campaignValues['ends_at'] = $now->copy()->addHours((int)$campaign->duration_hours)->format('Y-m-d H:i:s');
            }
            NexusDB::table('hdvideo_freeleech_pool_campaigns')->where('id', $campaign->id)->update($campaignValues);
            NexusDB::table('bonus_logs')->insert([
                'business_type' => BonusLogs::BUSINESS_TYPE_FREELEECH_POOL,
                'uid' => $uid, 'old_total_value' => $old, 'value' => -$accepted, 'new_total_value' => $new,
                'comment' => '[站免池] 投放魔力，轮次 #' . $campaign->id,
                'created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);
            return ['accepted' => $accepted, 'requested' => $requested, 'activated' => $activated, 'wallet' => $new];
        });

        if (function_exists('clear_user_cache')) clear_user_cache($uid);
        self::refreshActivationCache();
        return $result;
    }

    private static function ensureCurrentCampaign(): ?object
    {
        return NexusDB::transaction(function () {
            $setting = NexusDB::table('hdvideo_freeleech_pool_settings')->where('id', 1)->lockForUpdate()->first();
            return self::ensureCurrentCampaignLocked($setting);
        });
    }

    private static function ensureCurrentCampaignLocked(?object $setting): ?object
    {
        $campaign = NexusDB::table('hdvideo_freeleech_pool_campaigns')
            ->whereIn('status', ['collecting', 'active'])->orderByDesc('id')->lockForUpdate()->first();
        if ($campaign && $campaign->status === 'active' && $campaign->ends_at && Carbon::parse($campaign->ends_at)->isPast()) {
            NexusDB::table('hdvideo_freeleech_pool_campaigns')->where('id', $campaign->id)->update(['status' => 'completed', 'updated_at' => now()->format('Y-m-d H:i:s')]);
            $campaign = null;
            self::refreshActivationCache();
        }
        if (!$campaign && $setting && (int)$setting->enabled === 1) {
            $id = NexusDB::table('hdvideo_freeleech_pool_campaigns')->insertGetId([
                'goal' => max(1, (float)$setting->goal), 'collected' => 0,
                'duration_hours' => max(1, (int)$setting->duration_hours), 'status' => 'collecting',
                'activated_at' => null, 'ends_at' => null,
                'created_at' => now()->format('Y-m-d H:i:s'), 'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);
            $campaign = NexusDB::table('hdvideo_freeleech_pool_campaigns')->where('id', $id)->first();
        }
        return $campaign;
    }
}
