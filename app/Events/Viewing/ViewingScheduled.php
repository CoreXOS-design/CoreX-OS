<?php

declare(strict_types=1);

namespace App\Events\Viewing;

use App\Events\AbstractDomainEvent;

/**
 * Fires once when a buyer's viewing appointment is put in the calendar (category viewing / viewings / buyer_viewing). Raised by
 * CalendarEventObserver::created; an edit, a reschedule or a cancellation raises nothing. userId = the appointment's owner (the agent
 * taking the buyer out); actorUserId = who created it. Spec: road-to-sold.md 22.2 (stage "Viewing scheduled"), 22.8.
 */
final class ViewingScheduled extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $calendarEventId,
        public readonly ?int $propertyId,
        public readonly ?int $contactId,
        public readonly ?int $agencyId,
        public readonly ?int $branchId,
        public readonly ?int $userId,
        public readonly ?string $startsAt,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }

    public function subject(): ?array
    {
        return ['App\\Models\\CommandCenter\\CalendarEvent', $this->calendarEventId];
    }
}
