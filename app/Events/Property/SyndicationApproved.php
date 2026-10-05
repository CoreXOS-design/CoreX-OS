<?php

declare(strict_types=1);

namespace App\Events\Property;

use App\Events\AbstractDomainEvent;
use App\Models\Property;

/**
 * Layer 3 — a chosen approver cleared the listing for syndication. The
 * property now carries `syndication_approved_at` and every portal control is
 * unlocked for it, permanently (spec D2).
 */
final class SyndicationApproved extends AbstractDomainEvent
{
    public function __construct(
        public readonly Property $property,
        public readonly int $approvalId,
        public readonly int $decidedByUserId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->property->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->decidedByUserId; }
    public function subject(): ?array { return [Property::class, $this->property->id]; }
}
