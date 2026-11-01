<?php

declare(strict_types=1);

namespace App\Console\Commands\Property;

use App\Services\Prospecting\Matching\UnwindContradictingFiles as Unwind;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * AT-520 4.13: split adverts that do not fit the property file they sit on back onto their own files (type family, bedrooms, price band, sizes - the
 * merge guard). Dry run by default: writes the report (JSON + CSV) and prints a summary. `--apply` moves the adverts (journalled); it needs Johan's go.
 */
final class UnwindContradictingFiles extends Command
{
    protected $signature = 'property-identity:unwind {--agency= : Agency id (required)} {--apply : Really move the adverts (default is a dry run)} {--report-dir= : Where the report and journal go} {--reverse= : Path of an unwind journal to put back (dry run unless --apply)} {--size-review= : Path of an unwind apply report: re-decide the groups moved only for size with today\'s rule and put the ones that no longer clash back (needs --reverse-journal)} {--reverse-journal= : The unwind journal that goes with --size-review}';

    protected $description = 'Split adverts that contradict their property file back onto their own files (dry run unless --apply)';

    public function handle(Unwind $unwind, TrackedPropertyMerger $merger): int
    {
        if (! app()->environment(['local', 'testing', 'qa'])) {
            $this->error('This only runs on QA / local / testing (APP_ENV is "' . app()->environment() . '").');

            return self::FAILURE;
        }
        if ($this->option('size-review')) {
            $agency = (int) $this->option('agency');
            $dir = (string) ($this->option('report-dir') ?: storage_path('app/reports'));
            $r = app(\App\Services\Prospecting\Matching\UnwindSizeReview::class)->run($agency, (string) $this->option('size-review'), (string) $this->option('reverse-journal'), (bool) $this->option('apply'),
                $dir . "/unwind-agency{$agency}-size-review-journal-" . now()->format('Ymd-His') . '.jsonl');
            $this->line(($this->option('apply') ? 'APPLIED ' : 'DRY RUN ') . json_encode($r));

            return self::SUCCESS;
        }
        if ($this->option('reverse')) {
            $r = app(\App\Services\Prospecting\Matching\UnwindReverser::class)->reverse((string) $this->option('reverse'), (bool) $this->option('apply'));
            $this->line(($this->option('apply') ? 'REVERSED ' : 'DRY RUN of the reversal ') . json_encode($r));

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
        $stamp = now()->format('Ymd-His');
        if ($apply) {
            $merger->journalTo($dir . "/unwind-agency{$agencyId}-journal-{$stamp}.jsonl");
        }
        $r = $unwind->run($agencyId, $apply);
        $merger->journalTo(null);

        $base = $dir . "/unwind-agency{$agencyId}-" . ($apply ? 'apply' : 'dry-run') . "-{$stamp}";
        File::put($base . '.json', json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $fh = fopen($base . '.csv', 'wb');
        fputcsv($fh, ['file_id', 'address', 'adverts_kept', 'adverts_moved', 'moved_to', 'reason']);
        foreach ($r['rows'] as $row) {
            foreach ($row['moved'] as $m) {
                fputcsv($fh, [$row['file'], $row['address'], implode(' ', $row['kept']), implode(' ', $m['adverts']), $m['to'], $m['reason']]);
            }
        }
        fclose($fh);

        $this->line(($apply ? 'APPLIED' : 'DRY RUN') . " agency {$agencyId}: files checked {$r['files_checked']}, files touched {$r['files_touched']}, adverts moved {$r['adverts_moved']} in {$r['groups_moved']} groups "
            . "(restored files {$r['to_restored_file']}, new files {$r['to_new_file']}); by reason " . json_encode($r['by_reason']));
        $this->info("Report: {$base}.csv / .json");

        return self::SUCCESS;
    }
}
