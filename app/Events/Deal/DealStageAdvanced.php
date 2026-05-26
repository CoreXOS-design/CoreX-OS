<?php

declare(strict_types=1);

namespace App\Events\Deal;

use App\Events\AbstractDomainEvent;
use App\Models\Deal;

/**
 * Fires when a Deal advances to a new stage (offer / accepted / granted / registered etc.).
 */
final class DealStageAdvanced extends AbstractDomainEvent
{
    public function __construct(
        public readonly Deal $deal,
        public readonly ?string $fromStage,
        public readonly ?string $toStage,
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
        return ['from_stage' => $this->fromStage, 'to_stage' => $this->toStage];
    }
}
