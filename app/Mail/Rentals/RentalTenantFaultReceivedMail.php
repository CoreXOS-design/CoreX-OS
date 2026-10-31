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
 * .ai/specs/rentals-faults-work-orders.md section 31.2 - the tenant's confirmation at report time: "we have your fault, your agent has been told". Sent once, to the
 * tenant(s) on the lease, under the agency's existing "notify tenant on status change" setting.
 */
class RentalTenantFaultReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $recipientName;
    public string $propertyAddress;
    public string $portalUrl;

    public function __construct(public RentalFaultReport $faultReport, string $recipientName, ?\App\Models\Contact $recipient = null)
    {
        $this->onQueue('mail');
        $this->recipientName = $recipientName ?: 'there';
        $this->propertyAddress = $faultReport->property?->buildDisplayAddress() ?: ('Property #' . $faultReport->property_id);
        $this->portalUrl = $recipient
            ? \App\Support\PortalLink::forContact($recipient, \App\Support\PortalLink::VIEW_TENANT, ['fault' => $faultReport->id])
            : \App\Support\PortalLink::unaddressed(\App\Support\PortalLink::VIEW_TENANT, ['fault' => $faultReport->id]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "We have your fault report — {$this->propertyAddress}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.tenant-fault-received');
    }
}
