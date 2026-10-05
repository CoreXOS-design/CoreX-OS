<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\AuctionBidder;

/**
 * AT-432 §17 — fires when a bidder registration is submitted, whether
 * entered at the door by staff or through the public online form (§10.1).
 * Known subscribers (§17): FICA task, contact enrichment, agent
 * notification, audit.
 */
final class BidderRegistered extends AbstractDomainEvent
{
    public function __construct(
        public readonly AuctionBidder $bidder,
        public readonly ?int $actorId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->bidder->agency_id; }
    public function actorUserId(): ?int { return $this->actorId; }
    public function subject(): ?array { return [AuctionBidder::class, $this->bidder->id]; }

    public function context(): array
    {
        return [
            'auction_id' => $this->bidder->auction_id,
            'contact_id' => $this->bidder->contact_id,
            'registration_source' => $this->bidder->registration_source,
        ];
    }
}
