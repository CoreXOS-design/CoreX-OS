<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-527 - one "Post now" / "Schedule" of a video ad to the CoreX Facebook page (spec .ai/specs/training-videos.md 9.22). Platform record: no agency scope.
 * `dry_run` = the publish call only logged what it would have sent (external publishing is off on this server).
 */
class VideoAdPost extends Model
{
    use SoftDeletes;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'video_ad_id', 'social_account_id', 'format', 'message', 'status', 'scheduled_for', 'published_at', 'platform_post_id', 'dry_run', 'failure_reason', 'created_by',
    ];

    protected $casts = [
        // UTC wall clock in the column, whatever the app timezone is (same cast as the Ad Manager's scheduled posts).
        'scheduled_for' => \App\Casts\UtcDateTime::class,
        'published_at' => 'datetime',
        'dry_run' => 'boolean',
    ];

    public function ad(): BelongsTo
    {
        return $this->belongsTo(VideoAd::class, 'video_ad_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }
}
