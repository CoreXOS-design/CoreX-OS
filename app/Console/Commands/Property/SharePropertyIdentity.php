<?php

declare(strict_types=1);

namespace App\Console\Commands\Property;

use App\Services\Prospecting\PropertyIdentityShareReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Property identity A5 (.ai/specs/property-identity.md §14).
 *
 *   php artisan property-identity:share --dry-run
 *
 * READ-ONLY. Reports what sharing the property / advert row across agencies WOULD do. There is no --apply in this build;
 * Johan reviews it together with the A4 reconcile report. Refuses to run outside QA / local / testing.
 */
final class SharePropertyIdentity extends Command
{
    protected $signature = 'property-identity:share
        {--dry-run : Report only (the only mode there is)}
        {--apply : NOT AVAILABLE - sharing waits for review of this report}
        {--samples=15 : Sample rows listed per section}';

    protected $description = 'Dry-run report: shared advert / property rows across agencies (public facts only)';

    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'qa'];

    public function handle(PropertyIdentityShareReport $report): int
    {
        if ($this->option('apply')) {
            $this->error('There is no --apply: nothing is shared until Johan has reviewed this report.');

            return self::FAILURE;
        }
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->error('This only runs on QA / local / testing (APP_ENV is "' . app()->environment() . '").');

            return self::FAILURE;
        }

        $r = $report->run(max(5, (int) $this->option('samples')));
        $stamp = now()->format('Ymd-His');
        $dir = storage_path('app/reports');
        File::ensureDirectoryExists($dir);
        $md = $dir . "/property-identity-share-dry-run-{$stamp}.md";
        $json = $dir . "/property-identity-share-dry-run-{$stamp}.json";
        File::put($md, $this->render($r));
        File::put($json, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->line(File::get($md));
        $this->info("Report written to {$md}");
        $this->comment('Dry run: nothing was written to the database.');

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $r */
    private function render(array $r): string
    {
        $a = $r['adverts'];
        $p = $r['shared_properties'];
        $c = $r['conflicts'];
        $pf = $r['public_fact_coverage'];
        $pv = $r['privacy'];
        $o = ['# property-identity:share - dry run (' . now()->toDateTimeString() . ', env ' . app()->environment() . ')', '',
            'Nothing here has been changed. Each section says what an approved share step WOULD do.', '',
            'Agencies with data: ' . implode(', ', $r['agencies']), '',
            '## 1. Adverts in the shared pool',
            "- Distinct adverts held by any agency: **{$a['distinct']}**",
            "  - already in the pool: {$a['already_in_pool']}  /  would be added: {$a['would_be_created']}",
            "  - seen by more than one agency: {$a['seen_by_several_agencies']}",
            "- Internal contribution records that would be written (advert x agency): {$a['contribution_records']}",
            "- Pool today: {$r['pool_today']['adverts']} adverts, {$r['pool_today']['shared_properties']} shared properties, {$r['pool_today']['contribution_records']} contribution records",
            '', '## 2. Shared properties (adverts and property files grouped)',
            "- Shared properties that would exist: **{$p['total']}**",
            "  - one agency, one property file: {$p['single_agency_single_file']}",
            "  - one agency, SEVERAL files joined by a shared advert (needs the A4 merge decision first): {$p['single_agency_several_files']}",
            "  - spanning more than one agency: {$p['span_agencies']}",
            "  - blocked because a file in the group holds several units / houses (split first, A4 section 3): {$p['blocked_by_over_merge']}",
            "- Agency property files that would point at a shared property: {$p['agency_files_that_would_link']}",
            '', '## 3. Where agencies disagree about one advert',
            "- Adverts seen by several agencies: {$c['adverts_seen_by_several_agencies']}; suburb differs: {$c['suburb_differs']}; price differs: {$c['price_differs']}",
            '', '## 4. How complete the public facts are (advert rows that would feed the pool)'];
        foreach ($pf as $k => $v) {
            $o[] = "- {$k}: {$v}";
        }
        $o[] = '';
        $o[] = '## 5. Privacy self-check';
        $o[] = '- Columns that would be written to the shared side: ' . implode(', ', $pv['columns_that_would_be_written']);
        $o[] = '- Private columns among them: ' . ($pv['private_columns_among_them'] ? '**' . implode(', ', $pv['private_columns_among_them']) . '**' : 'none');
        $o[] = '- Shared tables (' . implode(', ', $pv['tables']) . ') carry an agency column: ' . ($pv['shared_tables_have_agency_column'] ? '**YES - wrong**' : 'no');
        $o[] = '- Internal-only (agency-scoped, never returned with a pool read): ' . implode(', ', $pv['internal_only']);
        foreach (['span_agencies' => 'Samples - groups spanning agencies', 'several_files' => 'Samples - several files of one agency'] as $k => $title) {
            if ($p['samples'][$k]) {
                $o[] = '';
                $o[] = "**{$title}**";
                foreach ($p['samples'][$k] as $x) {
                    $o[] = '- ' . json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        }

        return implode("\n", $o) . "\n";
    }
}
