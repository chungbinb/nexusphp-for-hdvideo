<?php

namespace App\Services;

use App\Models\BonusLogs;
use App\Models\Setting;
use App\Models\Torrent;
use App\Models\TorrentListSetting;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Nexus\Database\NexusDB;
use RuntimeException;

class TorrentPromotionService
{
    private static bool $schemaEnsured = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaEnsured) return;
        TorrentListSetting::ensureSchema();
        // Works in both Laravel routes and NexusPHP's legacy PHP entry points without facades.
        $schema = (new TorrentListSetting())->getConnection()->getSchemaBuilder();
        if (! $schema->hasTable('hdvideo_torrent_bonus_promotions')) {
            $schema->create('hdvideo_torrent_bonus_promotions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('torrent_id')->unsigned()->unique();
                $table->integer('pin_buyer_id')->unsigned()->nullable();
                $table->dateTime('pin_until')->nullable()->index();
                $table->integer('free_buyer_id')->unsigned()->nullable();
                $table->dateTime('free_until')->nullable()->index();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->index(['pin_until', 'torrent_id']);
            });
        }
        if (! $schema->hasTable('hdvideo_torrent_download_rewards')) {
            $schema->create('hdvideo_torrent_download_rewards', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('torrent_id')->unsigned()->index();
                $table->integer('sponsor_id')->unsigned()->index();
                $table->decimal('amount', 20, 1)->default(0);
                $table->integer('reward_user_count')->unsigned()->default(1);
                $table->dateTime('starts_at')->index();
                $table->dateTime('ends_at')->index();
                $table->string('status', 20)->default('pending')->index();
                $table->decimal('settled_amount', 20, 1)->default(0);
                $table->unsignedBigInteger('total_uploaded')->default(0);
                $table->dateTime('settled_at')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->index(['status', 'ends_at']);
            });
        }
        if (! $schema->hasTable('hdvideo_torrent_download_reward_snapshots')) {
            $schema->create('hdvideo_torrent_download_reward_snapshots', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('reward_id')->index();
                $table->integer('user_id')->unsigned()->index();
                $table->unsignedBigInteger('uploaded_begin')->default(0);
                $table->dateTime('created_at')->nullable();
                $table->unique(['reward_id', 'user_id'], 'hdv_reward_snapshot_unique');
            });
        }
        if (! $schema->hasTable('hdvideo_torrent_download_reward_payouts')) {
            $schema->create('hdvideo_torrent_download_reward_payouts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('reward_id')->index();
                $table->integer('torrent_id')->unsigned()->index();
                $table->integer('user_id')->unsigned()->index();
                $table->unsignedBigInteger('uploaded')->default(0);
                $table->decimal('amount', 20, 1)->default(0);
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->unique(['reward_id', 'user_id'], 'hdv_reward_payout_unique');
            });
        }
        self::$schemaEnsured = true;
    }

    public static function settings(): array
    {
        self::ensureSchema();
        $defaults = [
            'source_tag_id' => 8, 'auto_sticky_days' => 5, 'official_free_hours' => 24, 'normal_free_hours' => 12,
            'bonus_sticky_cost' => 10000.0, 'bonus_sticky_days' => 5,
            'bonus_free_cost' => 10000.0, 'bonus_free_hours' => 12, 'bonus_promotion_enabled' => 1,
        ];
        $row = NexusDB::table('hdvideo_torrent_settings')->where('id', 1)->first();
        return $row ? array_merge($defaults, (array)$row) : $defaults;
    }

    public static function tagStatus(int $torrentId): array
    {
        $settings = self::settings();
        $officialTag = (int)Setting::get('bonus.official_tag');
        $sourceTag = (int)$settings['source_tag_id'];
        $wanted = array_values(array_unique(array_filter([$officialTag, $sourceTag])));
        $found = [];
        if ($torrentId > 0 && $wanted) {
            $found = NexusDB::table('torrent_tags')->where('torrent_id', $torrentId)->whereIn('tag_id', $wanted)->pluck('tag_id')->map(fn ($id) => (int)$id)->all();
        }
        return [
            'official' => $officialTag > 0 && in_array($officialTag, $found, true),
            'source' => $sourceTag > 0 && in_array($sourceTag, $found, true),
            'official_tag_id' => $officialTag,
            'source_tag_id' => $sourceTag,
        ];
    }

    public static function applyNewTorrentDefaults(int $torrentId): void
    {
        if ($torrentId <= 0) return;
        $settings = self::settings();
        $torrent = Torrent::query()->find($torrentId);
        if (! $torrent) return;
        $tags = self::tagStatus($torrentId);
        $updates = [];
        $added = $torrent->added ? Carbon::parse($torrent->added) : now();

        if ($torrent->pos_state === Torrent::POS_STATE_STICKY_NONE && $tags['official']) {
            $updates['pos_state'] = $tags['source'] ? Torrent::POS_STATE_STICKY_FIRST : Torrent::POS_STATE_STICKY_SECOND;
            $updates['pos_state_until'] = $added->copy()->addDays(max(1, (int)$settings['auto_sticky_days']))->toDateTimeString();
        }
        if ((int)$torrent->sp_state === Torrent::PROMOTION_NORMAL) {
            $hours = $tags['official'] ? (int)$settings['official_free_hours'] : (int)$settings['normal_free_hours'];
            if ($hours > 0) {
                $updates['sp_state'] = Torrent::PROMOTION_FREE;
                $updates['promotion_time_type'] = 2;
                $updates['promotion_until'] = $added->copy()->addHours($hours)->toDateTimeString();
            }
        }
        if ($updates) {
            Torrent::query()->where('id', $torrentId)->update($updates);
            if (function_exists('publish_model_event')) publish_model_event(\App\Enums\ModelEventEnum::TORRENT_UPDATED, $torrentId);
        }
    }

    public static function priorityOrderSql(string $alias = 'torrents'): string
    {
        self::ensureSchema();
        $officialTag = (int)Setting::get('bonus.official_tag');
        $officialMagic = $officialTag > 0
            ? "EXISTS (SELECT 1 FROM hdvideo_torrent_bonus_promotions bp WHERE bp.torrent_id = {$alias}.id AND bp.pin_until > NOW()) AND EXISTS (SELECT 1 FROM torrent_tags ot WHERE ot.torrent_id = {$alias}.id AND ot.tag_id = {$officialTag})"
            : '0 = 1';
        return "CASE WHEN ({$officialMagic}) THEN 0 WHEN {$alias}.pos_state = '" . Torrent::POS_STATE_STICKY_FIRST . "' THEN 1 WHEN {$alias}.pos_state = '" . Torrent::POS_STATE_STICKY_SECOND . "' THEN 2 WHEN {$alias}.pos_state = '" . Torrent::POS_STATE_STICKY_THIRD . "' THEN 3 ELSE 4 END";
    }

    public static function status(int $torrentId): array
    {
        self::ensureSchema();
        $settings = self::settings();
        $torrent = Torrent::query()->find($torrentId, ['id', 'name', 'owner', 'sp_state', 'promotion_time_type', 'promotion_until', 'pos_state', 'pos_state_until']);
        if (! $torrent) throw new RuntimeException('种子不存在。');
        $bonus = NexusDB::table('hdvideo_torrent_bonus_promotions')->where('torrent_id', $torrentId)->first();
        return [
            'torrent' => $torrent,
            'tags' => self::tagStatus($torrentId),
            'settings' => $settings,
            'bonus' => $bonus ? (array)$bonus : null,
            'download_rewards' => self::downloadRewards($torrentId),
        ];
    }

    public static function createDownloadReward(int $uid, int $torrentId, float $amount, int $rewardUserCount, int $durationHours): array
    {
        self::ensureSchema();
        $amount = round($amount, 1);
        $rewardUserCount = max(1, min(100, $rewardUserCount));
        $durationHours = max(1, min(720, $durationHours));
        if ($amount < 1) throw new RuntimeException('奖励魔力至少为 1。');
        if ((int)self::settings()['bonus_promotion_enabled'] !== 1) throw new RuntimeException('魔力推广当前未开放。');

        $result = NexusDB::transaction(function () use ($uid, $torrentId, $amount, $rewardUserCount, $durationHours) {
            $user = NexusDB::table('users')->where('id', $uid)->lockForUpdate()->first(['id', 'seedbonus']);
            $torrent = NexusDB::table('torrents')->where('id', $torrentId)->first(['id', 'name']);
            if (! $user) throw new RuntimeException('用户不存在。');
            if (! $torrent) throw new RuntimeException('种子不存在。');
            if ((float)$user->seedbonus < $amount) throw new RuntimeException('魔力余额不足。');

            $now = now();
            $endsAt = $now->copy()->addHours($durationHours);
            NexusDB::table('users')->where('id', $uid)->update(['seedbonus' => NexusDB::raw('seedbonus - ' . $amount)]);
            $rewardId = NexusDB::table('hdvideo_torrent_download_rewards')->insertGetId([
                'torrent_id' => $torrentId,
                'sponsor_id' => $uid,
                'amount' => $amount,
                'reward_user_count' => $rewardUserCount,
                'starts_at' => $now->toDateTimeString(),
                'ends_at' => $endsAt->toDateTimeString(),
                'status' => 'pending',
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ]);

            $snapshotRows = NexusDB::table('snatched')
                ->where('torrentid', $torrentId)
                ->where('uploaded', '>', 0)
                ->get(['userid', 'uploaded'])
                ->map(fn ($row) => [
                    'reward_id' => $rewardId,
                    'user_id' => (int)$row->userid,
                    'uploaded_begin' => (int)$row->uploaded,
                    'created_at' => $now->toDateTimeString(),
                ])
                ->all();
            if ($snapshotRows) {
                foreach (array_chunk($snapshotRows, 1000) as $chunk) {
                    NexusDB::table('hdvideo_torrent_download_reward_snapshots')->insert($chunk);
                }
            }

            NexusDB::table('bonus_logs')->insert(self::bonusLog(
                $uid,
                (float)$user->seedbonus,
                -$amount,
                (float)$user->seedbonus - $amount,
                "[种子下载奖励池] #{$torrentId} 总额 {$amount} 魔力，奖励 {$rewardUserCount} 人，{$durationHours} 小时后结算",
                BonusLogs::BUSINESS_TYPE_REWARD_TORRENT,
                $now
            ));

            return ['id' => $rewardId, 'amount' => $amount, 'count' => $rewardUserCount, 'hours' => $durationHours];
        });

        if (function_exists('clear_user_cache')) clear_user_cache($uid);
        return $result;
    }

    public static function settleDueDownloadRewards(int $limit = 20): int
    {
        self::ensureSchema();
        $now = now();
        $ids = NexusDB::table('hdvideo_torrent_download_rewards')
            ->where('status', 'pending')
            ->where('ends_at', '<=', $now->toDateTimeString())
            ->orderBy('ends_at')
            ->limit($limit)
            ->pluck('id')
            ->all();
        $settled = 0;
        foreach ($ids as $id) {
            try {
                self::settleDownloadReward((int)$id);
                $settled++;
            } catch (\Throwable $e) {
                do_log("settle download reward failed, id: {$id}, error: " . $e->getMessage(), 'error');
            }
        }
        return $settled;
    }

    public static function settleDownloadReward(int $rewardId): void
    {
        self::ensureSchema();
        NexusDB::transaction(function () use ($rewardId) {
            $now = now();
            $reward = NexusDB::table('hdvideo_torrent_download_rewards')->where('id', $rewardId)->lockForUpdate()->first();
            if (! $reward || $reward->status !== 'pending') return;
            $torrent = NexusDB::table('torrents')->where('id', $reward->torrent_id)->first(['id', 'owner']);
            if (! $torrent) {
                self::refundDownloadReward($reward, '种子不存在，奖励池退回');
                return;
            }

            $rows = NexusDB::table('snatched as s')
                ->leftJoin('hdvideo_torrent_download_reward_snapshots as rs', function ($join) use ($rewardId) {
                    $join->on('rs.user_id', '=', 's.userid')->where('rs.reward_id', '=', $rewardId);
                })
                ->where('s.torrentid', (int)$reward->torrent_id)
                ->where('s.userid', '<>', (int)$reward->sponsor_id)
                ->where('s.userid', '<>', (int)$torrent->owner)
                ->selectRaw('s.userid, CASE WHEN s.uploaded > COALESCE(rs.uploaded_begin, 0) THEN s.uploaded - COALESCE(rs.uploaded_begin, 0) ELSE 0 END as uploaded_delta')
                ->orderByDesc('uploaded_delta')
                ->limit((int)$reward->reward_user_count)
                ->get()
                ->filter(fn ($row) => (int)$row->uploaded_delta > 0)
                ->values();

            $totalUploaded = (int)$rows->sum('uploaded_delta');
            if ($totalUploaded <= 0 || $rows->isEmpty()) {
                self::refundDownloadReward($reward, '没有符合条件的上传贡献，奖励池退回');
                return;
            }

            $remaining = (float)$reward->amount;
            $payoutRows = [];
            $count = $rows->count();
            foreach ($rows as $index => $row) {
                $amount = $index === $count - 1
                    ? round($remaining, 1)
                    : floor(((float)$reward->amount * (int)$row->uploaded_delta / $totalUploaded) * 10) / 10;
                if ($amount <= 0) continue;
                $user = NexusDB::table('users')->where('id', (int)$row->userid)->lockForUpdate()->first(['id', 'seedbonus']);
                if (! $user) continue;
                NexusDB::table('users')->where('id', (int)$row->userid)->update(['seedbonus' => NexusDB::raw('seedbonus + ' . $amount)]);
                NexusDB::table('bonus_logs')->insert(self::bonusLog(
                    (int)$row->userid,
                    (float)$user->seedbonus,
                    $amount,
                    (float)$user->seedbonus + $amount,
                    "[种子下载奖励池] #{$reward->torrent_id} 上传 " . (int)$row->uploaded_delta . " 字节，瓜分奖励",
                    BonusLogs::BUSINESS_TYPE_RECEIVE_REWARD,
                    $now
                ));
                $payoutRows[] = [
                    'reward_id' => $rewardId,
                    'torrent_id' => (int)$reward->torrent_id,
                    'user_id' => (int)$row->userid,
                    'uploaded' => (int)$row->uploaded_delta,
                    'amount' => $amount,
                    'created_at' => $now->toDateTimeString(),
                    'updated_at' => $now->toDateTimeString(),
                ];
                $remaining = round($remaining - $amount, 1);
            }

            if (!$payoutRows) {
                self::refundDownloadReward($reward, '没有可发放用户，奖励池退回');
                return;
            }

            NexusDB::table('hdvideo_torrent_download_reward_payouts')->insert($payoutRows);
            NexusDB::table('hdvideo_torrent_download_rewards')->where('id', $rewardId)->update([
                'status' => 'settled',
                'settled_amount' => array_sum(array_column($payoutRows, 'amount')),
                'total_uploaded' => $totalUploaded,
                'settled_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ]);
        });
    }

    private static function refundDownloadReward(object $reward, string $reason): void
    {
        $now = now();
        $user = NexusDB::table('users')->where('id', (int)$reward->sponsor_id)->lockForUpdate()->first(['id', 'seedbonus']);
        if ($user) {
            NexusDB::table('users')->where('id', (int)$reward->sponsor_id)->update(['seedbonus' => NexusDB::raw('seedbonus + ' . (float)$reward->amount)]);
            NexusDB::table('bonus_logs')->insert(self::bonusLog(
                (int)$reward->sponsor_id,
                (float)$user->seedbonus,
                (float)$reward->amount,
                (float)$user->seedbonus + (float)$reward->amount,
                "[种子下载奖励池] #{$reward->torrent_id} {$reason}",
                BonusLogs::BUSINESS_TYPE_RECEIVE_REWARD,
                $now
            ));
        }
        NexusDB::table('hdvideo_torrent_download_rewards')->where('id', (int)$reward->id)->update([
            'status' => 'refunded',
            'settled_at' => $now->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ]);
    }

    private static function downloadRewards(int $torrentId): array
    {
        self::ensureSchema();
        return NexusDB::table('hdvideo_torrent_download_rewards')
            ->where('torrent_id', $torrentId)
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn ($row) => (array)$row)
            ->all();
    }

    public static function purchase(int $uid, int $torrentId, bool $buyPin, bool $buyFree, ?int $durationHours = null): array
    {
        if (! $buyPin && ! $buyFree) throw new RuntimeException('请至少选择一项推广功能。');
        $settings = self::settings();
        if ((int)$settings['bonus_promotion_enabled'] !== 1) throw new RuntimeException('魔力推广当前未开放。');
        $pinBaseHours = max(1, (int)$settings['bonus_sticky_days'] * 24);
        $freeBaseHours = max(1, (int)$settings['bonus_free_hours']);
        $hours = $durationHours !== null ? max(1, min(720, $durationHours)) : max(1, $freeBaseHours);
        $pinCost = $buyPin ? round(max(0, (float)$settings['bonus_sticky_cost']) * $hours / $pinBaseHours, 1) : 0;
        $freeCost = $buyFree ? round(max(0, (float)$settings['bonus_free_cost']) * $hours / $freeBaseHours, 1) : 0;
        $total = $pinCost + $freeCost;
        if ($total <= 0) throw new RuntimeException('推广价格配置无效，请联系管理员。');

        $result = NexusDB::transaction(function () use ($uid, $torrentId, $buyPin, $buyFree, $settings, $hours, $pinCost, $freeCost, $total) {
            $user = NexusDB::table('users')->where('id', $uid)->lockForUpdate()->first(['id', 'seedbonus']);
            $torrent = NexusDB::table('torrents')->where('id', $torrentId)->lockForUpdate()->first(['id', 'name', 'sp_state', 'promotion_time_type', 'promotion_until', 'pos_state', 'pos_state_until']);
            if (! $user) throw new RuntimeException('用户不存在。');
            if (! $torrent) throw new RuntimeException('种子不存在。');
            if ((float)$user->seedbonus < $total) throw new RuntimeException('魔力余额不足。');
            if ($buyFree && (int)$torrent->promotion_time_type === 1 && (int)$torrent->sp_state !== Torrent::PROMOTION_NORMAL) {
                throw new RuntimeException('该种子当前为永久促销，不能使用魔力覆盖。');
            }
            $now = now();
            $record = NexusDB::table('hdvideo_torrent_bonus_promotions')->where('torrent_id', $torrentId)->lockForUpdate()->first();
            $recordValues = ['updated_at' => $now->toDateTimeString()];
            $torrentValues = [];
            $tags = self::tagStatus($torrentId);

            if ($buyPin) {
                $base = $record && $record->pin_until && Carbon::parse($record->pin_until)->isFuture() ? Carbon::parse($record->pin_until) : $now->copy();
                $pinUntil = $base->addHours($hours);
                $desired = $tags['official'] ? Torrent::POS_STATE_STICKY_FIRST : Torrent::POS_STATE_STICKY_THIRD;
                $currentRank = self::positionRank((string)$torrent->pos_state);
                $desiredRank = self::positionRank($desired);
                $newState = $currentRank <= $desiredRank ? (string)$torrent->pos_state : $desired;
                $torrentValues['pos_state'] = $newState;
                if ((string)$torrent->pos_state !== Torrent::POS_STATE_STICKY_NONE && empty($torrent->pos_state_until)) {
                    $torrentValues['pos_state_until'] = null;
                } else {
                    $currentUntil = $torrent->pos_state_until ? Carbon::parse($torrent->pos_state_until) : $now->copy();
                    $torrentValues['pos_state_until'] = ($currentUntil->gt($pinUntil) ? $currentUntil : $pinUntil)->toDateTimeString();
                }
                $recordValues['pin_buyer_id'] = $uid;
                $recordValues['pin_until'] = $pinUntil->toDateTimeString();
            }

            if ($buyFree) {
                $candidates = [$now->copy()];
                if ($record && $record->free_until && Carbon::parse($record->free_until)->isFuture()) $candidates[] = Carbon::parse($record->free_until);
                if ((int)$torrent->sp_state === Torrent::PROMOTION_FREE && $torrent->promotion_until && Carbon::parse($torrent->promotion_until)->isFuture()) $candidates[] = Carbon::parse($torrent->promotion_until);
                $base = collect($candidates)->sortByDesc(fn (Carbon $date) => $date->timestamp)->first();
                $freeUntil = $base->copy()->addHours($hours);
                $torrentValues['sp_state'] = Torrent::PROMOTION_FREE;
                $torrentValues['promotion_time_type'] = 2;
                $torrentValues['promotion_until'] = $freeUntil->toDateTimeString();
                $recordValues['free_buyer_id'] = $uid;
                $recordValues['free_until'] = $freeUntil->toDateTimeString();
            }

            NexusDB::table('users')->where('id', $uid)->update(['seedbonus' => NexusDB::raw('seedbonus - ' . $total)]);
            NexusDB::table('torrents')->where('id', $torrentId)->update($torrentValues);
            if ($record) NexusDB::table('hdvideo_torrent_bonus_promotions')->where('id', $record->id)->update($recordValues);
            else NexusDB::table('hdvideo_torrent_bonus_promotions')->insert(array_merge($recordValues, ['torrent_id' => $torrentId, 'created_at' => $now->toDateTimeString()]));

            $running = (float)$user->seedbonus;
            if ($buyPin) {
                NexusDB::table('bonus_logs')->insert(self::bonusLog($uid, $running, -$pinCost, $running - $pinCost, "[种子推广] #{$torrentId} 魔力置顶 {$hours}小时", BonusLogs::BUSINESS_TYPE_STICKY_PROMOTION, $now));
                $running -= $pinCost;
            }
            if ($buyFree) {
                NexusDB::table('bonus_logs')->insert(self::bonusLog($uid, $running, -$freeCost, $running - $freeCost, "[种子推广] #{$torrentId} Free {$hours}小时", BonusLogs::BUSINESS_TYPE_TORRENT_FREE_PROMOTION, $now));
                $running -= $freeCost;
            }
            return ['spent' => $total, 'wallet' => $running, 'pin' => $buyPin, 'free' => $buyFree, 'hours' => $hours];
        });
        if (function_exists('clear_user_cache')) clear_user_cache($uid);
        if (function_exists('publish_model_event')) publish_model_event(\App\Enums\ModelEventEnum::TORRENT_UPDATED, $torrentId);
        return $result;
    }

    private static function positionRank(string $state): int
    {
        return match ($state) {
            Torrent::POS_STATE_STICKY_FIRST => 1,
            Torrent::POS_STATE_STICKY_SECOND => 2,
            Torrent::POS_STATE_STICKY_THIRD => 3,
            default => 4,
        };
    }

    private static function bonusLog(int $uid, float $old, float $delta, float $new, string $comment, int $type, Carbon $now): array
    {
        return ['business_type' => $type, 'uid' => $uid, 'old_total_value' => $old, 'value' => $delta, 'new_total_value' => $new, 'comment' => $comment, 'created_at' => $now->toDateTimeString(), 'updated_at' => $now->toDateTimeString()];
    }
}
