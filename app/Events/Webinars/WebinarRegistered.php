<?php

declare(strict_types=1);

namespace App\Events\Webinars;

use App\Events\AbstractDomainEvent;
use App\Models\WebinarRegistration;

/**
 * Someone registered for a webinar through the public form.
 *
 * Spec: .ai/specs/webinar-registration.md §6.5 · corex-domain-events-spec.md §5
 *
 * A system-owner event, not a tenant one: agencyId() is null by design — a webinar
 * registration is RR Technologies' sales data and belongs to no agency.
 *
 * NO LISTENER IN V1. The confirmation email is queued directly by
 * WebinarRegistrationService, because it carries the plaintext access code and the
 * code's lifetime is the transaction that mints it — routing it through a listener
 * would put a live credential on an extra hop for no gain. This event exists so the
 * registration lands in domain_event_log like every other fact in CoreX, and so
 * anything that later needs to react to a signup has a named contract to subscribe to
 * rather than inventing its own query path.
 *
 * If a listener IS added later it must be registered EXPLICITLY in
 * AppServiceProvider::boot() — event auto-discovery is off in this codebase — and it
 * must stay synchronous, queueing a Mailable rather than itself: a queued listener on
 * a domain event fatals on deserialisation, because SerializesModels cannot restore
 * AbstractDomainEvent's parent-declared readonly properties from the child scope.
 *
 * The event carries no credential. Unlike DemoAccessGranted — which must ship the
 * plaintext to its mailing listener and therefore redacts it from the audit payload —
 * there is nothing secret here to redact.
 */
class WebinarRegistered extends AbstractDomainEvent
{
    public function __construct(
        public readonly WebinarRegistration $registration,
        public readonly bool $wasReissue = false,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    /** Nobody was logged in — this came off a public form on the website. */
    public function actorUserId(): ?int
    {
        return null;
    }

    public function subject(): ?array
    {
        return [WebinarRegistration::class, $this->registration->getKey()];
    }
}
