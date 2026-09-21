<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Models\RentalWorkOrderSetting;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-work-orders.md §3/§6, Stage 4 — full CRUD + list screen
 * per BUILD_STANDARD §1a-§1d. A work order raised FROM a fault report is
 * created via RentalFaultReportController::raiseWorkOrder() instead — this
 * controller's store() is for a work order raised directly.
 */
class RentalWorkOrderController extends Controller
{
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
        $allowedSorts = ['reported_at', 'property', 'status'];
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
        if ($propertyId = $request->get('property_id')) {
            $query->where('rental_work_orders.property_id', $propertyId);
        }
        if ($paidBy = $request->get('paid_by')) {
            $query->where('rental_work_orders.paid_by', $paidBy);
        }
        if ($dateFrom = $request->get('date_from')) {
            $query->where('rental_work_orders.reported_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->get('date_to')) {
            $query->where('rental_work_orders.reported_at', '<=', $dateTo);
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

        return view('corex.rental-work-orders.index', [
            'workOrders' => $workOrders,
            'sort' => $sort,
            'direction' => $direction,
            'hasAnyWorkOrders' => $hasAnyWorkOrders,
            'filters' => $request->only(['q', 'status', 'trade_type', 'property_id', 'paid_by', 'date_from', 'date_to', 'overdue']),
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
        $validated = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'lease_id' => ['nullable', 'exists:leases,id'],
            'rental_inspection_item_id' => ['nullable', 'exists:rental_inspection_items,id'],
            'reported_by_type' => ['required', 'in:' . implode(',', [
                RentalWorkOrder::REPORTED_BY_TENANT,
                RentalWorkOrder::REPORTED_BY_AGENT_NOTICED,
                RentalWorkOrder::REPORTED_BY_OWNER_INSTRUCTED,
                RentalWorkOrder::REPORTED_BY_INSPECTION,
            ])],
            'reported_by_contact_id' => ['nullable', 'exists:contacts,id'],
            'reported_inspection_observation_id' => ['nullable', 'exists:rental_inspection_observations,id'],
            'trade_type' => ['nullable', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:191'],
            'description' => ['required', 'string'],
            'priority' => ['nullable', 'in:low,normal,urgent'],
        ]);

        $property = Property::findOrFail($validated['property_id']);
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
        $rentalWorkOrder->load([
            'property', 'lease.tenants.contact', 'inspectionItem', 'supplier',
            'reportedByContact', 'reportedByUser', 'reportedFaultReport', 'cancelledByUser',
            'createdByUser', 'photos.uploadedBy', 'updates.createdByUser', 'approvals.recordedByUser',
        ]);

        return view('corex.rental-work-orders.show', [
            'workOrder' => $rentalWorkOrder,
            'completionRequiresPhoto' => RentalWorkOrderSetting::completionRequiresPhotoFor($rentalWorkOrder->agency_id),
        ]);
    }

    /** Editable only while status='reported' — the reportable facts, not the lifecycle. */
    public function update(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
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
        $validated = $request->validate([
            'agency_service_provider_id' => ['required', 'exists:agency_service_providers,id'],
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
        try {
            $rentalWorkOrder->startProgress($request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_work_order' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Marked in progress.');
    }

    public function complete(Request $request, RentalWorkOrderService $service, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
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
        $validated = $request->validate(['note' => ['required', 'string']]);

        $rentalWorkOrder->addNote($validated['note'], $request->user());

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Note added.');
    }

    public function cancel(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
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
        if (!$rentalWorkOrder->isDeletable()) {
            return back()->withErrors(['rental_work_order' => 'This work order has evidence logged against it and cannot be deleted — cancel it instead.']);
        }

        $rentalWorkOrder->delete();

        return redirect()->route('corex.rental-work-orders.index')->with('success', 'Work order archived.');
    }

    public function restore(Request $request, int $rentalWorkOrder): RedirectResponse
    {
        $workOrder = RentalWorkOrder::withTrashed()->findOrFail($rentalWorkOrder);
        $workOrder->restore();

        return redirect()->route('corex.rental-work-orders.show', $workOrder)->with('success', 'Work order restored.');
    }

    /** §3.4 — 'reported' and 'in_progress' photo types upload the same way; 'completed' feeds the completion gate. */
    public function storePhoto(Request $request, RentalWorkOrderService $service, RentalWorkOrder $rentalWorkOrder): JsonResponse
    {
        $validated = $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:51200',
            'photo_type' => ['required', 'in:' . implode(',', [
                RentalWorkOrder::PHOTO_REPORTED, RentalWorkOrder::PHOTO_IN_PROGRESS, RentalWorkOrder::PHOTO_COMPLETED,
            ])],
            'client_idempotency_key' => 'nullable|uuid',
        ]);

        $clientKey = $validated['client_idempotency_key'] ?? null;
        if ($clientKey) {
            $existing = RentalWorkOrderPhoto::where('client_idempotency_key', $clientKey)->first();
            if ($existing) {
                return response()->json($existing, 200);
            }
        }

        $photo = $service->storePhoto($rentalWorkOrder, $request->file('photo'), $validated['photo_type'], $request->user(), $clientKey);

        return response()->json($photo, 201);
    }
}
