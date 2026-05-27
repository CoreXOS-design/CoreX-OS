<?php

declare(strict_types=1);

namespace App\Events\Deal;

use App\Events\AbstractDomainEvent;
use App\Models\Deal;
use App\Models\DealMoneyLine;

/**
 * Fires when a money line on a Deal is created or updated.
 */
final class DealMoneyLineChanged extends AbstractDomainEvent
{
    /**
     * @param array<string,mixed> $change
     */
    public function __construct(
        public readonly Deal $deal,
        public readonly DealMoneyLine $line,
        public readonly array $change = [],
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->deal->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [DealMoneyLine::class, $this->line->id]; }

    public function context(): array
    {
        return ['deal_id' => $this->deal->id, 'change' => $this->change];
    }
}
