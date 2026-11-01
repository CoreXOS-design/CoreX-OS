<?php

declare(strict_types=1);

namespace App\Console\Commands\Property;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * AT-520/AT-528 (1 Nov): the stored alert "mandated" flags came from a parser that matched the word "Mandated" anywhere in the alert block (an agency is called
 * "Mandated"), and the emails are gone, so none can be verified. This clears them for one agency, journalled one line per row (id, old, new), and can put them back from
 * that journal. Dry run unless --apply. The stored sole-mandate wording flag is recomputed afterwards (mic:match-sole-mandate).
 */
final class ClearAlertMandateFlags extends Command
{
    protected $signature = 'mic:clear-alert-mandate {--agency= : Agency id (required)} {--apply : Really clear} {--restore= : Journal to put the flags back from} {--report-dir= : Where the journal goes}';

    protected $description = 'Clear the stored P24 / PP alert is_mandated flags for an agency (journalled, restorable)';

    private const TABLES = ['p24_listings', 'pp_alert_listings'];

    public function handle(): int
    {
        if ($this->option('restore')) {
            $n = 0;
            foreach (array_filter(array_map('trim', file((string) $this->option('restore')) ?: [])) as $line) {
                $e = json_decode($line, true);
                if (is_array($e) && in_array($e['table'] ?? '', self::TABLES, true)) {
                    $n += DB::table($e['table'])->where('id', $e['id'])->update(['is_mandated' => $e['old']]);
                }
            }
            $this->info("Restored {$n} flags.");

            return self::SUCCESS;
        }
        $agencyId = (int) $this->option('agency');
        if ($agencyId <= 0) {
            $this->error('Give --agency=<id>.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $dir = (string) ($this->option('report-dir') ?: storage_path('app/reports'));
        File::ensureDirectoryExists($dir);
        $journal = $apply ? fopen($dir . "/clear-alert-mandate-agency{$agencyId}-journal-" . now()->format('Ymd-His') . '.jsonl', 'ab') : null;
        foreach (self::TABLES as $table) {
            $q = DB::table($table)->where('agency_id', $agencyId)->where('is_mandated', 1);
            $ids = (clone $q)->pluck('id')->all();
            $this->line("{$table}: " . count($ids) . ' flagged');
            if ($apply && $ids) {
                foreach ($ids as $id) {
                    fwrite($journal, json_encode(['table' => $table, 'id' => (int) $id, 'column' => 'is_mandated', 'old' => 1, 'new' => 0]) . "\n");
                }
                DB::table($table)->whereIn('id', $ids)->update(['is_mandated' => 0]);
            }
        }
        $journal && fclose($journal);
        $this->info($apply ? 'Cleared.' : 'Dry run - nothing changed.');

        return self::SUCCESS;
    }
}
