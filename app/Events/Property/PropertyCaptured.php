<?php

declare(strict_types=1);

namespace App\Events\Property;

use App\Events\AbstractDomainEvent;
use App\Models\Property;

/**
 * SPINE-3 — fires when a Property is created by an agent. Dispatched
 * from PropertyObserver::created alongside the audit log. Subject is
 * the Property; actor is the listing agent ($property->agent_id).
 *
 * System/import-created properties (no agent_id, or agent_id resolves
 * to a non-user role) credit nothing — the InstantPointService
 * null-actor guard handles that path.
 */
final class PropertyCaptured extends AbstractDomainEvent
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
