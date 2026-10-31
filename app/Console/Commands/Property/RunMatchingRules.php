<?php

declare(strict_types=1);

namespace App\Console\Commands\Property;

use App\Services\Prospecting\Matching\MatchingRules;
use App\Services\Prospecting\PropertyIdentityReconciler;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * AT-520 4.4 (property-identity.md 17.4): the matching rules by hand, dry run first.
 *
 *   php artisan property-identity:matching-rules portal-group --dry-run
 *   php artisan property-identity:matching-rules all --apply
 *
 * Rules: portal-group (P24's own groups), twin (same agency, other portal), complex, map-point. Each respects its agency settings. QA / local / testing only;
 * the agencies in --exclude-agency (the test agency) are left out; --apply journals every row it moves.
 */
final class RunMatchingRules extends Command
{
    protected $signature = 'property-identity:matching-rules
        {rule : portal-group | twin | complex | map-point | all}
        {--dry-run : Count and queue nothing (the default)}
        {--apply : Join / group / queue}
        {--agency= : One agency id}
        {--exclude-agency=20 : Comma-separated agency ids to leave out}
        {--journal-dir= : Where the row journal is written (default: storage/app/reports)}';

    protected $description = 'Run the P24-group, same-agency-twin, complex and map-point matching rules (dry run first)';

    public function handle(MatchingRules $rules, PropertyIdentityReconciler $reconciler, TrackedPropertyMerger $merger): int
    {
        if (! app()->environment(['local', 'testing', 'qa'])) {
            $this->error('This only runs on QA / local / testing (APP_ENV is "' . app()->environment() . '").');

            return self::FAILURE;
        }
        $rule = (string) $this->argument('rule');
        if ($rule !== 'all' && ! in_array($rule, MatchingRules::RULES, true)) {
            $this->error('Unknown rule. Known: ' . implode(', ', MatchingRules::RULES) . ', all');

            return self::FAILURE;
        }
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose --dry-run or --apply, not both.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $exclude = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('exclude-agency')))));
        $agencies = $this->option('agency') ? [(int) $this->option('agency')] : array_values(array_diff($reconciler->agencies(), $exclude));
        $dir = (string) ($this->option('journal-dir') ?: storage_path('app/reports'));
        File::ensureDirectoryExists($dir);
        $stamp = now()->format('Ymd-His');
        if ($apply) {
            $merger->journalTo($dir . "/matching-rules-{$rule}-journal-{$stamp}.jsonl");
        }
        $results = [];
        foreach ($agencies as $agencyId) {
            $results[$agencyId] = $rules->run($agencyId, $apply, $rule === 'all' ? null : [$rule]);
            foreach ($results[$agencyId] as $name => $r) {
                $this->line("agency {$agencyId} {$name}: " . json_encode($r));
            }
        }
        $merger->journalTo(null);
        $base = $dir . '/matching-rules-' . $rule . '-' . ($apply ? 'apply' : 'dry-run') . "-{$stamp}.json";
        File::put($base, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info("Report written to {$base}");
        if (! $apply) {
            $this->comment('Dry run: nothing was written to the database.');
        }

        return self::SUCCESS;
    }
}
