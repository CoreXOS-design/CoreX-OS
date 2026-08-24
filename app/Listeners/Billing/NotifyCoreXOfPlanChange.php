<?php

declare(strict_types=1);

namespace App\Listeners\Billing;

use App\Events\Billing\AgencyPlanChanged;
use App\Jobs\Billing\SendAgencyPlanChangedEmail;
use Illuminate\Support\Facades\Log;

/**
 * An agency's plan auto-switched — tell CoreX (andre@ + johan@).
 *
 * Spec: .ai/specs/agency-billing.md §7.5  (AT-11, decision D3)
 *
 * SYNC, and it must stay sync. This listener does no work itself: it flattens
 * the event into scalars and hands them to SendAgencyPlanChangedEmail, which is
 * the queued half. The SMTP round trip never sits in the request that hired the
 * 11th agent.
 *
 * ⚠ DO NOT make this listener `implements ShouldQueue`. Queuing a listener
 *   serializes the EVENT, and AbstractDomainEvent's readonly metadata cannot be
 *   rehydrated from a subclass scope — it throws "Cannot initialize readonly
 *   property AbstractDomainEvent::$eventId". The full explanation, and why the
 *   scalar payload is the better design anyway, is in SendAgencyPlanChangedEmail's
 *   docblock.
 *
 * FAILURE-ISOLATED: dispatching the notification must never break the user save
 * that triggered the plan change.
 */
class NotifyCoreXOfPlanChange
{
    public function handle(AgencyPlanChanged $event): void
    {
        try {
            SendAgencyPlanChangedEmail::dispatch(
                agencyId:           (int) $event->agency->id,
                agencyName:         (string) ($event->agency->name ?? 'Unknown agency'),
                fromPlan:           $event->fromPlan,
                toPlan:             $event->toPlan,
                seats:              $event->seats,
                previousMonthlyZar: $event->previousMonthlyZar,
                newMonthlyZar:      $event->newMonthlyZar,
                occurredAt:         $event->occurredAt->format('Y-m-d H:i:s'),
            );
        } catch (\Throwable $e) {
            Log::error("Could not queue the agency plan-change email for agency #{$event->agency->id}: {$e->getMessage()}", [
                'from_plan' => $event->fromPlan,
                'to_plan'   => $event->toPlan,
                'exception' => $e,
            ]);
        }
    }
}
