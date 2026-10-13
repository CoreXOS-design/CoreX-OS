<?php

namespace App\Mail\Rentals;

use App\Models\RentalWorkOrder;
use App\Models\User;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.19 (13 Oct 2026, Sherry walk) - a short plain note to the contractor when something
 * about THEIR job changes and they would otherwise only find out by opening the page: the appointment was set or moved, or the owner
 * answered their request for extra money. Sent AS the responsible agent through the agency mail path. No prices of the agency's own,
 * nothing about the owner beyond their answer. The contractor's private link (in the go-ahead email) carries the detail.
 */
class RentalContractorUpdateMail extends RentalMaintenanceMail
{
    public function __construct(
        public RentalWorkOrder $workOrder,
        public string $contractorName,
        public string $heading,
        public string $line,
        ?User $agent = null,
    ) {
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
        return $this->heading . ' - ' . $this->address();
    }

    protected function viewName(): string
    {
        return 'emails.rentals.maintenance.contractor-update';
    }

    protected function viewData(): array
    {
        return [
            'heading' => $this->heading,
            'contractorName' => $this->contractorName ?: 'there',
            'line' => $this->line,
            'title' => $this->workOrder->title,
        ];
    }
}
