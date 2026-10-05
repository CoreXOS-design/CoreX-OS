<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\AuctionLot;

/**
 * Fires when no bid reached the reserve and the seller declined, or no bid
 * at all (§6.2, §12.3). Known subscribers (§17): property status restore,
 * under-bidder follow-up, audit — under-bidder follow-up is a Phase 2
 * listener (needs auction_bidders); Phase 1 wires property status only.
 */
final class AuctionLotPassedIn extends AbstractDomainEvent
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
        ];
    }
}
