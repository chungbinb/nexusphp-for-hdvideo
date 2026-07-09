<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UserAvatarFrame extends NexusModel
{
    public $timestamps = false;

    protected $table = 'hdvideo_user_avatar_frames';

    protected static bool $schemaEnsured = false;

    protected $fillable = [
        'uid', 'frame_id', 'source', 'status', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'uid' => 'integer',
        'frame_id' => 'integer',
        'status' => 'integer',
    ];

    const STATUS_OWNED = 0;
    const STATUS_WEARING = 1;

    const SOURCE_FREE = 'free';
    const SOURCE_SHOP = 'shop';
    const SOURCE_ADMIN = 'admin';

    public static function ensureSchemaOnly(): void
    {
        if (static::$schemaEnsured) {
            return;
        }
        if (defined('IN_NEXUS') && IN_NEXUS) {
            sql_query("CREATE TABLE IF NOT EXISTS `hdvideo_user_avatar_frames` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `uid` INT UNSIGNED NOT NULL,
                `frame_id` INT UNSIGNED NOT NULL,
                `source` VARCHAR(30) NOT NULL DEFAULT 'shop',
                `status` TINYINT NOT NULL DEFAULT 0,
                `created_at` DATETIME NULL DEFAULT NULL,
                `updated_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `hdvideo_user_avatar_frames_uid_frame_unique` (`uid`, `frame_id`),
                KEY `hdvideo_user_avatar_frames_uid_status_index` (`uid`, `status`),
                KEY `hdvideo_user_avatar_frames_frame_id_index` (`frame_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci") or sqlerr(__FILE__, __LINE__);
            static::$schemaEnsured = true;
            return;
        }

        $schema = Schema::connection((new static)->getConnectionName());
        if (! $schema->hasTable('hdvideo_user_avatar_frames')) {
            $schema->create('hdvideo_user_avatar_frames', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('uid');
                $table->unsignedInteger('frame_id');
                $table->string('source', 30)->default(self::SOURCE_SHOP);
                $table->tinyInteger('status')->default(self::STATUS_OWNED);
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->unique(['uid', 'frame_id'], 'hdvideo_user_avatar_frames_uid_frame_unique');
                $table->index(['uid', 'status'], 'hdvideo_user_avatar_frames_uid_status_index');
                $table->index('frame_id', 'hdvideo_user_avatar_frames_frame_id_index');
            });
        }
        static::$schemaEnsured = true;
    }

    public static function grant(int $uid, int $frameId, string $source): self
    {
        static::ensureSchemaOnly();
        $now = date('Y-m-d H:i:s');
        $owned = static::query()->where('uid', $uid)->where('frame_id', $frameId)->first();
        if ($owned) {
            return $owned;
        }
        return static::query()->create([
            'uid' => $uid,
            'frame_id' => $frameId,
            'source' => $source,
            'status' => self::STATUS_OWNED,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function wear(int $uid, int $frameId): void
    {
        static::ensureSchemaOnly();
        $owned = static::query()->where('uid', $uid)->where('frame_id', $frameId)->first();
        if (! $owned) {
            throw new \RuntimeException('你还没有拥有这个头像挂件。');
        }
        $now = date('Y-m-d H:i:s');
        static::query()->where('uid', $uid)->update(['status' => self::STATUS_OWNED, 'updated_at' => $now]);
        $owned->status = self::STATUS_WEARING;
        $owned->updated_at = $now;
        $owned->save();
    }

    public static function clearWearing(int $uid): void
    {
        static::ensureSchemaOnly();
        static::query()->where('uid', $uid)->update([
            'status' => self::STATUS_OWNED,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function frame(): BelongsTo
    {
        return $this->belongsTo(AvatarFrame::class, 'frame_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uid');
    }
}
