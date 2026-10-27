<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One YouTube training video on CoreX's public Training Videos page.
 *
 * Spec: .ai/specs/training-videos.md §3.2
 *
 * Platform record, not tenant-scoped. Archive = soft delete = off the public page.
 */
class TrainingVideo extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'training_video_group_id',
        'title',
        'description',
        'youtube_url',
        'youtube_video_id',
        'created_by',
        'updated_by',
    ];

    public function group(): BelongsTo
    {
        // withTrashed: the admin list still names the group of a video whose group is archived.
        return $this->belongsTo(TrainingVideoGroup::class, 'training_video_group_id')->withTrashed();
    }

    /**
     * Videos the public may see: not archived, and either ungrouped or in a group
     * that is not archived (spec D10).
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('training_video_group_id')
              ->orWhereHas('group', fn (Builder $g) => $g->whereNull('deleted_at'));
        });
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
