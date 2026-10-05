<?php

declare(strict_types=1);

namespace App\Events\Property;

use App\Events\AbstractDomainEvent;
use App\Models\Property;

/**
 * Layer 3 — an agent clicked "Send for approval" on a compliance-clear listing.
 * Spec: .ai/specs/syndication-approval-gate.md §8.
 *
 * The listener that reacts to this is SYNC and queues a job carrying scalars
 * (event discovery is off in CoreX, and a queued listener on a domain event
 * fatals restoring AbstractDomainEvent's readonly $eventId).
 */
final class SyndicationApprovalRequested extends AbstractDomainEvent
{
    public function __construct(
        public readonly Property $property,
        public readonly int $approvalId,
        public readonly int $requestedByUserId,
        /** @var list<int> */
        public readonly array $approverUserIds = [],
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->property->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->requestedByUserId; }
    public function subject(): ?array { return [Property::class, $this->property->id]; }
}
