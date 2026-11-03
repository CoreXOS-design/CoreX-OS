<?php

namespace App\Mail\Rentals;

use App\Models\RentalFaultReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * .ai/specs/rentals-faults-work-orders.md section 31.4 - the tenant is told the moment the job is sent to a contractor or crew ("your repair is being arranged").
 * Neutral wording only: never a price, a quote or the owner's reasons.
 */
class RentalTenantRepairArrangedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $recipientName;
    public string $propertyAddress;
    public string $who;
    public string $portalUrl;

    public function __construct(public RentalFaultReport $faultReport, string $recipientName, string $who, ?\App\Models\Contact $recipient = null)
    {
        $this->onQueue('mail');
        $this->recipientName = $recipientName ?: 'there';
        $this->who = $who;
        $this->propertyAddress = $faultReport->property?->buildDisplayAddress() ?: ('Property #' . $faultReport->property_id);
        $this->portalUrl = $recipient
            ? \App\Support\PortalLink::forContact($recipient, \App\Support\PortalLink::VIEW_TENANT, ['fault' => $faultReport->id])
            : \App\Support\PortalLink::unaddressed(\App\Support\PortalLink::VIEW_TENANT, ['fault' => $faultReport->id]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your repair is being arranged — {$this->propertyAddress}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.tenant-repair-arranged');
    }
}
