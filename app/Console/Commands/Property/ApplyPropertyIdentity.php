<?php

declare(strict_types=1);

namespace App\Console\Commands\Property;

use App\Services\Prospecting\PropertyIdentityApplier;
use App\Services\Prospecting\PropertyIdentityReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Property identity apply (.ai/specs/property-identity.md §16). One step at a time, dry run first:
 *
 *   php artisan property-identity:apply own-stock-joins --dry-run
 *   php artisan property-identity:apply own-stock-joins --apply
 *
 * Steps: own-stock-joins | advert-clashes | split-over-merged | share. The default mode is --dry-run (nothing written,
 * the diff is saved). --apply re-plans against the live data and writes only what the plan says, journalling every row
 * it moves. Refuses to run outside QA / local / testing. The test agency (20) is left out of every step.
 */
final class ApplyPropertyIdentity extends Command
{
    protected $signature = 'property-identity:apply
        {step : own-stock-joins | advert-clashes | split-over-merged | share}
        {--dry-run : Plan and save the diff, write nothing (the default)}
        {--apply : Write what the plan says}
        {--agency= : One agency id (default: every agency with data except the excluded ones)}
        {--exclude-agency=20 : Comma-separated agency ids to leave out (test / demo agencies)}
        {--journal-dir= : Where the row-level journal is written (default: storage/app/reports)}';

    protected $description = 'Apply one approved property-identity step (dry run first): joins, advert clashes, splits, share';

    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'qa'];

    public function handle(PropertyIdentityApplier $applier, PropertyIdentityReconciler $reconciler): int
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->error('This only runs on QA / local / testing (APP_ENV is "' . app()->environment() . '").');

            return self::FAILURE;
        }
        $step = (string) $this->argument('step');
        if (! in_array($step, PropertyIdentityApplier::STEPS, true)) {
            $this->error('Unknown step. Known: ' . implode(', ', PropertyIdentityApplier::STEPS));

            return self::FAILURE;
        }
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose --dry-run or --apply, not both.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $exclude = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('exclude-agency')))));
        $agencies = $this->option('agency') ? [(int) $this->option('agency')] : array_values(array_diff($reconciler->agencies(), $exclude));

        $stamp = now()->format('Ymd-His');
        $mode = $apply ? 'apply' : 'dry-run';
        $dir = storage_path('app/reports');
        File::ensureDirectoryExists($dir);
        $jdir = (string) ($this->option('journal-dir') ?: $dir);
        File::ensureDirectoryExists($jdir);
        if ($apply) {
            $applier->journalTo($jdir . "/property-identity-{$step}-journal-{$stamp}.jsonl");
        }

        $results = [];
        if ($step === 'share') {
            $this->line('Sharing (excluding agencies ' . implode(',', $exclude) . ') ...');
            $results['share'] = $applier->share($exclude, $apply);
        } else {
            foreach ($agencies as $agencyId) {
                $this->line("{$step}: agency {$agencyId} ...");
                $results[$agencyId] = match ($step) {
                    'own-stock-joins'   => $applier->ownStockJoins($agencyId, $apply),
                    'advert-clashes'    => $applier->advertClashes($agencyId, $apply),
                    'split-over-merged' => $applier->splitOverMerged($agencyId, $apply),
                };
            }
        }
        $applier->journalTo(null);

        $base = $dir . "/property-identity-apply-{$step}-{$mode}-{$stamp}";
        File::put($base . '.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $md = "# property-identity:apply {$step} - {$mode} (" . now()->toDateTimeString() . ', env ' . app()->environment() . ")\n\n";
        $md .= $apply ? "Written to the database.\n\n" : "Nothing was written. This is the diff an --apply run would make.\n\n";
        foreach ($results as $k => $r) {
            $md .= '## ' . (is_int($k) ? "Agency {$k}" : ucfirst((string) $k)) . "\n\n```json\n" . json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n```\n\n";
        }
        File::put($base . '.md', $md);

        $this->line($md);
        $this->info("Report written to {$base}.md");
        if ($apply) {
            $this->info("Journal: {$jdir}/property-identity-{$step}-journal-{$stamp}.jsonl");
        } else {
            $this->comment('Dry run: nothing was written to the database.');
        }

        return self::SUCCESS;
    }
}
