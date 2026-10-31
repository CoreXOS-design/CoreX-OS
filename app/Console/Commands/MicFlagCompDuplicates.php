<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MarketIntelligence\Comps\CompDuplicateFlagger;
use Illuminate\Console\Command;

/**
 * MIC step 5.3 (.ai/specs/mic-work-tab.md section 11.1, AT-524).
 *
 *   php artisan mic:flag-comp-duplicates                     # DRY RUN (default): counts + sample groups, nothing written
 *   php artisan mic:flag-comp-duplicates --apply             # flag repeats of the same CMA sale (journalled); also safe as a nightly pass
 *   php artisan mic:flag-comp-duplicates --rollback=<run_id> # undo a run from its journal
 *
 * Never deletes a row. Only rows not yet checked are placed; re-running changes nothing. QA only until Johan orders otherwise.
 */
final class MicFlagCompDuplicates extends Command
{
    protected $signature = 'mic:flag-comp-duplicates
                            {--apply : Write the flags (default is a dry run)}
                            {--agency= : Limit to one agency_id}
                            {--rollback= : Undo the run with this run_id}
                            {--report= : Also write the summary as JSON to this path}';

    protected $description = 'MIC 5.3: flag duplicate CMA sales (one canonical row per sale, repeats point at it) and presentation comps that are copies.';

    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'qa'];

    public function handle(CompDuplicateFlagger $flagger): int
    {
        if (! in_array(app()->environment(), self::ALLOWED_ENVIRONMENTS, true)) {
            $this->error('Refusing to run outside QA / local / testing (environment: ' . app()->environment() . ').');

            return self::FAILURE;
        }

        if ($runId = (string) $this->option('rollback')) {
            $n = $flagger->rollback($runId);
            $this->info("Rolled back run {$runId}: {$n} values put back.");

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $agency = $this->option('agency') !== null && $this->option('agency') !== '' ? (int) $this->option('agency') : null;
        $r = $flagger->run($agency, $apply);

        $this->line(($apply ? 'APPLIED' : 'DRY RUN') . '  run_id=' . $r['run_id'] . ($agency ? "  agency={$agency}" : ''));
        $this->line(sprintf('  sale rows (comp + subject, priced and dated): %d  | buckets to place: %d | rows placed: %d', $r['sale_rows'], $r['buckets_touched'], $r['rows_checked']));
        $this->line(sprintf('  repeats %s: %d in %d sales  | canonical sales after: %d', $apply ? 'flagged' : 'that would be flagged', $r['duplicates_flagged'], $r['groups_with_repeats'], $r['canonical_after']));
        $this->line('  repeats by row type: ' . json_encode($r['by_row_type']));
        $this->line(sprintf('  orphaned repeats re-homed: %d', $r['healed_orphans']));
        $this->line(sprintf('  presentation comps that are copies of a CMA row: %d (%d of them point at a soft-deleted CMA row)', $r['psc_copies'], $r['psc_copies_to_deleted_comp']));
        foreach ($r['samples'] as $s) {
            $this->line(sprintf('    %s R%s  canonical #%d  repeats %s  %s %s %s', $s['sale_date'], number_format($s['sale_price']), $s['canonical'], implode(',', $s['repeats']), $s['scheme'] ?? '', $s['section'] ?? '', $s['address'] ?? ''));
        }

        if ($path = (string) $this->option('report')) {
            file_put_contents($path, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line("Report written to {$path}");
        }

        return self::SUCCESS;
    }
}
