<?php

namespace App\Mail\Rentals;

use App\Models\RentalFaultReport;
use App\Models\RentalWorkOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * .ai/specs/rental-portal-access.md §6 — AT-445. A fault report entering
 * owner-approval, or a work-order quote going over the spend limit —
 * either way, the landlord has a decision waiting in the portal.
 */
class RentalLandlordDecisionNeededMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $recipientName;
    public string $propertyAddress;
    public string $title;
    public string $portalUrl;

    public function __construct(public RentalFaultReport|RentalWorkOrder $decisionSubject, string $recipientName, ?string $recipientEmail = null)
    {
        $this->onQueue('mail');
        $this->recipientName = $recipientName ?: 'there';
        $this->propertyAddress = $decisionSubject->property?->buildDisplayAddress() ?: ('Property #' . $decisionSubject->property_id);
        // Fault flow F2: the owner is only ever told the agent's sanitised wording, never the tenant's original.
        $this->title = $decisionSubject instanceof RentalFaultReport ? $decisionSubject->ownerVersion()['title'] : $decisionSubject->title;
        // The button opens THAT fault (or work order) in the OWNER view with its decision controls, for THIS owner: built by PortalLink
        // (signed recipient reference + view + target), never by hand.
        $target = $decisionSubject instanceof RentalFaultReport ? ['fault' => $decisionSubject->id] : ['wo' => $decisionSubject->id];
        $this->portalUrl = $recipientEmail
            ? \App\Support\PortalLink::forEmail($recipientEmail, \App\Support\PortalLink::VIEW_OWNER, $target)
            : \App\Support\PortalLink::unaddressed(\App\Support\PortalLink::VIEW_OWNER, $target);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "A decision is needed — {$this->propertyAddress}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.landlord-decision-needed');
    }
}
