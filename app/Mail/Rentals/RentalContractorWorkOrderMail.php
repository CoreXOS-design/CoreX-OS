<?php

namespace App\Mail\Rentals;

use App\Models\RentalWorkOrder;
use App\Models\User;

/**
 * .ai/specs/rental-work-orders.md §17.9.5 / §17.16 — the work order sent to the outside contractor, showing that the
 * owner approved. The Work order PDF is attached. No tenant details, no owner contact details, no agency fee — the
 * contractor sees only their own quote. Replaces the plain RentalWorkOrderSupplierMail.
 */
class RentalContractorWorkOrderMail extends RentalMaintenanceMail
{
    public function __construct(
        public RentalWorkOrder $workOrder,
        public string $contractorName,
        string $pdfContents,
        string $pdfFilename,
        public string $ownerApprovalLine,
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
        return 'Work order — ' . $this->address();
    }

    protected function viewName(): string
    {
        return 'emails.rentals.maintenance.contractor-work-order';
    }

    protected function viewData(): array
    {
        return [
            'contractorName' => $this->contractorName ?: 'there',
            'title' => $this->workOrder->title,
            'description' => $this->workOrder->description,
            'trade' => $this->workOrder->trade_type ? ucfirst($this->workOrder->trade_type) : null,
            'ownerApprovalLine' => $this->ownerApprovalLine,
        ];
    }
}
