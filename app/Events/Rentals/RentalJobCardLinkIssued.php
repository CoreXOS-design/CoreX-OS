<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\RentalJobCard;

/**
 * Fires when an office user mints (or re-issues) the per-job crew link for a job
 * card. The raw token is never part of the payload — only the token row id.
 * Spec: .ai/specs/rental-work-orders.md §14.27.5.
 */
final class RentalJobCardLinkIssued extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalJobCard $jobCard,
        public readonly int $tokenId,
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
            'token_id' => $this->tokenId,
            'rental_crew_id' => $this->jobCard->rental_crew_id,
        ];
    }
}
