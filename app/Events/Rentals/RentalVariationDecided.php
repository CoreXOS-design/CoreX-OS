<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\RentalWorkOrderVariation;

/**
 * The owner (portal) or the office on the owner's behalf (agent capture) approved or declined a variation. Dispatched by Build 2. Spec §17.7.4.
 */
final class RentalVariationDecided extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalWorkOrderVariation $variation,
        public readonly string $decision,
        public readonly string $via,
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
        return ['decision' => $this->decision, 'via' => $this->via];
    }
}
