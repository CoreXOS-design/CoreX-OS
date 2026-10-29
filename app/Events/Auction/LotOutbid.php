<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\AuctionLot;
use App\Models\AuctionRegistration;

/** The leading bidder on a lot was outbid. */
final class LotOutbid extends AbstractDomainEvent
{
    public function __construct(public readonly AuctionLot $lot, public readonly AuctionRegistration $outbid, public readonly float $newAmount, ?string $traceId = null)
    {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->lot->agency_id ?? null; }
    public function actorUserId(): ?int { return null; }
    public function subject(): ?array { return [AuctionLot::class, $this->lot->id]; }

    public function context(): array
    {
        return ['registration_id' => $this->outbid->id, 'new_amount' => $this->newAmount];
    }
}
