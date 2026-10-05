<?php

declare(strict_types=1);

namespace App\Events\Property;

use App\Events\AbstractDomainEvent;
use App\Models\Property;

/** Layer 3 — the approver turned the listing down; the reason reaches the agent. */
final class SyndicationRejected extends AbstractDomainEvent
{
    public function __construct(
        public readonly Property $property,
        public readonly int $approvalId,
        public readonly int $decidedByUserId,
        public readonly string $reason,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->property->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->decidedByUserId; }
    public function subject(): ?array { return [Property::class, $this->property->id]; }
}
