<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * An article authored by an agent, shown on their public website profile.
 * Self-service content (My Portal → Profile). Only published articles reach
 * the public website API.
 *
 * Spec: .ai/specs/testimonials.md (agent linkage).
 */
class AgentArticle extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'user_id',
        'title',
        'slug',
        'excerpt',
        'cover_image_path',
        'body',
        'link_url',
        'tags',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Word count of the article body (tags stripped). */
    public function wordCount(): int
    {
        return str_word_count(strip_tags((string) $this->body));
    }

    /** Estimated read time in minutes (~200 wpm), minimum 1. */
    public function readMinutes(): int
    {
        return max(1, (int) ceil($this->wordCount() / 200));
    }

    /** Hashtags/topics as a clean array (leading '#' stripped). */
    public function tagList(): array
    {
        if (!$this->tags) {
            return [];
        }

        return collect(preg_split('/[,\n]+/', (string) $this->tags))
            ->map(fn ($t) => ltrim(trim($t), '#'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function coverImageUrl(): ?string
    {
        return $this->cover_image_path
            ? Storage::disk('public')->url(ltrim($this->cover_image_path, '/'))
            : null;
    }

    /** Stable per-article preview slug for the URL. */
    public function previewSlug(): string
    {
        return $this->slug ?: Str::slug($this->title) ?: 'article';
    }
}
