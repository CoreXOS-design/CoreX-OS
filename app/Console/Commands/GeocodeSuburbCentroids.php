<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\P24Suburb;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Part 3 — one-time (re-runnable) geocode of p24_suburbs to a centroid, for the
 * Buyer-Demand heatmap.
 *
 * Strategy (free, no API cost): the centroid is the MEDIAN lat/lng of already-geocoded
 * rows in that suburb, after rejecting any source row outside a plausible South Africa
 * bounding box —
 *   1. `properties` grouped by p24_suburb_id (id-keyed, exact).
 *   2. fallback: `prospecting_listings` grouped by the free-text `suburb`, resolved to a
 *      p24_suburbs row via P24Suburb::lookup() (name match) for suburbs properties didn't
 *      cover.
 * Idempotent: only fills suburbs that are still ungeocoded unless --force.
 *
 * 2026-08-27 (Johan, live) — the original AVG(lat)/AVG(lng) had no outlier
 * protection: property #5668 ("PTN 144 Farm 8159 Melbourne") is geocoded to
 * Melbourne, AUSTRALIA (-37.81, 144.96), and three more properties sit at
 * exactly (0, 0) — the classic "geocoder failed silently" sentinel. A single
 * bad row anywhere in a suburb's property list dragged that suburb's whole
 * centroid with it — confirmed live: Umzumbe's averaged centroid landed at
 * longitude 48°, not on the African continent at all. 14 of the first 145
 * geocoded centroids (~10%) landed with no real address anywhere near them.
 * Two independent guards now: (1) SOURCE_BOUNDS rejects a row outside
 * plausible South Africa before it can ever enter an average — catches the
 * Melbourne/null-island cases outright; (2) the per-axis MEDIAN (not mean)
 * of whatever remains protects against an outlier that's wrong but still
 * technically in-country, which the bounds check alone wouldn't catch.
 */
class GeocodeSuburbCentroids extends Command
{
    protected $signature = 'map:geocode-suburbs {--force : Re-geocode suburbs that already have a centroid} {--dry-run}';

    protected $description = 'Geocode each P24 suburb to its centroid (median of geocoded properties/listings, outlier-rejected) for the buyer-demand heatmap.';

    /**
     * Plausible South Africa bounding box (Cape Agulhas to the Limpopo
     * border; west coast/Namibia border to the KZN/Mozambique border), with
     * generous margin. A source row outside this is rejected before it can
     * enter any average — it is never "corrected", just excluded.
     */
    private const SOURCE_LAT_MIN = -35.0;
    private const SOURCE_LAT_MAX = -22.0;
    private const SOURCE_LNG_MIN = 16.0;
    private const SOURCE_LNG_MAX = 33.0;

    private static function inBounds(float $lat, float $lng): bool
    {
        return $lat >= self::SOURCE_LAT_MIN && $lat <= self::SOURCE_LAT_MAX
            && $lng >= self::SOURCE_LNG_MIN && $lng <= self::SOURCE_LNG_MAX;
    }

    /** Per-axis median — sorted middle value (or average of the two middle values on an even count). */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $dry   = (bool) $this->option('dry-run');

        $target = P24Suburb::query()->whereNull('deleted_at');
        if (! $force) {
            $target->whereNull('latitude');
        }
        $suburbs = $target->get();
        $totalSuburbs = P24Suburb::whereNull('deleted_at')->count();

        $this->info(($dry ? '[dry-run] ' : '') . "Geocoding {$suburbs->count()} of {$totalSuburbs} suburbs"
            . ($force ? ' (forced re-geocode).' : ' (ungeocoded only).'));

        // Pass 1 — raw geocoded properties, bounds-filtered, grouped by p24_suburb_id in PHP.
        $propPointsBySuburb = [];
        $rejectedProps = 0;
        DB::table('properties')
            ->whereNotNull('p24_suburb_id')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereNull('deleted_at')
            ->select(['p24_suburb_id', 'latitude', 'longitude'])
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$propPointsBySuburb, &$rejectedProps) {
                foreach ($rows as $r) {
                    $lat = (float) $r->latitude;
                    $lng = (float) $r->longitude;
                    if (! self::inBounds($lat, $lng)) {
                        $rejectedProps++;
                        continue;
                    }
                    $propPointsBySuburb[$r->p24_suburb_id]['lat'][] = $lat;
                    $propPointsBySuburb[$r->p24_suburb_id]['lng'][] = $lng;
                }
            });

        // Pass 2 — raw geocoded listings, bounds-filtered, grouped by free-text suburb name in PHP.
        $listingPointsByName = [];
        $rejectedListings = 0;
        DB::table('prospecting_listings')
            ->whereNotNull('suburb')->where('suburb', '!=', '')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereNull('deleted_at')
            ->select(['suburb', 'latitude', 'longitude'])
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$listingPointsByName, &$rejectedListings) {
                foreach ($rows as $r) {
                    $lat = (float) $r->latitude;
                    $lng = (float) $r->longitude;
                    if (! self::inBounds($lat, $lng)) {
                        $rejectedListings++;
                        continue;
                    }
                    $key = mb_strtolower(trim((string) $r->suburb));
                    $listingPointsByName[$key]['lat'][] = $lat;
                    $listingPointsByName[$key]['lng'][] = $lng;
                }
            });

        $fromProps = 0;
        $fromListings = 0;
        $failed = 0;
        $failedNames = [];

        foreach ($suburbs as $suburb) {
            $lat = $lng = null;
            $source = null;

            if (isset($propPointsBySuburb[$suburb->id])) {
                $lat = round(self::median($propPointsBySuburb[$suburb->id]['lat']), 7);
                $lng = round(self::median($propPointsBySuburb[$suburb->id]['lng']), 7);
                $source = 'properties_avg';
                $fromProps++;
            } else {
                $key = mb_strtolower(trim((string) $suburb->name));
                if ($key !== '' && isset($listingPointsByName[$key])) {
                    $lat = round(self::median($listingPointsByName[$key]['lat']), 7);
                    $lng = round(self::median($listingPointsByName[$key]['lng']), 7);
                    $source = 'listings_avg';
                    $fromListings++;
                }
            }

            if ($lat === null) {
                $failed++;
                if (count($failedNames) < 25) {
                    $failedNames[] = $suburb->name;
                }
                continue;
            }

            if (! $dry) {
                $suburb->update([
                    'latitude'  => $lat,
                    'longitude' => $lng,
                    'centroid_source' => $source,
                    'centroid_geocoded_at' => now(),
                ]);
            }
        }

        $geocoded = $fromProps + $fromListings;
        $this->info(($dry ? '[dry-run] ' : '')
            . "Geocoded: {$geocoded} (properties_avg: {$fromProps}, listings_avg: {$fromListings}) · failed (no geocoded source): {$failed}."
            . " Rejected out-of-bounds source rows: {$rejectedProps} properties, {$rejectedListings} listings.");
        if ($failed > 0) {
            $this->warn('Suburbs with no geocodable source (sample): ' . implode(', ', $failedNames)
                . ($failed > count($failedNames) ? ' …' : ''));
        }

        return self::SUCCESS;
    }
}
