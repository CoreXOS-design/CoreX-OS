<?php

declare(strict_types=1);

namespace App\Events\Audit;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AT-321-C — pillar-agnostic sibling of PropertyAuditWriteFailed. Raised when an
 * audit write throws for any audited pillar (currently: contact). The subject save
 * ALWAYS succeeds regardless; this event (plus a per-pillar channel error log)
 * makes the swallowed failure visible so an audit gap is never silent.
 *
 * Deliberately a PLAIN event, not an AbstractDomainEvent: we are already inside a
 * failure path, so it must not depend on a second DB write (domain_event_log) that
 * could also be down.
 */
final class AuditWriteFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $pillar,     // e.g. 'contact'
        public readonly int $subjectId,     // e.g. the contact id
        public readonly string $stage,      // e.g. 'observer', 'agent-assigned', 'quiet-update'
        public readonly string $message,
    ) {
    }
}
