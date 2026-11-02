<?php

declare(strict_types=1);

namespace App\Events\RoadToSold;

use App\Events\AbstractDomainEvent;

/**
 * Road to Sold v4 (road-to-sold.md 23.5 / 23.17): a claim hold ran out and the claim was released (reason hold_ended) with its belt record.
 * userId = the agent who holds the claim; actorUserId = who pressed Extend (null for the daily job). holdUntil is the new end for an extension, the end that passed for an ending.
 */
final class ClaimHoldEnded extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $claimId,
        public readonly int $agencyId,
        public readonly int $userId,
        public readonly ?int $prospectId,
        public readonly ?string $holdUntil,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }

    public function subject(): ?array
    {
        return ['App\\Models\\ProspectingClaim', $this->claimId];
    }
}
