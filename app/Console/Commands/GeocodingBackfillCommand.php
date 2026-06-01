<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Geocoding\PropertyGeoBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Phase 3f C1 — bulk GPS backfill.
 *
 *   php artisan geocoding:backfill              (both types, all unresolved)
 *   php artisan geocoding:backfill --type=properties
 *   php artisan geocoding:backfill --type=tracked_properties --limit=500
 *   php artisan geocoding:backfill --force      (re-resolves rows that already have GPS)
 *
 * Reports progress every 50 rows + a final breakdown by source/confidence.
 * Warns when GOOGLE_GEOCODING_API_KEY isn't configured — the run will still
 * complete from MIC + portal_capture data, but coverage may be low.
 */
final class GeocodingBackfillCommand extends Command
{
    protected $signature = 'geocoding:backfill
        {--limit=0 : Max rows to process (0 = all unresolved)}
        {--type=both : both | properties | tracked_properties | comp_rows | competition}
        {--force : Re-resolve rows that already have GPS}
        {--id= : Single property ID to re-resolve (implies --force)}
        {--suspect-only : Limit re-resolve to suburb_centroid / unresolved / low-confidence pins (implies --force)}';

    protected $description = 'Resolve GPS via AddressResolverService for properties, tracked properties, market_report_comp_rows (sold-comp pool), and prospecting_listings (Active Competition pool).';

    public function handle(PropertyGeoBackfillService $svc): int
    {
        $type        = (string) $this->option('type');
        $limit       = (int)    $this->option('limit');
        $force       = (bool)   $this->option('force');
        $singleId    = $this->option('id') !== null ? (int) $this->option('id') : null;
        $suspectOnly = (bool)   $this->option('suspect-only');

        // --id and --suspect-only both imply force re-resolve.
        if ($singleId !== null || $suspectOnly) $force = true;

        if (!in_array($type, ['both', 'properties', 'tracked_properties', 'comp_rows', 'competition'], true)) {
            $this->error("Invalid --type: {$type}");
            return self::INVALID;
        }

        // Single-ID path — bypasses the type loop entirely.
        if ($singleId !== null) {
            $p = Property::withoutGlobalScopes()->find($singleId);
            if (!$p) {
                $this->error("Property #{$singleId} not found.");
                return self::FAILURE;
            }
            $this->info("=== Re-resolving property #{$singleId} (force) ===");
            $this->line("  address: {$p->address}");
            $this->line("  before:  lat={$p->latitude}, lng={$p->longitude}, source={$p->geo_source}");
            $res = $svc->backfillProperty($p, batchId: null, force: true);
            $p->refresh();
            $this->line("  after:   lat={$p->latitude}, lng={$p->longitude}, source={$p->geo_source}");
            $this->line("  result:  " . json_encode($res));
            return self::SUCCESS;
        }

        if (empty(config('services.google.geocoding_api_key'))) {
            $this->warn('GOOGLE_GEOCODING_API_KEY is NOT configured.');
            $this->warn('Resolution will be limited to MIC + portal_capture data only.');
            $this->warn('To enable Google Geocoding: add GOOGLE_GEOCODING_API_KEY=... to .env and re-run.');
            $this->newLine();
        }

        $batchId = (string) Str::uuid();
        $tallyTotal = ['resolved' => 0, 'failed' => 0, 'pre_existing' => 0];
        $bySource   = [];

        if ($type === 'both' || $type === 'properties') {
            $this->info('=== Backfilling properties ===');
            $this->runForModel(Property::query()->withoutGlobalScopes(), $svc, 'property', $limit, $force, $suspectOnly, $batchId, $tallyTotal, $bySource);
            $this->newLine();
        }

        if ($type === 'both' || $type === 'tracked_properties') {
            $this->info('=== Backfilling tracked_properties ===');
            $this->runForModel(TrackedProperty::query()->withoutGlobalScopes(), $svc, 'tracked_property', $limit, $force, false, $batchId, $tallyTotal, $bySource);
            $this->newLine();
        }

        // CMA-map backfill: comp rows (market_report_comp_rows — 25% of
        // the historic pool lacks GPS) + competition (prospecting_listings
        // — 100% historic gap until this build). Plain DB::table loops
        // (no Eloquent model) — each row gets one resolver call, persists
        // back to the table. The eager hooks in MicSnapshotHydrator +
        // CompetitorStockMatchService keep these populated going forward.
        if ($type === 'comp_rows' || $type === 'both') {
            $this->info('=== Backfilling market_report_comp_rows ===');
            $this->backfillCompRows($limit, $force, $tallyTotal, $bySource);
            $this->newLine();
        }
        if ($type === 'competition' || $type === 'both') {
            $this->info('=== Backfilling prospecting_listings (competition) ===');
            $this->backfillCompetition($limit, $force, $tallyTotal, $bySource);
            $this->newLine();
        }

        $this->newLine();
        $this->info('=== Summary ===');
        $this->line(sprintf(
            'Resolved: %d | Pre-existing GPS: %d | Failed: %d',
            $tallyTotal['resolved'],
            $tallyTotal['pre_existing'],
            $tallyTotal['failed'],
        ));
        if (!empty($bySource)) {
            $this->line('By source:');
            ksort($bySource);
            foreach ($bySource as $src => $n) {
                $this->line("  {$src}: {$n}");
            }
        }
        $this->line("Batch ID: {$batchId}");
        return self::SUCCESS;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array{resolved:int, failed:int, pre_existing:int} $tallyTotal
     * @param array<string,int> $bySource
     */
    private function runForModel(
        $query,
        PropertyGeoBackfillService $svc,
        string $kind,
        int $limit,
        bool $force,
        bool $suspectOnly,
        string $batchId,
        array &$tallyTotal,
        array &$bySource,
    ): void {
        if ($suspectOnly && $kind === 'property') {
            // Re-resolve only rows that came back as a suburb_centroid /
            // unresolved / low-confidence — the legacy frontend Nominatim
            // suburb-only pins. Limit to rows with GPS (otherwise they're
            // caught by the !force path below anyway).
            $query = $query->whereNotNull('latitude')->whereNotNull('longitude')
                ->where(function ($q) {
                    $q->whereIn('geo_source', ['suburb_centroid', 'unresolved', 'nominatim_suburb'])
                      ->orWhereNull('geo_source')
                      ->orWhereIn('geo_confidence', ['failed', 'low'])
                      ->orWhereNull('geo_confidence');
                });
        } elseif (!$force) {
            $query = $query->where(function ($q) {
                $q->whereNull('latitude')->orWhereNull('longitude');
            });
        }
        if ($limit > 0) {
            $query = $query->limit($limit);
        }

        $rows = $query->get();
        $total = $rows->count();
        if ($total === 0) {
            $this->line('  Nothing to resolve.');
            return;
        }

        $processed = 0;
        foreach ($rows as $row) {
            try {
                $result = $kind === 'property'
                    ? $svc->backfillProperty($row, $batchId, $force)
                    : $svc->backfillTrackedProperty($row, $batchId);
            } catch (\Throwable $e) {
                $this->warn("  {$kind}#{$row->id}: " . $e->getMessage());
                $tallyTotal['failed']++;
                $processed++;
                continue;
            }

            $src = $result['source'] ?? 'unknown';
            $bySource[$src] = ($bySource[$src] ?? 0) + 1;

            if ($result['lat_lng_resolved']) {
                $tallyTotal[$src === 'pre_existing' ? 'pre_existing' : 'resolved']++;
            } else {
                $tallyTotal['failed']++;
            }

            $processed++;
            if ($processed % 50 === 0 || $processed === $total) {
                $this->line(sprintf(
                    '  %d/%d processed — %d resolved, %d failed.',
                    $processed,
                    $total,
                    $tallyTotal['resolved'],
                    $tallyTotal['failed'],
                ));
            }
        }
    }

    /**
     * Backfill GPS on market_report_comp_rows for the CMA-map fix.
     * Rows with an address but no lat/lng get resolved + persisted.
     * `--force` re-resolves rows that already have GPS (rare; mostly
     * for testing).
     */
    private function backfillCompRows(int $limit, bool $force, array &$tallyTotal, array &$bySource): void
    {
        $q = \Illuminate\Support\Facades\DB::table('market_report_comp_rows')
            ->whereNotNull('address')->where('address', '<>', '');
        if (!$force) {
            $q->where(function ($q) {
                $q->whereNull('latitude')->orWhereNull('longitude');
            });
        }
        if ($limit > 0) $q->limit($limit);
        $rows = $q->select(['id', 'address', 'suburb_normalised', 'latitude', 'longitude'])->get();
        $total = $rows->count();
        if ($total === 0) {
            $this->line('  Nothing to resolve.');
            return;
        }

        $resolver = new \App\Services\Geocoding\AddressResolverService();
        $processed = 0;
        foreach ($rows as $row) {
            try {
                $result = $resolver->resolve(
                    (string) $row->address,
                    $row->suburb_normalised ?: null,
                    null,
                    context: 'mic_comp_row:' . (int) $row->id,
                );
                if ($result->hasGps()) {
                    \Illuminate\Support\Facades\DB::table('market_report_comp_rows')
                        ->where('id', (int) $row->id)
                        ->update([
                            'latitude'  => $result->latitude,
                            'longitude' => $result->longitude,
                            'updated_at'=> now(),
                        ]);
                    $tallyTotal['resolved']++;
                    $src = (string) $result->source;
                    $bySource[$src] = ($bySource[$src] ?? 0) + 1;
                } else {
                    $tallyTotal['failed']++;
                }
            } catch (\Throwable $e) {
                $this->warn("  comp_row#{$row->id}: " . $e->getMessage());
                $tallyTotal['failed']++;
            }
            $processed++;
            if ($processed % 50 === 0 || $processed === $total) {
                $this->line("  {$processed}/{$total} processed — {$tallyTotal['resolved']} resolved, {$tallyTotal['failed']} failed.");
            }
        }
    }

    /**
     * Backfill GPS on prospecting_listings for the CMA-map Active
     * Competition layer. Historic rows have 100% address + 0% GPS;
     * this clears the gap.
     */
    private function backfillCompetition(int $limit, bool $force, array &$tallyTotal, array &$bySource): void
    {
        $q = \Illuminate\Support\Facades\DB::table('prospecting_listings')
            ->whereNotNull('address')->where('address', '<>', '')
            ->whereNull('deleted_at');
        if (!$force) {
            $q->where(function ($q) {
                $q->whereNull('latitude')->orWhereNull('longitude');
            });
        }
        if ($limit > 0) $q->limit($limit);
        $rows = $q->select(['id', 'address', 'suburb', 'latitude', 'longitude'])->get();
        $total = $rows->count();
        if ($total === 0) {
            $this->line('  Nothing to resolve.');
            return;
        }

        $resolver = new \App\Services\Geocoding\AddressResolverService();
        $processed = 0;
        foreach ($rows as $row) {
            try {
                $result = $resolver->resolve(
                    (string) $row->address,
                    $row->suburb ?: null,
                    null,
                    context: 'prospecting_listing:' . (int) $row->id,
                );
                if ($result->hasGps()) {
                    \Illuminate\Support\Facades\DB::table('prospecting_listings')
                        ->where('id', (int) $row->id)
                        ->update([
                            'latitude'  => $result->latitude,
                            'longitude' => $result->longitude,
                            'updated_at'=> now(),
                        ]);
                    $tallyTotal['resolved']++;
                    $src = (string) $result->source;
                    $bySource[$src] = ($bySource[$src] ?? 0) + 1;
                } else {
                    $tallyTotal['failed']++;
                }
            } catch (\Throwable $e) {
                $this->warn("  competition#{$row->id}: " . $e->getMessage());
                $tallyTotal['failed']++;
            }
            $processed++;
            if ($processed % 50 === 0 || $processed === $total) {
                $this->line("  {$processed}/{$total} processed — {$tallyTotal['resolved']} resolved, {$tallyTotal['failed']} failed.");
            }
        }
    }
}
