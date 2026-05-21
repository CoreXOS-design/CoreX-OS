<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;
use App\Models\ProspectingClaim;

/**
 * Fires when a sweeper job (Phase 5) or expiry-check detects a claim that
 * has gone stale (e.g. 48h+ with no feedback). Distinct from the existing
 * ProspectingClaimFlagged event — that one is a deliberate BM-flag-to-bm
 * action by a manager. This event is the *automatic* detection signal.
 *
 * Spec §14.2.
 */
final class ClaimFlaggedAsStale extends AbstractDomainEvent
{
    public function __construct(
        public readonly ProspectingClaim $claim,
        public readonly int $staleHours,
        public readonly string $detectionReason,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return (int) $this->claim->agency_id; }
    public function actorUserId(): ?int { return null; /* system-detected */ }

    public function subject(): ?array
    {
        return [ProspectingClaim::class, (int) $this->claim->id];
    }

    public function context(): array
    {
        return [
            'listing_id'        => (int) $this->claim->prospecting_listing_id,
            'claim_owner_id'    => (int) $this->claim->user_id,
            'stale_hours'       => $this->staleHours,
            'detection_reason'  => $this->detectionReason,
        ];
    }
}
