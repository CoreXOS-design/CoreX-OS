<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\Auction;

/**
 * Fires when an auction's catalogue goes public. Spec:
 * .ai/specs/auctions.md §9 step 5, §17. Known subscribers (per §17): Property
 * status, syndication, matching, seller notification, audit — Phase 1 wires
 * property status; syndication/matching/notification listeners land with
 * their own phases (§21).
 */
final class AuctionCataloguePublished extends AbstractDomainEvent
{
    public function __construct(
        public readonly Auction $auction,
        public readonly ?int $actorId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->auction->agency_id; }
    public function actorUserId(): ?int { return $this->actorId; }
    public function subject(): ?array { return [Auction::class, $this->auction->id]; }

    public function context(): array
    {
        return [
            'reference' => $this->auction->reference,
            'lot_count' => $this->auction->lots()->count(),
        ];
    }
}
