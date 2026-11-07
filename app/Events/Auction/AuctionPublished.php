<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\Auction;

/** An auction was published to the public auction site. */
final class AuctionPublished extends AbstractDomainEvent
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
        return ['reference' => $this->auction->reference, 'format' => $this->auction->format, 'starts_at' => $this->auction->starts_at?->toIso8601String()];
    }
}
