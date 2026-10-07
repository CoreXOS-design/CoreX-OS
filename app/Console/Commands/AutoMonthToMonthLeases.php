<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Services\Rentals\LeaseAutoMonthToMonthService;
use Illuminate\Console\Command;

/**
 * .ai/specs/leases.md §5.3 — daily: an active lease that has reached its end date (per the agency's own setting) with no
 * notice to vacate and no renewal on record goes month-to-month on its own. Idempotent; agency by agency.
 *
 *   php artisan leases:auto-month-to-month --dry-run          what WOULD switch, changing nothing
 *   php artisan leases:auto-month-to-month --lease=123        only that lease (real run)
 *   php artisan leases:auto-month-to-month                    every agency (real run)
 */
class AutoMonthToMonthLeases extends Command
{
    protected $signature = 'leases:auto-month-to-month {--dry-run : List what would switch; change nothing} {--lease= : Only this lease id}';

    protected $description = 'Switch leases that reached their end date with no notice and no renewal on record to month-to-month (per agency setting)';

    public function handle(LeaseAutoMonthToMonthService $service): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = $this->option('lease') ? (int) $this->option('lease') : null;
        $due = 0;
        $switched = 0;

        foreach (Agency::all() as $agency) {
            foreach ($service->dueFor($agency, null, $only) as $lease) {
                $due++;
                $line = "lease #{$lease->id} (agency {$agency->id}) ended {$lease->end_date->format('Y-m-d')}";
                if ($dry) {
                    $this->line("  WOULD SWITCH: {$line}");
                    continue;
                }
                if ($service->convert($lease)) {
                    $switched++;
                    $this->line("  SWITCHED: {$line} — now month-to-month");
                }
            }
        }

        $this->info($dry ? "Dry run. Due to switch: {$due}. Nothing changed." : "Done. Due: {$due}, switched: {$switched}.");

        return 0;
    }
}
