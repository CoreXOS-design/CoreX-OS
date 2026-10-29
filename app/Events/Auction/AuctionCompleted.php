<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\Auction;

/** Every lot has a final result and the auction was marked completed. */
final class AuctionCompleted extends AbstractDomainEvent
{
    public function __construct(public readonly Auction $auction, public readonly ?int $byUserId = null, ?string $traceId = null)
    {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->auction->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->byUserId; }
    public function subject(): ?array { return [Auction::class, $this->auction->id]; }

    public function context(): array
    {
        return ['reference' => $this->auction->reference];
    }
}
