<?php

namespace App\Console\Commands;

use App\Services\Rentals\LeaseBreachService;
use Illuminate\Console\Command;

/**
 * .ai/specs/rental-notices.md §7b - daily: a breach notice whose remedy-by date has been reached and which is still open tells the lease's
 * agents ONCE ("Breach remedy date reached - was it fixed?"). The lease screen's Next bar asks the same question live, whether or not this
 * has run. Idempotent; agency by agency.
 *
 *   php artisan breaches:check-remedy-dates --dry-run        what WOULD be alerted, telling no one
 *   php artisan breaches:check-remedy-dates --lease=123      only that lease (real run)
 *   php artisan breaches:check-remedy-dates                  every agency (real run)
 */
class CheckBreachRemedyDates extends Command
{
    protected $signature = 'breaches:check-remedy-dates {--dry-run : List what would be alerted; tell no one} {--lease= : Only this lease id}';

    protected $description = 'Alert the lease agents once when a breach notice\'s remedy-by date has been reached and the breach is still open';

    public function handle(LeaseBreachService $service): int
    {
        $dry = (bool) $this->option('dry-run');
        $due = $service->dueForAlert(null, $this->option('lease') ? (int) $this->option('lease') : null);
        $told = 0;

        foreach ($due as $breach) {
            $line = "breach #{$breach->id} (agency {$breach->agency_id}, lease #{$breach->lease_id}) remedy by {$breach->remedy_deadline->format('Y-m-d')}";
            if ($dry) {
                $this->line("  WOULD ALERT: {$line}");
                continue;
            }
            $reached = $service->sendRemedyAlert($breach);
            $told++;
            $this->line("  ALERTED: {$line} - {$reached} agent(s)");
        }

        $this->info($dry ? "Dry run. Due: {$due->count()}. Nothing sent." : "Done. Due: {$due->count()}, alerted: {$told}.");

        return 0;
    }
}
