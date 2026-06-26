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
 * Strategy (free, no API cost): the centroid is the AVERAGE lat/lng of already-geocoded
 * rows in that suburb —
 *   1. `properties` grouped by p24_suburb_id (id-keyed, exact).
 *   2. fallback: `prospecting_listings` grouped by the free-text `suburb`, resolved to a
 *      p24_suburbs row via P24Suburb::lookup() (name match) for suburbs properties didn't
 *      cover.
 * Idempotent: only fills suburbs that are still ungeocoded unless --force.
 */
class GeocodeSuburbCentroids extends Command
{
    protected $signature = 'map:geocode-suburbs {--force : Re-geocode suburbs that already have a centroid} {--dry-run}';

    protected $description = 'Geocode each P24 suburb to its centroid (avg of geocoded properties/listings) for the buyer-demand heatmap.';

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

        // Pass 1 — centroids from properties grouped by p24_suburb_id (exact id key).
        $propCentroids = DB::table('properties')
            ->whereNotNull('p24_suburb_id')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereNull('deleted_at')
            ->groupBy('p24_suburb_id')
            ->selectRaw('p24_suburb_id, AVG(latitude) as lat, AVG(longitude) as lng, COUNT(*) as n')
            ->get()
            ->keyBy('p24_suburb_id');

        // Pass 2 — centroids from prospecting_listings grouped by free-text suburb name.
        $listingCentroids = DB::table('prospecting_listings')
            ->whereNotNull('suburb')->where('suburb', '!=', '')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereNull('deleted_at')
            ->groupBy('suburb')
            ->selectRaw('suburb, AVG(latitude) as lat, AVG(longitude) as lng, COUNT(*) as n')
            ->get();
        $listingByName = [];
        foreach ($listingCentroids as $row) {
            $listingByName[mb_strtolower(trim((string) $row->suburb))] = $row;
        }

        $fromProps = 0;
        $fromListings = 0;
        $failed = 0;
        $failedNames = [];

        foreach ($suburbs as $suburb) {
            $lat = $lng = null;
            $source = null;

            if (isset($propCentroids[$suburb->id])) {
                $c = $propCentroids[$suburb->id];
                $lat = round((float) $c->lat, 7);
                $lng = round((float) $c->lng, 7);
                $source = 'properties_avg';
                $fromProps++;
            } else {
                $key = mb_strtolower(trim((string) $suburb->name));
                if ($key !== '' && isset($listingByName[$key])) {
                    $c = $listingByName[$key];
                    $lat = round((float) $c->lat, 7);
                    $lng = round((float) $c->lng, 7);
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
            . "Geocoded: {$geocoded} (properties_avg: {$fromProps}, listings_avg: {$fromListings}) · failed (no geocoded source): {$failed}.");
        if ($failed > 0) {
            $this->warn('Suburbs with no geocodable source (sample): ' . implode(', ', $failedNames)
                . ($failed > count($failedNames) ? ' …' : ''));
        }

        return self::SUCCESS;
    }
}
