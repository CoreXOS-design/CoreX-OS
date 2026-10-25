<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\RentalWorkOrder;

/**
 * A repair appointment was booked or changed on a work order, by staff or by the owner on the portal. AT-508 P1.
 * Dispatched by RentalWorkOrderService::setAppointment() after the change is saved.
 */
final class RentalAppointmentSet extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalWorkOrder $workOrder,
        public readonly bool $changed,
        public readonly string $setBy,   // 'staff' | 'owner'
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->workOrder->agency_id; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [RentalWorkOrder::class, $this->workOrder->id]; }

    public function context(): array
    {
        return ['changed' => $this->changed, 'set_by' => $this->setBy];
    }
}
