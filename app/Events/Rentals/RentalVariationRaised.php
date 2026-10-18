<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\RentalWorkOrderVariation;

/**
 * Extra work (or a higher external quote) pushed an approved work order above its approved amount: a variation row was created or revised — auto-approved within the owner's terms, or waiting for the owner. Dispatched by Build 2. Spec §17.7.
 */
final class RentalVariationRaised extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalWorkOrderVariation $variation,
        public readonly ?int $actorUserId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->variation->agency_id; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [RentalWorkOrderVariation::class, $this->variation->id]; }

    public function context(): array
    {
        return ['status' => $this->variation->status, 'revision' => $this->variation->revision, 'rental_work_order_id' => $this->variation->rental_work_order_id];
    }
}
