<?php

declare(strict_types=1);

namespace App\Events\Property;

use App\Events\AbstractDomainEvent;
use App\Models\Property;

/**
 * SPINE-3 — fires when a Property's compliance_snapshot_at transitions
 * from NULL to a value (i.e. compliance was first passed/snapshotted).
 * Dispatched from PropertyObserver::saved in the same block as the
 * audit-log entry for the same transition. Actor is the listing agent
 * ($property->agent_id).
 */
final class PropertyCompliancePassed extends AbstractDomainEvent
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
