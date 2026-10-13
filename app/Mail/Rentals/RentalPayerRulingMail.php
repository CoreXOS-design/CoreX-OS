<?php

namespace App\Mail\Rentals;

use App\Models\Contact;
use App\Models\RentalPayerRuling;
use App\Models\RentalWorkOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.13.4 - the owner and the tenant are each told the "Who pays" ruling in plain words. A record,
 * not a charge: it says so and that the agency will be in touch about payment. The evidence file is never attached or linked.
 */
class RentalPayerRulingMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $recipientName;
    public string $propertyAddress;
    public string $faultTitle;
    public string $portalUrl;

    /** @param array<string,mixed> $words RentalWhoPaysService::payload() for this audience */
    public function __construct(public RentalWorkOrder $workOrder, public RentalPayerRuling $ruling, public string $audience, public array $words, Contact $recipient, public bool $replacesEarlier = false)
    {
        $this->onQueue('mail');
        $this->recipientName = trim((string) $recipient->first_name) ?: 'there';
        $this->propertyAddress = $workOrder->property?->buildDisplayAddress() ?: ('Property #' . $workOrder->property_id);
        $this->faultTitle = (string) $workOrder->title;
        $view = $audience === 'owner' ? \App\Support\PortalLink::VIEW_OWNER : \App\Support\PortalLink::VIEW_TENANT;
        $target = $workOrder->reported_fault_report_id ? ['fault' => $workOrder->reported_fault_report_id] : ['wo' => $workOrder->id];
        $this->portalUrl = $recipient->email
            ? \App\Support\PortalLink::forEmail($recipient->email, $view, $target, (int) $recipient->id)
            : \App\Support\PortalLink::unaddressed($view, $target);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->replacesEarlier ? 'Updated: who pays for the repair' : 'Who pays for the repair') . " - {$this->propertyAddress}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.payer-ruling');
    }
}
