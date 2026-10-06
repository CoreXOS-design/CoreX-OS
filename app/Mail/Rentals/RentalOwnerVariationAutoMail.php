<?php

namespace App\Mail\Rentals;

use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderVariation;
use App\Models\User;

/**
 * .ai/specs/rental-work-orders.md §17.7.2 / §17.16 — information only: extra work was added and, because it falls within
 * the owner's agreed work terms, it was approved automatically (agency setting notify_landlord_on_auto_variation).
 * Says what was added, the new total and which agreed term it fell within. Selling figures only.
 */
class RentalOwnerVariationAutoMail extends RentalMaintenanceMail
{
    /** @param array<int, array{description: string, quantity: string, total: string}> $lines */
    public function __construct(
        public RentalWorkOrderVariation $variation,
        public RentalWorkOrder $workOrder,
        public string $ownerName,
        public array $lines,
        public string $withinTerm,
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
        return 'Extra work added within your agreed terms — ' . $this->address();
    }

    protected function viewName(): string
    {
        return 'emails.rentals.maintenance.owner-variation-auto';
    }

    protected function viewData(): array
    {
        return [
            'ownerName' => $this->ownerName ?: 'there',
            'title' => $this->workOrder->title,
            'lines' => $this->lines,
            'extra' => number_format((float) $this->variation->extra_amount, 2),
            'newTotal' => number_format((float) $this->variation->new_total, 2),
            'withinTerm' => $this->withinTerm,
            'termText' => (string) $this->variation->term_text,
        ];
    }
}
