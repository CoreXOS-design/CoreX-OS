<?php

namespace App\Console\Commands\Rentals;

use App\Models\RentalInspectionScan;
use App\Services\Rentals\RentalInspectionScanReaderService;
use Illuminate\Console\Command;

/**
 * AT-463 / spec §62 - read a scan that is already stored again with the CURRENT reader (after a reader fix, or after the
 * form's paper size was corrected). Nothing is applied to any inspection - the result is a fresh review list; marks already
 * applied by an agent are left untouched, only still-unapplied proposals are replaced.
 *
 *   php artisan rental-inspections:reread-scan 1 --restore
 */
class RereadInspectionScan extends Command
{
    protected $signature = 'rental-inspections:reread-scan {scan : the scan id} {--restore : un-archive the scan first (an archived scan is not reachable from the inspection)}';

    protected $description = 'Re-read a stored inspection site-form scan with the current reader (proposals only - nothing is applied)';

    public function handle(RentalInspectionScanReaderService $reader): int
    {
        $scan = RentalInspectionScan::withoutGlobalScopes()->withTrashed()->find($this->argument('scan'));
        if (! $scan) {
            $this->error('No such scan.');

            return self::FAILURE;
        }
        if ($scan->trashed()) {
            if (! $this->option('restore')) {
                $this->warn('That scan is archived. Re-reading it will not make it reachable again - pass --restore to un-archive it.');
            } else {
                $scan->restore();
                $scan->forceFill(['archived_by_user_id' => null])->save();
                $this->info('Scan un-archived.');
            }
        }

        $reader->reread($scan->fresh());
        $scan = RentalInspectionScan::withoutGlobalScopes()->withTrashed()->find($scan->id);

        $this->line("Scan #{$scan->id} (inspection #{$scan->rental_inspection_id}): {$scan->status}");
        foreach ($scan->page_results ?? [] as $page) {
            $this->line('  page ' . $page['page'] . ($page['form_page'] ? ' = form page ' . $page['form_page'] : '') . ': ' . $page['status']
                . (isset($page['rows']) ? ", {$page['rows']} rows, {$page['proposed']} proposed, {$page['flagged']} to check" : '')
                . (! empty($page['reason']) ? ' - ' . $page['reason'] : ''));
        }
        if ($scan->failure_reason) {
            $this->warn($scan->failure_reason);
        }

        return $scan->status === RentalInspectionScan::STATUS_NEEDS_REVIEW ? self::SUCCESS : self::FAILURE;
    }
}
