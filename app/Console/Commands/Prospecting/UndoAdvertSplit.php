<?php

declare(strict_types=1);

namespace App\Console\Commands\Prospecting;

use App\Services\Prospecting\Matching\AdvertSplitException;
use App\Services\Prospecting\Matching\AdvertSplitService;
use Illuminate\Console\Command;

/**
 * mic-work-tab.md 13.12: undo "Not the same property" splits from their journal (advert-split-agency<id>-journal-<date>.jsonl) - the same thing the popover's
 * Undo does. Every split in the journal is put back (newest first), or only `--split=<id>`; one already undone is skipped. Dry run unless `--apply`.
 */
final class UndoAdvertSplit extends Command
{
    protected $signature = 'mic:advert-split {--undo= : Path of the split journal} {--split= : Only this split id} {--apply : Really put it back (default is a dry run)}';

    protected $description = 'Undo "Not the same property" splits from their journal (dry run unless --apply)';

    public function handle(AdvertSplitService $svc): int
    {
        $path = (string) $this->option('undo');
        if ($path === '' || ! is_file($path)) {
            $this->error('Give --undo=<path of the split journal>.');

            return self::FAILURE;
        }
        $only = $this->option('split') !== null ? (int) $this->option('split') : null;
        $records = [];
        foreach (array_reverse(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
            $e = json_decode($line, true);
            if (is_array($e) && ($e['event'] ?? null) === 'split' && ($only === null || (int) $e['split_id'] === $only)) {
                $records[] = $e;
            }
        }
        $done = 0;
        $skipped = 0;
        foreach ($records as $e) {
            if (! $this->option('apply')) {
                $this->line("would undo split {$e['split_id']} (advert {$e['portal_ref']}, file {$e['from_file_id']} <- {$e['new_file_id']})");
                continue;
            }
            try {
                $svc->undo((int) $e['agency_id'], (int) $e['split_id'], null, $e);
                $done++;
            } catch (AdvertSplitException $x) {
                $skipped++;
                $this->line("split {$e['split_id']}: {$x->getMessage()}");
            }
        }
        $this->line(($this->option('apply') ? 'UNDONE ' : 'DRY RUN ') . json_encode(['splits' => count($records), 'undone' => $done, 'skipped' => $skipped]));

        return self::SUCCESS;
    }
}
