<?php

namespace App\Http\Controllers\Settings\Prospecting;

use App\Http\Controllers\Controller;
use App\Models\Prospecting\RegionAlias;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * AT-239 Regions screen (Johan-final region model) — Settings → Prospecting → Regions.
 *
 * A region record is the official MDB municipality (canonical, immutable, assigned
 * mechanically by point-in-polygon). The agency edits only the ALIAS: the display
 * name used everywhere (MIC filter, tiles, reports). Empty alias → the municipal
 * name shows. P24-alias suggestions are pre-filled (Ray Nkonyeni → "Hibiscus
 * Coast") and shown as a hint; agent-captured area text also surfaces as a hint.
 * The unmapped queue lists towns whose suburbs carry no coordinate (cannot be
 * placed) so they can be geocoded or hand-assigned.
 */
class RegionsController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);

        $regions = RegionAlias::where('agency_id', $agencyId)
            ->orderBy('display_order')->orderBy('municipality')->get();

        // Town + suburb counts per municipality, and a suburb sample for the row.
        foreach ($regions as $region) {
            $townIds = DB::table('towns')->where('agency_id', $agencyId)
                ->where('region', $region->municipality)->whereNull('deleted_at')->pluck('id');
            $region->town_count = $townIds->count();
            $region->suburb_count = $townIds->isEmpty() ? 0 : DB::table('town_suburbs')
                ->where('agency_id', $agencyId)->whereIn('town_id', $townIds)->whereNull('deleted_at')->count();
            $region->town_names = DB::table('towns')->where('agency_id', $agencyId)
                ->where('region', $region->municipality)->whereNull('deleted_at')
                ->orderBy('name')->pluck('name')->implode(', ');
        }

        // Unmapped queue — towns not yet placed in a municipality (no geocoded suburb,
        // or centroid outside all boundaries). These need geocoding or hand-mapping.
        $unmappedTowns = DB::table('towns')->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('region')->orWhere('region', ''))
            ->orderBy('name')->get(['id', 'name']);

        return view('settings.prospecting.regions', compact('regions', 'unmappedTowns'));
    }

    public function updateAlias(Request $request, string $municipality): RedirectResponse
    {
        $user = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);

        $data = $request->validate([
            'alias' => ['nullable', 'string', 'max:120'],
        ]);
        $alias = trim((string) ($data['alias'] ?? ''));

        $region = RegionAlias::where('agency_id', $agencyId)
            ->where('municipality', $municipality)->first();
        if (! $region) {
            return back()->withErrors('That region is not configured for this agency.');
        }

        $region->alias = $alias !== '' ? $alias : null;
        $region->save();

        return back()->with('status', 'Region name updated — “' . $region->displayName() . '” now displays everywhere.');
    }
}
