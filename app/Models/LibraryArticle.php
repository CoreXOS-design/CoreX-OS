<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A short, original-wording brief the Article Library pipeline wrote from a news item, with the
 * credit and the link to the original. Nothing here is ever published by CoreX — an agent picks
 * "Use this" and gets their own unpublished AgentArticle.
 *
 * GLOBAL by design — NOT BelongsToAgency / AgencyScope (documented deviation, like ai_usage_events):
 * the library is one platform-wide shelf that every agency reads and none can change. Agencies get
 * it read-only through scopeVisibleToAgencies(); the copy they make (AgentArticle) IS agency-scoped.
 *
 * Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §C.2.
 */
class LibraryArticle extends Model
{
    use SoftDeletes;

    public const STATUS_READY  = 'ready';
    public const STATUS_HIDDEN = 'hidden';

    protected $fillable = [
        'source_id', 'source_name', 'source_url', 'source_url_hash', 'title', 'excerpt', 'body',
        'tags_json', 'source_published_at', 'status', 'generated_at', 'cost_zar',
    ];

    protected $casts = [
        'tags_json'           => 'array',
        'source_published_at' => 'datetime',
        'generated_at'        => 'datetime',
        'cost_zar'            => 'decimal:4',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(ArticleSource::class, 'source_id')->withTrashed();
    }

    /**
     * What an agency may see: ready (not hidden) AND from a source that is not archived. Archiving
     * a source withdraws its articles from every agency's tab as well as stopping new fetches.
     */
    public function scopeVisibleToAgencies(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_READY)
            ->whereIn('source_id', ArticleSource::query()->select('id'));
    }

    /** The date an article is listed and sorted by: when the source published it, else when we wrote it. */
    public static function dateSql(): string
    {
        return 'COALESCE(library_articles.source_published_at, library_articles.generated_at, library_articles.created_at)';
    }

    /** Tags as a clean list of strings. */
    public function tagList(): array
    {
        return array_values(array_filter((array) $this->tags_json, 'is_string'));
    }

    /** The credit line that travels with every copy. */
    public function creditLine(): string
    {
        return 'Source: ' . $this->source_name . ' — read the original: ' . $this->source_url;
    }
}
