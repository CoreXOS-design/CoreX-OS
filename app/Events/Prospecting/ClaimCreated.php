<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;
use App\Models\ProspectingClaim;

/**
 * Fires when a prospecting_claim is created (direct claim button or upgrade
 * from pitch-lock). Spec §14.2.
 *
 * Distinct from the existing ProspectingClaimFlagged event (that one is the
 * BM-flag-to-bm action on a stale claim).
 */
final class ClaimCreated extends AbstractDomainEvent
{
    public function __construct(
        public readonly ProspectingClaim $claim,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return (int) $this->claim->agency_id; }
    public function actorUserId(): ?int { return (int) $this->claim->user_id; }

    public function subject(): ?array
    {
        return [ProspectingClaim::class, (int) $this->claim->id];
    }

    public function context(): array
    {
        return [
            'listing_id' => (int) $this->claim->prospecting_listing_id,
            'status'     => (string) $this->claim->status,
        ];
    }
}
