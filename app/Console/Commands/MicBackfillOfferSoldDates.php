<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Properties\PropertyOfferSoldDates;
use Illuminate\Console\Command;

/**
 * MIC step 5.4 (.ai/specs/mic-work-tab.md section 11.4, AT-524).
 *
 *   php artisan mic:backfill-offer-sold-dates                     # DRY RUN (default): counts + days-to-sale per suburb, nothing written
 *   php artisan mic:backfill-offer-sold-dates --apply             # fill empty under_offer_at / sold_at from audit events after the agency's trusted-sales date; fix sold_stock backfill-day dates (journalled)
 *   php artisan mic:backfill-offer-sold-dates --rollback=<run_id> # undo a run from its journal
 *
 * Fills only empty columns, never overwrites; events before the trusted-sales date (MicAgencySetting, default 26 Jun 2026), console fixes and import
 * stamps are ignored. QA only until Johan orders otherwise.
 */
final class MicBackfillOfferSoldDates extends Command
{
    protected $signature = 'mic:backfill-offer-sold-dates
                            {--apply : Write the dates (default is a dry run)}
                            {--agency= : Limit to one agency_id}
                            {--rollback= : Undo the run with this run_id}
                            {--report= : Also write the summary as JSON to this path}';

    protected $description = 'MIC 5.4: back-fill properties.under_offer_at / sold_at from trustworthy audit events and correct sold_stock rows that carry the backfill day.';

    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'qa'];

    public function handle(PropertyOfferSoldDates $service): int
    {
        if (! in_array(app()->environment(), self::ALLOWED_ENVIRONMENTS, true)) {
            $this->error('Refusing to run outside QA / local / testing (environment: ' . app()->environment() . ').');

            return self::FAILURE;
        }

        if ($runId = (string) $this->option('rollback')) {
            $n = $service->rollback($runId);
            $this->info("Rolled back run {$runId}: {$n} values put back.");

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $agency = $this->option('agency') !== null && $this->option('agency') !== '' ? (int) $this->option('agency') : null;
        $r = $service->backfill($agency, $apply);

        $this->line(($apply ? 'APPLIED' : 'DRY RUN') . '  run_id=' . $r['run_id'] . '  trusted-sales dates: ' . json_encode($r['agencies']));
        $this->line(sprintf('  status-change events read: %d (console fixes ignored %d, import stamps ignored %d); properties skipped because the log does not end at their status: %d',
            $r['events_considered'], $r['events_excluded_console'], $r['events_excluded_import'], $r['skipped_mismatch']));
        $this->line(sprintf('  under_offer_at %s: %d   sold_at %s: %d   sold_stock rows %s: %d', $apply ? 'filled' : 'would fill', $r['under_offer_filled'], $apply ? 'filled' : 'would fill', $r['sold_filled'], $apply ? 'corrected' : 'would correct', $r['sold_stock_corrected']));
        $this->line('  busiest days: ' . json_encode($r['event_days']));
        $this->line('  days to sale by suburb (listed date -> sold_at; after the run):');
        foreach ($r['by_suburb'] as $s) {
            $this->line(sprintf('    %-26s sales %3d  avg %5s days to sale   offers %3d  avg %5s days to offer', $s['suburb'], $s['sales'], $s['avg_days_to_sale'], $s['with_offer'], $s['avg_days_to_offer'] ?? '-'));
        }

        if ($path = (string) $this->option('report')) {
            file_put_contents($path, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line("Report written to {$path}");
        }

        return self::SUCCESS;
    }
}
