<?php

declare(strict_types=1);

namespace App\Events\Deal;

use App\Events\AbstractDomainEvent;
use App\Models\Deal;

/**
 * Fires when a Deal's accepted_status / commission_status transitions.
 */
final class DealStatusChanged extends AbstractDomainEvent
{
    public function __construct(
        public readonly Deal $deal,
        public readonly ?string $fromStatus,
        public readonly ?string $toStatus,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->deal->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [Deal::class, $this->deal->id]; }

    public function context(): array
    {
        return ['from' => $this->fromStatus, 'to' => $this->toStatus];
    }
}
