<?php

namespace App\Console\Commands\Prospecting;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * AT-239 — correct the blanket p24_suburbs.region.
 *
 * Every P24 suburb historically carried the literal default 'kzn-south-coast'
 * (create_p24_suburbs_table, 2026-02) — a blanket that was never a real
 * assignment. This aligns the field with the MDB municipality model: clear the
 * blanket, then set region = the containing MDB municipality wherever the suburb
 * has a derivable coordinate (averaged from geocoded prospecting listings of the
 * same name), point-in-polygon against the cached boundary layer. Suburbs with
 * no coordinate are left NULL (the unmapped queue) — NEVER a blanket default.
 *
 * Idempotent + never-blanket by construction: it only ever writes a specific
 * municipality to the specific suburb name it resolved, or NULL.
 */
class ReconcileP24SuburbRegions extends Command
{
    protected $signature = 'prospecting:reconcile-p24-suburb-regions
        {--dry-run : report only, write nothing}';

    protected $description = 'Replace the blanket p24_suburbs.region default with MDB municipalities (NULL where unknown).';

    private const CACHE_PATH = 'mdb/zaf_adm3.geojson';
    private const BLANKET = 'kzn-south-coast';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $features = json_decode((string) (Storage::exists(self::CACHE_PATH) ? Storage::get(self::CACHE_PATH) : ''), true)['features'] ?? null;
        if (! $features) {
            $this->error('MDB boundary layer not cached — run prospecting:assign-municipalities first (it downloads it).');
            return self::FAILURE;
        }

        $total = DB::table('p24_suburbs')->count();
        $blanket = DB::table('p24_suburbs')->where('region', self::BLANKET)->count();
        $this->info("p24_suburbs: {$total} total, {$blanket} carrying the '" . self::BLANKET . "' blanket.");

        // 1) Clear the blanket (only the false default — never touch a real assignment).
        if (! $dry) {
            DB::table('p24_suburbs')->where('region', self::BLANKET)->update(['region' => null]);
        }

        // 2) Derive a centroid per suburb NAME from geocoded prospecting listings, PIP → municipality.
        $centroids = DB::table('prospecting_listings')
            ->whereNotNull('latitude')->whereNotNull('longitude')->whereNotNull('suburb')
            ->selectRaw('LOWER(TRIM(suburb)) sub, AVG(latitude) la, AVG(longitude) lo')
            ->groupBy(DB::raw('LOWER(TRIM(suburb))'))
            ->get();

        $assigned = 0; $unresolved = 0;
        foreach ($centroids as $c) {
            $munic = $this->municipalityAt($features, (float) $c->lo, (float) $c->la);
            if ($munic === null) { $unresolved++; continue; }
            $this->line(($dry ? '[dry] ' : '') . "  {$c->sub} → {$munic}");
            if (! $dry) {
                DB::table('p24_suburbs')->whereRaw('LOWER(TRIM(name)) = ?', [$c->sub])->update(['region' => $munic]);
            }
            $assigned++;
        }

        $nullNow = $dry ? '(dry)' : DB::table('p24_suburbs')->whereNull('region')->count();
        $this->info(($dry ? '[dry-run] ' : '') . "assigned {$assigned} suburb-names to a municipality, {$unresolved} centroids outside boundaries, {$nullNow} rows now NULL (unmapped).");

        return self::SUCCESS;
    }

    private function municipalityAt(array $features, float $lng, float $lat): ?string
    {
        foreach ($features as $f) {
            $g = $f['geometry'] ?? null;
            if (! $g) { continue; }
            $hit = false;
            if ($g['type'] === 'Polygon') {
                $hit = $this->pip($lng, $lat, $g['coordinates']);
            } elseif ($g['type'] === 'MultiPolygon') {
                foreach ($g['coordinates'] as $poly) {
                    if ($this->pip($lng, $lat, $poly)) { $hit = true; break; }
                }
            }
            if ($hit) { return $f['properties']['shapeName'] ?? null; }
        }
        return null;
    }

    private function pip(float $lng, float $lat, array $rings): bool
    {
        $inside = false;
        foreach ($rings as $ri => $ring) {
            $n = count($ring); $j = $n - 1; $c = false;
            for ($i = 0; $i < $n; $i++) {
                $xi = $ring[$i][0]; $yi = $ring[$i][1]; $xj = $ring[$j][0]; $yj = $ring[$j][1];
                if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                    $c = ! $c;
                }
                $j = $i;
            }
            if ($ri === 0) { $inside = $c; } elseif ($c) { $inside = false; }
        }
        return $inside;
    }
}
