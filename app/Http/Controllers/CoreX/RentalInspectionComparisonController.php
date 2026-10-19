<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionItemFinding;
use App\Models\RentalInventory;
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
     * Read-only. Only meaningful for an out-inspection — an in-inspection
     * has nothing to compare against yet, and an ad_hoc inspection sits
     * outside the in/out lifecycle entirely (rental-inspections.md §4).
     */
    public function show(Request $request, RentalInspection $rentalInspection): View
    {
        abort_unless($rentalInspection->type === RentalInspection::TYPE_OUT, 404);
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $rentalInspection->load('property', 'lease.tenants.contact');

        // §45.7a — the baseline is resolved from the tenancy's lease chain inside the service, never from
        // anything the request carries. Search / filter / sort are plain GET parameters, normalised there
        // (a malformed value is absorbed to its default, never a 500).
        $comparison = $this->comparison->buildComparison($rentalInspection, $request->query());

        return view('corex.rental-inspections.partials.deposit-comparison-page', $comparison + [
            'inspection' => $rentalInspection,
            'inInspection' => $comparison['baseline'],
            'headerFacts' => $this->comparison->compareHeaderFacts($comparison['baseline'], $rentalInspection),
            'dispositionLabels' => RentalInspectionItemFinding::DISPOSITION_LABELS,
            'inventoryComparison' => $this->inventoryComparisonFor($request, $rentalInspection),
        ]);
    }

    /**
     * §45.7a item 6 — the inventory comparison stays its own page; this screen only LINKS to it. Offered
     * only for a completed inventory on this tenancy that the viewer is allowed to see, so the link never
     * reveals an inventory the viewer's own scope would hide.
     */
    private function inventoryComparisonFor(Request $request, RentalInspection $inspection): ?RentalInventory
    {
        $leaseIds = $this->comparison->tenancyLeaseIds($inspection->lease, $inspection->lease_id);
        // No inventories permission at all (a null data scope) means no link — and never a lookup that would throw.
        if ($leaseIds === [] || \App\Services\PermissionService::getDataScope($request->user(), 'rental_inventories') === null) {
            return null;
        }

        return RentalInventory::query()
            ->visibleTo($request->user())
            ->whereIn('lease_id', $leaseIds)
            ->where('status', RentalInventory::STATUS_COMPLETED)
            ->latest('id')
            ->first();
    }

    public function recordFinding(Request $request, RentalInspection $rentalInspection, RentalInspectionItem $rentalInspectionItem): RedirectResponse
    {
        abort_unless($rentalInspection->type === RentalInspection::TYPE_OUT, 404);
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $validated = $request->validate([
            'disposition' => ['required', 'in:' . implode(',', array_keys(RentalInspectionItemFinding::DISPOSITION_LABELS))],
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
