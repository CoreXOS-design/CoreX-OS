<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;
use App\Models\ProspectingClaim;

/**
 * Fires when a sweeper job auto-releases a claim after the configured stale
 * window (typically 7 days, per spec). Distinct from ClaimReleased (manual)
 * and ClaimFlaggedAsStale (flagged but not yet released).
 *
 * Spec §14.2.
 */
final class ClaimAutoReleased extends AbstractDomainEvent
{
    public function __construct(
        public readonly ProspectingClaim $claim,
        public readonly int $staleDays,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return (int) $this->claim->agency_id; }
    public function actorUserId(): ?int { return null; /* system-released */ }

    public function subject(): ?array
    {
        return [ProspectingClaim::class, (int) $this->claim->id];
    }

    public function context(): array
    {
        return [
            'listing_id'     => (int) $this->claim->prospecting_listing_id,
            'claim_owner_id' => (int) $this->claim->user_id,
            'stale_days'     => $this->staleDays,
        ];
    }
}
