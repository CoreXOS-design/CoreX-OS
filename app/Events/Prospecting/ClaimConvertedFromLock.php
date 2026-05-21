<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;
use App\Models\ProspectingClaim;

/**
 * Fires when a temp pitch-lock is upgraded to a permanent claim — i.e. the
 * pitch was actually sent and the agent's stake on the listing becomes durable.
 * Spec §14.2.
 */
final class ClaimConvertedFromLock extends AbstractDomainEvent
{
    public function __construct(
        public readonly ProspectingClaim $claim,
        public readonly ?int $temporaryLockId = null,
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
            'listing_id'       => (int) $this->claim->prospecting_listing_id,
            'temporary_lock_id' => $this->temporaryLockId,
        ];
    }
}
