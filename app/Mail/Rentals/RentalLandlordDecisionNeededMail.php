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
    /** section 22.4 - for a FAULT the agent has proposed someone for: who, and what the visit costs, in the email itself (null = nobody proposed / a work order). */
    public ?string $proposedName = null;
    public ?string $proposedCost = null;
    public bool $isFault = false;

    /** audit C-A1: 0 = the first mail; 1 / 2 = the first / second reminder (the same mail, said plainly as a reminder). */
    public int $reminderNumber = 0;

    public function __construct(public RentalFaultReport|RentalWorkOrder $decisionSubject, string $recipientName, ?string $recipientEmail = null, ?\App\Models\Contact $recipient = null, int $reminderNumber = 0)
    {
        $this->reminderNumber = $reminderNumber;
        $this->onQueue('mail');
        $this->recipientName = $recipientName ?: 'there';
        $this->propertyAddress = $decisionSubject->property?->buildDisplayAddress() ?: ('Property #' . $decisionSubject->property_id);
        // Fault flow F2: the owner is only ever told the agent's sanitised wording, never the tenant's original.
        $this->title = $decisionSubject instanceof RentalFaultReport ? $decisionSubject->ownerVersion()['title'] : $decisionSubject->title;
        $this->isFault = $decisionSubject instanceof RentalFaultReport;
        if ($this->isFault && ($proposed = app(\App\Services\Rentals\FaultInspectionService::class)->proposedFor($decisionSubject))) {
            $this->proposedName = (string) $proposed['name'];
            $cost = trim((string) ($proposed['callout'] ?? ''));
            $this->proposedCost = $cost === 'No call-out fee.' ? $this->proposedName . ' charges no call-out fee, so the visit costs you nothing.'
                : ($cost !== '' ? rtrim($cost, '.') . '.' : null);
        }
        // The button opens THAT fault (or work order) in the OWNER view with its decision controls, for THIS owner: built by PortalLink
        // (signed recipient reference + view + target), never by hand.
        $target = $decisionSubject instanceof RentalFaultReport ? ['fault' => $decisionSubject->id] : ['wo' => $decisionSubject->id];
        $this->portalUrl = $recipientEmail
            ? \App\Support\PortalLink::forEmail($recipientEmail, \App\Support\PortalLink::VIEW_OWNER, $target, (int) $recipient?->id)
            : \App\Support\PortalLink::unaddressed(\App\Support\PortalLink::VIEW_OWNER, $target);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->reminderNumber > 0 ? 'Reminder: ' : '') . ($this->isFault ? 'Your agent needs your go-ahead' : 'A decision is needed') . " — {$this->propertyAddress}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.landlord-decision-needed');
    }
}
