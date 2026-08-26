<?php

declare(strict_types=1);

namespace App\Events\Webinars;

use App\Events\AbstractDomainEvent;
use App\Models\WebinarRegistration;

/**
 * A registrant's pre-webinar reminder was queued.
 *
 * Spec: .ai/specs/webinar-registration.md §6.5 · corex-domain-events-spec.md §5
 *
 * System-owner event; agencyId() is null by design.
 *
 * Fired by SendWebinarReminders once per registration, immediately after
 * reminder_sent_at is stamped — so the event count and the stamp can never disagree
 * about how many reminders a person was sent.
 */
class WebinarReminderSent extends AbstractDomainEvent
{
    public function __construct(
        public readonly WebinarRegistration $registration,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    /** Sent by the scheduler, not by a person. */
    public function actorUserId(): ?int
    {
        return null;
    }

    public function subject(): ?array
    {
        return [WebinarRegistration::class, $this->registration->getKey()];
    }
}
