<?php

namespace App\Mail\Rentals;

use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderQuote;
use App\Models\User;

/**
 * .ai/specs/rental-work-orders.md §17.16 / §17.9.3 — a quote reaches the owner: a job card's quote the agent sent, or an
 * outside contractor's quote that is over the owner's no-approval limit. Carries the quote document, the OWNER-FACING
 * amount (never the contractor's own figure when the agency adds a fee), the estimate term and — when the amount needs
 * the owner's approval — the "decision needed" wording and a pointer to the portal. Replaces the old plain owner mails
 * (RentalWorkOrderOwnerMail created / quote revised) and the plain "decision needed" mail for work orders.
 */
class RentalOwnerQuoteMail extends RentalMaintenanceMail
{
    public function __construct(
        public RentalWorkOrder $workOrder,
        public RentalWorkOrderQuote $quote,
        public string $ownerName,
        public bool $needsDecision,
        ?string $documentContents = null,
        ?string $documentFilename = null,
        public string $termText = '',
        ?User $agent = null,
    ) {
        $this->pdfContents = $documentContents;
        $this->pdfFilename = $documentFilename;
        if ($agent) {
            $this->fromAgent($agent);
        }
    }

    protected function workOrderForMail(): RentalWorkOrder
    {
        return $this->workOrder;
    }

    protected function subjectText(): string
    {
        $revised = (int) $this->quote->revision > 1;

        return ($this->needsDecision ? 'Your approval is needed' : ($revised ? 'Revised quote' : 'Quote')) . ' — ' . $this->address();
    }

    protected function viewName(): string
    {
        return 'emails.rentals.maintenance.owner-quote';
    }

    protected function viewData(): array
    {
        return [
            'ownerName' => $this->ownerName ?: 'there',
            'title' => $this->workOrder->title,
            'description' => $this->workOrder->description,
            'amount' => number_format($this->quote->ownerFacingAmount(), 2),
            'revision' => (int) $this->quote->revision,
            'needsDecision' => $this->needsDecision,
            'hasDocument' => $this->pdfContents !== null,
            'termText' => $this->termText,
            'portalUrl' => url('/portal'),
        ];
    }
}
