<?php

declare(strict_types=1);

namespace App\Listeners\Billing;

use App\Events\Agent\AgencyHeadcountChanged;
use App\Models\Agency;
use App\Services\Billing\SubscriptionReconciler;
use Illuminate\Support\Facades\Log;

/**
 * Billing reacts to the Agent pillar: a user was added, deactivated, archived
 * or restored, so the agency may have crossed the plan threshold.
 *
 * Spec: .ai/specs/agency-billing.md §7.2  (AT-11)
 *
 * SYNC, deliberately. The work is one COUNT and — only on an actual plan
 * change — one UPDATE. That is well inside the domain-events performance
 * budget (§E10), and running it inline means the plan is already correct by the
 * time the request that hired the 11th agent renders its response. The
 * expensive part (the email) is what gets queued, downstream.
 *
 * FAILURE-ISOLATED. A billing hiccup must never break a user save — if an admin
 * cannot deactivate a resigning agent because our billing table is unhappy, we
 * have made their day worse to protect our invoice. Swallow, log, move on: the
 * nightly sweep will pick up whatever we missed.
 */
class ReconcileAgencySubscription
{
    public function __construct(private readonly SubscriptionReconciler $reconciler)
    {
    }

    public function handle(AgencyHeadcountChanged $event): void
    {
        try {
            $agency = Agency::find($event->affectedAgencyId);

            if (! $agency) {
                return;   // agency hard-deleted or never existed — nothing to bill
            }

            $this->reconciler->reconcile($agency);
        } catch (\Throwable $e) {
            Log::warning(
                "Billing reconcile failed for agency #{$event->affectedAgencyId}: {$e->getMessage()}",
                ['reason' => $event->reason, 'exception' => $e]
            );
        }
    }
}
