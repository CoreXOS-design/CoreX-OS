<?php

declare(strict_types=1);

namespace App\Events\Demo;

use App\Events\AbstractDomainEvent;
use App\Models\DemoAccessGrant;

/**
 * A prospect used their credential for the first time — the trial clock started.
 *
 * Spec: .ai/specs/demo-access-control.md §7
 *
 * Fired ONLY by the writer that won the conditional
 * `UPDATE … WHERE first_login_at IS NULL` (DemoAccessGrant::stampFirstLogin()).
 * Two tabs opening at once produce exactly ONE of these — which is the point: if
 * both fired, the audit log would claim the trial started twice.
 *
 * At emit time the grant is already refreshed, so expires_at is the real one.
 */
class DemoAccessFirstLogin extends AbstractDomainEvent
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
            'company_name'   => $this->grant->company_name,
            'first_login_at' => optional($this->grant->first_login_at)->toIso8601String(),
            'expires_at'     => optional($this->grant->expires_at)->toIso8601String(),
        ];
    }
}
