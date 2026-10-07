<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
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
    use AuthorizesRentalRecordScope;

    public function __construct(private readonly RentalInspectionComparisonService $comparison)
    {
    }

    /**
     * §45.7a (Build I-7) — the Move-out comparison. Read-only apart from the agent's own classification below. Only
     * meaningful for an out-inspection. The baseline (the tenancy's completed In, resolved along the lease chain) is
     * worked out here from the lease — never from an id in the request.
     *
     * Screen standard (BUILD_STANDARD §1b): search (item/room text), filter (room, difference type, classification state,
     * "differences only"), sort (walking order by default, or worst first). A child of the inspection — no list of its own,
     * nothing to paginate beyond the rooms collapsing. Scoping: the bound inspection through the shared guard; the baseline
     * and every photo/finding/context read hangs off that inspection and its tenancy, never off a posted id.
     */
    public function show(Request $request, RentalInspection $rentalInspection): View
    {
        abort_unless($rentalInspection->type === RentalInspection::TYPE_OUT, 404);
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $rentalInspection->load('property', 'lease.tenants.contact');

        $filters = [
            'q' => $request->get('q'),
            'room' => $request->get('room'),
            'difference' => array_key_exists((string) $request->get('difference'), RentalInspectionComparisonService::DIFFERENCE_LABELS) ? $request->get('difference') : null,
            'classification' => $request->get('classification'),
            'differences_only' => $request->boolean('differences_only'),
            'sort' => $request->get('sort') === 'severity' ? 'severity' : 'walking',
        ];
        $comparison = $this->comparison->moveOutComparison($rentalInspection, $filters);
        // The room picker lists EVERY room, not just the ones the current filters left standing.
        $unfiltered = array_filter($filters, fn ($v, $k) => $k !== 'sort' && $v, ARRAY_FILTER_USE_BOTH) === []
            ? $comparison
            : $this->comparison->moveOutComparison($rentalInspection, []);

        return view('corex.rental-inspections.partials.deposit-comparison-page', [
            'inspection' => $rentalInspection,
            'inInspection' => $comparison['baseline'],
            'comparison' => $comparison,
            'filters' => $filters,
            // The inventory has its own move-out comparison (RentalInventoryComparisonService); this screen links to it.
            'inventory' => \App\Models\RentalInventory::where('lease_id', $rentalInspection->lease_id)
                ->where('status', \App\Models\RentalInventory::STATUS_COMPLETED)->latest('id')->first(),
            'differenceLabels' => RentalInspectionComparisonService::DIFFERENCE_LABELS,
            'dispositionLabels' => \App\Models\RentalInspectionItemFinding::DISPOSITION_LABELS,
            'roomOptions' => collect($unfiltered['rooms'])->map(fn ($r) => ['id' => $r['room_id'] ?? 'general', 'label' => $r['label']])->values(),
        ]);
    }

    public function recordFinding(Request $request, RentalInspection $rentalInspection, RentalInspectionItem $rentalInspectionItem): RedirectResponse
    {
        abort_unless($rentalInspection->type === RentalInspection::TYPE_OUT, 404);
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $validated = $request->validate([
            'disposition' => ['required', 'in:' . implode(',', array_keys(\App\Models\RentalInspectionItemFinding::DISPOSITION_LABELS))],
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
