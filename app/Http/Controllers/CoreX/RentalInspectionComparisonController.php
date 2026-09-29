<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Services\RentalInspectionComparisonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-inspection-form.md §7 — the in-vs-out deposit comparison.
 * Deliberately a separate controller from RentalInspectionRecordingController
 * (which owns observation/photo/signature recording) and
 * RentalInspectionController (which owns the list screen) — this is a third,
 * narrower concern: reading a comparison of two already-recorded inspections
 * and capturing an agent's wear-and-tear/flagged judgement on it. Nothing in
 * this controller computes or stores a monetary amount — see the service's
 * own docblock for why.
 */
class RentalInspectionComparisonController extends Controller
{
    public function __construct(private readonly RentalInspectionComparisonService $comparison)
    {
    }

    /**
     * Read-only. Only meaningful for an out-inspection — an in-inspection
     * has nothing to compare against yet, and an ad_hoc inspection sits
     * outside the in/out lifecycle entirely (rental-inspections.md §4).
     */
    public function show(Request $request, RentalInspection $rentalInspection): View
    {
        abort_unless($rentalInspection->type === RentalInspection::TYPE_OUT, 404);

        $rentalInspection->load('property', 'lease.tenants.contact');
        $inInspection = $this->comparison->matchingInInspection($rentalInspection);

        return view('corex.rental-inspections.partials.deposit-comparison-page', [
            'inspection' => $rentalInspection,
            'inInspection' => $inInspection,
            'items' => $this->comparison->compareItems($rentalInspection),
            'headerFacts' => $this->comparison->compareHeaderFacts($inInspection, $rentalInspection),
        ]);
    }

    public function recordFinding(Request $request, RentalInspection $rentalInspection, RentalInspectionItem $rentalInspectionItem): RedirectResponse
    {
        abort_unless($rentalInspection->type === RentalInspection::TYPE_OUT, 404);

        $validated = $request->validate([
            'disposition' => ['required', 'in:wear_and_tear,flagged'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->comparison->recordFinding(
                $rentalInspection,
                $rentalInspectionItem,
                $validated['disposition'],
                $validated['note'],
                $request->user(),
            );
        } catch (\LogicException $e) {
            return back()->withErrors(['finding' => $e->getMessage()]);
        }

        return redirect()
            ->route('corex.rental-inspections.deposit-comparison', $rentalInspection)
            ->with('success', 'Finding recorded.');
    }
}
