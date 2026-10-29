<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\AuctionRegistration;

/** A buyer submitted a bidder registration for an auction. */
final class BidderRegistered extends AbstractDomainEvent
{
    public function __construct(public readonly AuctionRegistration $registration, ?string $traceId = null)
    {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->registration->agency_id ?? null; }
    public function actorUserId(): ?int { return null; }
    public function subject(): ?array { return [AuctionRegistration::class, $this->registration->id]; }

    public function context(): array
    {
        return ['auction_id' => $this->registration->auction_id, 'client_user_id' => $this->registration->client_user_id, 'bidder_type' => $this->registration->bidder_type];
    }
}
