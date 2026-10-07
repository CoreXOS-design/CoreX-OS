<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\Lease;

/**
 * A lease agreement was signed and accepted (the agent's final approval). Spec: .ai/specs/leases.md §15.15
 * (Build L1 — declared; Build L3b emits it from UpdateLeaseSigningState).
 */
final class LeaseAgreementSigned extends AbstractDomainEvent
{
    public function __construct(
        public readonly Lease $lease,
        public readonly ?int $signatureTemplateId,
        public readonly ?int $actorUserId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->lease->agency_id; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [Lease::class, $this->lease->id]; }
}
