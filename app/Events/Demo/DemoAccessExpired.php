<?php

declare(strict_types=1);

namespace App\Events\Demo;

use App\Events\AbstractDomainEvent;
use App\Models\DemoAccessGrant;

/**
 * The gate turned away a grant whose trial has run out.
 *
 * Spec: .ai/specs/demo-access-control.md §7
 *
 * OBSERVED, not scheduled. There is no cron that expires grants, because status
 * is derived, never stored (§4.2) — a grant becomes expired the moment the clock
 * passes expires_at, whether or not anyone is watching. This event fires when the
 * gate actually *notices*, i.e. when the prospect next tries to get in.
 *
 * That means it can fire more than once for the same grant (they keep trying).
 * That is correct and intentional: each one is a real, distinct fact — "a
 * prospect was turned away at this instant" — and a sales signal worth having.
 * Nothing downstream should treat it as a once-per-grant transition.
 */
class DemoAccessExpired extends AbstractDomainEvent
{
    public function __construct(
        public readonly DemoAccessGrant $grant,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function subject(): ?array
    {
        return [DemoAccessGrant::class, $this->grant->getKey()];
    }

    public function context(): array
    {
        return [
            'company_name' => $this->grant->company_name,
            'expired_at'   => optional($this->grant->expires_at)->toIso8601String(),
        ];
    }
}
