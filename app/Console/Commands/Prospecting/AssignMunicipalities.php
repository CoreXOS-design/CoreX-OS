<?php

namespace App\Console\Commands\Prospecting;

use App\Models\Prospecting\RegionAlias;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * AT-239 region engine (Johan-final 2026-07-13, national) — mechanical, NEVER hardcoded.
 *
 * The region is the official MDB local municipality, assigned by point-in-polygon.
 * The region SET is driven by the MDB boundary layer + our actual suburb data, NOT
 * by any curated seed:
 *   • region_aliases carries EVERY MDB local municipality in SA (nationally complete —
 *     "CoreX already knows every municipality"), each with an agency-editable alias.
 *   • Every distinct geocoded prospecting suburb is PIP'd to its municipality and a
 *     town(=municipality) + town_suburb mapping is built, so EVERY municipality that
 *     has stock appears in the MIC region filter (eThekwini included).
 *   • Suburbs with no usable coordinate go to the unmapped queue — never a default.
 *
 * Boundary layer cached on disk (geoBoundaries ADM3-ZAF, MDB source, vintage 2020) —
 * downloaded once, then fully offline.
 */
class AssignMunicipalities extends Command
{
    protected $signature = 'prospecting:assign-municipalities
        {--agency= : limit to one agency_id (default: every agency with prospecting stock)}
        {--refresh-boundaries : re-download the MDB boundary layer}
        {--dry-run : report only, write nothing}';

    protected $description = 'Assign every geocoded suburb to its MDB municipality (PIP); seed the national region list + agency aliases.';

    private const BOUNDARY_URL = 'https://github.com/wmgeolab/geoBoundaries/raw/9469f09/releaseData/gbOpen/ZAF/ADM3/geoBoundaries-ZAF-ADM3_simplified.geojson';
    private const BOUNDARY_VINTAGE = 'MDB via geoBoundaries ADM3-ZAF, vintage 2020';
    private const CACHE_PATH = 'mdb/zaf_adm3.geojson';

    /** Municipality (canonical MDB) → P24 alias, pre-filled as the agency alias. */
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

        // The national municipality list — driven by the MDB layer, never a seed.
        $allMunicipalities = collect($features)
            ->map(fn ($f) => $f['properties']['shapeName'] ?? null)
            ->filter()->unique()->values();
        $this->info('Boundary layer: ' . self::BOUNDARY_VINTAGE . ' — ' . $allMunicipalities->count() . ' local municipalities (national).');

        $agencyIds = $this->option('agency')
            ? [(int) $this->option('agency')]
            : DB::table('prospecting_listings')->distinct()->pluck('agency_id')->map(fn ($v) => (int) $v)->filter()->all();

        $now = now();
        foreach ($agencyIds as $agencyId) {
            // (1) NATIONAL region list — a region_aliases row for every MDB municipality.
            if (! $dry) {
                $order = 0;
                foreach ($allMunicipalities as $munic) {
                    $order++;
                    $suggestion = self::P24_ALIAS_MAP[$munic] ?? null;
                    $existing = RegionAlias::withoutGlobalScopes()
                        ->where('agency_id', $agencyId)->where('municipality', $munic)->first();
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

            // (2) Rebuild towns(=municipality) + town_suburbs from EVERY geocoded suburb.
            //     Soft-clear first so retired curated towns drop out; municipality towns
            //     are un-soft-deleted by the upsert below.
            if (! $dry) {
                DB::table('towns')->where('agency_id', $agencyId)->whereNull('deleted_at')->update(['deleted_at' => $now]);
                DB::table('town_suburbs')->where('agency_id', $agencyId)->whereNull('deleted_at')->update(['deleted_at' => $now]);
            }

            $centroids = DB::table('prospecting_listings')
                ->where('agency_id', $agencyId)
                ->whereNotNull('latitude')->whereNotNull('longitude')->whereNotNull('suburb')
                ->selectRaw('LOWER(TRIM(suburb)) sub, MAX(suburb) label, AVG(latitude) la, AVG(longitude) lo')
                ->groupBy(DB::raw('LOWER(TRIM(suburb))'))
                ->get();

            $placed = 0; $queued = 0; $munisWithStock = [];
            foreach ($centroids as $c) {
                $munic = $this->municipalityAt($features, (float) $c->lo, (float) $c->la);
                if ($munic === null) { $queued++; continue; }
                $munisWithStock[$munic] = ($munisWithStock[$munic] ?? 0) + 1;
                if (! $dry) {
                    $slug = Str::slug($munic);
                    DB::table('towns')->updateOrInsert(
                        ['agency_id' => $agencyId, 'slug' => $slug],
                        ['name' => $munic, 'region' => $munic, 'display_order' => 0, 'deleted_at' => null, 'updated_at' => $now, 'created_at' => $now],
                    );
                    $townId = (int) DB::table('towns')->where('agency_id', $agencyId)->where('slug', $slug)->value('id');
                    DB::table('town_suburbs')->updateOrInsert(
                        ['agency_id' => $agencyId, 'suburb_normalised' => $c->sub],
                        ['town_id' => $townId, 'suburb_name' => $c->label, 'deleted_at' => null, 'updated_at' => $now, 'created_at' => $now],
                    );
                }
                $placed++;
            }

            arsort($munisWithStock);
            $this->info(($dry ? '[dry-run] ' : '') . "agency {$agencyId}: {$placed} suburbs placed across " . count($munisWithStock)
                . ' municipalities-with-stock, ' . $queued . ' queued (no coordinate). National region rows: ' . $allMunicipalities->count() . '.');
            foreach ($munisWithStock as $m => $n) {
                $alias = self::P24_ALIAS_MAP[$m] ?? null;
                $this->line("   {$m}" . ($alias ? " (shows as \"{$alias}\")" : '') . ": {$n} suburbs");
            }
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
