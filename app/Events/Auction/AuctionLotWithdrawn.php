<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\AuctionLot;

/**
 * Fires when a lot is pulled before the sale (§6.2). Known subscribers
 * (§17): property status restore, bidder notification, audit — bidder
 * notification is a Phase 2 listener; Phase 1 wires property status only.
 */
final class AuctionLotWithdrawn extends AbstractDomainEvent
{
    public function __construct(
        public readonly AuctionLot $lot,
        public readonly ?string $reason = null,
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
            'reason' => $this->reason,
        ];
    }
}
