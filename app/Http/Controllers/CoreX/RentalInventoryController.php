<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInventory;
use App\Models\RentalInspectionSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-inventory.md §7 — the tracked/searchable list of every
 * inventory, plus its administrative lifecycle (create, cancel, archive,
 * restore). Recording lines and signatures happens on this controller's own
 * show() page — an inventory is a smaller document than a full inspection,
 * so (unlike RentalInspection) there is no separate property-tab surface;
 * one controller covers Read, lifecycle, and recording. Mirrors
 * RentalInspectionController's shape throughout.
 */
class RentalInventoryController extends Controller
{
    public function create(Request $request): View
    {
        $properties = Property::where('listing_type', 'rental')
            ->whereIn('id', Lease::where('status', Lease::STATUS_ACTIVE)->pluck('property_id'))
            ->orderBy('title')
            ->limit(500)
            ->get();

        return view('corex.rental-inventories.create', ['properties' => $properties]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'property_id' => ['required', 'integer', 'exists:properties,id'],
        ]);

        $property = Property::findOrFail($validated['property_id']);
        $lease = Lease::where('property_id', $property->id)->where('status', Lease::STATUS_ACTIVE)->first();
        if (! $lease) {
            return back()->withInput()->withErrors(['rental_inventory' => 'This property has no active lease — an inventory needs one to attach to.']);
        }

        try {
            $inventory = RentalInventory::start($property, $lease, $request->user());
        } catch (\LogicException $e) {
            return back()->withInput()->withErrors(['rental_inventory' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-inventories.show', $inventory)->with('success', 'Inventory started.');
    }

    /**
     * Search: property address, tenant name, agent name (creator). Sort:
     * created_at (default, most-recent-first), property address, status.
     * Filter: status, date range.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $sort = $request->get('sort', 'created_at');
        $direction = $request->get('direction', 'desc');
        $allowedSorts = ['created_at', 'property', 'status'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'created_at';
        }
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        $archived = $request->boolean('archived');

        $query = RentalInventory::query()
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
            $query->where('rental_inventories.status', $status);
        }

        if ($dateFrom = $request->get('date_from')) {
            $query->where('rental_inventories.created_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->get('date_to')) {
            $query->where('rental_inventories.created_at', '<=', $dateTo);
        }

        if ($sort === 'property') {
            $query->join('properties', 'properties.id', '=', 'rental_inventories.property_id')
                ->orderBy('properties.title', $direction)
                ->select('rental_inventories.*');
        } else {
            $query->orderBy("rental_inventories.{$sort}", $direction);
        }

        $hasAnyInventories = RentalInventory::query()
            ->when($archived, fn ($q) => $q->onlyTrashed())
            ->visibleTo($user, $request->get('scope'))
            ->exists();

        $inventories = $query->paginate(25)->withQueryString();

        return view('corex.rental-inventories.index', [
            'inventories' => $inventories,
            'sort' => $sort,
            'direction' => $direction,
            'hasAnyInventories' => $hasAnyInventories,
            'archived' => $archived,
            'filters' => $request->only(['q', 'status', 'date_from', 'date_to']),
        ]);
    }

    public function show(Request $request, RentalInventory $rentalInventory): View
    {
        $rentalInventory->load([
            'property', 'lease.tenants.contact',
            'lines.createdBy',
            'signatures.partyContact', 'signatures.recordedByUser',
            'createdBy', 'cancelledBy',
        ]);

        return view('corex.rental-inventories.show', [
            'inventory' => $rentalInventory,
            // Reuses the SAME agency-configurable list RentalInspection's
            // refusal capture already uses — one list of "why didn't this
            // party sign," not a second one for a second document.
            'refusalReasonPresets' => RentalInspectionSetting::refusalReasonPresetsFor($rentalInventory->agency_id),
        ]);
    }

    public function cancel(Request $request, RentalInventory $rentalInventory): RedirectResponse
    {
        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:500'],
        ]);

        $rentalInventory->cancel($request->user(), $validated['cancel_reason']);

        return redirect()->route('corex.rental-inventories.show', $rentalInventory)->with('success', 'Inventory cancelled.');
    }

    /** Soft delete — archive, never destroy (non-negotiable #1). restore() below undoes it. */
    public function destroy(Request $request, RentalInventory $rentalInventory): RedirectResponse
    {
        $rentalInventory->forceFill(['archived_by_user_id' => $request->user()->id])->save();
        $rentalInventory->delete();

        return redirect()->route('corex.rental-inventories.index')->with('success', 'Inventory archived.');
    }

    public function restore(Request $request, int $rentalInventory): RedirectResponse
    {
        $inventory = RentalInventory::withTrashed()->findOrFail($rentalInventory);
        $inventory->restore();
        $inventory->forceFill(['archived_by_user_id' => null])->save();

        return redirect()->route('corex.rental-inventories.show', $inventory)->with('success', 'Inventory restored.');
    }
}
