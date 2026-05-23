<?php

declare(strict_types=1);

namespace App\Events\Mandate;

use App\Events\AbstractDomainEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Fires when a mandate reaches its expiry date.
 */
final class MandateExpired extends AbstractDomainEvent
{
    public function __construct(
        public readonly Model $mandate,
        public readonly ?int $agencyIdHint = null,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->agencyIdHint ?? ($this->mandate->agency_id ?? null);
    }
    public function actorUserId(): ?int { return $this->actorUserId; }

    public function subject(): ?array
    {
        return [get_class($this->mandate), $this->mandate->getKey()];
    }
}
