<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\AuctionLot;

/**
 * Fires when the hammer falls BELOW reserve (§6.2, §12.1). The seller must
 * confirm or decline within `confirmation_deadline`. Known subscribers
 * (§17): seller confirmation task, calendar deadline, audit.
 */
final class AuctionLotSoldSubjectToConfirmation extends AbstractDomainEvent
{
    public function __construct(
        public readonly AuctionLot $lot,
        public readonly ?int $actorId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->lot->agency_id; }
    public function actorUserId(): ?int { return $this->actorId; }
    public function subject(): ?array { return [AuctionLot::class, $this->lot->id]; }

    public function context(): array
    {
        return [
            'auction_id' => $this->lot->auction_id,
            'property_id' => $this->lot->property_id,
            'hammer_price' => $this->lot->hammer_price,
            'confirmation_deadline' => $this->lot->confirmation_deadline?->toIso8601String(),
        ];
    }
}
