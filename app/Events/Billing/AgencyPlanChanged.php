<?php

declare(strict_types=1);

namespace App\Events\Billing;

use App\Events\AbstractDomainEvent;
use App\Models\Agency;

/**
 * An agency's plan auto-switched because its headcount crossed the threshold.
 *
 * Spec: .ai/specs/agency-billing.md §7  (AT-11)
 * Catalogue: .ai/specs/corex-domain-events-spec.md §5
 *
 * When it fires:
 *   SubscriptionReconciler, and ONLY after a compare-and-set update actually
 *   changed the stored plan. That CAS is what makes this event — and therefore
 *   the email — exactly-once: if a page render and the nightly sweep reconcile
 *   the same agency at the same instant, only the writer that won the update
 *   emits. See SubscriptionReconciler::reconcile().
 *
 * This is a past-tense fact: the plan HAS changed and is persisted by the time
 * subscribers see it.
 *
 * Typical subscribers:
 *   - App\Listeners\Billing\NotifyCoreXOfPlanChange (emails andre@ + johan@)
 *   - Audit\RecordDomainEvent (wildcard)
 */
final class AgencyPlanChanged extends AbstractDomainEvent
{
    public function __construct(
        public readonly Agency $agency,
        public readonly string $fromPlan,
        public readonly string $toPlan,
        public readonly int $seats,
        public readonly float $previousMonthlyZar,
        public readonly float $newMonthlyZar,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->agency->id;
    }

    public function subject(): ?array
    {
        return [Agency::class, $this->agency->id];
    }

    /** Did they move up a plan (more seats) or down? */
    public function isUpgrade(): bool
    {
        return $this->toPlan === 'agency' && $this->fromPlan === 'team';
    }
}
