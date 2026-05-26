<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;
use App\Models\ProspectingClaim;

/**
 * Fires when a branch manager flags a stale prospecting claim for review.
 *
 * Defined in Build E.1 so the E.2 flag-to-BM endpoint can dispatch it the
 * moment that endpoint is wired. Recorded in domain_event_log via the
 * existing RecordDomainEvent listener.
 *
 * Spec: .ai/specs/build-e-suggested-action-chips-spec.md §8.7, §7 (R1).
 */
final class ProspectingClaimFlagged extends AbstractDomainEvent
{
    public function __construct(
        public readonly ProspectingClaim $claim,
        public readonly int $agencyId,
        public readonly int $flaggedByUserId,
        public readonly string $reason,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->agencyId;
    }

    public function actorUserId(): ?int
    {
        return $this->flaggedByUserId;
    }

    public function subject(): ?array
    {
        return [ProspectingClaim::class, $this->claim->id];
    }

    public function context(): array
    {
        return [
            'claim_status'   => (string) $this->claim->status,
            'reason'         => $this->reason,
            'claim_user_id'  => (int) $this->claim->user_id,
            'listing_id'     => (int) $this->claim->prospecting_listing_id,
        ];
    }
}
