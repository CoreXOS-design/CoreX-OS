<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\RentalJobCard;

/**
 * Fires when the crew's work is recorded as completed — by the crew on their
 * link / crew page, or by the office from the uploaded signed copy. It does
 * NOT mean the card is closed (the agent sign-off still does). The landlord
 * email listens to this event, so it fires for BOTH routes: the work is done
 * either way. Spec: .ai/specs/rental-work-orders.md §14.27.5 / §14.28.
 */
final class RentalJobCardCrewCompleted extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalJobCard $jobCard,
        public readonly string $signedByName,
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
            'signed_by_name' => $this->signedByName,
            'via' => $this->via,
            'rental_crew_id' => $this->jobCard->rental_crew_id,
        ];
    }
}
