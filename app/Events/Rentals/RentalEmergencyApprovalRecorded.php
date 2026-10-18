<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\RentalEmergencyApproval;

/**
 * The office captured the owner's agreement to emergency work against a work order (no cost attached). There is no override of this agreement anywhere. Dispatched by Build 2. Spec §17.8.
 */
final class RentalEmergencyApprovalRecorded extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalEmergencyApproval $approval,
        public readonly ?int $actorUserId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->approval->agency_id; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [RentalEmergencyApproval::class, $this->approval->id]; }

    public function context(): array
    {
        return ['rental_work_order_id' => $this->approval->rental_work_order_id, 'approved_via' => $this->approval->approved_via];
    }
}
