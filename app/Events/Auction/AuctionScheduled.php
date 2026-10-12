<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\Auction;

/**
 * Fires when an auction leaves `draft` for `scheduled` (date locked, not yet
 * public). Spec: .ai/specs/auctions.md §6.1, §17.
 */
final class AuctionScheduled extends AbstractDomainEvent
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
            'starts_at' => $this->auction->starts_at?->toIso8601String(),
        ];
    }
}
