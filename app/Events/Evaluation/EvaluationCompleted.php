<?php

declare(strict_types=1);

namespace App\Events\Evaluation;

use App\Events\AbstractDomainEvent;

/**
 * Fires once per evaluation, when the first feedback for it is captured (the evaluation was done).
 * Raised by CalendarEventFeedbackObserver::saved(); a second attendee's feedback, or an edit of the
 * first, does not raise it again. Spec: road-to-sold.md step 4.
 */
final class EvaluationCompleted extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $calendarEventId,
        public readonly int $feedbackId,
        public readonly ?int $agencyId,
        public readonly ?int $userId,
        public readonly ?int $propertyId,
        public readonly ?int $contactId,
        public readonly ?string $completedAt,
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
