<?php

namespace App\Mail\Rentals;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\RentalWorkOrder;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/rental-work-orders.md §17.10.6 — the office sends a disputed job back to the crew or the contractor: the
 * tenant's note and photo links (crew: also a fresh private link). Sent AS the agent who pressed "Send back" through
 * the agency mailbox path. Never carries prices, the owner, or the tenant's contact details.
 */
class RentalDisputeSentBackMail extends BaseSignatureMail
{
    /** @param array<int, string> $photoUrls */
    public function __construct(
        public RentalWorkOrder $workOrder,
        public ?string $recipientName,
        public string $tenantNote,
        public array $photoUrls,
        public ?string $crewLinkUrl,
        ?User $agent = null,
    ) {
        if ($agent) {
            $this->fromAgent($agent);
        }
    }

    private function agencyName(): string
    {
        $agency = $this->workOrder->agency;

        return $agency?->trading_name ?: ($agency?->name ?: 'Your agency');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: '[' . $this->agencyName() . '] Please revisit: ' . $this->workOrder->title,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.dispute-sent-back', with: [
            'agencyName' => $this->agencyName(),
            'recipientName' => $this->recipientName,
            'title' => $this->workOrder->title,
            'address' => $this->workOrder->property?->buildDisplayAddress(),
            'tenantNote' => $this->tenantNote,
            'photoUrls' => array_map(fn ($u) => str_starts_with((string) $u, 'http') ? $u : url((string) $u), $this->photoUrls),
            'crewLinkUrl' => $this->crewLinkUrl,
            'footer' => $this->getAgentFooter(),
        ]);
    }
}
