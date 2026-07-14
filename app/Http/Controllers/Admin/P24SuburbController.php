<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\P24Suburb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class P24SuburbController extends Controller
{
    private function ensureAccess(): void
    {
        abort_unless(Auth::user()?->hasPermission('manage_p24'), 403);
    }

    /**
     * AT-246 region model (Johan-signed) — ONE screen. Three layers:
     *   (1) suburb→town  = P24 (p24_suburbs.p24_city_id → p24_cities). READ-ONLY.
     *   (2) town→region  = MDB municipality per TOWN (towns.region), auto where the
     *       town-centroid PIP is confident, a plain dropdown to pick/override otherwise.
     *   (3) region→alias = the agency's display name (region_aliases).
     * MIC reads this and nothing else. The parallel town_suburbs editor is retired.
     */
    public function index(Request $request)
    {
        $this->ensureAccess();
        $agencyId = (int) ($request->user()?->effectiveAgencyId() ?: 0);
        $search   = trim((string) $request->query('q', ''));

        // (1)+(2) The agency's P24 towns, each with its assigned region (municipality).
        $towns = \DB::table('towns')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'p24_city_id', 'region']);

        // Suburbs per town (P24, read-only).
        $cityIds = $towns->pluck('p24_city_id')->filter()->all();
        $suburbsByCity = [];
        if (! empty($cityIds)) {
            foreach (\DB::table('p24_suburbs')->whereIn('p24_city_id', $cityIds)->whereNull('deleted_at')
                        ->orderBy('name')->get(['p24_city_id', 'name']) as $s) {
                $suburbsByCity[$s->p24_city_id][] = $s->name;
            }
        }
        foreach ($towns as $t) {
            $t->suburbs = $suburbsByCity[$t->p24_city_id] ?? [];
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $towns = $towns->filter(function ($t) use ($needle) {
                if (str_contains(mb_strtolower((string) $t->name), $needle)) { return true; }
                foreach ($t->suburbs as $s) { if (str_contains(mb_strtolower((string) $s), $needle)) { return true; } }
                return false;
            })->values();
        }

        // (3) Aliases for the municipalities the agency has stock in + the full national
        //     municipality list for the per-town region dropdown.
        $municipalitiesWithStock = $towns->pluck('region')->filter()->unique()->sort()->values();
        $aliases = \DB::table('region_aliases')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereIn('municipality', $municipalitiesWithStock)->orderBy('municipality')->pluck('alias', 'municipality');
        $allMunicipalities = \DB::table('region_aliases')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->orderBy('municipality')->pluck('municipality');

        return view('admin.p24-suburbs', compact('towns', 'aliases', 'allMunicipalities', 'municipalitiesWithStock', 'search'));
    }

    /** (2) Set/override a town's region (the MDB municipality). */
    public function saveTownRegion(Request $request, int $townId)
    {
        $this->ensureAccess();
        $agencyId = (int) ($request->user()?->effectiveAgencyId() ?: 0);
        $data = $request->validate(['region' => ['nullable', 'string', 'max:120']]);
        $region = trim((string) ($data['region'] ?? ''));

        $town = \DB::table('towns')->where('agency_id', $agencyId)->where('id', $townId)->whereNull('deleted_at')->first();
        abort_unless($town, 404);

        \DB::table('towns')->where('id', $town->id)->update(['region' => $region !== '' ? $region : null, 'updated_at' => now()]);
        if ($region !== '') {
            \DB::table('region_aliases')->updateOrInsert(
                ['agency_id' => $agencyId, 'municipality' => $region],
                ['deleted_at' => null, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        return back()->with('success', "Region for {$town->name} set to " . ($region ?: '— (unassigned)') . '.');
    }

    /** (3) Set the agency alias for a municipality (display name; blank → municipal name). */
    public function saveAlias(Request $request, string $municipality)
    {
        $this->ensureAccess();
        $agencyId = (int) ($request->user()?->effectiveAgencyId() ?: 0);
        $data = $request->validate(['alias' => ['nullable', 'string', 'max:120']]);
        $alias = trim((string) ($data['alias'] ?? ''));

        \DB::table('region_aliases')->updateOrInsert(
            ['agency_id' => $agencyId, 'municipality' => $municipality],
            ['alias' => $alias !== '' ? $alias : null, 'deleted_at' => null, 'updated_at' => now(), 'created_at' => now()],
        );

        return back()->with('success', "“{$municipality}” now displays as “" . ($alias ?: $municipality) . '”.');
    }

    public function store(Request $request)
    {
        $this->ensureAccess();

        $validated = $request->validate([
            'name'            => 'required|string|max:100',
            'p24_id'          => 'nullable|integer|min:1',
            'region'          => 'nullable|string|max:100',
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

        P24Suburb::create([
            'name'            => trim($validated['name']),
            'slug'            => $slug,
            'p24_id'          => $validated['p24_id'] ?? null,
            'region'          => $validated['region'] ?? null,
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
            'region'          => 'nullable|string|max:100',
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

        $p24Suburb->update([
            'name'            => trim($validated['name']),
            'slug'            => $slug,
            'p24_id'          => $validated['p24_id'] ?? null,
            'region'          => $validated['region'] ?? null,
            'surrounding_ids' => $surroundingIds,
            'confirmed'       => !empty($validated['confirmed']),
        ]);

        return redirect()->route('admin.p24-suburbs.index')
            ->with('success', 'Suburb updated.');
    }

    public function destroy(P24Suburb $p24Suburb)
    {
        $this->ensureAccess();

        $p24Suburb->delete();

        return redirect()->route('admin.p24-suburbs.index')
            ->with('success', 'Suburb archived.');
    }
}
