<?php

declare(strict_types=1);

namespace App\Console\Commands\Property;

use App\Services\Prospecting\PortalRefBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Property identity A2 (.ai/specs/property-identity.md §4, §8).
 *
 *   php artisan property-refs:normalise --dry-run     # ALWAYS first: counts + report, nothing written
 *   php artisan property-refs:normalise --apply       # then the real run (idempotent, resumable by re-running)
 *
 * Fills the DERIVED canonical advert reference ("p24:116123456", "pp:T1014486") beside every raw advert
 * number. Raw columns are never changed. Refuses to run outside QA / local / testing.
 */
final class NormalisePortalRefs extends Command
{
    protected $signature = 'property-refs:normalise
        {--dry-run : Count and report only; write nothing (the default when neither flag is given)}
        {--apply : Write the canonical references}
        {--source=* : Limit to these sources (' . 'tracked_property_external_refs, prospecting_listings, p24_listings, portal_listings, properties_p24, properties_pp, property_external_sources)}
        {--chunk=1000 : Rows per batch}';

    protected $description = 'Normalise every portal advert number into one canonical reference (dry-run first)';

    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'qa'];

    public function handle(PortalRefBackfill $backfill): int
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->error('This only runs on QA / local / testing until Johan orders it elsewhere (APP_ENV is "' . app()->environment() . '").');

            return self::FAILURE;
        }
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $only = array_values(array_filter((array) $this->option('source')));
        $unknown = array_diff($only, PortalRefBackfill::sourceKeys());
        if ($unknown) {
            $this->error('Unknown source: ' . implode(', ', $unknown) . '. Known: ' . implode(', ', PortalRefBackfill::sourceKeys()));

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $report = $backfill->run($apply, $only ?: null, max(100, (int) $this->option('chunk')));

        $markdown = $this->render($report);
        $dir = storage_path('app/reports');
        File::ensureDirectoryExists($dir);
        $file = $dir . '/property-refs-normalise-' . $report['mode'] . '-' . now()->format('Ymd-His') . '.md';
        File::put($file, $markdown);

        $this->line($markdown);
        $this->info('Report written to ' . $file);
        if (! $apply) {
            $this->comment('Dry run: nothing was written. Run with --apply to fill the canonical references.');
        }

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $r */
    private function render(array $r): string
    {
        $out = ['# property-refs:normalise — ' . $r['mode'] . ' (' . now()->toDateTimeString() . ', env ' . app()->environment() . ')', ''];
        $out[] = '| Source | Rows | No advert no. | Normalised | Unparseable | Already canonical | To change | Written | Distinct refs |';
        $out[] = '|---|---|---|---|---|---|---|---|---|';
        foreach ($r['sources'] as $k => $s) {
            $out[] = sprintf('| %s | %d | %d | %d | %d | %d | %d | %d | %d |', $k, $s['rows'], $s['no_advert_number'], $s['normalised'], $s['unparseable'], $s['already_canonical'], $s['to_change'], $s['written'], $s['distinct_canonical']);
        }
        $out[] = '';
        foreach ($r['sources'] as $k => $s) {
            if ($s['by_shape']) {
                arsort($s['by_shape']);
                $out[] = '**' . $k . '** formats seen (9 = digits, A = letters): ' . implode(', ', array_map(fn ($shape, $n) => "`{$shape}` ×{$n}", array_keys($s['by_shape']), $s['by_shape']));
            }
            if ($s['samples']) {
                $out[] = '**' . $k . '** unparseable samples: ' . implode(' | ', array_map(fn ($x) => '`' . $x . '`', $s['samples']));
            }
        }
        $c = $r['cross'];
        $out[] = '';
        $out[] = '## Across sources';
        $out[] = '- Distinct canonical adverts: ' . $c['distinct_canonical_refs'];
        $out[] = '- Seen in two or more source tables: ' . $c['in_two_or_more_sources'] . ' (three or more: ' . $c['in_three_or_more'] . ')';
        foreach ($c['pair_overlaps'] as $pair => $n) {
            $out[] = '  - ' . $pair . ': ' . $n;
        }
        $out[] = '- Clashes (the same advert on two different property files of one agency): ' . $c['clashes'];
        foreach ($c['clash_samples'] as $x) {
            $out[] = '  - ' . $x;
        }

        return implode("\n", $out) . "\n";
    }
}
