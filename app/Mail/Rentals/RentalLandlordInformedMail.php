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
 * .ai/specs/rentals-faults-work-orders.md §19.10 (AT-452 round 2, item I) - the owner is INFORMED that their agent arranged a repair without
 * waiting (inside the property's no-approval limit, or as an emergency). Same channel as {@see RentalLandlordDecisionNeededMail}; nothing to decide.
 */
class RentalLandlordInformedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $recipientName;
    public string $propertyAddress;
    public string $title;
    public string $arrangedText;
    public string $portalUrl;

    public function __construct(public RentalFaultReport $fault, string $recipientName, ?string $recipientEmail = null, ?\App\Models\Contact $recipient = null)
    {
        $this->onQueue('mail');
        $this->recipientName = $recipientName ?: 'there';
        $this->propertyAddress = $fault->property?->buildDisplayAddress() ?: ('Property #' . $fault->property_id);
        $this->title = $fault->ownerVersion()['title'];
        $this->arrangedText = $fault->arrangedText() ?: 'Your agent arranged this repair.';
        $target = ['fault' => $fault->id];
        $this->portalUrl = $recipientEmail
            ? \App\Support\PortalLink::forEmail($recipientEmail, \App\Support\PortalLink::VIEW_OWNER, $target, (int) $recipient?->id)
            : \App\Support\PortalLink::unaddressed(\App\Support\PortalLink::VIEW_OWNER, $target);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your agent arranged a repair - {$this->propertyAddress}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.landlord-informed');
    }
}
