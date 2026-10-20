<?php

declare(strict_types=1);

namespace App\Events\Evaluation;

use App\Events\AbstractDomainEvent;

/**
 * Fires once when a property evaluation (calendar class `property_evaluation`) is booked, from any
 * place that creates one: the calendar, or Road to Sold's "evaluation booked" outcome. Raised by
 * CalendarEventObserver::created(). Never "valuation" - Johan's standing word rule.
 *
 * userId = the calendar owner (the agent who will do the evaluation). Spec: road-to-sold.md step 4.
 */
final class EvaluationBooked extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $calendarEventId,
        public readonly ?int $agencyId,
        public readonly ?int $userId,
        public readonly ?int $propertyId,
        public readonly ?int $contactId,
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
