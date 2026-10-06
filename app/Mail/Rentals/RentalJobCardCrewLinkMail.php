<?php

namespace App\Mail\Rentals;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\RentalJobCard;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/rental-work-orders.md §14.28 — "Email to crew": the per-job link,
 * sent AS the agent who pressed the button (fromAgent()), through the agency
 * mailbox path (RentalMailDispatcher), never a plain Mail::to()->send().
 * Neutral wording, agency-branded; no prices, no landlord, no quote data.
 */
class RentalJobCardCrewLinkMail extends BaseSignatureMail
{
    public function __construct(
        public RentalJobCard $jobCard,
        public string $url,
        public ?string $expiresOn,
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
            subject: "[{$agency}] Job: {$this->jobCard->title}",
        );
    }

    public function content(): Content
    {
        $property = $this->jobCard->property;

        return new Content(view: 'emails.rentals.crew-link', with: [
            'agencyName' => $this->jobCard->agency?->trading_name ?: ($this->jobCard->agency?->name ?: 'Your agency'),
            'title' => $this->jobCard->title,
            'address' => $property?->buildDisplayAddress(),
            'scheduledAt' => $this->jobCard->scheduled_at?->copy()->setTimezone($this->jobCard->agency?->outreachTimezone() ?: config('app.timezone'))->format('D j M Y, H:i'),
            'crewName' => $this->jobCard->crew?->name,
            'url' => $this->url,
            'expiresOn' => $this->expiresOn,
            'footer' => $this->getAgentFooter(),
        ]);
    }
}
