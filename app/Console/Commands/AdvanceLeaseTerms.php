<?php

namespace App\Console\Commands;

use App\Services\Rentals\LeaseTermAdvanceService;
use Illuminate\Console\Command;

/**
 * .ai/specs/rental-renewals.md §22 - a lease term changes state ON ITS DATE. Daily, before the month-to-month check:
 *   - a renewal signed and approved earlier goes active on its start date (the lease it renews expires with it);
 *   - a tenancy whose move-out / end date has come and whose out-inspection confirmed the property vacant ends (property back to its pre-let status).
 * Idempotent; every agency.
 *
 *   php artisan leases:advance-terms --dry-run     what WOULD change, changing nothing
 *   php artisan leases:advance-terms --lease=123   only that lease
 *   php artisan leases:advance-terms --date=2027-11-01   as if today were that day (testing)
 */
class AdvanceLeaseTerms extends Command
{
    protected $signature = 'leases:advance-terms {--dry-run : List what would change; change nothing} {--lease= : Only this lease id} {--date= : Treat this Y-m-d as today}';

    protected $description = 'Activate signed renewals on their start date and end tenancies on their move-out date (out-inspection on file)';

    public function handle(LeaseTermAdvanceService $service): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = $this->option('lease') ? (int) $this->option('lease') : null;
        $today = $this->option('date') ?: now()->toDateString();

        $renewals = $service->activateDueRenewals($today, $dry, $only);
        $ended = $service->endVacatedTenancies($today, $dry, $only);

        foreach ($renewals as $r) {
            $this->line("  RENEWAL #{$r['lease_id']}: {$r['action']}" . ($r['note'] ? " - {$r['note']}" : ''));
        }
        foreach ($ended as $r) {
            $this->line("  TENANCY #{$r['lease_id']}: {$r['action']}");
        }

        $activated = count(array_filter($renewals, fn ($r) => in_array($r['action'], ['activated', 'would_activate'], true)));
        $ends = count(array_filter($ended, fn ($r) => in_array($r['action'], ['ended', 'would_end'], true)));
        $this->info(($dry ? 'Dry run. ' : '') . "Renewals due: {$activated}. Tenancies ended: {$ends}." . ($dry ? ' Nothing changed.' : ''));

        return 0;
    }
}
