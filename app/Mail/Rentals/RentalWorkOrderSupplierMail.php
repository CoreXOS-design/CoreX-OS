<?php

namespace App\Mail\Rentals;

use App\Models\RentalWorkOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * .ai/specs/rental-work-orders.md §4 — "can even email the supplier," an
 * optional step per Johan's own wording, sent once a supplier is actually
 * assigned. This email is automated — CoreX sends it. WhatsApp is NOT
 * automated (§4's own standing finding) — a wa.me link is the ceiling,
 * offered separately on the work-order screen, not sent from here.
 */
class RentalWorkOrderSupplierMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $agencyName;
    public string $propertyAddress;

    public function __construct(public RentalWorkOrder $workOrder)
    {
        $this->onQueue('mail');
        $this->agencyName = $workOrder->property?->agency?->name ?? config('mail.from.name', 'CoreX OS');
        $this->propertyAddress = $workOrder->property?->buildDisplayAddress() ?: ('Property #' . $workOrder->property_id);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Job request — {$this->propertyAddress} ({$this->agencyName})");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.work-order-supplier');
    }
}
