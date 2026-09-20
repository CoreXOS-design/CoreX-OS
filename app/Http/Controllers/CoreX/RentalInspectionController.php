<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalInspection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-inspections.md §5 — the tracked/searchable list of every
 * inspection. Recording observations/photos/signatures HAPPENS on the
 * property's Rental Images tab (§1/§4, a separate controller); this
 * controller is the agency-level Read + administrative-lifecycle surface —
 * search, sort, filter, cancel, archive, restore.
 */
class RentalInspectionController extends Controller
{
    /**
     * Search: property address, tenant name, agent name (creator). Sort:
     * scheduled_for (default, most-recent-first), property address, status,
     * type. Filter: status, type, date range, has-unresolved-discrepancy.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $sort = $request->get('sort', 'scheduled_for');
        $direction = $request->get('direction', 'desc');
        $allowedSorts = ['scheduled_for', 'property', 'status', 'type'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'scheduled_for';
        }
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        $query = RentalInspection::query()
            ->visibleTo($user, $request->get('scope'))
            ->with(['property', 'lease.tenants.contact', 'createdBy']);

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('property', function ($p) use ($search) {
                    $p->searchAddress($search);
                })->orWhereHas('lease.tenants.contact', function ($c) use ($search) {
                    $c->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                })->orWhereHas('createdBy', function ($u) use ($search) {
                    $u->where('name', 'like', "%{$search}%");
                });
            });
        }

        if ($status = $request->get('status')) {
            $query->where('rental_inspections.status', $status);
        }

        if ($type = $request->get('type')) {
            $query->where('rental_inspections.type', $type);
        }

        if ($dateFrom = $request->get('date_from')) {
            $query->where('rental_inspections.scheduled_for', '>=', $dateFrom);
        }
        if ($dateTo = $request->get('date_to')) {
            $query->where('rental_inspections.scheduled_for', '<=', $dateTo);
        }

        if ($request->boolean('has_unresolved_discrepancy')) {
            $query->withUnresolvedDiscrepancy();
        }

        if ($sort === 'property') {
            $query->join('properties', 'properties.id', '=', 'rental_inspections.property_id')
                ->orderBy('properties.title', $direction)
                ->select('rental_inspections.*');
        } else {
            $query->orderBy("rental_inspections.{$sort}", $direction);
        }

        $hasAnyInspections = RentalInspection::query()->visibleTo($user, $request->get('scope'))->exists();

        $inspections = $query->paginate(25)->withQueryString();

        return view('corex.rental-inspections.index', [
            'inspections' => $inspections,
            'sort' => $sort,
            'direction' => $direction,
            'hasAnyInspections' => $hasAnyInspections,
            'filters' => $request->only(['q', 'status', 'type', 'date_from', 'date_to', 'has_unresolved_discrepancy']),
        ]);
    }

    public function show(Request $request, RentalInspection $rentalInspection): View
    {
        $rentalInspection->load([
            'property', 'lease.tenants.contact',
            'observations.item', 'observations.observedByUser', 'observations.observedByContact', 'observations.photos',
            'discrepancies.observations', 'discrepancies.resolvedBy', 'discrepancies.acceptedObservation',
            'signatures.partyContact', 'signatures.recordedByUser', 'createdBy', 'cancelledBy',
        ]);

        return view('corex.rental-inspections.show', [
            'inspection' => $rentalInspection,
            // §15.5/§15.8 — refusal_reason_preset stores a KEY; this maps it
            // to the agency's own current label for display. A key an
            // agency has since removed/renamed still shows the key itself
            // (never blank) via the blade's own fallback.
            'refusalReasonPresets' => \App\Models\RentalInspectionSetting::refusalReasonPresetsFor($rentalInspection->agency_id),
        ]);
    }

    public function cancel(Request $request, RentalInspection $rentalInspection): RedirectResponse
    {
        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:500'],
        ]);

        $rentalInspection->cancel($request->user(), $validated['cancel_reason']);

        return redirect()->route('corex.rental-inspections.show', $rentalInspection)->with('success', 'Inspection cancelled.');
    }

    public function destroy(Request $request, RentalInspection $rentalInspection): RedirectResponse
    {
        if (!$rentalInspection->isDeletable()) {
            return back()->withErrors(['rental_inspection' => 'This inspection has recorded observations and cannot be deleted — cancel it instead.']);
        }

        $rentalInspection->delete();

        return redirect()->route('corex.rental-inspections.index')->with('success', 'Inspection archived.');
    }

    public function restore(Request $request, int $rentalInspection): RedirectResponse
    {
        $inspection = RentalInspection::withTrashed()->findOrFail($rentalInspection);
        $inspection->restore();

        return redirect()->route('corex.rental-inspections.show', $inspection)->with('success', 'Inspection restored.');
    }
}
