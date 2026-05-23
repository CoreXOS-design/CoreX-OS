<?php

declare(strict_types=1);

namespace App\Events\Mandate;

use App\Events\AbstractDomainEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Fires when a tracked-property is promoted to formal stock via mandate signing
 * (the Universal Match-or-Create promoteToStock path). Bridges Prospecting
 * intelligence with the Property pillar.
 */
final class MandateConverted extends AbstractDomainEvent
{
    public function __construct(
        public readonly Model $mandate,
        public readonly ?Model $deal = null,
        public readonly ?int $agencyIdHint = null,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->agencyIdHint
            ?? ($this->mandate->agency_id ?? null)
            ?? ($this->deal->agency_id ?? null);
    }
    public function actorUserId(): ?int { return $this->actorUserId; }

    public function subject(): ?array
    {
        return [get_class($this->mandate), $this->mandate->getKey()];
    }

    public function context(): array
    {
        return [
            'deal_id' => $this->deal?->getKey(),
            'deal_class' => $this->deal ? get_class($this->deal) : null,
        ];
    }
}
