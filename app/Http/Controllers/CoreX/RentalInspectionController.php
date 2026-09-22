<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-inspections.md §5 — the tracked/searchable list of every
 * inspection. Recording observations/photos/signatures HAPPENS on the
 * property's Rental Images tab (§1/§4, a separate controller); this
 * controller is the agency-level Read + administrative-lifecycle surface —
 * search, sort, filter, create (a picker that hands off to the same
 * RentalInspection::start() the tab's own AJAX flow calls — no second
 * implementation), cancel, archive, restore.
 */
class RentalInspectionController extends Controller
{
    /**
     * 2026-09-20 — the list screen had no way to start an inspection at all;
     * an agent had to already know to go to a property's Rental Images tab.
     * This is an ADDITIONAL entry point, not a replacement — the tab's own
     * "Start In/Out-Inspection" buttons keep working exactly as they did.
     * Only properties with an active lease are offered: RentalInspection::
     * start() hard-requires one, so listing properties without one would be
     * a guaranteed dead end.
     */
    public function create(Request $request): View
    {
        $properties = Property::where('listing_type', 'rental')
            ->whereIn('id', Lease::where('status', Lease::STATUS_ACTIVE)->pluck('property_id'))
            ->orderBy('title')
            ->limit(500)
            ->get();

        return view('corex.rental-inspections.create', ['properties' => $properties]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'property_id' => ['required', 'integer', 'exists:properties,id'],
            'type' => ['required', 'in:' . implode(',', [RentalInspection::TYPE_IN, RentalInspection::TYPE_OUT, RentalInspection::TYPE_AD_HOC])],
        ]);

        $property = Property::findOrFail($validated['property_id']);

        try {
            $inspection = RentalInspection::start($property, $validated['type'], $request->user());
        } catch (\LogicException $e) {
            return back()->withInput()->withErrors(['rental_inspection' => $e->getMessage()]);
        }

        // Recording (observations/photos/signatures) only happens on the
        // property's Inspections tab (§1/§4, renamed 2026-09-22 — label-
        // level only, same tab, same routes underneath) — this screen's own
        // show() page is read-only, so land the agent where they can
        // actually start working, not on a dead end they'd have to navigate
        // away from immediately.
        return redirect()->route('corex.properties.show', ['property' => $inspection->property_id, 'tab' => 'inspections'])
            ->with('success', ucfirst($validated['type']) . '-inspection started.');
    }

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

        $archived = $request->boolean('archived');

        $query = RentalInspection::query()
            ->when($archived, fn ($q) => $q->onlyTrashed())
            ->visibleTo($user, $request->get('scope'))
            ->with(['property', 'lease.tenants.contact', 'createdBy', 'archivedBy']);

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

        $hasAnyInspections = RentalInspection::query()
            ->when($archived, fn ($q) => $q->onlyTrashed())
            ->visibleTo($user, $request->get('scope'))
            ->exists();

        $inspections = $query->paginate(25)->withQueryString();

        return view('corex.rental-inspections.index', [
            'inspections' => $inspections,
            'sort' => $sort,
            'direction' => $direction,
            'hasAnyInspections' => $hasAnyInspections,
            'archived' => $archived,
            'filters' => $request->only(['q', 'status', 'type', 'date_from', 'date_to', 'has_unresolved_discrepancy']),
        ]);
    }

    public function show(Request $request, RentalInspection $rentalInspection): View
    {
        $rentalInspection->load([
            'property', 'lease.tenants.contact',
            'observations.item', 'observations.observedByUser', 'observations.observedByContact', 'observations.photos',
            'discrepancies.observations', 'discrepancies.resolvedBy', 'discrepancies.acceptedObservation',
            'signatures.partyContact', 'signatures.recordedByUser', 'signatures.supersededBy', 'createdBy', 'cancelledBy',
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

    /**
     * 2026-09-20 — a real QA1 walk found no supported way to archive an
     * inspection once it had any recorded observations: this used to refuse
     * with "cancel it instead", but cancel() only flips status without
     * hiding the record, a dead end for an agent who started one on the
     * wrong property. delete() here is already a SOFT delete (softDeletes()
     * column) with restore() already existing — archiving never destroys
     * the evidence, it only hides it from the working list, exactly as
     * every other entity's archive/restore floor already works.
     */
    public function destroy(Request $request, RentalInspection $rentalInspection): RedirectResponse
    {
        $rentalInspection->forceFill(['archived_by_user_id' => $request->user()->id])->save();
        $rentalInspection->delete();

        return redirect()->route('corex.rental-inspections.index')->with('success', 'Inspection archived.');
    }

    public function restore(Request $request, int $rentalInspection): RedirectResponse
    {
        $inspection = RentalInspection::withTrashed()->findOrFail($rentalInspection);
        $inspection->restore();
        $inspection->forceFill(['archived_by_user_id' => null])->save();

        return redirect()->route('corex.rental-inspections.show', $inspection)->with('success', 'Inspection restored.');
    }
}
