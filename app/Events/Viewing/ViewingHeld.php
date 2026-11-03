<?php

declare(strict_types=1);

namespace App\Events\Viewing;

use App\Events\AbstractDomainEvent;

/**
 * Fires when a buyer's viewing of ONE property is recorded as held: viewing feedback on a viewing appointment that says
 * "viewed" (the default), has been captured, is not blank and is not archived - the same rule PropertyViewings uses to count
 * a viewing. Raised by CalendarEventFeedbackObserver on the save that first makes the row count; "did not happen" / "declined
 * on arrival", a blank row, an edit of a row that already counted, and feedback on any other kind of appointment raise nothing.
 * One per buyer-and-property (a tour of four houses is four viewings held). userId = the appointment's owner (the agent who
 * took the buyer out); actorUserId = who captured the feedback. Spec: road-to-sold.md step 10.
 */
final class ViewingHeld extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $feedbackId,
        public readonly int $calendarEventId,
        public readonly ?int $propertyId,
        public readonly ?int $contactId,
        public readonly ?int $agencyId,
        public readonly ?int $branchId,
        public readonly ?int $userId,
        public readonly ?string $heldAt,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }

    public function subject(): ?array
    {
        return ['App\\Models\\CommandCenter\\CalendarEventFeedback', $this->feedbackId];
    }
}
