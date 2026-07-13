<?php

namespace App\Console\Commands\Prospecting;

use App\Models\Prospecting\RegionAlias;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * AT-239 region engine (Johan-final 2026-07-13) — mechanical municipal assignment.
 *
 * The region is the official MDB local municipality, assigned by point-in-polygon:
 * each town's centroid (averaged from its suburbs' geocoded prospecting listings)
 * is tested against the cached MDB boundary layer (geoBoundaries ADM3-ZAF, MDB
 * source, vintage 2020) → the containing municipality becomes towns.region.
 * Municipalities are nationally consistent for every agency. Each municipality
 * gets a region_aliases row carrying an agency-editable alias, pre-filled from
 * the P24-alias map where one exists (Ray Nkonyeni → "Hibiscus Coast").
 *
 * The polygon layer is cached on disk (storage/app/mdb/) — downloaded once, then
 * every run is offline (no per-request external calls). Towns whose suburbs carry
 * no coordinate cannot be placed → left for the unmapped queue.
 */
class AssignMunicipalities extends Command
{
    protected $signature = 'prospecting:assign-municipalities
        {--agency= : limit to one agency_id (default: every agency with towns)}
        {--refresh-boundaries : re-download the MDB boundary layer}
        {--dry-run : report only, write nothing}';

    protected $description = 'Assign towns.region to the MDB municipality via point-in-polygon; seed agency region aliases.';

    private const BOUNDARY_URL = 'https://github.com/wmgeolab/geoBoundaries/raw/9469f09/releaseData/gbOpen/ZAF/ADM3/geoBoundaries-ZAF-ADM3_simplified.geojson';
    private const BOUNDARY_VINTAGE = 'MDB via geoBoundaries ADM3-ZAF, vintage 2020';
    private const CACHE_PATH = 'mdb/zaf_adm3.geojson';

    /** Municipality (canonical MDB) → P24 alias region, pre-filled as the agency alias suggestion. */
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
        $this->info('Boundary layer: ' . self::BOUNDARY_VINTAGE . ' (' . count($features) . ' municipalities).');

        $agencyIds = $this->option('agency')
            ? [(int) $this->option('agency')]
            : DB::table('towns')->whereNull('deleted_at')->distinct()->pluck('agency_id')->map(fn ($v) => (int) $v)->all();

        $now = now();
        foreach ($agencyIds as $agencyId) {
            $towns = DB::table('towns')->where('agency_id', $agencyId)->whereNull('deleted_at')->get(['id', 'name', 'slug']);
            $assigned = 0; $queued = 0; $municipalities = [];

            foreach ($towns as $town) {
                $c = $this->townCentroid($agencyId, $town->id);
                if ($c === null) {
                    $queued++;
                    $this->line("  [queue] {$town->name}: no geocoded suburb → cannot place");
                    continue;
                }
                $munic = $this->municipalityAt($features, $c['lng'], $c['lat']);
                if ($munic === null) {
                    $queued++;
                    $this->line("  [queue] {$town->name}: centroid outside all boundaries");
                    continue;
                }
                $this->line("  {$town->name} → {$munic}");
                if (! $dry) {
                    DB::table('towns')->where('id', $town->id)->update(['region' => $munic, 'updated_at' => $now]);
                }
                $assigned++;
                $municipalities[$munic] = true;
            }

            // One region_aliases row per municipality the agency operates in; pre-fill the
            // alias from the P24 map on first creation (agency can change it later).
            $order = 0;
            foreach (array_keys($municipalities) as $munic) {
                $order++;
                $suggestion = self::P24_ALIAS_MAP[$munic] ?? null;
                if (! $dry) {
                    $existing = RegionAlias::withoutGlobalScopes()
                        ->where('agency_id', $agencyId)->where('municipality', $munic)->first();
                    if ($existing) {
                        $existing->alias_suggestion = $suggestion;
                        $existing->save();
                    } else {
                        RegionAlias::withoutGlobalScopes()->create([
                            'agency_id' => $agencyId,
                            'municipality' => $munic,
                            'alias' => $suggestion,           // pre-filled ("Hibiscus Coast" on Ray Nkonyeni)
                            'alias_suggestion' => $suggestion,
                            'display_order' => $order,
                        ]);
                    }
                }
            }

            $this->info(($dry ? '[dry-run] ' : '') . "agency {$agencyId}: {$assigned} towns placed, {$queued} queued, " . count($municipalities) . ' municipalities.');
        }

        return self::SUCCESS;
    }

    /** @return array{lat:float,lng:float}|null */
    private function townCentroid(int $agencyId, int $townId): ?array
    {
        $norms = DB::table('town_suburbs')->where('agency_id', $agencyId)->where('town_id', $townId)
            ->whereNull('deleted_at')->pluck('suburb_normalised')->all();
        if (empty($norms)) { return null; }

        $row = DB::table('prospecting_listings')
            ->where('agency_id', $agencyId)
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereIn(DB::raw('LOWER(TRIM(suburb))'), $norms)
            ->selectRaw('AVG(latitude) la, AVG(longitude) lo, COUNT(*) c')
            ->first();

        if (! $row || ! $row->c) { return null; }
        return ['lat' => (float) $row->la, 'lng' => (float) $row->lo];
    }

    /** Load + cache the boundary features (offline after first fetch). @return array<int,array>|null */
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

    /** Ray-casting point-in-polygon; ring[0] outer, rest holes. */
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
            if ($ri === 0) { $inside = $c; } elseif ($c) { $inside = false; } // inside a hole → out
        }
        return $inside;
    }
}
