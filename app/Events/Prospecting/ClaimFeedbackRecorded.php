<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;
use App\Models\ProspectingClaim;

/**
 * Fires when feedback is recorded against a prospecting claim
 * (contacted / meeting_set / listing / not_interested / lost). Spec §14.2.
 */
final class ClaimFeedbackRecorded extends AbstractDomainEvent
{
    public function __construct(
        public readonly ProspectingClaim $claim,
        public readonly string $newStatus,
        public readonly ?string $notes = null,
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
            'new_status' => $this->newStatus,
            'has_notes'  => $this->notes !== null && $this->notes !== '',
        ];
    }
}
