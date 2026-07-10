<?php

namespace App\Services;

use App\Models\BonusLogs;
use App\Models\Setting;
use App\Models\Torrent;
use App\Models\TorrentListSetting;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nexus\Database\NexusDB;
use RuntimeException;

class TorrentPromotionService
{
    private static bool $schemaEnsured = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaEnsured) return;
        TorrentListSetting::ensureSchema();
        // Works in both Laravel routes and NexusPHP's legacy PHP entry points.
        $schema = DB::connection((new TorrentListSetting())->getConnectionName())->getSchemaBuilder();
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
        ];
    }

    public static function purchase(int $uid, int $torrentId, bool $buyPin, bool $buyFree): array
    {
        if (! $buyPin && ! $buyFree) throw new RuntimeException('请至少选择一项推广功能。');
        $settings = self::settings();
        if ((int)$settings['bonus_promotion_enabled'] !== 1) throw new RuntimeException('魔力推广当前未开放。');
        $pinCost = $buyPin ? max(0, (float)$settings['bonus_sticky_cost']) : 0;
        $freeCost = $buyFree ? max(0, (float)$settings['bonus_free_cost']) : 0;
        $total = $pinCost + $freeCost;
        if ($total <= 0) throw new RuntimeException('推广价格配置无效，请联系管理员。');

        $result = NexusDB::transaction(function () use ($uid, $torrentId, $buyPin, $buyFree, $settings, $pinCost, $freeCost, $total) {
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
                $pinUntil = $base->addDays(max(1, (int)$settings['bonus_sticky_days']));
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
                $freeUntil = $base->copy()->addHours(max(1, (int)$settings['bonus_free_hours']));
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
                NexusDB::table('bonus_logs')->insert(self::bonusLog($uid, $running, -$pinCost, $running - $pinCost, "[种子推广] #{$torrentId} 魔力置顶", BonusLogs::BUSINESS_TYPE_STICKY_PROMOTION, $now));
                $running -= $pinCost;
            }
            if ($buyFree) {
                NexusDB::table('bonus_logs')->insert(self::bonusLog($uid, $running, -$freeCost, $running - $freeCost, "[种子推广] #{$torrentId} Free", BonusLogs::BUSINESS_TYPE_TORRENT_FREE_PROMOTION, $now));
                $running -= $freeCost;
            }
            return ['spent' => $total, 'wallet' => $running, 'pin' => $buyPin, 'free' => $buyFree];
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
