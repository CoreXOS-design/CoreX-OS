<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\AuctionBid;

/** Staff retracted a bid. */
final class BidRetracted extends AbstractDomainEvent
{
    public function __construct(public readonly AuctionBid $bid, public readonly ?int $byUserId = null, ?string $traceId = null)
    {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->bid->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->byUserId; }
    public function subject(): ?array { return [AuctionBid::class, $this->bid->id]; }

    public function context(): array
    {
        return ['lot_id' => $this->bid->lot_id, 'reason' => $this->bid->retract_reason];
    }
}
