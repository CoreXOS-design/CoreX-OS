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
 * .ai/specs/rental-work-orders.md §4 — sent only for a work order raised
 * WITHOUT an upstream fault report (owner-instructed proactive work, or
 * straight from an inspection observation). A work order raised FROM a
 * fault report never sends this — the tenant was already notified at
 * fault-report creation (§3a), and re-notifying at work-order stage would
 * be the same fact printed twice.
 */
class RentalWorkOrderTenantMail extends Mailable implements ShouldQueue
{
    public $queue = 'mail';

    use Queueable, SerializesModels;

    public string $tenantName;
    public string $agencyName;
    public string $propertyAddress;

    public function __construct(public RentalWorkOrder $workOrder, string $tenantName)
    {
        $this->tenantName = $tenantName ?: 'there';
        $this->agencyName = $workOrder->property?->agency?->name ?? config('mail.from.name', 'CoreX OS');
        $this->propertyAddress = $workOrder->property?->buildDisplayAddress() ?: ('Property #' . $workOrder->property_id);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your reported issue is on record — {$this->propertyAddress}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.work-order-tenant');
    }
}
