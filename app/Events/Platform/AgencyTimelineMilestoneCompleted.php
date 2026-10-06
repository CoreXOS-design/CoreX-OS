<?php

declare(strict_types=1);

namespace App\Events\Platform;

use App\Events\AbstractDomainEvent;

/**
 * Fires when a timeline item is marked done (manually or by an auto-complete trigger).
 *
 * Catalogue: .ai/specs/corex-domain-events-spec.md §5 (AT-447 additions).
 * Payload is SCALARS ONLY; keep listeners SYNC (see AgencyFeatureToggled).
 */
final class AgencyTimelineMilestoneCompleted extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $agencyId,
        public readonly int $timelineId,
        public readonly int $itemId,
        public readonly string $source,
        public readonly ?int $completedByUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->completedByUserId; }
}
