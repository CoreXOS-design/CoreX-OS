<?php

declare(strict_types=1);

namespace App\Console\Commands\Property;

use App\Services\Prospecting\PropertyIdentityReconciler;
use App\Services\Prospecting\SameAddressSweep;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Same-address sweep (.ai/specs/property-identity.md section 17). Dry run first:
 *
 *   php artisan property-identity:same-address no-suburb --dry-run     (M2: files with no suburb)
 *   php artisan property-identity:same-address exact --apply           (M3 / M4: a suburb on every file)
 *
 * Houses and stands with the same number + street + unit merge; flats with no unit number and big blocks go to the review list. The default mode
 * is --dry-run (the diff is saved); --apply journals every row it moves. Refuses to run outside QA / local / testing. Agency 20 is left out.
 */
final class SweepSameAddress extends Command
{
    protected $signature = 'property-identity:same-address
        {scope : no-suburb | exact | all}
        {--dry-run : Plan and save the diff, write nothing (the default)}
        {--apply : Merge houses and stands, queue flats for review}
        {--agency= : One agency id (default: every agency with data except the excluded ones)}
        {--exclude-agency=20 : Comma-separated agency ids to leave out (test / demo agencies)}
        {--journal-dir= : Where the row-level journal is written (default: storage/app/reports)}';

    protected $description = 'Merge property files that share number + street + unit (houses/stands); send flats and blocks to the review list';

    public function handle(SameAddressSweep $sweep, PropertyIdentityReconciler $reconciler, TrackedPropertyMerger $merger): int
    {
        if (! app()->environment(['local', 'testing', 'qa'])) {
            $this->error('This only runs on QA / local / testing (APP_ENV is "' . app()->environment() . '").');

            return self::FAILURE;
        }
        $scope = (string) $this->argument('scope');
        if (! in_array($scope, SameAddressSweep::SCOPES, true)) {
            $this->error('Unknown scope. Known: ' . implode(', ', SameAddressSweep::SCOPES));

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
            $merger->journalTo($jdir . "/same-address-{$scope}-journal-{$stamp}.jsonl");
        }

        $results = [];
        foreach ($agencies as $agencyId) {
            $this->line("same-address {$scope}: agency {$agencyId} ...");
            $results[$agencyId] = $sweep->run($agencyId, $scope, $apply, 'same-address-' . $scope);
        }
        $merger->journalTo(null);

        $base = $dir . "/same-address-{$scope}-{$mode}-{$stamp}";
        File::put($base . '.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        foreach ($results as $agencyId => $r) {
            $this->line("Agency {$agencyId}: {$r['groups']} groups, {$r['files']} files; merged {$r['merged_files']}, refused {$r['merge_refused']}, review pairs {$r['review_pairs']}, flat groups {$r['flat_groups']}, big groups {$r['big_groups']}, vetoed {$r['vetoed']}");
        }
        $this->info("Report written to {$base}.json");
        if (! $apply) {
            $this->comment('Dry run: nothing was written to the database.');
        }

        return self::SUCCESS;
    }
}
