<?php

declare(strict_types=1);

namespace App\Events\RoadToSold;

use App\Events\AbstractDomainEvent;

/**
 * Road to Sold v4 (road-to-sold.md 23.9): the result of an evaluation / presentation was recorded on a belt record - won, undecided, lost or not selling.
 * Raised once per (record, result, day) by ResultService after its transaction. userId = the agent who holds the record; actorUserId = who recorded it.
 * `source` = where the answer came from (belt_card, evaluation_feedback, presentation_outcome, ...).
 */
final class ProspectResultRecorded extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $prospectId,
        public readonly int $agencyId,
        public readonly int $userId,
        public readonly int $trackedPropertyId,
        public readonly string $station,
        public readonly string $resultClass,
        public readonly string $resultKey,
        public readonly string $source,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }

    public function subject(): ?array
    {
        return ['App\\Models\\RoadToSold\\RtsProspect', $this->prospectId];
    }
}
