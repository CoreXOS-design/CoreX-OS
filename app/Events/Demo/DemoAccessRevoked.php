<?php

declare(strict_types=1);

namespace App\Events\Demo;

use App\Events\AbstractDomainEvent;
use App\Models\DemoAccessGrant;

/**
 * An owner withdrew a demo access grant.
 *
 * Spec: .ai/specs/demo-access-control.md §7
 *
 * Fired once, on the transition from not-revoked to revoked (re-revoking a
 * revoked grant is a no-op and emits nothing).
 *
 * The block takes effect on the demo host within the gate cache TTL (≤60s) —
 * not instantly. The admin UI says so on the confirm dialog. Do not imply a kill
 * we cannot deliver.
 */
class DemoAccessRevoked extends AbstractDomainEvent
{
    public function __construct(
        public readonly DemoAccessGrant $grant,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function actorUserId(): ?int
    {
        return $this->grant->revoked_by_user_id;
    }

    public function subject(): ?array
    {
        return [DemoAccessGrant::class, $this->grant->getKey()];
    }

    public function context(): array
    {
        return [
            'company_name' => $this->grant->company_name,
            'revoked_at'   => optional($this->grant->revoked_at)->toIso8601String(),
        ];
    }
}
