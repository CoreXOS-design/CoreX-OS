<?php

declare(strict_types=1);

namespace App\Events\Property;

use App\Events\AbstractDomainEvent;
use App\Models\Property;

/**
 * SPINE-3 — fires when a Property's published_at transitions from NULL
 * to a value (i.e. the listing goes LIVE for the first time, or is
 * re-published after being un-published). Dispatched from
 * PropertyObserver::saved alongside the audit log entry. Actor is the
 * listing agent ($property->agent_id).
 */
final class PropertyPublished extends AbstractDomainEvent
{
    public function __construct(
        public readonly Property $property,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->property->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [Property::class, $this->property->id]; }
}
