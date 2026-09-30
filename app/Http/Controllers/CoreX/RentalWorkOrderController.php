<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\EnforcesRecordVisibility;
use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Models\RentalWorkOrderSetting;
use App\Services\Rentals\RentalDocumentPdfService;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * .ai/specs/rental-work-orders.md §3/§6, Stage 4 — full CRUD + list screen
 * per BUILD_STANDARD §1a-§1d. A work order raised FROM a fault report is
 * created via RentalFaultReportController::raiseWorkOrder() instead — this
 * controller's store() is for a work order raised directly.
 */
class RentalWorkOrderController extends Controller
{
    use EnforcesRecordVisibility;

    /**
     * Search: property address, tenant name, supplier name, title/description.
     * Sort: reported_at (default, most-recent-first), status, property.
     * Filter: status, trade type, date range, property, paid_by, overdue.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $sort = $request->get('sort', 'reported_at');
        $direction = $request->get('direction', 'desc');
        $allowedSorts = ['reported_at', 'property', 'status', 'priority'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'reported_at';
        }
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        $query = RentalWorkOrder::query()
            ->visibleTo($user, $request->get('scope'))
            ->with(['property', 'lease.tenants.contact', 'supplier', 'createdByUser']);

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('property', function ($p) use ($search) {
                    $p->searchAddress($search);
                })->orWhereHas('lease.tenants.contact', function ($c) use ($search) {
                    $c->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%");
                })->orWhereHas('supplier', function ($s) use ($search) {
                    $s->where('name', 'like', "%{$search}%");
                })->orWhere('rental_work_orders.title', 'like', "%{$search}%")
                    ->orWhere('rental_work_orders.description', 'like', "%{$search}%");
            });
        }

        if ($status = $request->get('status')) {
            $query->where('rental_work_orders.status', $status);
        }
        if ($tradeType = $request->get('trade_type')) {
            $query->where('rental_work_orders.trade_type', $tradeType);
        }
        if ($priority = $request->get('priority')) {
            $query->where('rental_work_orders.priority', $priority);
        }
        if ($propertyId = $request->get('property_id')) {
            $query->where('rental_work_orders.property_id', $propertyId);
        }
        // Navigation, 2026-09-22 — reached from a lease's own detail page
        // (Johan: "every feature needs a navigation link where the work
        // happens"), same query-parameter shape as property_id above.
        if ($leaseId = $request->get('lease_id')) {
            $query->where('rental_work_orders.lease_id', $leaseId);
        }
        // Reached from a contact's own detail page — a contact can be a
        // tenant (via lease_tenants) or a landlord (via contact_property);
        // matches either, since the link doesn't know or care which.
        if ($contactId = $request->get('contact_id')) {
            $query->where(function ($q) use ($contactId) {
                $q->whereHas('lease.tenants', fn ($t) => $t->where('contact_id', $contactId))
                    ->orWhereHas('property.contacts', fn ($c) => $c->where('contacts.id', $contactId));
            });
        }
        if ($paidBy = $request->get('paid_by')) {
            $query->where('rental_work_orders.paid_by', $paidBy);
        }
        if ($dateFrom = $request->get('date_from')) {
            $query->where('rental_work_orders.reported_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->get('date_to')) {
            // Inclusive end date: a bare 'YYYY-MM-DD' compares as midnight,
            // which would drop every record reported ON the end date.
            $query->where('rental_work_orders.reported_at', '<=', preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo . ' 23:59:59' : $dateTo);
        }
        if ($request->boolean('overdue')) {
            $query->overdue(RentalWorkOrderSetting::overdueReminderDaysFor($user->effectiveAgencyId()));
        }

        if ($sort === 'property') {
            $query->join('properties', 'properties.id', '=', 'rental_work_orders.property_id')
                ->orderBy('properties.title', $direction)
                ->select('rental_work_orders.*');
        } else {
            $query->orderBy("rental_work_orders.{$sort}", $direction);
        }

        $hasAnyWorkOrders = RentalWorkOrder::query()->visibleTo($user, $request->get('scope'))->exists();

        $workOrders = $query->paginate(25)->withQueryString();

        // §"List screen gaps" — property_id/lease_id are reached via a link
        // from that record's own page (property tab, lease detail), never
        // picked from a dropdown of every property/lease in the agency;
        // the active filter is surfaced as a named, clearable chip instead.
        $filteredProperty = $propertyId ? Property::find($propertyId) : null;
        $filteredLease = $leaseId ?? null ? Lease::find($leaseId) : null;

        // §39, 2026-09-28 — summary tiles row, same reused FICA/rental-
        // applications pattern (§39 note on RentalInspectionController).
        // Status tiles are the real enum (RentalWorkOrder::STATUS_*).
        // Exception tile: "Overdue" — reuses the SAME overdue() scope +
        // RentalWorkOrderSetting::overdueReminderDaysFor() the ?overdue=1
        // filter above already wires in; a spend-threshold tile was the
        // other option Johan named, but overdue is the one this list
        // already has a real, working query-param filter for.
        $woTileBase = fn () => RentalWorkOrder::query()->visibleTo($user, $request->get('scope'));
        $tileCounts = [
            'reported' => $woTileBase()->where('rental_work_orders.status', RentalWorkOrder::STATUS_REPORTED)->count(),
            'ordered' => $woTileBase()->where('rental_work_orders.status', RentalWorkOrder::STATUS_ORDERED)->count(),
            'in_progress' => $woTileBase()->where('rental_work_orders.status', RentalWorkOrder::STATUS_IN_PROGRESS)->count(),
            'completed' => $woTileBase()->where('rental_work_orders.status', RentalWorkOrder::STATUS_COMPLETED)->count(),
            'cancelled' => $woTileBase()->where('rental_work_orders.status', RentalWorkOrder::STATUS_CANCELLED)->count(),
            'overdue' => $woTileBase()->overdue(RentalWorkOrderSetting::overdueReminderDaysFor($user->effectiveAgencyId()))->count(),
        ];

        return view('corex.rental-work-orders.index', [
            'workOrders' => $workOrders,
            'sort' => $sort,
            'direction' => $direction,
            'hasAnyWorkOrders' => $hasAnyWorkOrders,
            'filters' => $request->only(['q', 'status', 'trade_type', 'priority', 'property_id', 'lease_id', 'paid_by', 'date_from', 'date_to', 'overdue']),
            'filteredProperty' => $filteredProperty,
            'filteredLease' => $filteredLease,
            'tileCounts' => $tileCounts,
        ]);
    }

    /** §6 — "Work Order" button, reachable from the property tab (pre-filled property_id/lease_id). */
    public function create(Request $request): View
    {
        $property = $request->get('property_id') ? Property::findOrFail($request->get('property_id')) : null;
        $lease = $request->get('lease_id') ? Lease::findOrFail($request->get('lease_id')) : null;

        return view('corex.rental-work-orders.create', [
            'property' => $property,
            'lease' => $lease,
        ]);
    }

    /** A work order raised DIRECTLY — not from a fault report. See RentalFaultReportController::raiseWorkOrder(). */
    public function store(Request $request, RentalWorkOrderService $service): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();
        $propertyId = $request->input('property_id');

        $validated = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            // The lease / inspection item must belong to THIS property — a
            // same-agency lease on another property would otherwise make
            // notifyTenant() email the wrong tenant (audit L2).
            'lease_id' => ['nullable', Rule::exists('leases', 'id')->where('property_id', $propertyId)],
            'rental_inspection_item_id' => ['nullable', Rule::exists('rental_inspection_items', 'id')->where('property_id', $propertyId)],
            'reported_by_type' => ['required', 'in:' . implode(',', [
                RentalWorkOrder::REPORTED_BY_TENANT,
                RentalWorkOrder::REPORTED_BY_AGENT_NOTICED,
                RentalWorkOrder::REPORTED_BY_OWNER_INSTRUCTED,
                RentalWorkOrder::REPORTED_BY_INSPECTION,
            ])],
            'reported_by_contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('agency_id', $agencyId)],
            'reported_inspection_observation_id' => ['nullable', 'exists:rental_inspection_observations,id'],
            'trade_type' => ['nullable', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:191'],
            'description' => ['required', 'string'],
            'priority' => ['nullable', 'in:low,normal,urgent'],
        ]);

        $property = Property::findOrFail($validated['property_id']);
        // The acting user must be able to see this property (own/branch/agency)
        // — same rule as the inventory store; AgencyScope alone is not enough.
        abort_unless(
            Property::query()->visibleTo($request->user())->whereKey($property->id)->exists(),
            404
        );
        $user = $request->user();

        $attributes = $validated;
        unset($attributes['property_id']);
        $attributes['created_by_user_id'] = $user->id;
        if ($validated['reported_by_type'] === RentalWorkOrder::REPORTED_BY_AGENT_NOTICED) {
            $attributes['reported_by_user_id'] = $user->id;
        }

        $workOrder = $service->report($property, $attributes);

        return redirect()->route('corex.rental-work-orders.show', $workOrder)->with('success', 'Work order logged.');
    }

    public function show(Request $request, RentalWorkOrder $rentalWorkOrder): View
    {
        $this->assertVisible($request, $rentalWorkOrder);

        $rentalWorkOrder->load([
            'property', 'lease.tenants.contact', 'inspectionItem', 'supplier',
            'reportedByContact', 'reportedByUser', 'reportedFaultReport', 'cancelledByUser',
            'createdByUser', 'photos.uploadedBy', 'updates.createdByUser', 'approvals.recordedByUser',
            'quotes.supplier', 'quotes.capturedByUser',
        ]);

        return view('corex.rental-work-orders.show', [
            'workOrder' => $rentalWorkOrder,
            'completionRequiresPhoto' => RentalWorkOrderSetting::completionRequiresPhotoFor($rentalWorkOrder->agency_id),
            'noApprovalThreshold' => $rentalWorkOrder->spendThreshold(),
            // §3.4c full-CRUD floor — archived quotes stay reachable with a
            // restore path on this same screen (no separate quotes index).
            'archivedQuotes' => $rentalWorkOrder->quotes()->onlyTrashed()->with('supplier')->get(),
        ]);
    }

    /**
     * §"Printing" — a work order handed to a supplier. Same OWN/BRANCH/AGENCY
     * check as show() above (assertVisible) — a user who cannot open this
     * record's own detail page cannot download it either.
     */
    public function pdf(RentalWorkOrder $rentalWorkOrder, RentalDocumentPdfService $service)
    {
        $this->assertVisible(request(), $rentalWorkOrder);

        $pdf = $service->workOrderPdf($rentalWorkOrder);

        return request()->boolean('dl')
            ? $pdf->download($service->workOrderFilename($rentalWorkOrder))
            : $pdf->stream($service->workOrderFilename($rentalWorkOrder));
    }

    /** Editable only while status='reported' — the reportable facts, not the lifecycle. */
    public function update(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->assertVisible($request, $rentalWorkOrder);
        abort_unless($rentalWorkOrder->status === RentalWorkOrder::STATUS_REPORTED, 409, 'This work order has moved on and can no longer be edited here.');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'description' => ['required', 'string'],
            'trade_type' => ['nullable', 'string', 'max:60'],
            'priority' => ['nullable', 'in:low,normal,urgent'],
        ]);

        $rentalWorkOrder->update($validated);

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Work order updated.');
    }

    public function assignSupplier(Request $request, RentalWorkOrderService $service, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->assertVisible($request, $rentalWorkOrder);
        $validated = $request->validate([
            'agency_service_provider_id' => ['required', Rule::exists('agency_service_providers', 'id')->where('agency_id', $request->user()->effectiveAgencyId())],
            'trade_type' => ['nullable', 'string', 'max:60'],
        ]);

        try {
            $rentalWorkOrder->assignSupplier((int) $validated['agency_service_provider_id'], $validated['trade_type'] ?? null, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_work_order' => $e->getMessage()]);
        }

        $service->notifySupplier($rentalWorkOrder->fresh());

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Supplier assigned.');
    }

    /**
     * §3.4a — only for a work order raised WITHOUT an upstream fault report
     * (one raised FROM a fault report inherits its decision instead, §3a.1).
     */
    public function recordApproval(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->assertVisible($request, $rentalWorkOrder);
        $validated = $request->validate([
            'decision' => ['required', 'in:' . implode(',', [
                \App\Models\RentalApproval::DECISION_APPROVED,
                \App\Models\RentalApproval::DECISION_DECLINED,
            ])],
            'evidence_type' => ['required', 'in:' . implode(',', [
                \App\Models\RentalApproval::EVIDENCE_WHATSAPP,
                \App\Models\RentalApproval::EVIDENCE_EMAIL,
                \App\Models\RentalApproval::EVIDENCE_VERBAL_NOTE,
            ])],
            'evidence_text' => ['required', 'string'],
            'decided_at' => ['nullable', 'date'],
        ]);

        try {
            $rentalWorkOrder->recordApproval($request->user(), $validated);
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_work_order' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Approval decision recorded.');
    }

    public function startProgress(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->assertVisible($request, $rentalWorkOrder);
        try {
            $rentalWorkOrder->startProgress($request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_work_order' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Marked in progress.');
    }

    public function complete(Request $request, RentalWorkOrderService $service, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->assertVisible($request, $rentalWorkOrder);
        $validated = $request->validate([
            'paid_by' => ['required', 'in:' . implode(',', [
                RentalWorkOrder::PAID_BY_OWNER,
                RentalWorkOrder::PAID_BY_TENANT,
                RentalWorkOrder::PAID_BY_DEPOSIT_DEDUCTION,
                RentalWorkOrder::PAID_BY_NOT_YET_PAID,
            ])],
            'cost_amount' => ['nullable', 'numeric', 'min:0'],
            'completion_notes' => ['nullable', 'string'],
        ]);

        try {
            $rentalWorkOrder->complete($request->user(), $validated);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return back()->withErrors(['rental_work_order' => $e->getMessage()]);
        }

        $service->notifyCompleted($rentalWorkOrder->fresh());

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Work order completed.');
    }

    public function addNote(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->assertVisible($request, $rentalWorkOrder);
        $validated = $request->validate(['note' => ['required', 'string']]);

        $rentalWorkOrder->addNote($validated['note'], $request->user());

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Note added.');
    }

    public function cancel(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->assertVisible($request, $rentalWorkOrder);
        $validated = $request->validate(['cancel_reason' => ['required', 'string', 'max:500']]);

        try {
            $rentalWorkOrder->cancel($request->user(), $validated['cancel_reason']);
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_work_order' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Work order cancelled.');
    }

    public function destroy(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->assertVisible($request, $rentalWorkOrder);
        if (!$rentalWorkOrder->isDeletable()) {
            return back()->withErrors(['rental_work_order' => 'This work order has evidence logged against it and cannot be deleted — cancel it instead.']);
        }

        $rentalWorkOrder->archive($request->user());

        return redirect()->route('corex.rental-work-orders.index')->with('success', 'Work order archived.');
    }

    public function restore(Request $request, int $rentalWorkOrder): RedirectResponse
    {
        $workOrder = RentalWorkOrder::withTrashed()->findOrFail($rentalWorkOrder);
        $this->assertVisible($request, $workOrder);
        $workOrder->restoreRecord($request->user());

        return redirect()->route('corex.rental-work-orders.show', $workOrder)->with('success', 'Work order restored.');
    }

    /** §3.4 — 'reported' and 'in_progress' photo types upload the same way; 'completed' feeds the completion gate. */
    public function storePhoto(Request $request, RentalWorkOrderService $service, RentalWorkOrder $rentalWorkOrder): JsonResponse
    {
        $this->assertVisible($request, $rentalWorkOrder);
        $validated = $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:51200',
            'photo_type' => ['required', 'in:' . implode(',', [
                RentalWorkOrder::PHOTO_REPORTED, RentalWorkOrder::PHOTO_IN_PROGRESS, RentalWorkOrder::PHOTO_COMPLETED,
            ])],
            'client_idempotency_key' => 'nullable|uuid',
        ]);

        $clientKey = $validated['client_idempotency_key'] ?? null;
        if ($clientKey) {
            $existing = RentalWorkOrderPhoto::where('client_idempotency_key', $clientKey)
                ->where('rental_work_order_id', $rentalWorkOrder->id)->first();
            if ($existing) {
                return response()->json($existing, 200);
            }
        }

        $photo = $service->storePhoto($rentalWorkOrder, $request->file('photo'), $validated['photo_type'], $request->user(), $clientKey);

        return response()->json($photo, 201);
    }
}
