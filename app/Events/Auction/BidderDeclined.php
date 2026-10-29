<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\AuctionRegistration;

/** Staff declined a bidder registration. */
final class BidderDeclined extends AbstractDomainEvent
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
        return ['auction_id' => $this->registration->auction_id, 'reason' => $this->registration->declined_reason];
    }
}
