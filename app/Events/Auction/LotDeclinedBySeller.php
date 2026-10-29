<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\AuctionLot;

/** The seller declined the highest bid on a lot sold below its reserve; the lot was passed in. */
final class LotDeclinedBySeller extends AbstractDomainEvent
{
    public function __construct(public readonly AuctionLot $lot, ?string $traceId = null)
    {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->lot->agency_id ?? null; }
    public function actorUserId(): ?int { return null; }
    public function subject(): ?array { return [AuctionLot::class, $this->lot->id]; }

    public function context(): array
    {
        return ['auction_id' => $this->lot->auction_id, 'property_id' => $this->lot->property_id, 'winning_bid_id' => $this->lot->winning_bid_id, 'note' => $this->lot->result_note];
    }
}
