<?php

namespace App\Mail\Rentals;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\RentalWorkOrder;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/rental-work-orders.md §17.10.6 / §17.16 — the owner is told the tenant reported the finished work as not
 * complete, with the tenant's words and photo links, and that the office is arranging a fix. Sent AS the property's
 * responsible agent through the agency mailbox path. No amounts, no cost, no tenant contact details.
 */
class RentalLandlordDisputeMail extends BaseSignatureMail
{
    /** @param array<int, string> $photoUrls */
    public function __construct(
        public RentalWorkOrder $workOrder,
        public string $landlordName,
        public string $tenantNote,
        public array $photoUrls,
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
            subject: '[' . $this->agencyName() . '] Work reported as not complete: ' . $this->workOrder->title,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.landlord-dispute', with: [
            'agencyName' => $this->agencyName(),
            'landlordName' => $this->landlordName,
            'title' => $this->workOrder->title,
            'address' => $this->workOrder->property?->buildDisplayAddress(),
            'tenantNote' => $this->tenantNote,
            // NOT named `photoUrls`: a public property of a Mailable OVERRIDES a same-named key of with() (see RentalDisputeSentBackMail).
            'photoLinks' => array_map(fn ($u) => str_starts_with((string) $u, 'http') ? $u : url((string) $u), $this->photoUrls),
            'footer' => $this->getAgentFooter(),
        ]);
    }
}
