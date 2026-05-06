<?php

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\MarketAnalyticsRun;
use App\Models\Presentation;
use App\Models\PresentationSnapshot;
use App\Models\SaleProbabilityRun;
use App\Services\PermissionService;
use Illuminate\Http\Request;

class PresentationSnapshotController extends Controller
{
    private function authorizePresentation(Presentation $presentation): void
    {
        $user = auth()->user();
        $scope = PermissionService::getDataScope($user, 'presentations');
        if ($scope === 'all') return;
        if ($scope === 'branch' && (int) $presentation->branch_id === (int) $user->effectiveBranchId()) return;
        if ($scope === 'own' && (int) $presentation->created_by_user_id === (int) $user->id) return;
        abort(403);
    }

    /**
     * Validate inputs, create an immutable snapshot row, and redirect to the snapshot view.
     */
    public function saveSnapshot(Request $request, Presentation $presentation)
    {
        $this->authorizePresentation($presentation);
        $validated = $request->validate([
            'market_run_id'       => ['required', 'integer', 'exists:market_analytics_runs,id'],
            'prob_run_id'         => ['required', 'integer', 'exists:sale_probability_runs,id'],
            'inputs_json'         => ['required', 'string'],
            'output_summary_json' => ['required', 'string'],
        ]);

        $snapshot = PresentationSnapshot::create([
            'presentation_id'         => $presentation->id,
            'generated_by_user_id'    => auth()->id(),
            'created_by_user_id'      => auth()->id(),
            'market_analytics_run_id' => $validated['market_run_id'],
            'sale_probability_run_id' => $validated['prob_run_id'],
            'inputs_json'             => $validated['inputs_json'],
            'output_summary_json'     => $validated['output_summary_json'],
            'snapshot_json'           => '{}',
            'generated_at'            => now(),
        ]);

        // Capture buyer demand snapshot (Module 13) for historical comparison
        if ($presentation->listing_id) {
            try {
                $agencyId = $presentation->agency_id ?? (auth()->user()->effectiveAgencyId() ?? 1);
                $buyerDemand = app(\App\Services\PropertyMatchScoringService::class)
                    ->getBuyerDemandForProperty($presentation->listing_id, $agencyId);

                \Illuminate\Support\Facades\DB::table('property_presentation_snapshots')->insert([
                    'property_id' => $presentation->listing_id,
                    'presentation_id' => $presentation->id,
                    'generated_at' => now(),
                    'generated_by_user_id' => auth()->id(),
                    'market_data_snapshot' => json_encode([
                        'buyer_demand' => $buyerDemand,
                        'captured_at' => now()->toIso8601String(),
                    ]),
                    'days_on_market_at_time' => $presentation->listing_id
                        ? (int) now()->diffInDays(\App\Models\Property::withoutGlobalScopes()->find($presentation->listing_id)?->published_at ?? now())
                        : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // Don't block snapshot save
            }
        }

        return redirect()->route('presentations.snapshots.show', [$presentation, $snapshot])
            ->with('success', 'Snapshot saved.');
    }

    /**
     * Display a saved snapshot. Ensures the snapshot belongs to the given presentation.
     */
    public function showSnapshot(Presentation $presentation, PresentationSnapshot $snapshot)
    {
        $this->authorizePresentation($presentation);
        abort_if($snapshot->presentation_id !== $presentation->id, 404);

        $maRun = $snapshot->market_analytics_run_id
            ? MarketAnalyticsRun::find($snapshot->market_analytics_run_id)
            : null;

        $spRun = $snapshot->sale_probability_run_id
            ? SaleProbabilityRun::find($snapshot->sale_probability_run_id)
            : null;

        return view('presentations.snapshot', compact('presentation', 'snapshot', 'maRun', 'spRun'));
    }
}
