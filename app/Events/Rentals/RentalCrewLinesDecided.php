<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\RentalJobCard;

/**
 * The office accepted or rejected crew-added lines (one event per batch). Dispatched by Build 1. Spec §17.5.3.
 */
final class RentalCrewLinesDecided extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalJobCard $jobCard,
        public readonly int $accepted,
        public readonly int $rejected,
        public readonly ?int $actorUserId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->jobCard->agency_id; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [RentalJobCard::class, $this->jobCard->id]; }

    public function context(): array
    {
        return ['accepted' => $this->accepted, 'rejected' => $this->rejected];
    }
}
