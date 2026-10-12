<?php

namespace App\Mail\Rentals;

use App\Models\RentalFaultReport;
use App\Models\RentalRecordedOwnerDecision;
use App\Models\RentalWorkOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * .ai/specs/rentals-faults-work-orders.md §20.4 (AT-452 round 3) - the safeguard: the moment an agent records a decision FOR the owner, the owner
 * is emailed the same sentence the portal shows ("Authorised by your agent <name> on your instruction, given by <phone call> on <date>."), with
 * the agent's note and a link to the portal where they can query it. Sent under the existing owner-notice setting.
 */
class RentalOwnerDecisionRecordedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $recipientName;
    public string $propertyAddress;
    public string $title;
    public string $note;
    public string $portalUrl;

    public function __construct(public RentalFaultReport|RentalWorkOrder $decisionSubject, public RentalRecordedOwnerDecision $row, public string $sentence, string $recipientName, ?string $recipientEmail = null, ?\App\Models\Contact $recipient = null)
    {
        $this->onQueue('mail');
        $this->recipientName = $recipientName ?: 'there';
        $this->propertyAddress = $decisionSubject->property?->buildDisplayAddress() ?: ('Property #' . $decisionSubject->property_id);
        $this->title = $decisionSubject instanceof RentalFaultReport ? $decisionSubject->ownerVersion()['title'] : (string) $decisionSubject->title;
        $this->note = $row->note;
        $target = $decisionSubject instanceof RentalFaultReport ? ['fault' => $decisionSubject->id] : ['wo' => $decisionSubject->id];
        $this->portalUrl = $recipientEmail
            ? \App\Support\PortalLink::forEmail($recipientEmail, \App\Support\PortalLink::VIEW_OWNER, $target, (int) $recipient?->id)
            : \App\Support\PortalLink::unaddressed(\App\Support\PortalLink::VIEW_OWNER, $target);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "A decision was recorded for you - {$this->propertyAddress}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.owner-decision-recorded');
    }
}
