<?php

declare(strict_types=1);

namespace App\Events\RoadToSold;

use App\Events\AbstractDomainEvent;

/**
 * An agent (or a manager for them) ends a pause early. Spec road-to-sold.md 23.13.
 *
 * userId = the agent it is about; actorUserId = who pressed the button (the agent, an assistant or a manager).
 */
final class RoadToSoldResumed extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $agencyId,
        public readonly string $track,
        public readonly int $userId,
        public readonly ?string $wasPausedUntil,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }

    public function subject(): ?array
    {
        return ['App\\Models\\User', $this->userId];
    }
}
