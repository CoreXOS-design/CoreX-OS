<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\AuctionLot;

/**
 * Fires when the hammer falls at/above reserve (§12.1). CPA s45(4): the sale
 * is complete at the fall of the hammer. Known subscribers (§17): Deal
 * creation, property status, commission, seller + buyer notification, audit
 * — Deal creation/commission are Phase 3 listeners (§21); Phase 1 wires
 * property status only.
 */
final class AuctionLotSold extends AbstractDomainEvent
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
            'reserve_met' => $this->lot->reserve_met,
        ];
    }
}
