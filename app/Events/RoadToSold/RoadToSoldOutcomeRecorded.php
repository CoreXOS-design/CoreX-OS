<?php

declare(strict_types=1);

namespace App\Events\RoadToSold;

use App\Events\AbstractDomainEvent;

/**
 * Fires once when an agent (or their assistant) records the outcome of a Road to Sold call
 * (step 3 - contact + outcome). Raised by RoadToSold\OutcomeService::record() after its
 * transaction has saved the outcome.
 *
 * userId = the agent the outcome belongs to; recordedByUserId = the assistant who made the call
 * for the agent (null = the agent did it themselves). Spec: road-to-sold.md section 4 step 3, 6.2.
 */
final class RoadToSoldOutcomeRecorded extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $outcomeId,
        public readonly int $agencyId,
        public readonly string $track,
        public readonly string $outcomeKey,
        public readonly string $kind,
        public readonly int $trackedPropertyId,
        public readonly int $userId,
        public readonly ?int $recordedByUserId = null,
        public readonly bool $evaluationBooked = false,
        public readonly ?int $calendarEventId = null,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }

    public function subject(): ?array
    {
        return ['App\\Models\\RoadToSold\\RtsContactOutcome', $this->outcomeId];
    }
}
