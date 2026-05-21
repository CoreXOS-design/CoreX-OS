<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;
use App\Models\ProspectingClaim;

/**
 * Fires when a claim is released by its owner or a BM/admin (with reason).
 * Spec §14.2.
 */
final class ClaimReleased extends AbstractDomainEvent
{
    public function __construct(
        public readonly ProspectingClaim $claim,
        public readonly int $releasedByUserId,
        public readonly ?string $reason = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return (int) $this->claim->agency_id; }
    public function actorUserId(): ?int { return $this->releasedByUserId; }

    public function subject(): ?array
    {
        return [ProspectingClaim::class, (int) $this->claim->id];
    }

    public function context(): array
    {
        return [
            'listing_id'       => (int) $this->claim->prospecting_listing_id,
            'claim_owner_id'   => (int) $this->claim->user_id,
            'released_by_self' => $this->releasedByUserId === (int) $this->claim->user_id,
            'reason'           => $this->reason,
        ];
    }
}
