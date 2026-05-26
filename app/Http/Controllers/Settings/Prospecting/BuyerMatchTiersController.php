<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings\Prospecting;

use App\Http\Controllers\Controller;
use App\Services\Prospecting\ProspectingConfigurationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Prospecting Setup → Buyer Match Tiers tab handler.
 * Single PUT endpoint to update the per-agency thresholds row.
 * Reads happen via ProspectingConfigurationService::buyerMatchTiers().
 */
final class BuyerMatchTiersController extends Controller
{
    public function __construct(
        private readonly ProspectingConfigurationService $config,
    ) {}

    public function update(Request $request)
    {
        $user = $request->user();
        $agencyId = $user?->effectiveAgencyId() ?? $user?->agency_id;
        if ($agencyId === null) abort(403);

        $validated = $request->validate([
            'strong_min_score'    => 'required|integer|min:0|max:100',
            'mid_min_score'       => 'required|integer|min:0|max:100',
            'weak_min_score'      => 'required|integer|min:0|max:100',
            'strong_label'        => 'required|string|max:30',
            'mid_label'           => 'required|string|max:30',
            'weak_label'          => 'required|string|max:30',
            'show_weak_in_badge'  => 'sometimes',
        ]);

        // Logical ordering: strong > mid > weak (strict to avoid ambiguous bucketing).
        if (!(
            $validated['strong_min_score'] > $validated['mid_min_score']
            && $validated['mid_min_score'] > $validated['weak_min_score']
        )) {
            return back()
                ->withErrors(['thresholds' => 'Thresholds must be in order: Strong > Mid > Weak.'])
                ->withInput();
        }

        $payload = [
            'agency_id'          => $agencyId,
            'strong_min_score'   => (int) $validated['strong_min_score'],
            'mid_min_score'      => (int) $validated['mid_min_score'],
            'weak_min_score'     => (int) $validated['weak_min_score'],
            'strong_label'       => trim((string) $validated['strong_label']),
            'mid_label'          => trim((string) $validated['mid_label']),
            'weak_label'         => trim((string) $validated['weak_label']),
            'show_weak_in_badge' => (bool) $request->boolean('show_weak_in_badge'),
            'updated_at'         => now(),
        ];

        $existing = DB::table('buyer_match_tiers')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            DB::table('buyer_match_tiers')
                ->where('agency_id', $agencyId)
                ->update($payload);
        } else {
            $payload['created_at'] = now();
            DB::table('buyer_match_tiers')->insert($payload);
        }

        // Invalidate the per-request cache so subsequent reads see the new values.
        $this->config->clearCache($agencyId);

        return redirect()
            ->route('settings.prospecting.index', ['tab' => 'buyer-match-tiers'])
            ->with('status', 'Buyer match thresholds updated.');
    }
}
