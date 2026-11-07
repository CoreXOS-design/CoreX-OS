<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\AuctionInvoice;

/** A bidder invoice was issued to the winning bidder. */
final class AuctionInvoiceIssued extends AbstractDomainEvent
{
    public function __construct(public readonly AuctionInvoice $invoice, public readonly ?int $byUserId = null, ?string $traceId = null)
    {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->invoice->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->byUserId; }
    public function subject(): ?array { return [AuctionInvoice::class, $this->invoice->id]; }

    public function context(): array
    {
        return ['number' => $this->invoice->number, 'lot_id' => $this->invoice->lot_id, 'total_amount' => (float) $this->invoice->total_amount];
    }
}
