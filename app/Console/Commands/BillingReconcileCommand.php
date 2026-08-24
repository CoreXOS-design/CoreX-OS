<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Agency;
use App\Services\Billing\SubscriptionReconciler;
use Illuminate\Console\Command;

/**
 * Nightly safety net: bring every agency's stored plan in line with its live
 * headcount, and email us about any switch that the request-time listener
 * missed.
 *
 * Spec: .ai/specs/agency-billing.md §7.4  (AT-11)
 *
 * The listener on AgencyHeadcountChanged catches every plan change that goes
 * through the User model. This sweep catches everything that does NOT — a bulk
 * import, a `DB::table('users')->update(...)`, a hand-run SQL statement, a
 * restore that bypassed the observer. Those paths are real, and without this
 * command an agency could sit on the wrong plan indefinitely with nobody told.
 *
 * Safe to run as often as you like: SubscriptionReconciler's compare-and-set
 * means a no-op costs one COUNT per agency and emails nobody.
 */
class BillingReconcileCommand extends Command
{
    protected $signature = 'corex:billing-reconcile
                            {--dry-run : Report what would change without writing or emailing}';

    protected $description = 'Reconcile every agency\'s billing plan against its live active-user count (AT-11)';

    public function handle(SubscriptionReconciler $reconciler): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;
        $scanned = 0;

        if ($dryRun) {
            $this->warn('DRY RUN — no plans will be changed and no emails will be sent.');
        }

        Agency::query()->orderBy('name')->chunkById(100, function ($agencies) use ($reconciler, $dryRun, &$changed, &$scanned) {
            foreach ($agencies as $agency) {
                $scanned++;

                if ($dryRun) {
                    // Reproduce the decision without taking it.
                    $pricing = app(\App\Services\Billing\SubscriptionPricingService::class);
                    $seats   = $pricing->billableSeats($agency);
                    $derived = $pricing->derivePlan($seats);
                    $stored  = (string) \App\Models\Billing\AgencySubscription::forAgency((int) $agency->id)->plan;

                    if ($stored !== $derived) {
                        $changed++;
                        $this->line("  WOULD SWITCH  {$agency->name}: {$stored} → {$derived} ({$seats} users)");
                    }

                    continue;
                }

                $event = $reconciler->reconcile($agency);

                if ($event !== null) {
                    $changed++;
                    $this->line("  SWITCHED  {$agency->name}: {$event->fromPlan} → {$event->toPlan} ({$event->seats} users)");
                }
            }
        });

        $this->info("Scanned {$scanned} agencies; {$changed} plan change(s).");

        return self::SUCCESS;
    }
}
