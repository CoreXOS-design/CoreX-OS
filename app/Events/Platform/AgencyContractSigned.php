<?php

declare(strict_types=1);

namespace App\Events\Platform;

use App\Events\AbstractDomainEvent;

/**
 * Fires when the Platform E-Sign document linked to an agency's timeline becomes fully signed (AgencyTimelineService::syncAgreement). Subscribers: CompleteTimelineItemsOnTrigger (trigger contract_signed).
 *
 * Catalogue: .ai/specs/corex-domain-events-spec.md §5 (AT-447 additions).
 * Payload is SCALARS ONLY; keep listeners SYNC (see AgencyFeatureToggled).
 */
final class AgencyContractSigned extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $agencyId,
        public readonly int $documentId,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }
}
