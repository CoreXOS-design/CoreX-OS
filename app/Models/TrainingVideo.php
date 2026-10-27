<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * One training video on CoreX's public Training Videos page — a YouTube link, or a file the
 * owner uploaded and CoreX shrank (spec §8).
 *
 * Spec: .ai/specs/training-videos.md §3.2, §8
 *
 * Platform record, not tenant-scoped. Archive = soft delete = off the public page.
 */
class TrainingVideo extends Model
{
    use SoftDeletes;

    public const SOURCE_YOUTUBE = 'youtube';
    public const SOURCE_UPLOAD  = 'upload';

    public const STATUS_READY      = 'ready';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_FAILED     = 'failed';

    protected $fillable = [
        'training_video_group_id',
        'title',
        'description',
        'source',
        'status',
        'failure_reason',
        'youtube_url',
        'youtube_video_id',
        'file_name',
        'original_size_bytes',
        'size_bytes',
        'duration_seconds',
        'width',
        'height',
        'processing_started_at',
        'pending_upload_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'processing_started_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // U8: archiving an uploaded video takes its files out of the web-served folder, so
        // the old address returns 404; restoring puts them back. Same filesystem, so instant.
        static::deleted(function (TrainingVideo $video) {
            if (! $video->isForceDeleting() && $video->isUpload()) {
                $video->moveFiles(from: 'public', to: 'local');
            }
        });
        static::restored(function (TrainingVideo $video) {
            if ($video->isUpload()) {
                $video->moveFiles(from: 'local', to: 'public');
            }
        });
    }

    public function group(): BelongsTo
    {
        // withTrashed: the admin list still names the group of a video whose group is archived.
        return $this->belongsTo(TrainingVideoGroup::class, 'training_video_group_id')->withTrashed();
    }

    /**
     * Videos the public may see: ready, not archived, and either ungrouped or in a group
     * that is not archived (spec D10). A video still Processing or Failed is never public (§8.4).
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('training_videos.status', self::STATUS_READY)->where(function (Builder $q) {
            $q->whereNull('training_video_group_id')
              ->orWhereHas('group', fn (Builder $g) => $g->whereNull('deleted_at'));
        });
    }

    public function isUpload(): bool
    {
        return $this->source === self::SOURCE_UPLOAD;
    }

    /** Processing for longer than the configured limit: the worker is probably not running (U6). */
    public function isStuck(): bool
    {
        return $this->pending_upload_id !== null
            && $this->status === self::STATUS_PROCESSING
            && $this->processing_started_at !== null
            && $this->processing_started_at->lte(now()->subMinutes((int) config('training-videos.stuck_after_minutes')));
    }

    /** A new file is being swapped in while the old one keeps playing (U9). */
    public function isReplacing(): bool
    {
        return $this->status === self::STATUS_READY && $this->pending_upload_id !== null && $this->failure_reason === null;
    }

    /** The swap failed; the old file is still playing and the new upload is kept for "Try again". */
    public function replacementFailed(): bool
    {
        return $this->status === self::STATUS_READY && $this->pending_upload_id !== null && $this->failure_reason !== null;
    }

    /** Whether "Try again" applies: an upload that failed, or a replacement that failed. */
    public function canRetry(): bool
    {
        return $this->isUpload() && $this->pending_upload_id !== null
            && ($this->status === self::STATUS_FAILED || $this->replacementFailed());
    }

    public function fileUrl(): ?string
    {
        return $this->file_name ? Storage::disk('public')->url($this->relativePath('mp4')) : null;
    }

    public function posterUrl(): ?string
    {
        return $this->file_name ? Storage::disk('public')->url($this->relativePath('jpg')) : null;
    }

    /** "180 MB → 32 MB" (or just the final size when the original size is unknown). */
    public function sizeSummary(): ?string
    {
        if (! $this->size_bytes) {
            return null;
        }

        return $this->original_size_bytes
            ? self::formatBytes($this->original_size_bytes) . ' → ' . self::formatBytes($this->size_bytes)
            : self::formatBytes($this->size_bytes);
    }

    public function durationLabel(): ?string
    {
        if (! $this->duration_seconds) {
            return null;
        }

        return intdiv($this->duration_seconds, 60) . ':' . str_pad((string) ($this->duration_seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    public static function formatBytes(int $bytes): string
    {
        return $bytes >= 1073741824
            ? round($bytes / 1073741824, 1) . ' GB'
            : max(1, (int) round($bytes / 1048576)) . ' MB';
    }

    /** Folder-relative path of one of this video's files: training-videos/{file_name}.{ext}. */
    public function relativePath(string $ext, ?string $fileName = null): string
    {
        return config('training-videos.folder') . '/' . ($fileName ?? $this->file_name) . '.' . $ext;
    }

    /** Delete both files of a stored copy from both folders (used when a file is replaced or purged). */
    public static function deleteFiles(?string $fileName): void
    {
        if (! $fileName) {
            return;
        }
        foreach (['public', 'local'] as $disk) {
            foreach (['mp4', 'jpg'] as $ext) {
                Storage::disk($disk)->delete(config('training-videos.folder') . '/' . $fileName . '.' . $ext);
            }
        }
    }

    /** Move the mp4 + poster between the public and private folders (U8). */
    public function moveFiles(string $from, string $to): void
    {
        if (! $this->file_name) {
            return;
        }
        foreach (['mp4', 'jpg'] as $ext) {
            $source = Storage::disk($from)->path($this->relativePath($ext));
            if (! is_file($source)) {
                continue;
            }
            $target = Storage::disk($to)->path($this->relativePath($ext));
            File::ensureDirectoryExists(dirname($target));
            File::move($source, $target);
        }
    }

    public function thumbnailUrl(): string
    {
        return 'https://i.ytimg.com/vi/' . $this->youtube_video_id . '/mqdefault.jpg';
    }

    public function embedUrl(): string
    {
        return 'https://www.youtube-nocookie.com/embed/' . $this->youtube_video_id . '?autoplay=1&rel=0';
    }

    public function watchUrl(): string
    {
        return 'https://www.youtube.com/watch?v=' . $this->youtube_video_id;
    }

    /**
     * The 11-character video ID from any link YouTube hands out (spec D4), or null.
     *
     * Host is checked exactly — "youtube.com.evil.example" or a lookalike never passes.
     */
    public static function extractYoutubeId(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        $id   = null;

        if ($host === 'youtu.be') {
            $id = explode('/', ltrim($path, '/'))[0] ?? null;
        } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)) {
            if ($path === '/watch') {
                parse_str($parts['query'] ?? '', $query);
                $id = is_string($query['v'] ?? null) ? $query['v'] : null;
            } elseif (preg_match('#^/(?:shorts|embed|live|v)/([^/?]+)#', $path, $m)) {
                $id = $m[1];
            }
        }

        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : null;
    }
}
