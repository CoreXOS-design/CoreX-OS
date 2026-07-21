<?php

declare(strict_types=1);

namespace App\Events\Property;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AT-321 — raised when a property audit write throws. The property save ALWAYS
 * succeeds regardless; this event (plus a property_audit channel error log) makes
 * the swallowed failure visible so an audit gap is never silent.
 *
 * Deliberately a PLAIN event, not an AbstractDomainEvent: we are already inside a
 * failure path, so it must not depend on a second DB write (domain_event_log) that
 * could also be down.
 */
final class PropertyAuditWriteFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $propertyId,
        public readonly string $stage,      // e.g. 'observer', 'agent-merge', 'quiet-update'
        public readonly string $message,
    ) {
    }
}
