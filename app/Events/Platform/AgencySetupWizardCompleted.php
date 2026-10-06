<?php

declare(strict_types=1);

namespace App\Events\Platform;

use App\Events\AbstractDomainEvent;

/**
 * Fires once, when an agency's Admin finishes the Agency Onboarding Setup Wizard. Subscribers: CompleteTimelineItemsOnTrigger (trigger setup_wizard_completed).
 *
 * Catalogue: .ai/specs/corex-domain-events-spec.md §5 (AT-447 additions).
 * Payload is SCALARS ONLY; keep listeners SYNC (see AgencyFeatureToggled).
 */
final class AgencySetupWizardCompleted extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $agencyId,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }
}
