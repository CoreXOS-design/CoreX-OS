<?php

declare(strict_types=1);

namespace App\Events\Webinars;

use App\Events\AbstractDomainEvent;
use App\Models\WebinarRegistration;

/**
 * A registrant was queued the webinar's joining link.
 *
 * Spec: .ai/specs/webinar-registration.md §4.4 · corex-domain-events-spec.md §5
 *
 * System-owner event; agencyId() is null by design.
 *
 * Fired by WebinarApiController::sendJoinLink() once per registration, immediately
 * after join_link_sent_at is stamped — so the event count and the stamp can never
 * disagree about what a person was sent. Without this, the join-link mail would be the
 * only webinar email invisible to domain_event_log, which is the log people reach for
 * when asked what a registrant was actually told.
 *
 * NO LISTENER, deliberately, exactly as with WebinarRegistered and WebinarReminderSent
 * (spec §6.5): the mail is queued directly by the controller. Event auto-discovery is
 * OFF in this codebase, so any listener added later must be registered explicitly in
 * AppServiceProvider::boot() — and must stay SYNCHRONOUS, queueing a Mailable rather
 * than itself, because a queued listener on a domain event fatals on deserialisation
 * (AbstractDomainEvent's parent-declared readonly properties cannot be restored from
 * the child scope).
 */
class WebinarJoinLinkSent extends AbstractDomainEvent
{
    public function __construct(
        public readonly WebinarRegistration $registration,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    /**
     * Sent by the marketing website's console over the site connector, which
     * authenticates a MACHINE, not a person — there is no CoreX user to attribute it
     * to, the same way the §4.3 admin writes have none.
     */
    public function actorUserId(): ?int
    {
        return null;
    }

    public function subject(): ?array
    {
        return [WebinarRegistration::class, $this->registration->getKey()];
    }
}
