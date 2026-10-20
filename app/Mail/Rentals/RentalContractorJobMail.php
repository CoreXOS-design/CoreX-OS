<?php

namespace App\Mail\Rentals;

use App\Models\RentalWorkOrder;
use App\Models\User;

/**
 * .ai/specs/rentals-faults-work-orders.md §19 (AT-452 round 2) - the contractor is told the moment they are appointed: the job, where it is,
 * how to get in, and the one private link where they see the photos (with the office's marks and notes), accept or decline, and upload a
 * quote. Sent AS the agent who appointed them, through the agency mail path. No owner details, no prices; the tenant's name and phone only
 * when the agency has switched that on for its crews (the same setting).
 */
class RentalContractorJobMail extends RentalMaintenanceMail
{
    public function __construct(
        public RentalWorkOrder $workOrder,
        public string $contractorName,
        public string $url,
        public ?string $expiresOn,
        public int $photoCount,
        public ?string $accessNotes,
        public ?string $tenantLine,
        public ?string $agentLine,
        ?User $agent = null,
        // AT-459 — structured copies of the same facts, so the email can offer a tap-to-call number and an Open in Maps link
        public ?array $tenants = null,
        public ?array $access = null,
        public ?string $mapUrl = null,
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
        return 'Job request — ' . $this->address();
    }

    protected function viewName(): string
    {
        return 'emails.rentals.maintenance.contractor-job';
    }

    protected function viewData(): array
    {
        // .ai/specs/rentals-faults-work-orders.md §20.4 - the call-out fee on our records, stated only when the supplier charges one
        $supplier = $this->workOrder->supplier()->withoutGlobalScopes()->withTrashed()->first();
        $fee = $supplier?->calloutFeeAmount();

        return [
            'calloutFee' => $fee !== null ? \App\Models\DealV2\AgencyServiceProvider::formatRand($fee) : null,
            'calloutNote' => $supplier?->calloutFeeNote(),
            'lookAndQuote' => app(\App\Services\Rentals\RentalWhoPaysService::class)->isFaultJob($this->workOrder),
            'contractorName' => $this->contractorName ?: 'there',
            'title' => $this->workOrder->title,
            'description' => $this->workOrder->description,
            'trade' => $this->workOrder->trade_type ? ucfirst($this->workOrder->trade_type) : null,
            'url' => $this->url,
            'expiresOn' => $this->expiresOn,
            'photoCount' => $this->photoCount,
            'accessNotes' => $this->accessNotes,
            'tenantLine' => $this->tenantLine,
            'tenants' => $this->tenants ?? [],
            'access' => $this->access,
            'mapUrl' => $this->mapUrl,
            'agentLine' => $this->agentLine,
        ];
    }
}
