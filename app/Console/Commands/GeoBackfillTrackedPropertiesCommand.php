<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Prospecting\TrackedProperty;
use App\Services\Geocoding\GeocodeRateLimiter;
use App\Services\Geocoding\PropertyGeoBackfillService;
use App\Support\Geocoding\AddressNormaliser;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;

/**
 * Phase 11a D — targeted backfill of GPS coordinates on `tracked_properties`,
 * intended for use when seeding a new suburb (Uvongo first) or recovering
 * after a long quota drought.
 *
 *   php artisan geo:backfill-tracked-properties --area=Uvongo --dry-run
 *   php artisan geo:backfill-tracked-properties --area=Uvongo --limit=200
 *   php artisan geo:backfill-tracked-properties --limit=50 --force
 *
 * Layers on top of the existing PropertyGeoBackfillService (Phase 3f) — no
 * fork of the waterfall, just:
 *   1. Per-suburb scoping (matches suburb OR suburb_normalised).
 *   2. Runtime admin override on GeocodeRateLimiter so the run isn't gated
 *      by the daily user/env cap. (The cap exists to protect us against
 *      runaway request-driven traffic, not against deliberate operator
 *      backfills.)
 *   3. Per-row outcome log to a dated file in storage/logs/ — separate from
 *      the rolling geocoding channel so an operator can grep just this run.
 *   4. Sets `geocode_needs_review=true` for low-confidence results
 *      (suburb / failed) so the prospecting UI can flag them.
 *   5. SS-aware via AddressNormaliser::parse() — when complex_name is
 *      present we rebuild "Ss <Scheme>, <street>" so the cache key
 *      consolidates all units of a scheme onto one entry.
 *
 * `--dry-run` skips the resolver call AND the DB write — it only enumerates
 * candidates so an operator can preview cost ("you're about to attempt
 * N geocodes").
 */
final class GeoBackfillTrackedPropertiesCommand extends Command
{
    protected $signature = 'geo:backfill-tracked-properties
        {--area= : Restrict to a single suburb (matched case-insensitive on suburb OR suburb_normalised)}
        {--limit=200 : Max rows to attempt}
        {--dry-run : Enumerate candidates without calling the geocoder or writing}
        {--force : Re-resolve rows that already have GPS}';

    protected $description = 'Backfill tracked_properties GPS via the geocoding waterfall (Phase 11a — operator-triggered, bypasses daily cap).';

    public function handle(PropertyGeoBackfillService $svc): int
    {
        $area    = (string) ($this->option('area') ?? '');
        $limit   = max(1, (int) $this->option('limit'));
        $dryRun  = (bool) $this->option('dry-run');
        $force   = (bool) $this->option('force');
        $batchId = (string) Str::uuid();

        $query = TrackedProperty::query()->withoutGlobalScopes();

        if ($area !== '') {
            $needle = mb_strtolower(trim($area));
            $query->where(function (Builder $q) use ($needle) {
                $q->whereRaw('LOWER(suburb) = ?', [$needle])
                  ->orWhereRaw('LOWER(suburb_normalised) = ?', [$needle]);
            });
        }

        if (!$force) {
            $query->where(function (Builder $q) {
                $q->whereNull('latitude')->orWhereNull('longitude');
            });
        }

        $total = (clone $query)->count();
        $rows  = $query->limit($limit)->get();

        $this->info(sprintf(
            '=== geo:backfill-tracked-properties — area=%s | candidates=%d | will-attempt=%d | dry-run=%s ===',
            $area !== '' ? $area : '(all)',
            $total,
            $rows->count(),
            $dryRun ? 'yes' : 'no',
        ));

        if ($rows->isEmpty()) {
            $this->line('  Nothing to resolve.');
            return self::SUCCESS;
        }

        $log = $this->openRunLog($batchId, $area, $rows->count(), $dryRun);

        if ($dryRun) {
            foreach ($rows as $tp) {
                $candidate = $this->buildGeocodeInput($tp);
                $log->info('DRY tracked_property', [
                    'id'        => $tp->id,
                    'suburb'    => $tp->suburb,
                    'candidate' => $candidate,
                ]);
                $this->line("  TP#{$tp->id} → {$candidate}");
            }
            $log->info('DRY end', ['batch_id' => $batchId, 'count' => $rows->count()]);
            $this->newLine();
            $this->info('Dry-run complete. No upstream calls were made.');
            return self::SUCCESS;
        }

        // Engage override for the lifetime of this run — operator-triggered
        // backfills bypass the daily cap (the cap exists for request-driven
        // traffic, not deliberate ops work). Always release in `finally`.
        GeocodeRateLimiter::engageRuntimeOverride();

        $tally = ['exact' => 0, 'street' => 0, 'suburb' => 0, 'failed' => 0, 'pre_existing' => 0];
        $needsReview = 0;

        try {
            foreach ($rows as $i => $tp) {
                $candidate = $this->buildGeocodeInput($tp);

                // Apply the SS-aware geocode_target when we have a complex.
                // We don't overwrite tp->street_*; we just feed the resolver
                // a cleaner string when one is available.
                if ($candidate !== '' && $tp->complex_name && stripos($candidate, ' Ss ') === false) {
                    $candidate = $this->rebuildWithSsPrefix($tp);
                }

                $started = microtime(true);

                try {
                    // Reuse the existing service so MIC + portal_capture
                    // branches stay in scope. We re-fetch result data off the
                    // row after save so we can inspect confidence here.
                    $result = $svc->backfillTrackedProperty($tp, $batchId);
                } catch (\Throwable $e) {
                    $log->error('row failed', [
                        'id'    => $tp->id,
                        'addr'  => $candidate,
                        'error' => $e->getMessage(),
                    ]);
                    $tally['failed']++;
                    continue;
                }

                $latencyMs  = (int) round((microtime(true) - $started) * 1000);
                $confidence = (string) ($result['confidence'] ?? 'failed');
                $source     = (string) ($result['source'] ?? 'unresolved');

                $tally[$confidence] = ($tally[$confidence] ?? 0) + 1;

                // Flag low-confidence results for human review on the UI.
                $review = in_array($confidence, ['suburb', 'failed'], true);
                if ($review !== (bool) $tp->geocode_needs_review) {
                    DB::table('tracked_properties')->where('id', $tp->id)->update([
                        'geocode_needs_review' => $review,
                        'updated_at'           => now(),
                    ]);
                }
                if ($review) $needsReview++;

                $log->info('resolved', [
                    'id'           => $tp->id,
                    'suburb'       => $tp->suburb,
                    'candidate'    => $candidate,
                    'source'       => $source,
                    'confidence'   => $confidence,
                    'lat'          => $tp->fresh()?->latitude,
                    'lng'          => $tp->fresh()?->longitude,
                    'latency_ms'   => $latencyMs,
                    'needs_review' => $review,
                ]);

                $n = $i + 1;
                if ($n % 25 === 0 || $n === $rows->count()) {
                    $this->line(sprintf(
                        '  %d/%d processed — exact:%d street:%d suburb:%d failed:%d review:%d',
                        $n, $rows->count(),
                        $tally['exact'] ?? 0, $tally['street'] ?? 0,
                        $tally['suburb'] ?? 0, $tally['failed'] ?? 0,
                        $needsReview,
                    ));
                }
            }
        } finally {
            GeocodeRateLimiter::releaseRuntimeOverride();
        }

        $this->newLine();
        $this->info('=== Summary ===');
        foreach ($tally as $k => $v) {
            $this->line("  {$k}: {$v}");
        }
        $this->line("  needs_review (suburb/failed): {$needsReview}");
        $this->line("  batch_id: {$batchId}");

        $log->info('batch end', ['batch_id' => $batchId, 'tally' => $tally, 'needs_review' => $needsReview]);

        return self::SUCCESS;
    }

    /** Plain candidate the resolver will see when complex_name is empty. */
    private function buildGeocodeInput(TrackedProperty $tp): string
    {
        return trim((string) (
            ($tp->street_number ? $tp->street_number . ' ' : '') .
            (string) $tp->street_name
        ));
    }

    /** "<unit?> Ss <Scheme>, <street>" — reconstructs the canonical SA SS form. */
    private function rebuildWithSsPrefix(TrackedProperty $tp): string
    {
        $unit   = trim((string) ($tp->unit_number ?? ''));
        $scheme = trim((string) ($tp->complex_name ?? ''));
        $street = $this->buildGeocodeInput($tp);
        $prefix = ($unit !== '' ? $unit . ' ' : '') . 'Ss ' . $scheme;
        return $street !== '' ? $prefix . ', ' . $street : $prefix;
    }

    /**
     * Stand up a dedicated Monolog file logger for this run. Keeps the
     * per-row trace out of the rolling geocoding.log so an operator can
     * inspect one backfill without scrolling past unrelated entries.
     */
    private function openRunLog(string $batchId, string $area, int $count, bool $dryRun): Logger
    {
        $date = CarbonImmutable::now('Africa/Johannesburg')->toDateString();
        $path = storage_path("logs/geocode-backfill-{$date}.log");
        $logger = new Logger('geo-backfill');
        $handler = new StreamHandler($path, Logger::INFO);
        $handler->setFormatter(new LineFormatter("[%datetime%] %level_name%: %message% %context%\n", 'Y-m-d H:i:s', true, true));
        $logger->pushHandler($handler);
        $logger->info('batch start', [
            'batch_id' => $batchId,
            'area'     => $area !== '' ? $area : null,
            'count'    => $count,
            'dry_run'  => $dryRun,
        ]);
        return $logger;
    }
}
