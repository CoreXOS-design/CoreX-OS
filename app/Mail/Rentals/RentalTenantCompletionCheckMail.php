<?php

namespace App\Mail\Rentals;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/rental-work-orders.md §17.10.3 — "work at your home has been reported complete — please check it". Sent AS
 * the property's responsible agent through the agency mailbox path (RentalMailDispatcher), never a plain Mail::to().
 * Carries the one-click response link and the date the agency will treat silence as accepted. No prices, no owner data.
 */
class RentalTenantCompletionCheckMail extends BaseSignatureMail
{
    public function __construct(
        public RentalWorkCompletionRound $round,
        public RentalWorkOrder $workOrder,
        public string $tenantName,
        public string $url,
        ?User $agent = null,
        public ?\App\Models\Contact $recipient = null,
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
            subject: '[' . $this->agencyName() . '] Please check the work: ' . $this->workOrder->title,
        );
    }

    public function content(): Content
    {
        $tz = $this->workOrder->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');

        return new Content(view: 'emails.rentals.tenant-completion-check', with: [
            'agencyName' => $this->agencyName(),
            'tenantName' => $this->tenantName,
            'title' => $this->workOrder->title,
            'address' => $this->workOrder->property?->buildDisplayAddress(),
            // never a crew member's name: the team label on the agency's own crew (RentalWorkOrderClientViewService::reportedBy)
            'reportedBy' => app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)->reportedBy($this->round, $this->workOrder) ?: 'the maintenance crew',
            'reportedOn' => $this->round->opened_at?->copy()->setTimezone($tz)->format('j M Y'),
            'answerBy' => $this->round->window_ends_at?->copy()->setTimezone($tz)->format('j M Y'),
            'url' => $this->url,
            'portalUrl' => $this->recipient
                ? \App\Support\PortalLink::forContact($this->recipient, \App\Support\PortalLink::VIEW_TENANT, ['wo' => $this->workOrder->id])
                : \App\Support\PortalLink::unaddressed(\App\Support\PortalLink::VIEW_TENANT, ['wo' => $this->workOrder->id]),
            'footer' => $this->getAgentFooter(),
        ]);
    }
}
