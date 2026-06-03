<?php

declare(strict_types=1);

namespace App\Events\Marketing;

use App\Events\AbstractDomainEvent;
use App\Models\PropertyMarketingPost;

/**
 * SPINE-3 — fires when a PropertyMarketingPost transitions to
 * status='published' (the post is live on Facebook / Instagram /
 * other platform). Dispatched from PropertyMarketingPostObserver::
 * updated only on the FIRST status flip into 'published' (idempotent
 * via wasChanged + the per-(post, user, day) credit dedup key).
 *
 * Actor is the post's owning user ($post->user_id). The canonical
 * action point is "live on the platform", NOT the prior 'draft'
 * insert — only credit when the publish actually succeeds.
 */
final class MarketingPostPublished extends AbstractDomainEvent
{
    public function __construct(
        public readonly PropertyMarketingPost $post,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        // PropertyMarketingPost belongs to a Property; agency is read
        // off the property to avoid an explicit relation load.
        return $this->post->property?->agency_id;
    }

    public function actorUserId(): ?int { return $this->post->user_id !== null ? (int) $this->post->user_id : null; }
    public function subject(): ?array { return [PropertyMarketingPost::class, $this->post->id]; }

    public function context(): array
    {
        return ['platform' => $this->post->platform];
    }
}
