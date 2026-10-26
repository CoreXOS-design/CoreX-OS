<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\RentalFaultReport;
use App\Models\RentalWorkOrder;

/**
 * A decision is waiting for the property's owner (a fault sent for approval, a quote or variation over the owner's limit), or the owner
 * is being chased about one ($reminderNumber 1 / 2). AT-508 P1 — the owner's phone is told through this, whatever the email setting says.
 */
final class RentalOwnerDecisionNeeded extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalFaultReport|RentalWorkOrder $subject,
        public readonly int $reminderNumber = 0,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->subject->agency_id; }
    public function actorUserId(): ?int { return null; }
    public function subject(): ?array { return [$this->subject::class, $this->subject->id]; }

    public function context(): array
    {
        return ['reminder_number' => $this->reminderNumber];
    }
}
