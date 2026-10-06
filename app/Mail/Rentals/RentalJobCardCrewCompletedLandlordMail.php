<?php

namespace App\Mail\Rentals;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\RentalJobCard;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/rental-work-orders.md §14.27.1 Q11 / §14.28 — the landlord is told
 * the crew has marked the work completed (by link or from the signed copy).
 * Sent AS the property's responsible agent through the agency mailbox path.
 * Says the agency will check the work — it does not close anything, and it
 * carries no prices or quote data.
 */
class RentalJobCardCrewCompletedLandlordMail extends BaseSignatureMail
{
    public function __construct(
        public RentalJobCard $jobCard,
        public string $landlordName,
        public string $signedByName,
        public string $completedOn,
        ?User $agent = null,
    ) {
        if ($agent) {
            $this->fromAgent($agent);
        }
    }

    public function envelope(): Envelope
    {
        $agency = $this->jobCard->agency?->trading_name ?: ($this->jobCard->agency?->name ?: 'Your agency');

        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "[{$agency}] Work completed: {$this->jobCard->title}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.crew-completed-landlord', with: [
            'agencyName' => $this->jobCard->agency?->trading_name ?: ($this->jobCard->agency?->name ?: 'Your agency'),
            'landlordName' => $this->landlordName,
            'title' => $this->jobCard->title,
            'address' => $this->jobCard->property?->buildDisplayAddress(),
            'signedByName' => $this->signedByName,
            'completedOn' => $this->completedOn,
            'footer' => $this->getAgentFooter(),
        ]);
    }
}
