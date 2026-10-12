<?php

namespace App\Mail\Rentals;

use App\Models\RentalAuthorisation;
use App\Models\RentalFaultReport;
use App\Models\RentalWorkOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.5 - the safeguard: the moment an authorisation is made on the owner's behalf (an agent recorded what the
 * owner told them, or sent someone to look under the owner's permission) the owner is emailed the SAME sentence the portal shows, with the agent's note
 * and a link to the portal where they can press "Query this". Sent under the existing owner-notice setting.
 */
class RentalAuthorisationToOwnerMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $recipientName;
    public string $propertyAddress;
    public string $title;
    public ?string $note;
    public string $portalUrl;
    public bool $capturedForOwner;

    public function __construct(public RentalFaultReport|RentalWorkOrder $authorityFor, public RentalAuthorisation $row, public string $sentence, string $recipientName, ?string $recipientEmail = null, ?\App\Models\Contact $recipient = null)
    {
        $this->onQueue('mail');
        $this->recipientName = $recipientName ?: 'there';
        $this->propertyAddress = $authorityFor->property?->buildDisplayAddress() ?: ('Property #' . $authorityFor->property_id);
        $this->title = $authorityFor instanceof RentalFaultReport ? $authorityFor->ownerVersion()['title'] : (string) $authorityFor->title;
        $this->capturedForOwner = $row->basis === RentalAuthorisation::BASIS_OWNER_INSTRUCTION;
        $this->note = $this->capturedForOwner ? $row->note : null;
        $target = $authorityFor instanceof RentalFaultReport ? ['fault' => $authorityFor->id] : ['wo' => $authorityFor->id];
        $this->portalUrl = $recipientEmail
            ? \App\Support\PortalLink::forEmail($recipientEmail, \App\Support\PortalLink::VIEW_OWNER, $target, (int) $recipient?->id)
            : \App\Support\PortalLink::unaddressed(\App\Support\PortalLink::VIEW_OWNER, $target);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->capturedForOwner ? 'A decision was recorded for you' : 'Your agent sent someone to look at your property') . " - {$this->propertyAddress}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.authorisation-to-owner');
    }
}
