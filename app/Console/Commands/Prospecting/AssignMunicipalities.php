<?php

namespace App\Console\Commands\Prospecting;

use App\Models\Prospecting\RegionAlias;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * AT-246 corrected region engine (Johan-final) — TOWN-level, never suburb-level.
 *
 * Three layers, each from its natural source, zero duplication:
 *   (1) suburb→town  = P24 (p24_suburbs.p24_city_id → p24_cities). Read-only.
 *   (2) town→region  = MDB municipality by spatial lookup of the TOWN's centroid.
 *   (3) region→alias = agency alias (region_aliases; national 212 municipalities).
 *
 * `towns` now mirrors the P24 towns the agency has stock in (name + p24_city_id +
 * region = its municipality). Suburbs are NOT stored in an editable town_suburbs
 * tree — they come from p24_suburbs; the parallel tree is retired (soft-deleted).
 *
 * Fixes yesterday's fault: assigning each SUBURB's own coordinate put Albersville
 * (a Port Shepstone suburb) in "Umzumbe". Now Port Shepstone the TOWN is placed
 * once (→ Ray Nkonyeni → alias "Hibiscus Coast") and all its suburbs inherit it.
 */
class AssignMunicipalities extends Command
{
    protected $signature = 'prospecting:assign-municipalities
        {--agency= : limit to one agency_id (default: every agency with prospecting stock)}
        {--refresh-boundaries : re-download the MDB boundary layer}
        {--dry-run : report only, write nothing}';

    protected $description = 'Assign each P24 town (with stock) to its MDB municipality by town-centroid PIP; seed national region list.';

    private const BOUNDARY_URL = 'https://github.com/wmgeolab/geoBoundaries/raw/9469f09/releaseData/gbOpen/ZAF/ADM3/geoBoundaries-ZAF-ADM3_simplified.geojson';
    private const BOUNDARY_VINTAGE = 'MDB via geoBoundaries ADM3-ZAF, vintage 2020';
    private const CACHE_PATH = 'mdb/zaf_adm3.geojson';

    private const P24_ALIAS_MAP = [
        'Ray Nkonyeni' => 'Hibiscus Coast',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $features = $this->loadBoundaries((bool) $this->option('refresh-boundaries'));
        if ($features === null) {
            $this->error('Could not load the MDB boundary layer.');
            return self::FAILURE;
        }
        $allMunicipalities = collect($features)->map(fn ($f) => $f['properties']['shapeName'] ?? null)->filter()->unique()->values();
        $this->info('Boundary layer: ' . self::BOUNDARY_VINTAGE . ' — ' . $allMunicipalities->count() . ' local municipalities (national).');

        $agencyIds = $this->option('agency')
            ? [(int) $this->option('agency')]
            : DB::table('prospecting_listings')->distinct()->pluck('agency_id')->map(fn ($v) => (int) $v)->filter()->all();

        $now = now();
        foreach ($agencyIds as $agencyId) {
            // (1) National region list.
            if (! $dry) {
                $order = 0;
                foreach ($allMunicipalities as $munic) {
                    $order++;
                    $suggestion = self::P24_ALIAS_MAP[$munic] ?? null;
                    $existing = RegionAlias::withoutGlobalScopes()->where('agency_id', $agencyId)->where('municipality', $munic)->first();
                    if ($existing) {
                        $existing->alias_suggestion = $suggestion;
                        if ($suggestion && ! $existing->alias) { $existing->alias = $suggestion; }
                        $existing->display_order = $order;
                        $existing->save();
                    } else {
                        RegionAlias::withoutGlobalScopes()->create([
                            'agency_id' => $agencyId, 'municipality' => $munic,
                            'alias' => $suggestion, 'alias_suggestion' => $suggestion, 'display_order' => $order,
                        ]);
                    }
                }
            }

            // (2) Per-P24-town centroid (from that town's geocoded listings), grouped by
            //     the P24 city id — the town, not the suburb.
            $townCentroids = DB::table('prospecting_listings as pl')
                ->join('p24_suburbs as ps', DB::raw('LOWER(TRIM(ps.name))'), '=', DB::raw('LOWER(TRIM(pl.suburb))'))
                ->where('pl.agency_id', $agencyId)
                ->whereNotNull('pl.latitude')->whereNotNull('pl.longitude')
                ->whereNotNull('ps.p24_city_id')
                ->groupBy('ps.p24_city_id')
                ->selectRaw('ps.p24_city_id, AVG(pl.latitude) la, AVG(pl.longitude) lo, COUNT(*) c')
                ->get();

            // Retire the parallel town_suburbs tree + rebuild towns (soft — no data loss).
            if (! $dry) {
                DB::table('town_suburbs')->where('agency_id', $agencyId)->whereNull('deleted_at')->update(['deleted_at' => $now]);
                DB::table('towns')->where('agency_id', $agencyId)->whereNull('deleted_at')->update(['deleted_at' => $now]);
            }

            $placed = 0; $queued = 0; $munisWithStock = [];
            foreach ($townCentroids as $t) {
                $townName = DB::table('p24_cities')->where('id', $t->p24_city_id)->value('name') ?: ('P24 town ' . $t->p24_city_id);
                $munic = $this->municipalityAt($features, (float) $t->lo, (float) $t->la);
                if ($munic === null) { $queued++; continue; }
                $munisWithStock[$munic] = ($munisWithStock[$munic] ?? 0) + 1;
                $this->line(($dry ? '[dry] ' : '') . "  {$townName} (P24 town {$t->p24_city_id}) → {$munic}");
                if (! $dry) {
                    $slug = Str::slug($townName) . '-' . $t->p24_city_id;
                    DB::table('towns')->updateOrInsert(
                        ['agency_id' => $agencyId, 'slug' => $slug],
                        ['name' => $townName, 'p24_city_id' => $t->p24_city_id, 'region' => $munic,
                         'display_order' => 0, 'deleted_at' => null, 'updated_at' => $now, 'created_at' => $now],
                    );
                }
                $placed++;
            }

            $this->info(($dry ? '[dry-run] ' : '') . "agency {$agencyId}: {$placed} P24 towns placed across " . count($munisWithStock)
                . ' municipalities, ' . $queued . ' town-centroids outside boundaries. National region rows: ' . $allMunicipalities->count() . '.');
        }

        return self::SUCCESS;
    }

    private function loadBoundaries(bool $refresh): ?array
    {
        if ($refresh || ! Storage::exists(self::CACHE_PATH)) {
            $this->line('Downloading MDB boundary layer (one-time cache)…');
            $ctx = stream_context_create(['http' => ['timeout' => 90], 'https' => ['timeout' => 90]]);
            $body = @file_get_contents(self::BOUNDARY_URL, false, $ctx);
            if ($body === false || strlen($body) < 1000) { return null; }
            Storage::put(self::CACHE_PATH, $body);
        }
        $gj = json_decode(Storage::get(self::CACHE_PATH), true);
        return $gj['features'] ?? null;
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
