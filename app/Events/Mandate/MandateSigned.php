<?php

declare(strict_types=1);

namespace App\Events\Mandate;

use App\Events\AbstractDomainEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Fires when a mandate is signed. The "mandate" subject in CoreX is currently
 * a Deal (mandate-type deal) or a Property promoted from tracked → stock; this
 * event accepts a polymorphic mandate model + optional related property.
 */
final class MandateSigned extends AbstractDomainEvent
{
    public function __construct(
        public readonly Model $mandate,
        public readonly ?Model $property = null,
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
            ?? ($this->property->agency_id ?? null);
    }
    public function actorUserId(): ?int { return $this->actorUserId; }

    public function subject(): ?array
    {
        return [get_class($this->mandate), $this->mandate->getKey()];
    }

    public function context(): array
    {
        return [
            'property_id' => $this->property?->getKey(),
            'property_class' => $this->property ? get_class($this->property) : null,
        ];
    }
}
