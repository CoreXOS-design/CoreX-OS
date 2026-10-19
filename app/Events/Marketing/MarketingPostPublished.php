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
        // The post's own agency (a company-ad post has no property); the
        // property's agency for older rows without one.
        return $this->post->agency_id !== null ? (int) $this->post->agency_id : $this->post->property?->agency_id;
    }

    public function actorUserId(): ?int { return $this->post->user_id !== null ? (int) $this->post->user_id : null; }
    public function subject(): ?array { return [PropertyMarketingPost::class, $this->post->id]; }

    public function context(): array
    {
        // Company Ads §12: a post may be for a company ad instead of a listing (one of the two is set).
        return ['platform' => $this->post->platform, 'property_id' => $this->post->property_id, 'company_ad_id' => $this->post->company_ad_id];
    }
}
