<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\EnforcesRecordVisibility;
use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInventory;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInventorySetting;
use App\Services\Rentals\RentalInventoryComparisonService;
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
    use EnforcesRecordVisibility;

    public function create(Request $request): View
    {
        // §0a/§15 — an inventory is a PROPERTY feature, sale or rental, and
        // now genuinely accepts either: the active-lease filter this picker
        // had is removed, since RentalInventory::resolveOrStartFor()'s own
        // sibling helpers (start()/startForProperty()) below now cover both
        // shapes. Every property is offered, lease or none.
        $properties = Property::orderBy('title')
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
        // The property must be one this user may see (own/branch/agency).
        abort_unless(
            Property::query()->visibleTo($request->user())->whereKey($property->id)->exists(),
            404
        );
        $lease = Lease::where('property_id', $property->id)->where('status', Lease::STATUS_ACTIVE)->first();

        try {
            $inventory = $lease
                ? RentalInventory::start($property, $lease, $request->user())
                : RentalInventory::startForProperty($property, $request->user());
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
            // Inclusive end date (a bare date compares as midnight).
            $query->where('rental_inventories.created_at', '<=', preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo . ' 23:59:59' : $dateTo);
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
        $this->assertVisible($request, $rentalInventory);

        $rentalInventory->load([
            'property', 'lease.tenants.contact',
            'lines.createdBy', 'lines.room',
            'roomMarks',
            'signatures.partyContact', 'signatures.recordedByUser',
            'createdBy', 'cancelledBy',
        ]);

        // Johan, 2026-09-28, property 5294/inventory 8 — "lists only
        // Bedroom 1" (this page used to group $inventory->lines by
        // room_label, so a room with zero lines simply never appeared).
        // Every real space the property has now renders, whatever its
        // state — the SAME PropertyRoom source/order the capture screen
        // and the completion gate's own unvisitedRooms() already use, one
        // source of truth, never a second room list.
        $rooms = \App\Models\PropertyRoom::where('property_id', $rentalInventory->property_id)
            ->where('is_retired', false)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return view('corex.rental-inventories.show', [
            'inventory' => $rentalInventory,
            'rooms' => $rooms,
            // Reuses the SAME agency-configurable list RentalInspection's
            // refusal capture already uses — one list of "why didn't this
            // party sign," not a second one for a second document.
            'refusalReasonPresets' => RentalInspectionSetting::refusalReasonPresetsFor($rentalInventory->agency_id),
            // §41-follow-up (Job 3) — the "Resend report" popover's own
            // recipient list, straight off the SignedDocumentDistributable
            // contract method — one source of truth with what the service
            // itself will actually email, never a second client-side guess.
            'reportRecipients' => $rentalInventory->distributionRecipients(),
        ]);
    }

    /**
     * GET /corex/rental-inventories/{inventory}/report — §41-follow-up
     * (Job 3), the signed report PDF. Mirrors RentalInspectionController::
     * report() exactly: generates a public link first if none is live, so
     * a printed/shared link never 404s the moment someone actually opens it.
     */
    public function report(Request $request, RentalInventory $rentalInventory, \App\Services\Rentals\RentalInventoryReportPdfService $service)
    {
        $this->assertVisible($request, $rentalInventory);

        $rentalInventory->loadMissing(['property', 'lease.tenants.contact', 'lines.room', 'lines.moveInPhotos', 'signatures.partyContact']);

        // Read-only: this GET (view permission) no longer mints or rotates
        // the public link — that would invalidate a link already emailed
        // (audit L4). The link is created at completion / by the POST
        // resend-report path; a PDF with no live link simply omits the QR.

        $pdf = $service->generate($rentalInventory);

        return $pdf->download($service->filenameFor($rentalInventory));
    }

    /**
     * GET /corex/rental-inventories/{inventory}/comparison — §8, the
     * move-out review screen. Read-only: RentalInventoryComparisonService
     * computes fresh on every load, nothing here is stored. §0a/§15 — a
     * rental-only feature: a property-level inventory (no lease — a sale, or
     * a rental between tenancies) has no tenancy move-out to compare
     * against, so this refuses those the same way it already refuses a
     * not-yet-completed one.
     */
    public function comparison(Request $request, RentalInventory $rentalInventory, RentalInventoryComparisonService $service): View
    {
        $this->assertVisible($request, $rentalInventory);
        abort_unless($rentalInventory->status === RentalInventory::STATUS_COMPLETED, 400,
            'The move-out comparison is only available once the inventory itself is completed.');
        abort_unless($rentalInventory->lease_id !== null, 400,
            'The move-out comparison is a rental-only feature — this inventory has no lease to compare against.');

        // §12/§11.8/§14 — both sides of the evidence: the move-in photos an
        // agent tagged during capture, AND the move-out photos taken from
        // this comparison screen itself (§14, new) — eager-loaded here so
        // the comparison view can show both instead of leaving either
        // invisible on the one screen whose whole purpose is comparing them.
        $rentalInventory->load(['property', 'lease.tenants.contact', 'lines.moveInPhotos', 'lines.moveOutPhotos']);

        return view('corex.rental-inventories.comparison', [
            'inventory' => $rentalInventory,
            'rows' => $service->compare($rentalInventory),
            'dispositionPresets' => RentalInventorySetting::dispositionPresetsFor($rentalInventory->agency_id),
        ]);
    }

    public function cancel(Request $request, RentalInventory $rentalInventory): RedirectResponse
    {
        $this->assertVisible($request, $rentalInventory);

        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $rentalInventory->cancel($request->user(), $validated['cancel_reason']);
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_inventory' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-inventories.show', $rentalInventory)->with('success', 'Inventory cancelled.');
    }

    /** Soft delete — archive, never destroy (non-negotiable #1). restore() below undoes it. */
    public function destroy(Request $request, RentalInventory $rentalInventory): RedirectResponse
    {
        $this->assertVisible($request, $rentalInventory);

        $rentalInventory->forceFill(['archived_by_user_id' => $request->user()->id])->save();
        $rentalInventory->delete();

        return redirect()->route('corex.rental-inventories.index')->with('success', 'Inventory archived.');
    }

    public function restore(Request $request, int $rentalInventory): RedirectResponse
    {
        $inventory = RentalInventory::withTrashed()->findOrFail($rentalInventory);
        $this->assertVisible($request, $inventory);
        $inventory->restore();
        $inventory->forceFill(['archived_by_user_id' => null])->save();

        return redirect()->route('corex.rental-inventories.show', $inventory)->with('success', 'Inventory restored.');
    }
}
