<?php

namespace App\Observers;

use App\Events\Website\ArticleVisibilityChanged;
use App\Models\AgentArticle;
use Illuminate\Support\Facades\Log;

/**
 * Agency Public API — emit article.* webhooks when an agent article's website
 * presence changes. Guarded (only fires on a real publish transition or a
 * public-content change of a published article) and failure-isolated so it
 * never breaks a save.
 *
 * Spec: .ai/specs/agency-public-api.md §6.1.
 */
class AgentArticleObserver
{
    /** Public-content fields exposed by the website ArticleResource. */
    private const PUBLIC_FIELDS = [
        'title', 'slug', 'excerpt', 'cover_image_path', 'body', 'link_url', 'tags', 'published_at',
    ];

    public function created(AgentArticle $article): void
    {
        try {
            if ($article->is_published) {
                event(new ArticleVisibilityChanged($article, 'published'));
            }
        } catch (\Throwable $e) {
            Log::warning("Article website webhook (create) failed for #{$article->id}: {$e->getMessage()}");
        }
    }

    public function updated(AgentArticle $article): void
    {
        try {
            // publish flag flipped → published / removed.
            if ($article->wasChanged('is_published')) {
                event(new ArticleVisibilityChanged(
                    $article,
                    $article->is_published ? 'published' : 'removed'
                ));
                return;
            }

            // A published article's public content changed → updated.
            if ($article->is_published && $article->wasChanged(self::PUBLIC_FIELDS)) {
                event(new ArticleVisibilityChanged($article, 'updated'));
            }
        } catch (\Throwable $e) {
            Log::warning("Article website webhook (update) failed for #{$article->id}: {$e->getMessage()}");
        }
    }

    public function deleted(AgentArticle $article): void
    {
        try {
            if ($article->is_published) {
                event(new ArticleVisibilityChanged($article, 'removed'));
            }
        } catch (\Throwable $e) {
            Log::warning("Article website webhook (delete) failed for #{$article->id}: {$e->getMessage()}");
        }
    }

    public function restored(AgentArticle $article): void
    {
        try {
            if ($article->is_published) {
                event(new ArticleVisibilityChanged($article, 'published'));
            }
        } catch (\Throwable $e) {
            Log::warning("Article website webhook (restore) failed for #{$article->id}: {$e->getMessage()}");
        }
    }
}
