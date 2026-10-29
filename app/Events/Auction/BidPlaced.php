<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\AuctionBid;

/** A valid bid was placed on a lot (by hand or by the auto-bid engine). */
final class BidPlaced extends AbstractDomainEvent
{
    public function __construct(public readonly AuctionBid $bid, ?string $traceId = null)
    {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->bid->agency_id ?? null; }
    public function actorUserId(): ?int { return null; }
    public function subject(): ?array { return [AuctionBid::class, $this->bid->id]; }

    public function context(): array
    {
        return ['lot_id' => $this->bid->lot_id, 'auction_id' => $this->bid->auction_id, 'registration_id' => $this->bid->registration_id, 'source' => $this->bid->source];
    }
}
