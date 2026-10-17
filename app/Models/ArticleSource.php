<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One RSS link the platform owner has put on the Article Library's source list.
 *
 * GLOBAL by design — NOT BelongsToAgency. It is a platform-owner list: the only readers are the
 * owner-only /admin/article-library screens and ArticleScraperService. Archive = soft delete.
 *
 * Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §C.2.
 */
class ArticleSource extends Model
{
    use SoftDeletes;

    public const STATUS_OK     = 'ok';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'name', 'url', 'type', 'is_active',
        'last_run_at', 'last_status', 'last_error', 'last_new_articles', 'created_by',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'last_run_at'       => 'datetime',
        'last_new_articles' => 'integer',
    ];

    protected static function booted(): void
    {
        // Archiving/restoring a source shows/hides its articles to agencies — the tag filter list follows.
        $forget = static fn () => LibraryArticle::forgetVisibleTags();
        static::saved($forget);
        static::deleted($forget);
        static::restored($forget);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(LibraryArticle::class, 'source_id');
    }

    /** Sources the pipeline (and the presentations' scraper) should fetch: not archived, not paused. */
    public function scopeFetchable($query)
    {
        return $query->where('is_active', true);
    }
}
