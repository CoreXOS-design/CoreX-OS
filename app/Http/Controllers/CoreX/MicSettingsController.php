<?php

declare(strict_types=1);

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\AddressMatchSetting;
use App\Models\MicAgencySetting;
use App\Services\Prospecting\MicWork\MicWorkTiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The MIC Settings page (mic-work-tab.md section 9, step 6.5): the one place an agency tunes the Work tab. Per agency, saved into
 * `mic_agency_settings.values` through MicAgencySetting. The matching switches (after-import sweep, P24 group linking + price spread, twin
 * auto-link) belong to the matching lane and live in `address_match_settings`: shown here from their one source, changed on their own page.
 */
final class MicSettingsController extends Controller
{
    public function edit(Request $request)
    {
        $agencyId = $this->agencyId($request);
        $s = MicAgencySetting::for($agencyId);

        // Suburbs the agency has adverts in, plus any it already listed, as the area picker.
        $known = DB::table('prospecting_listings as l')->join('p24_suburbs as s', 's.id', '=', 'l.p24_suburb_id')
            ->where('l.agency_id', $agencyId)->whereNull('l.deleted_at')->whereNull('s.deleted_at')
            ->groupBy('s.id', 's.name')->orderBy('s.name')->get(['s.id', 's.name'])->keyBy('id');
        $chosen = $s->areas();
        $extra = array_diff($chosen, $known->keys()->all());
        if ($extra) {
            DB::table('p24_suburbs')->whereIn('id', $extra)->get(['id', 'name'])->each(fn ($r) => $known->put($r->id, $r));
        }

        return view('corex.market-intelligence.settings', [
            'values' => [
                'areas' => $chosen,
                'refresh_interval_days' => $s->refreshIntervalDaysValue(),
                'tiles_shown' => $s->tilesShown(),
                'score_weights' => $s->scoreWeights(),
                'neighbour_months' => $s->neighbourMonthsValue(),
                'neighbour_each_side' => $s->neighbourEachSideValue(),
                'gone_tile_days' => $s->goneTileDaysValue(),
                'detail_pages_per_session' => $s->detailPagesPerSession(),
                'area_agency_pages_per_run' => $s->areaAgencyPagesPerRunValue(),
                'area_street_pages_per_run' => $s->areaStreetPagesPerRunValue(),
                'opt_out_registry_check' => $s->optOutRegistryCheck(),
            ],
            'suburbs' => $known->values(),
            'tiles' => MicWorkTiles::TILES,
            'matching' => AddressMatchSetting::forAgency($agencyId),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $agencyId = $this->agencyId($request);
        $data = $request->validate([
            'areas' => ['nullable', 'array'],
            'areas.*' => ['integer', 'exists:p24_suburbs,id'],
            'add_areas' => ['nullable', 'string', 'max:500'],
            'refresh_interval_days' => ['required', 'integer', 'min:1', 'max:60'],
            'tiles_shown' => ['nullable', 'array'],
            'tiles_shown.*' => ['integer', 'between:1,11'],
            'weights' => ['required', 'array'],
            'weights.seller' => ['required', 'integer', 'min:0', 'max:100'],
            'weights.buyer' => ['required', 'integer', 'min:0', 'max:100'],
            'weights.pocket' => ['required', 'integer', 'min:0', 'max:100'],
            'weights.known' => ['required', 'integer', 'min:0', 'max:100'],
            'weights.reachable' => ['required', 'integer', 'min:0', 'max:100'],
            'neighbour_months' => ['required', 'integer', 'min:1', 'max:6'],
            'neighbour_each_side' => ['required', 'integer', 'min:1', 'max:15'],
            'gone_tile_days' => ['required', 'integer', 'min:1', 'max:365'],
            'detail_pages_per_session' => ['required', 'integer', 'min:1', 'max:200'],
            'area_agency_pages_per_run' => ['required', 'integer', 'min:1', 'max:100'],
            'area_street_pages_per_run' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        if (array_sum($data['weights']) !== 100) {
            return back()->withInput()->withErrors(['weights' => 'The five score weights must add up to 100 (they add up to ' . array_sum($data['weights']) . ').']);
        }
        $tiles = array_values(array_unique(array_map('intval', $data['tiles_shown'] ?? [])));
        if ($tiles === []) {
            return back()->withInput()->withErrors(['tiles_shown' => 'Show at least one tile.']);
        }

        // Areas the agency typed by name: only an exact suburb name is accepted, never a guess.
        $areas = array_map('intval', $data['areas'] ?? []);
        $unknown = [];
        foreach (array_filter(array_map('trim', explode(',', (string) ($data['add_areas'] ?? '')))) as $name) {
            $id = DB::table('p24_suburbs')->whereNull('deleted_at')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id');
            $id ? $areas[] = (int) $id : $unknown[] = $name;
        }

        MicAgencySetting::saveValues($agencyId, [
            'areas' => array_values(array_unique($areas)),
            'refresh_interval_days' => (int) $data['refresh_interval_days'],
            'tiles_shown' => $tiles,
            'score_weights' => array_map('intval', $data['weights']),
            'neighbour_months' => (int) $data['neighbour_months'],
            'neighbour_each_side' => (int) $data['neighbour_each_side'],
            'gone_tile_days' => (int) $data['gone_tile_days'],
            'detail_pages_per_session' => (int) $data['detail_pages_per_session'],
            'area_agency_pages_per_run' => (int) $data['area_agency_pages_per_run'],
            'area_street_pages_per_run' => (int) $data['area_street_pages_per_run'],
            // a checkbox that is absent means OFF only when this form posted it (guard, non-negotiable 10a)
            'opt_out_registry_check' => $request->boolean('opt_out_registry_check'),
        ]);
        \Illuminate\Support\Facades\Cache::forget("mic-work:has-scores:$agencyId");

        return redirect()->route('market-intelligence.settings')
            ->with('status', 'MIC settings saved.' . ($unknown ? ' Not found as a suburb name, so not added: ' . implode(', ', $unknown) . '.' : ''));
    }

    private function agencyId(Request $request): int
    {
        $agencyId = $request->user()->effectiveAgencyId();
        abort_unless($agencyId !== null, 403);

        return (int) $agencyId;
    }
}
