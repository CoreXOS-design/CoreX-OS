<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MarketReports\CanonicalReadMapper;
use App\Services\TitleTypeClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent backfill for the title type of imported comparable rows (market_report_comp_rows).
 *
 * Two faults in rows already stored (Staging report 437 / QA1 report 438, 26 Oct 2026):
 *   1. The LOOM report prints "Unit Number 00000" for every freehold property; the read stored it as the
 *      section number, and a section number reads as "this is a sectional unit". Placeholder section numbers
 *      (blank, all zeros, dashes, n/a) are set back to empty.
 *   2. Rows with no property type (a learned layout reads the table, not the "Property Type" printed once in
 *      the subject block). A blank row takes the type its report's other rows agree on — the same rule the
 *      importer now applies (CanonicalReadMapper::inheritTitleType). A report whose rows name no type, or two
 *      types, is left alone: those comps are "title type unknown" on the review list, never excluded for it.
 *
 * Only rows that need a change are touched, so a second run changes nothing.
 */
final class BackfillCompRowTitleTypes extends Command
{
    protected $signature = 'market-reports:backfill-comp-title-types
                            {--agency= : Limit to a specific agency_id}
                            {--report= : Limit to a specific market report id}
                            {--clear-stray-pins : Also clear a map pin that sits far from the rest of its report (a same-named street elsewhere)}
                            {--dry-run : Count only, no writes}';

    protected $description = 'Clear placeholder section numbers and let comp rows with no property type inherit their report\'s title type.';

    public function handle(CanonicalReadMapper $mapper): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $base = DB::table('market_report_comp_rows')->whereNull('deleted_at');
        if ($this->option('agency') !== null) {
            $base->where('agency_id', (int) $this->option('agency'));
        }
        if ($this->option('report') !== null) {
            $base->where('market_report_id', (int) $this->option('report'));
        }

        $placeholders = 0;
        $inherited    = 0;
        $left         = [];

        // 1. Placeholder section numbers.
        (clone $base)->whereNotNull('section_number')->orderBy('id')->chunkById(500, function ($rows) use (&$placeholders, $dryRun) {
            foreach ($rows as $row) {
                if (!TitleTypeClassifier::isPlaceholderSignal($row->section_number)) {
                    continue;
                }
                $placeholders++;
                if ($dryRun) {
                    continue;
                }
                $raw = $this->decode($row->raw_row_json);
                if (array_key_exists('section_number', $raw)) {
                    unset($raw['section_number']);
                }
                DB::table('market_report_comp_rows')->where('id', $row->id)->update([
                    'section_number' => null,
                    'raw_row_json'   => $raw === [] ? null : json_encode($raw),
                    'updated_at'     => now(),
                ]);
            }
        });

        // 2. Blank property type: inherit the report's agreed type.
        $reportIds = (clone $base)->whereIn('row_type', ['comp', 'listing'])
            ->where(function ($q) {
                $q->whereNull('property_type')->orWhere('property_type', '');
            })->distinct()->pluck('market_report_id');

        foreach ($reportIds as $reportId) {
            $rows = DB::table('market_report_comp_rows')->whereNull('deleted_at')
                ->where('market_report_id', $reportId)->whereIn('row_type', ['comp', 'listing'])
                ->orderBy('id')->get(['id', 'property_type', 'raw_row_json']);

            $comps = $rows->map(fn ($r) => ['property_type' => $r->property_type])->all();
            $after = $mapper->inheritTitleType($comps, null);

            $changed = 0;
            foreach ($rows as $i => $row) {
                if (trim((string) $row->property_type) !== '' || trim((string) ($after[$i]['property_type'] ?? '')) === '') {
                    continue;
                }
                $changed++;
                $inherited++;
                if ($dryRun) {
                    continue;
                }
                $raw = $this->decode($row->raw_row_json);
                $raw['property_type']     = $after[$i]['property_type'];
                $raw['title_type_source'] = 'report';
                DB::table('market_report_comp_rows')->where('id', $row->id)->update([
                    'property_type' => $after[$i]['property_type'],
                    'raw_row_json'  => json_encode($raw),
                    'updated_at'    => now(),
                ]);
            }
            if ($changed === 0) {
                $left[] = (int) $reportId;
            }
        }

        $stray = $this->option('clear-stray-pins') ? $this->clearStrayPins(clone $base, $dryRun) : null;

        $verb = $dryRun ? 'would be' : 'were';
        $this->info("{$placeholders} placeholder section numbers {$verb} cleared.");
        $this->info("{$inherited} comp rows {$verb} given their report's title type.");
        if ($left !== []) {
            $this->warn(count($left) . ' report(s) have rows with no title type and nothing to inherit (shown as "title type unknown", never excluded for it): ' . implode(', ', array_slice($left, 0, 20)));
        }

        if ($stray !== null) {
            $this->info("{$stray} stray map pins {$verb} cleared (the next presentation asks the geocoder again, with the suburb).");
        }

        return self::SUCCESS;
    }

    /**
     * A report's comparable sales sit together (one CMA = one neighbourhood). A pin more than 25 km from the median
     * pin of its own report is another place with the same street name, not the comp: it is cleared so it is
     * resolved again. Needs at least 5 pinned rows in the report to have a median to trust.
     */
    private function clearStrayPins(\Illuminate\Database\Query\Builder $base, bool $dryRun): int
    {
        $cleared = 0;
        $reportIds = (clone $base)->whereNotNull('latitude')->whereNotNull('longitude')->distinct()->pluck('market_report_id');
        foreach ($reportIds as $reportId) {
            $rows = DB::table('market_report_comp_rows')->whereNull('deleted_at')->where('market_report_id', $reportId)
                ->whereNotNull('latitude')->whereNotNull('longitude')->get(['id', 'latitude', 'longitude']);
            if ($rows->count() < 5) {
                continue;
            }
            $lats = $rows->pluck('latitude')->map(fn ($v) => (float) $v)->sort()->values();
            $lngs = $rows->pluck('longitude')->map(fn ($v) => (float) $v)->sort()->values();
            $mid = intdiv($rows->count(), 2);
            foreach ($rows as $r) {
                $d = \App\Support\MarketAnalytics\HaversineDistance::distanceMetres($lats[$mid], $lngs[$mid], (float) $r->latitude, (float) $r->longitude);
                if ($d <= 25_000) {
                    continue;
                }
                $cleared++;
                if (!$dryRun) {
                    DB::table('market_report_comp_rows')->where('id', $r->id)->update(['latitude' => null, 'longitude' => null, 'updated_at' => now()]);
                }
            }
        }

        return $cleared;
    }

    /** @return array<string, mixed> */
    private function decode(mixed $json): array
    {
        $d = is_string($json) ? json_decode($json, true) : (is_array($json) ? $json : []);

        return is_array($d) ? $d : [];
    }
}
