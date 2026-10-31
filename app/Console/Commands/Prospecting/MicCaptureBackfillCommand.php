<?php

declare(strict_types=1);

namespace App\Console\Commands\Prospecting;

use App\Services\Prospecting\MicCaptureBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * MIC capture back-fills (.ai/specs/mic-work-tab.md §11.2, step 3.5). One step at a time, dry run first:
 *
 *   php artisan mic:capture-backfill p24-groups --dry-run
 *   php artisan mic:capture-backfill p24-groups --apply --journal-dir=/mnt/HC_Volume_103099143/qa1-mic-capture-2026-10-31
 *
 * Steps: p24-groups | floor-sizes | sale-agency | erf-floor. Default is --dry-run (counts only). --apply journals every changed row.
 * Refuses to run outside QA / local / testing. The test agency (20) is left out.
 */
final class MicCaptureBackfillCommand extends Command
{
    protected $signature = 'mic:capture-backfill
        {step : p24-groups | floor-sizes | sale-agency | erf-floor}
        {--dry-run : Count what would change, write nothing (the default)}
        {--apply : Write the change and journal every row}
        {--exclude-agency=20 : Comma-separated agency ids to leave out (test / demo agencies)}
        {--journal-dir= : Where the row-level journal is written (default: storage/app/reports)}';

    protected $description = 'MIC capture back-fills: P24 groups from stored pages, floor sizes, owner adverts stored as agency "Sale" (dry run first)';

    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'qa'];

    public function handle(MicCaptureBackfill $backfill): int
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->error('This only runs on QA / local / testing (APP_ENV is "' . app()->environment() . '").');

            return self::FAILURE;
        }
        $step = (string) $this->argument('step');
        if (! in_array($step, MicCaptureBackfill::STEPS, true)) {
            $this->error('Unknown step. Known: ' . implode(', ', MicCaptureBackfill::STEPS));

            return self::FAILURE;
        }
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose --dry-run or --apply, not both.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $exclude = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('exclude-agency')))));

        $dir = (string) ($this->option('journal-dir') ?: storage_path('app/reports'));
        File::ensureDirectoryExists($dir);
        $stamp = now()->format('Ymd-His');
        $mode = $apply ? 'apply' : 'dry-run';
        if ($apply) {
            $backfill->journalTo($dir . "/mic-capture-backfill-{$step}-journal-{$stamp}.jsonl");
        }

        $result = match ($step) {
            'p24-groups'  => $backfill->p24Groups($apply, $exclude),
            'floor-sizes' => $backfill->floorSizes($apply, $exclude),
            'sale-agency' => $backfill->saleAgency($apply, $exclude),
            'erf-floor'   => $backfill->erfFloor($apply, $exclude),
        };
        $backfill->journalTo(null);

        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        File::put($dir . "/mic-capture-backfill-{$step}-{$mode}-{$stamp}.json", $json);
        $this->line("mic:capture-backfill {$step} - {$mode} (env " . app()->environment() . ')');
        $this->line($json);
        $this->info($apply ? 'Written to the database. Journal: ' . $dir . "/mic-capture-backfill-{$step}-journal-{$stamp}.jsonl" : 'Nothing was written.');

        return self::SUCCESS;
    }
}
