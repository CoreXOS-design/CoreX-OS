<?php

declare(strict_types=1);

namespace App\Events\Demo;

use App\Events\AbstractDomainEvent;
use App\Models\DemoAccessGrant;

/**
 * A demo access grant was issued to a prospect company.
 *
 * Spec: .ai/specs/demo-access-control.md §7 · corex-domain-events-spec.md §5
 *
 * Fired on PRIMARY, at issue. SendDemoAccessGrantEmail (queued) picks this up and
 * mails the credential — from PRIMARY's mailer. NEVER from the demo host, whose
 * mailer points at Mailpit: mail sent from there lands in a local catcher and the
 * prospect never receives it, silently.
 *
 * THE PLAINTEXT CODE IS REDACTED FROM THE AUDIT PAYLOAD.
 *
 * The listener genuinely needs the plaintext (it is the only moment it exists —
 * the DB holds only bcrypt(code)), so it must ride on the event. But
 * AbstractDomainEvent::payloadSnapshot() reflects over every public property and
 * writes the result into domain_event_log. A credential in an audit table is a
 * credential in every backup, log ship and support screenshot of that table,
 * forever. So payloadSnapshot() is overridden below to drop it — the mechanism
 * the base class explicitly sanctions ("concrete events may override to redact
 * sensitive data").
 *
 * This is a system-owner event, not a tenant one: agencyId() is null by design.
 */
class DemoAccessGranted extends AbstractDomainEvent
{
    public function __construct(
        public readonly DemoAccessGrant $grant,
        public readonly string $plaintextCode,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function actorUserId(): ?int
    {
        return $this->grant->issued_by_user_id;
    }

    public function subject(): ?array
    {
        return [DemoAccessGrant::class, $this->grant->getKey()];
    }

    /** Redact the credential. See the class docblock — this is the whole point. */
    public function payloadSnapshot(): array
    {
        $payload = parent::payloadSnapshot();

        $payload['plaintextCode'] = '[REDACTED]';

        return $payload;
    }

    public function context(): array
    {
        return [
            'company_name' => $this->grant->company_name,
            'expiry_hours' => $this->grant->expiry_hours,
        ];
    }
}
