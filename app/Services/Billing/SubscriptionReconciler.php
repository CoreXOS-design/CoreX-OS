<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Events\Billing\AgencyPlanChanged;
use App\Models\Agency;
use App\Models\Billing\AgencySubscription;

/**
 * Keeps an agency's STORED plan in step with the plan its headcount implies,
 * and announces the switch exactly once.
 *
 * Spec: .ai/specs/agency-billing.md §7.1  (AT-11)
 *
 * Idempotent by construction. Called from three places, all of which may race
 * each other:
 *   1. Both billing pages, before render (so a page can never show a plan that
 *      disagrees with its own seat count).
 *   2. ReconcileAgencySubscription, on AgencyHeadcountChanged (so the email is
 *      prompt).
 *   3. The nightly `corex:billing-reconcile` sweep (so a path that bypasses the
 *      observer entirely — a bulk import, a raw UPDATE — is still caught).
 *
 * The compare-and-set in reconcile() is what makes those three safe to overlap.
 */
class SubscriptionReconciler
{
    public function __construct(private readonly SubscriptionPricingService $pricing)
    {
    }

    /**
     * Bring the stored plan in line with the derived plan. Returns the emitted
     * event when a switch happened, or null when nothing changed (the common
     * case, and a no-op).
     */
    public function reconcile(Agency $agency): ?AgencyPlanChanged
    {
        $subscription = AgencySubscription::forAgency((int) $agency->id);

        // No agency context (owner/console with no tenant) — forAgency() handed
        // back an unsaved in-memory default. Nothing to reconcile, and nothing
        // to write. STANDARDS Rule 17.
        if (! $subscription->exists) {
            return null;
        }

        $seats       = $this->pricing->billableSeats($agency);
        $derivedPlan = $this->pricing->derivePlan($seats);
        $storedPlan  = (string) $subscription->plan;

        if ($storedPlan === $derivedPlan) {
            return null;
        }

        // ── COMPARE-AND-SET ──────────────────────────────────────────────────
        // Flip the plan ONLY if it is still what we read a moment ago. If a
        // concurrent request (or the nightly sweep) beat us to it, this updates
        // 0 rows and we stay silent — so exactly one caller emits the event, and
        // Johan and I get exactly one email per switch rather than two.
        $affected = AgencySubscription::withoutGlobalScopes()
            ->whereKey($subscription->getKey())
            ->where('plan', $storedPlan)
            ->update([
                'plan'            => $derivedPlan,
                'plan_changed_at' => now(),
                'updated_at'      => now(),
            ]);

        if ($affected !== 1) {
            return null;   // someone else won the race and owns the notification
        }

        $branches = $this->pricing->branchCount($agency);

        $event = new AgencyPlanChanged(
            agency:             $agency,
            fromPlan:           $storedPlan,
            toPlan:             $derivedPlan,
            seats:              $seats,
            previousMonthlyZar: $this->monthlyFor($storedPlan, $seats, $branches),
            newMonthlyZar:      $this->monthlyFor($derivedPlan, $seats, $branches),
        );

        event($event);

        return $event;
    }

    /**
     * The list price under a given plan for this headcount — used to tell the
     * email "R4 500/month → R4 445/month". Deliberately the COMPUTED price, not
     * the payable one: the plan switch is about the list price, and folding a
     * discount into it would make the email misleading.
     */
    private function monthlyFor(string $plan, int $seats, int $branches): float
    {
        $lines = $this->pricing->lines($plan, $seats, $branches);

        return round(array_sum(array_column($lines, 'amount')), 2);
    }
}
