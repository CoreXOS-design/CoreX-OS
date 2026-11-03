<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-527 - one generated video ad ("Ad N" under a feature). `snapshot` is the FROZEN script, voice, brand and render request it was made from: editing a script
 * or the brand later never changes it. Platform record (CoreX's own marketing): no agency scope. Archive = soft delete. Spec .ai/specs/training-videos.md 9.21.
 */
class VideoAd extends Model
{
    use SoftDeletes;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RENDERING = 'rendering';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'feature_key', 'ad_number', 'name', 'snapshot', 'length_seconds', 'formats', 'voice_key', 'status', 'progress_percent', 'stage', 'failure_reason',
        'render_batch_id', 'files', 'post_caption', 'post_hashtags', 'generated_by', 'generated_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'formats' => 'array',
        'files' => 'array',
        'post_hashtags' => 'array',
        'generated_at' => 'datetime',
    ];

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** The next free "Ad N" for a feature: highest number ever used (archived ones count) + 1. */
    public static function nextNumber(string $featureKey): int
    {
        return (int) static::withTrashed()->where('feature_key', $featureKey)->max('ad_number') + 1;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isWorking(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RENDERING], true);
    }

    /** "#a #b" text ready to paste under the caption. */
    public function hashtagLine(): string
    {
        return collect($this->post_hashtags ?? [])->map(fn ($h) => '#' . ltrim((string) $h, '#'))->implode(' ');
    }

    /** The whole post text: caption, blank line, hashtags. */
    public function postText(): string
    {
        return trim(trim((string) $this->post_caption) . "\n\n" . $this->hashtagLine());
    }

    public function headline(): string
    {
        return (string) data_get($this->snapshot, 'question.text', '');
    }

    /** Ready format files, e.g. ['9:16' => ['mp4' => ..., 'poster' => ..., 'seconds' => ...]]. */
    public function readyFormats(): array
    {
        return (array) ($this->files ?? []);
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';

        return $q->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('post_caption', 'like', $like)->orWhere('snapshot', 'like', $like));
    }
}
