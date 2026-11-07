<?php

declare(strict_types=1);

namespace App\Events\Auction;

use App\Events\AbstractDomainEvent;
use App\Models\AuctionInvoice;
use App\Models\AuctionInvoicePayment;

/** Money was recorded against a bidder invoice (and the invoice may now be fully paid). */
final class AuctionPaymentRecorded extends AbstractDomainEvent
{
    public function __construct(public readonly AuctionInvoice $invoice, public readonly AuctionInvoicePayment $payment, ?string $traceId = null)
    {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->invoice->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->payment->recorded_by; }
    public function subject(): ?array { return [AuctionInvoice::class, $this->invoice->id]; }

    public function context(): array
    {
        return ['kind' => $this->payment->kind, 'amount' => (float) $this->payment->amount, 'invoice_status' => $this->invoice->status, 'paid_in_full' => $this->invoice->status === AuctionInvoice::STATUS_PAID];
    }
}
