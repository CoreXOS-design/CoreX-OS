<?php

declare(strict_types=1);

namespace App\Events\Agent;

use App\Events\AbstractDomainEvent;
use App\Models\User;

/**
 * An agency's active-user count may have changed.
 *
 * Spec: .ai/specs/agency-billing.md §7.2  (AT-11)
 * Catalogue: .ai/specs/corex-domain-events-spec.md §5
 *
 * When it fires:
 *   UserObserver — on created, on `is_active` flipping, on soft-delete, on
 *   restore. Anything that can add or remove a *billable seat*.
 *
 * Why it exists:
 *   Billing must react to the Agent pillar without reaching into it
 *   (non-negotiable #9). The Agent pillar announces a fact about itself; the
 *   Billing pillar subscribes. No ad-hoc service call crosses the boundary.
 *
 * Note the "may have" — this event is a HINT, not a measurement. It carries no
 * seat count, because the count is authoritative only when read live from the
 * users table. Subscribers recount. That is deliberate: a stale number in an
 * event payload is a misbilled agency.
 *
 * Typical subscribers:
 *   - App\Listeners\Billing\ReconcileAgencySubscription
 *   - Audit\RecordDomainEvent (wildcard)
 */
final class AgencyHeadcountChanged extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $affectedAgencyId,
        public readonly ?User $user = null,
        public readonly string $reason = 'changed',   // created|activated|deactivated|deleted|restored
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->affectedAgencyId;
    }

    public function subject(): ?array
    {
        return $this->user ? [User::class, $this->user->id] : null;
    }
}
