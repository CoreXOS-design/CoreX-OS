<?php

declare(strict_types=1);

namespace App\Events\Website;

use App\Events\AbstractDomainEvent;
use App\Models\AgentArticle;

/**
 * An agent article's website presence changed and agency websites should be
 * notified.
 *
 *   published → is_published turned on (or a published article created)
 *   updated   → a published article's public content changed
 *   removed   → is_published turned off, or the article was soft-deleted
 *
 * Articles are agency-wide (not per-property), so the listener fans out to every
 * website key of the article's agency. The payload carries both `id` and
 * `agent_id` so the consuming site can bust its per-agent article cache.
 *
 * Spec: .ai/specs/agency-public-api.md §6.1.
 */
class ArticleVisibilityChanged extends AbstractDomainEvent
{
    public function __construct(
        public readonly AgentArticle $article,
        public readonly string $action,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->article->agency_id ? (int) $this->article->agency_id : null;
    }

    public function subject(): ?array
    {
        return [AgentArticle::class, $this->article->id];
    }

    public function webhookEvent(): string
    {
        return match ($this->action) {
            'published' => 'article.published',
            'removed'   => 'article.removed',
            default     => 'article.updated',
        };
    }
}
