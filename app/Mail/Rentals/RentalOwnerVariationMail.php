<?php

namespace App\Mail\Rentals;

use App\Models\RentalWorkOrderVariation;
use App\Models\RentalWorkOrder;
use App\Models\User;

/**
 * .ai/specs/rental-work-orders.md §17.7.4 / §17.16 — extra work (or a higher quote) beyond the owner's agreed terms
 * needs the owner's approval. The variation notice PDF is attached; the decision is made in the owner's portal (or the
 * office captures the owner's reply). On a revision of an open request ($isUpdate) the wording says the request changed.
 */
class RentalOwnerVariationMail extends RentalMaintenanceMail
{
    public function __construct(
        public RentalWorkOrderVariation $variation,
        public RentalWorkOrder $workOrder,
        public string $ownerName,
        ?string $pdfContents = null,
        ?string $pdfFilename = null,
        public bool $isUpdate = false,
        ?User $agent = null,
    ) {
        $this->pdfContents = $pdfContents;
        $this->pdfFilename = $pdfFilename;
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
        return ($this->isUpdate ? 'Updated request: extra work needs your approval' : 'Extra work needs your approval') . ' — ' . $this->address();
    }

    protected function viewName(): string
    {
        return 'emails.rentals.maintenance.owner-variation';
    }

    protected function viewData(): array
    {
        return [
            'ownerName' => $this->ownerName ?: 'there',
            'title' => $this->workOrder->title,
            'baseline' => number_format((float) $this->variation->baseline_amount, 2),
            'extra' => number_format((float) $this->variation->extra_amount, 2),
            'newTotal' => number_format((float) $this->variation->new_total, 2),
            'isUpdate' => $this->isUpdate,
            'termText' => (string) $this->variation->term_text,
            'portalUrl' => url('/portal'),
        ];
    }
}
