<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\RentalJobCard;

/**
 * Fires when the office uploads the wet-ink signed job card against a card.
 * Spec: .ai/specs/rental-work-orders.md §14.27.5 / §14.28.
 */
final class RentalJobCardSignedCopyUploaded extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalJobCard $jobCard,
        public readonly int $signedCopyId,
        public readonly bool $supersededEarlier = false,
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
            'signed_copy_id' => $this->signedCopyId,
            'superseded_earlier' => $this->supersededEarlier,
        ];
    }
}
