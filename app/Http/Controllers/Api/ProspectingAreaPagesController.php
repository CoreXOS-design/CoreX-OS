<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Prospecting\P24AreaPageIngest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/prospecting/area-pages - the free Property24 area pages (suburb trends, street house numbers, agencies)
 * the extension reads monthly. .ai/specs/mic-work-tab.md §11.2.2. Public facts only; stored globally by P24AreaPageIngest.
 */
class ProspectingAreaPagesController extends Controller
{
    public function store(Request $request, P24AreaPageIngest $ingest)
    {
        $v = $request->validate([
            'kind'                    => 'required|in:trends,agencies,streets',
            'p24_suburb_id'           => 'required|integer|min:1|max:99999999',
            'suburb'                  => 'nullable|string|max:120',
            'town'                    => 'nullable|string|max:120',
            'url'                     => 'nullable|string|max:500',
            'trends'                  => 'required_if:kind,trends|array',
            'trends.datasets'         => 'required_if:kind,trends|array',
            'trends.sold_labels'      => 'nullable|array',
            'trends.stock_by_beds'    => 'nullable|array',
            'agencies'                => 'required_if:kind,agencies|array|max:200',
            'streets'                 => 'required_if:kind,streets|array|max:100',
            'streets.*.addresses'     => 'nullable|array|max:1000',
        ]);

        // same rule as the import: a silent extension call must belong to an agency, never a guess
        $user = $request->user();
        if (! ($user?->effectiveAgencyId() ?? $user?->agency_id)) {
            throw new \App\Exceptions\MissingAgencyContextException('a prospecting area-page capture');
        }

        $suburbId = (int) $v['p24_suburb_id'];
        $suburb = isset($v['suburb']) ? trim($v['suburb']) : null;
        $town = isset($v['town']) ? trim($v['town']) : null;

        $written = match ($v['kind']) {
            // the nested arrays are read from the request itself: validated() would keep only the keys named in the rules
            'trends'   => $ingest->trends($suburbId, $suburb, $town, (array) $request->input('trends')),
            'agencies' => $ingest->agencies($suburbId, (array) $request->input('agencies')),
            'streets'  => $ingest->streets($suburbId, (array) $request->input('streets')),
        };
        Log::info('MIC area page stored', ['kind' => $v['kind'], 'p24_suburb_id' => $suburbId, 'user_id' => $user->id] + $written);

        return response()->json(['success' => true, 'kind' => $v['kind']] + $written + [
            'limits' => $this->limits((int) ($user->effectiveAgencyId() ?? $user->agency_id)),
        ]);
    }

    /** Agency caps on how much one run reads (cc5's MicAgencySetting owns them; these are the spec defaults). */
    private function limits(int $agencyId): array
    {
        $limits = ['agency_pages_per_run' => 10, 'street_pages_per_run' => 150];
        try {
            if (class_exists(\App\Models\MicAgencySetting::class) && method_exists(\App\Models\MicAgencySetting::class, 'for')) {
                $s = \App\Models\MicAgencySetting::for($agencyId);
                if (method_exists($s, 'areaAgencyPagesPerRun')) {
                    $limits['agency_pages_per_run'] = max(0, (int) $s->areaAgencyPagesPerRun());
                }
                if (method_exists($s, 'areaStreetPagesPerRun')) {
                    $limits['street_pages_per_run'] = max(0, (int) $s->areaStreetPagesPerRun());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('MIC area-page caps unreadable, using defaults (swallowed)', ['error' => $e->getMessage()]);
        }

        return $limits;
    }
}
