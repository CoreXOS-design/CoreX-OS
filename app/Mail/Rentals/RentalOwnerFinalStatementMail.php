<?php

namespace App\Mail\Rentals;

use App\Models\RentalWorkOrder;
use App\Models\User;

/**
 * .ai/specs/rental-work-orders.md §17.8.3 / §17.16 — the owner is told the work is closed and given the final statement
 * (selling figures only; "Approved as emergency work on {date}" when it was). Sent whichever route closed the work order
 * (the RentalWorkOrderClosed event). Replaces the old plain "work completed" owner mail.
 */
class RentalOwnerFinalStatementMail extends RentalMaintenanceMail
{
    public function __construct(
        public RentalWorkOrder $workOrder,
        public string $ownerName,
        string $pdfContents,
        string $pdfFilename,
        public ?string $emergencyBanner = null,
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
        return 'Work completed — final statement — ' . $this->address();
    }

    protected function viewName(): string
    {
        return 'emails.rentals.maintenance.owner-final-statement';
    }

    protected function viewData(): array
    {
        return [
            'ownerName' => $this->ownerName ?: 'there',
            'title' => $this->workOrder->title,
            'amount' => $this->workOrder->cost_amount !== null ? number_format((float) $this->workOrder->cost_amount, 2) : null,
            'emergencyBanner' => $this->emergencyBanner,
        ];
    }
}
