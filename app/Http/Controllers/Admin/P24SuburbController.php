<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\P24Suburb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class P24SuburbController extends Controller
{
    private function ensureAccess(): void
    {
        abort_unless(Auth::user()?->hasPermission('manage_p24'), 403);
    }

    private function agencyId(): int
    {
        return (int) (Auth::user()?->effectiveAgencyId() ?: 0);
    }

    public function index(Request $request)
    {
        $this->ensureAccess();
        $agencyId = $this->agencyId();

        // p24_suburbs is a shared table: a handful of agency-curated mappings
        // live alongside the full ~27k-row national P24 location tree that
        // `php artisan p24:sync-locations` caches here. Rendering every row as
        // an editable form exhausted PHP memory, so filtering and paging are
        // done in SQL — only one page (100 rows) is ever materialised. Confirmed
        // mappings (the agency's verified areas) sort to the top.
        //
        // AT-246 — Region is the TOWN's region (MDB municipality), read THROUGH
        // the suburb's P24 town (p24_suburbs.p24_city_id → towns.p24_city_id →
        // towns.region), never the suburb's own stale `region` column. Editing a
        // region happens at TOWN level (saveTownRegion) so all of a town's
        // suburbs move together — one truth. suburb→town→province is P24's
        // read-only hierarchy. MIC By-region reads towns.region and nothing else.
        $search    = trim((string) $request->query('q', ''));
        $region    = trim((string) $request->query('region', ''));    // town-level municipality
        $province  = trim((string) $request->query('province', ''));  // p24_provinces.id
        $town      = trim((string) $request->query('town', ''));      // p24_cities.id (== p24_city_id)
        $confirmed = (string) $request->query('confirmed', '');

        $query = P24Suburb::query();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%');
                if (ctype_digit($search)) {
                    $q->orWhere('p24_id', (int) $search);
                }
            });
        }

        // Province filter — suburbs whose P24 town sits in the province.
        if ($province !== '' && ctype_digit($province)) {
            $cityIds = DB::table('p24_cities')->where('p24_province_id', (int) $province)->pluck('id');
            $query->whereIn('p24_city_id', $cityIds);
        }

        // Town filter — exact P24 city (town) id.
        if ($town !== '' && ctype_digit($town)) {
            $query->where('p24_city_id', (int) $town);
        }

        // Region filter — town-level: suburbs whose town carries that municipality.
        if ($region !== '') {
            $cityIds = DB::table('towns')->where('agency_id', $agencyId)->whereNull('deleted_at')
                ->where('region', $region)->whereNotNull('p24_city_id')->pluck('p24_city_id');
            $query->whereIn('p24_city_id', $cityIds);
        }

        if ($confirmed === '1' || $confirmed === '0') {
            $query->where('confirmed', $confirmed === '1');
        }

        $suburbs = $query
            ->orderByDesc('confirmed')
            ->orderBy('name')
            ->paginate(100)
            ->withQueryString();

        // ── Read-through maps for THIS page's rows only (budget-safe) ──
        $cityIds = $suburbs->pluck('p24_city_id')->filter()->unique()->values();

        // suburb's P24 town → the agency's town row (id for the edit endpoint + region).
        $townsByCity = DB::table('towns')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereIn('p24_city_id', $cityIds)->get()->keyBy('p24_city_id');
        // suburb's P24 town → canonical P24 city (name + province) — read-only hierarchy.
        $citiesById = DB::table('p24_cities')->whereIn('id', $cityIds)->get(['id', 'name', 'p24_province_id'])->keyBy('id');
        $provincesById = DB::table('p24_provinces')
            ->whereIn('id', $citiesById->pluck('p24_province_id')->filter()->unique()->values())
            ->pluck('name', 'id');

        // Municipality → agency alias (display-name override, e.g. "Hibiscus Coast").
        $aliases = DB::table('region_aliases')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->pluck('alias', 'municipality');
        // The assignable municipalities for the per-town region dropdown.
        $municipalityOptions = DB::table('region_aliases')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->orderBy('municipality')->pluck('municipality');

        // Filter option lists (all small / agency-scoped).
        $regions = DB::table('towns')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereNotNull('region')->where('region', '!=', '')->distinct()->orderBy('region')->pluck('region');
        $provinceOptions = DB::table('p24_provinces')->orderBy('name')->pluck('name', 'id');
        $townOptions = DB::table('towns')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereNotNull('p24_city_id')->orderBy('name')->get(['p24_city_id', 'name']);

        return view('admin.p24-suburbs', [
            'suburbs'             => $suburbs,
            'regions'             => $regions,
            'search'              => $search,
            'selectedRegion'      => $region,
            'selectedStatus'      => $confirmed,
            'selectedProvince'    => $province,
            'selectedTown'        => $town,
            'townsByCity'         => $townsByCity,
            'citiesById'          => $citiesById,
            'provincesById'       => $provincesById,
            'aliases'             => $aliases,
            'municipalityOptions' => $municipalityOptions,
            'provinceOptions'     => $provinceOptions,
            'townOptions'         => $townOptions,
        ]);
    }

    /**
     * AT-246 — set/override a P24 town's region (the MDB municipality). One truth:
     * the change applies to every suburb P24 files under this town. Auto-fill from
     * the town-centroid PIP lives in the AssignMunicipalities command; this is the
     * manual set/override door. Blank clears it (back to unassigned).
     */
    public function saveTownRegion(Request $request, int $townId)
    {
        $this->ensureAccess();
        $agencyId = $this->agencyId();

        $region = trim((string) $request->input('region', ''));

        $town = DB::table('towns')->where('agency_id', $agencyId)->where('id', $townId)
            ->whereNull('deleted_at')->first();
        abort_if($town === null, 404);

        DB::table('towns')->where('id', $town->id)
            ->update(['region' => $region !== '' ? $region : null, 'updated_at' => now()]);

        // Ensure the municipality has an alias row so it can be renamed later.
        if ($region !== '') {
            DB::table('region_aliases')->updateOrInsert(
                ['agency_id' => $agencyId, 'municipality' => $region],
                ['updated_at' => now()]
            );
        }

        $label = $region !== '' ? $region : 'no region';
        return back()->with('success', "“{$town->name}” and all its suburbs are now in {$label}.");
    }

    /**
     * AT-246 — set the agency's display alias for a municipality (e.g. Ray
     * Nkonyeni → "Hibiscus Coast"). Blank alias falls back to the municipal name.
     */
    public function saveAlias(Request $request, string $municipality)
    {
        $this->ensureAccess();
        $agencyId = $this->agencyId();

        $alias = trim((string) $request->input('alias', ''));

        DB::table('region_aliases')->updateOrInsert(
            ['agency_id' => $agencyId, 'municipality' => $municipality],
            ['alias' => $alias !== '' ? $alias : null, 'updated_at' => now()]
        );

        return back()->with('success', "“{$municipality}” now displays as “" . ($alias ?: $municipality) . '”.');
    }

    public function store(Request $request)
    {
        $this->ensureAccess();

        $validated = $request->validate([
            'name'            => 'required|string|max:100',
            'p24_id'          => 'nullable|integer|min:1',
            'surrounding_ids' => 'nullable|string|max:500',
            'confirmed'       => 'nullable|boolean',
        ]);

        $slug = strtolower(str_replace(' ', '-', trim($validated['name'])));

        // Parse surrounding IDs from comma-separated string
        $surroundingIds = [];
        if (!empty($validated['surrounding_ids'])) {
            $surroundingIds = array_map('intval', array_filter(
                explode(',', $validated['surrounding_ids']),
                fn($v) => trim($v) !== ''
            ));
        }

        // AT-246 — no hardcoded 'kzn-south-coast' default. A new suburb inherits its
        // region from its P24 town (once mapped) or gets the marked suburb-level
        // fallback; it is NOT silently pinned to the South Coast.
        P24Suburb::create([
            'name'            => trim($validated['name']),
            'slug'            => $slug,
            'p24_id'          => $validated['p24_id'] ?? null,
            'region'          => null,
            'surrounding_ids' => $surroundingIds,
            'confirmed'       => !empty($validated['confirmed']),
        ]);

        return redirect()->route('admin.p24-suburbs.index')
            ->with('success', 'Suburb added successfully.');
    }

    public function update(Request $request, P24Suburb $p24Suburb)
    {
        $this->ensureAccess();

        $validated = $request->validate([
            'name'            => 'required|string|max:100',
            'p24_id'          => 'nullable|integer|min:1',
            'surrounding_ids' => 'nullable|string|max:500',
            'confirmed'       => 'nullable|boolean',
        ]);

        $slug = strtolower(str_replace(' ', '-', trim($validated['name'])));

        $surroundingIds = [];
        if (!empty($validated['surrounding_ids'])) {
            $surroundingIds = array_map('intval', array_filter(
                explode(',', $validated['surrounding_ids']),
                fn($v) => trim($v) !== ''
            ));
        }

        // AT-246 — a town-linked row Save does NOT touch `region` (region is the
        // town's, edited via saveCityRegion; writing a default here would clobber
        // every suburb). The ONLY time the suburb-level `region` is written is the
        // truly-townless fallback (a manual suburb with no P24 city) — that row
        // DOES submit a `region` field, so we honour it when present.
        $data = [
            'name'            => trim($validated['name']),
            'slug'            => $slug,
            'p24_id'          => $validated['p24_id'] ?? null,
            'surrounding_ids' => $surroundingIds,
            'confirmed'       => !empty($validated['confirmed']),
        ];
        if ($request->has('region')) {
            $r = trim((string) $request->input('region'));
            $data['region'] = $r !== '' ? $r : null;
        }
        $p24Suburb->update($data);

        return redirect()->route('admin.p24-suburbs.index')
            ->with('success', 'Suburb updated.');
    }

    /**
     * AT-246 — assign a region by P24 CITY (town). Works for EVERY suburb, because
     * every suburb carries a p24_city_id even when the agency has no towns row for
     * that city yet (88% of them). We find-or-create the agency's town for the city
     * (materialise-on-assign — the "link to town" the spec allows) and set its
     * region, so the change applies to every suburb P24 files under that town and no
     * row is ever a dead end. suburb→town stays P24's read-only truth.
     */
    public function saveCityRegion(Request $request, int $cityId)
    {
        $this->ensureAccess();
        $agencyId = $this->agencyId();
        abort_if($agencyId <= 0, 403, 'Switch into an agency before assigning regions.');

        $city = DB::table('p24_cities')->where('id', $cityId)->first();
        abort_if($city === null, 404);

        $region = trim((string) $request->input('region', ''));

        $exists = DB::table('towns')->where('agency_id', $agencyId)
            ->where('p24_city_id', $cityId)->whereNull('deleted_at')->exists();

        if ($exists) {
            DB::table('towns')->where('agency_id', $agencyId)->where('p24_city_id', $cityId)
                ->whereNull('deleted_at')
                ->update(['region' => $region !== '' ? $region : null, 'updated_at' => now()]);
        } else {
            DB::table('towns')->insert([
                'agency_id'   => $agencyId,
                'name'        => $city->name,
                'slug'        => \Illuminate\Support\Str::slug($city->name . '-' . $cityId),
                'p24_city_id' => $cityId,
                'region'      => $region !== '' ? $region : null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        if ($region !== '') {
            DB::table('region_aliases')->updateOrInsert(
                ['agency_id' => $agencyId, 'municipality' => $region],
                ['updated_at' => now()]
            );
        }

        $label = $region !== '' ? $region : 'no region';
        return back()->with('success', "“{$city->name}” and all its suburbs are now in {$label}.");
    }

    public function destroy(P24Suburb $p24Suburb)
    {
        $this->ensureAccess();

        $p24Suburb->delete();

        return redirect()->route('admin.p24-suburbs.index')
            ->with('success', 'Suburb archived.');
    }
}
