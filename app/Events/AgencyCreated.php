<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Agency;
use App\Models\User;

/**
 * Fires when a NEW live agency (with its first Admin) is created via
 * Admin → Agency Management → Create.
 *
 * Spec: .ai/specs/agency-onboarding-setup.md §3.4
 * Catalogue: .ai/specs/corex-domain-events-spec.md §5
 *
 * When it fires:
 *   AgencyController@store(), AFTER the create transaction commits, guarded by
 *   `if ($adminPayload)` — so DEMO agencies (no admin) never emit it.
 *
 * Who emits it:
 *   The HTTP create action (explicitly), NOT the Eloquent `Agency::created`
 *   boot hook. The boot hook already fans role provisioning; this domain event
 *   is a separate, once-per-real-create signal that carries the admin's email
 *   for onboarding.
 *
 * Typical subscribers:
 *   - App\Listeners\Onboarding\CreateAgencySetupPortal (creates the setup
 *     record + emails the Admin the guided-setup link)
 *   - Audit\RecordDomainEvent (wildcard)
 */
final class AgencyCreated extends AbstractDomainEvent
{
    public function __construct(
        public readonly Agency $agency,
        public readonly ?User $adminUser = null,
        public readonly ?string $adminEmail = null,
        public readonly ?int $createdByUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agency->id; }
    public function actorUserId(): ?int { return $this->createdByUserId; }
    public function subject(): ?array { return [Agency::class, $this->agency->id]; }
}
