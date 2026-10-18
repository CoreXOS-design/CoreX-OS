<?php

declare(strict_types=1);

namespace App\Events\Marketing;

use App\Events\AbstractDomainEvent;
use App\Models\PropertyMarketingPost;

/**
 * AT-456 Part D — fires when a SCHEDULED social post could not be published at its time
 * (status 'scheduled' -> 'publishing' -> 'failed'). The post is never retried automatically (a retry
 * could double-post); the agent is told and may schedule it again.
 *
 * Fired by: App\Services\Marketing\ScheduledPostPublisher::fail() (the `marketing:publish-due` run).
 * Typical subscribers: App\Listeners\Marketing\NotifyAgentOfFailedScheduledPost (in-app bell
 * notification to the post's owner), the domain_event_log audit listener.
 * Example trace context: a run of `marketing:publish-due` -> this event -> the bell notification.
 *
 * Counterpart of MarketingPostPublished (fired by PropertyMarketingPostObserver on the first flip to
 * 'published'); together they cover both outcomes of a scheduled post.
 */
final class MarketingPostScheduleFailed extends AbstractDomainEvent
{
    public function __construct(
        public readonly PropertyMarketingPost $post,
        public readonly string $reason,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->post->agency_id !== null ? (int) $this->post->agency_id : null;
    }

    /** The agent whose post it is. The publish run itself is a system action. */
    public function actorUserId(): ?int
    {
        return $this->post->user_id !== null ? (int) $this->post->user_id : null;
    }

    public function subject(): ?array
    {
        return [PropertyMarketingPost::class, $this->post->id];
    }

    public function context(): array
    {
        // Company Ads §12: a post may be for a company ad instead of a listing (one of the two is set).
        return ['platform' => $this->post->platform, 'reason' => $this->reason, 'property_id' => $this->post->property_id, 'company_ad_id' => $this->post->company_ad_id];
    }
}
