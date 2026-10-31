<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MarketIntelligence\Suburbs\SuburbIdBackfill;
use Illuminate\Console\Command;

/**
 * MIC step 5.1 (.ai/specs/mic-work-tab.md section 8, AT-519).
 *
 *   php artisan mic:backfill-suburb-ids                     # DRY RUN (default): counts + unresolved list, nothing written
 *   php artisan mic:backfill-suburb-ids --apply             # fill p24_suburb_id where it is NULL (journalled)
 *   php artisan mic:backfill-suburb-ids --rollback=<run_id> # undo a run from its journal
 *
 * Fills only NULL ids; never changes an existing one; unresolvable text stays NULL and is listed. QA only until Johan orders otherwise.
 */
final class MicBackfillSuburbIds extends Command
{
    protected $signature = 'mic:backfill-suburb-ids
                            {--apply : Write the ids (default is a dry run)}
                            {--agency= : Limit to one agency_id}
                            {--table=all : all | properties | tracked_properties | prospecting_listings | market_report_comp_rows}
                            {--rollback= : Undo the run with this run_id}
                            {--report= : Also write the summary as JSON to this path}';

    protected $description = 'MIC 5.1: fill p24_suburb_id (our p24_suburbs.id) from suburb text on properties, tracked files, MIC listings and CMA comp rows.';

    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'qa'];

    public function handle(SuburbIdBackfill $service): int
    {
        if (! in_array(app()->environment(), self::ALLOWED_ENVIRONMENTS, true)) {
            $this->error('Refusing to run outside QA / local / testing (environment: ' . app()->environment() . ').');

            return self::FAILURE;
        }

        if ($runId = (string) $this->option('rollback')) {
            $n = $service->rollback($runId);
            $this->info("Rolled back run {$runId}: {$n} rows set back to NULL.");

            return self::SUCCESS;
        }

        $tables = $this->option('table') === 'all'
            ? array_keys(SuburbIdBackfill::TARGETS)
            : [(string) $this->option('table')];
        foreach ($tables as $t) {
            if (! isset(SuburbIdBackfill::TARGETS[$t])) {
                $this->error("Unknown table '{$t}'.");

                return self::FAILURE;
            }
        }

        $apply = (bool) $this->option('apply');
        $agency = $this->option('agency') !== null && $this->option('agency') !== '' ? (int) $this->option('agency') : null;
        $report = $service->run($tables, $agency, $apply);

        $this->line(($apply ? 'APPLIED' : 'DRY RUN') . '  run_id=' . $report['run_id'] . ($agency ? "  agency={$agency}" : ''));
        foreach ($report['tables'] as $table => $r) {
            $this->line('');
            $this->info($table);
            $this->line(sprintf('  empty id rows: %d (%d distinct texts) -> %s %d, unresolved %d rows / %d texts',
                $r['before']['null_rows'], $r['before']['distinct_texts'], $apply ? 'filled' : 'would fill', $r['would_fill'], $r['unresolved_rows'], $r['unresolved_texts']));
            if ($r['after'] !== null) {
                $this->line('  after: ' . $r['after']['null_rows'] . ' rows still empty');
            }
            $this->line('  by method: ' . json_encode($r['by_method']));
            foreach ($r['top_targets'] as $label => $n) {
                $this->line(sprintf('    %-34s %d', $label, $n));
            }
            foreach (array_slice($r['unresolved_top'], 0, 8) as $u) {
                $this->line(sprintf('    UNRESOLVED  agency %d  "%s"  %d rows', $u['agency_id'], $u['text'], $u['rows']));
            }
        }

        if ($path = (string) $this->option('report')) {
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line("Report written to {$path}");
        }

        return self::SUCCESS;
    }
}
