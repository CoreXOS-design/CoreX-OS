<?php

declare(strict_types=1);

namespace App\Console\Commands\Property;

use App\Services\Prospecting\PropertyIdentityReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Property identity A4 (.ai/specs/property-identity.md §8).
 *
 *   php artisan property-identity:reconcile --dry-run
 *
 * READ-ONLY. Reports what a merge / join pass over the existing data WOULD do: our own stock joining property files,
 * the same advert held twice, and property files that hold several different units. There is no --apply in this
 * build; Johan reviews the report first. Refuses to run outside QA / local / testing.
 */
final class ReconcilePropertyIdentity extends Command
{
    protected $signature = 'property-identity:reconcile
        {--dry-run : Report only (the only mode there is)}
        {--apply : NOT AVAILABLE - merges wait for review of this report}
        {--agency= : One agency id (default: every agency with data)}
        {--samples=25 : Sample rows listed per section}';

    protected $description = 'Dry-run report: own stock -> property file joins, duplicate adverts, over-merged property files';

    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'qa'];

    public function handle(PropertyIdentityReconciler $reconciler): int
    {
        if ($this->option('apply')) {
            $this->error('There is no --apply: nothing is merged until Johan has reviewed this report.');

            return self::FAILURE;
        }
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->error('This only runs on QA / local / testing (APP_ENV is "' . app()->environment() . '").');

            return self::FAILURE;
        }

        $agencies = $this->option('agency') ? [(int) $this->option('agency')] : $reconciler->agencies();
        $samples = max(5, (int) $this->option('samples'));

        $results = [];
        foreach ($agencies as $agencyId) {
            $this->line("Reading agency {$agencyId} ...");
            $results[$agencyId] = $reconciler->run($agencyId, $samples);
        }

        $stamp = now()->format('Ymd-His');
        $dir = storage_path('app/reports');
        File::ensureDirectoryExists($dir);
        $md = $dir . "/property-identity-reconcile-dry-run-{$stamp}.md";
        $json = $dir . "/property-identity-reconcile-dry-run-{$stamp}.json";
        File::put($md, $this->render($results));
        File::put($json, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->line(File::get($md));
        $this->info("Report written to {$md}");
        $this->info("Detail (JSON) written to {$json}");
        $this->comment('Dry run: nothing was written to the database.');

        return self::SUCCESS;
    }

    /** @param array<int, array<string,mixed>> $results */
    private function render(array $results): string
    {
        $o = ['# property-identity:reconcile - dry run (' . now()->toDateTimeString() . ', env ' . app()->environment() . ')', '',
            'Nothing here has been changed. Each section says what a later, approved, apply step WOULD do.', ''];

        foreach ($results as $agencyId => $r) {
            $s = $r['own_stock'];
            $o[] = "## Agency {$agencyId}";
            $o[] = '';
            $o[] = '### 1. Our own properties -> property files';
            $o[] = "- Own properties read: {$s['properties']}";
            $o[] = "- Already on the property-file spine: {$s['already_on_spine']}";
            $o[] = "- Not usable (no street, erf, SG code or advert number): {$s['no_usable_address']}";
            $o[] = "- **Would join an existing property file: {$s['would_join_total']}**";
            foreach ($s['would_join'] as $k => $n) {
                $o[] = "  - {$k}: {$n}";
            }
            $o[] = "- Only a *possible* match (an agent would confirm): {$s['possible']}";
            $o[] = "- Would need a **new** property file: {$s['would_need_new_file']}";
            foreach (['join' => 'Join samples', 'possible' => 'Possible samples', 'new' => 'New-file samples'] as $k => $title) {
                if ($s['samples'][$k]) {
                    $o[] = '';
                    $o[] = "**{$title}**";
                    foreach ($s['samples'][$k] as $x) {
                        $o[] = '- ' . json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }
            }

            $c = $r['ref_clashes'];
            $o[] = '';
            $o[] = '### 2a. The same advert on two property files (one agency)';
            $o[] = "- Adverts held on more than one property file: **{$c['groups']}** (property files involved: {$c['files_involved']})";
            $o[] = "  - the files have the same address (safe to merge): {$c['same_address']}";
            $o[] = "  - the addresses only possibly agree (check): {$c['possible']}";
            $o[] = "  - the files have DIFFERENT addresses (the advert points at two houses - check before anything): {$c['different_address']}";
            $o[] = "  - a file could not be read: {$c['unreadable']}";
            foreach (['different_address' => 'Different-address samples', 'same_address' => 'Same-address samples'] as $k => $title) {
                if ($c['samples'][$k]) {
                    $o[] = '';
                    $o[] = "**{$title}**";
                    foreach ($c['samples'][$k] as $x) {
                        $o[] = '- ' . json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }
            }

            $d = $r['own_duplicates'];
            $o[] = '';
            $o[] = '### 2b. Our own properties that look duplicated';
            foreach ($d['same_advert'] as $col => $x) {
                $o[] = "- Same advert number ({$col}): {$x['groups']} adverts on {$x['rows']} properties";
            }
            $o[] = "- Same number + street + suburb + unit: {$d['same_address']['groups']} addresses on {$d['same_address']['rows']} properties";
            foreach ($d['same_address']['sample'] as $x) {
                $o[] = '  - ' . json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $m = $r['over_merged'];
            $o[] = '';
            $o[] = '### 3. Property files that hold several different units / houses';
            $o[] = "- Files with 6 or more adverts: {$m['files_with_many_adverts']}";
            $o[] = "  - one address, relisted over time (fine): {$m['looks_like_relist_history']}";
            $o[] = "  - **several units / numbers on one file (would be split): {$m['over_merged']}** ({$m['adverts_on_over_merged']} adverts; {$m['proposed_new_files']} new files)";
            foreach ($m['samples'] as $x) {
                $o[] = '- ' . json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $o[] = '';
        }

        return implode("\n", $o) . "\n";
    }
}
