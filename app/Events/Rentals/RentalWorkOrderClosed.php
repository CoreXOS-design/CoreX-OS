<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\RentalWorkOrder;

/**
 * A rental work order was closed (status = completed) — by the office Complete form, by the job-card close, or by a contractor link. Fires from inside RentalWorkOrder::complete(), so the owner's final statement (Build 2) is sent whichever route closed it. Spec: .ai/specs/rental-work-orders.md §17.16.
 */
final class RentalWorkOrderClosed extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalWorkOrder $workOrder,
        public readonly ?int $actorUserId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->workOrder->agency_id; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [RentalWorkOrder::class, $this->workOrder->id]; }

    public function context(): array
    {
        return ['assignment_type' => $this->workOrder->assignment_type, 'rental_job_card_id' => $this->workOrder->jobCard?->id];
    }
}
