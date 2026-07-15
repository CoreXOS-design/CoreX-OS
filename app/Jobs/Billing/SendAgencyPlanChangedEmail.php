<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Mail\Billing\AgencyPlanChangedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails andre@ + johan@ that an agency's plan auto-switched.
 *
 * Spec: .ai/specs/agency-billing.md §7.5  (AT-11)
 *
 * WHY A JOB AND NOT A QUEUED LISTENER — read before "simplifying" this away:
 *
 * The obvious shape is `NotifyCoreXOfPlanChange implements ShouldQueue`. It does
 * not work. AbstractDomainEvent declares its metadata (`eventId`, `occurredAt`,
 * `traceId`) as `readonly`, and PHP only permits a readonly property to be
 * initialized from the scope that DECLARES it. When a queued listener is
 * serialized and later rehydrated, Laravel's SerializesModels restores
 * properties by reflection from the SUBCLASS scope — so rehydrating any
 * AbstractDomainEvent subclass throws:
 *
 *     Cannot initialize readonly property App\Events\AbstractDomainEvent::$eventId
 *     from scope App\Events\Billing\AgencyPlanChanged
 *
 * So the listener stays SYNC and dispatches this job, which carries only
 * SCALARS. Nothing about a domain event ever touches the queue.
 *
 * That is not merely a workaround — it is the better payload anyway. A queued
 * job holding an Agency model re-queries it on rehydration, so an agency
 * archived between the switch and the send would blow the job up with
 * ModelNotFoundException. Scalars captured at emit time cannot rot.
 *
 * ⚠ DO NOT set `public string $queue`. The live and staging workers run
 *   `queue:work` with NO --queue flag, so they drain `default` and nothing else.
 *   A job pushed to a named queue is not slow — it is stranded forever, and the
 *   email silently never arrives.
 */
class SendAgencyPlanChangedEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public readonly int $agencyId,
        public readonly string $agencyName,
        public readonly string $fromPlan,
        public readonly string $toPlan,
        public readonly int $seats,
        public readonly float $previousMonthlyZar,
        public readonly float $newMonthlyZar,
        public readonly string $occurredAt,
    ) {
    }

    public function handle(): void
    {
        $recipients = array_values(array_filter(
            (array) config('corex-billing.notify.plan_change_recipients', [])
        ));

        if ($recipients === []) {
            Log::warning('Agency plan changed but no billing notification recipients are configured.', [
                'agency_id' => $this->agencyId,
            ]);

            return;
        }

        // The 'corex' mailer delivers via real SMTP from mail@corexos.co.za
        // regardless of the DEFAULT mailer — which is intentionally 'log' on
        // staging. Sending on the default mailer would drop this into
        // laravel.log and we would never know it hadn't arrived.
        Mail::mailer((string) config('corex-billing.notify.mailer', 'corex'))
            ->to($recipients)
            ->send(new AgencyPlanChangedMail(
                agencyId:           $this->agencyId,
                agencyName:         $this->agencyName,
                fromPlan:           $this->fromPlan,
                toPlan:             $this->toPlan,
                seats:              $this->seats,
                previousMonthlyZar: $this->previousMonthlyZar,
                newMonthlyZar:      $this->newMonthlyZar,
                occurredAt:         $this->occurredAt,
            ));
    }

    /**
     * All retries exhausted. The plan change itself is already persisted and
     * correct — we have lost a notification, not money. Log loudly enough that
     * it is findable, and let it go.
     */
    public function failed(\Throwable $e): void
    {
        Log::error("Agency plan-change email failed permanently for agency #{$this->agencyId}: {$e->getMessage()}", [
            'from_plan' => $this->fromPlan,
            'to_plan'   => $this->toPlan,
            'exception' => $e,
        ]);
    }
}
