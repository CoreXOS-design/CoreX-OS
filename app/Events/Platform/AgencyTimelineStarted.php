<?php

declare(strict_types=1);

namespace App\Events\Platform;

use App\Events\AbstractDomainEvent;

/**
 * Fires when the platform owner starts an onboarding timeline for an agency (AgencyTimelineService::start).
 *
 * Catalogue: .ai/specs/corex-domain-events-spec.md §5 (AT-447 additions).
 * Payload is SCALARS ONLY; keep listeners SYNC (see AgencyFeatureToggled).
 */
final class AgencyTimelineStarted extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $agencyId,
        public readonly int $timelineId,
        public readonly ?int $startedByUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->startedByUserId; }
}
