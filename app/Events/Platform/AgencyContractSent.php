<?php

declare(strict_types=1);

namespace App\Events\Platform;

use App\Events\AbstractDomainEvent;

/**
 * Fires when a platform contract is emailed to an agency signatory.
 *
 * Catalogue: .ai/specs/corex-domain-events-spec.md §5 (AT-447 additions).
 * Payload is SCALARS ONLY; keep listeners SYNC (see AgencyFeatureToggled).
 */
final class AgencyContractSent extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $agencyId,
        public readonly int $envelopeId,
        public readonly ?int $sentByUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->sentByUserId; }
}
