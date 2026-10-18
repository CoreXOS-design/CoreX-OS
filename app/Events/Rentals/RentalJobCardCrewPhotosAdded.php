<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\RentalJobCard;

/**
 * Fires when the crew uploads photos to a job card from their link / crew
 * page (one event per submit, not per file). Spec:
 * .ai/specs/rental-work-orders.md §14.27.5.
 */
final class RentalJobCardCrewPhotosAdded extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalJobCard $jobCard,
        public readonly int $count,
        public readonly string $photoType,
        public readonly string $via,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->jobCard->agency_id; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [RentalJobCard::class, $this->jobCard->id]; }

    public function context(): array
    {
        return [
            'count' => $this->count,
            'photo_type' => $this->photoType,
            'via' => $this->via,
        ];
    }
}
