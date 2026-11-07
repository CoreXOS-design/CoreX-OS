<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\AuctionRegistration;

/** Staff approved a bidder; a bidder number was issued. */
final class BidderApproved extends AbstractDomainEvent
{
    public function __construct(public readonly AuctionRegistration $registration, public readonly ?int $byUserId = null, ?string $traceId = null)
    {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->registration->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->byUserId; }
    public function subject(): ?array { return [AuctionRegistration::class, $this->registration->id]; }

    public function context(): array
    {
        return ['auction_id' => $this->registration->auction_id, 'bidder_number' => $this->registration->bidder_number];
    }
}
