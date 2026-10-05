<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Concerns\ExportsRentalList;
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
use Illuminate\View\View;

/**
 * .ai/specs/rental-work-orders.md §3/§6, Stage 4 — full CRUD + list screen
 * per BUILD_STANDARD §1a-§1d. A work order raised FROM a fault report is
 * created via RentalFaultReportController::raiseWorkOrder() instead — this
 * controller's store() is for a work order raised directly.
 */
class RentalWorkOrderController extends Controller
{
    use AuthorizesRentalRecordScope;
    use ExportsRentalList;

    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    /**
     * Search: property address, tenant name, supplier name, title/description.
     * Sort: reported_at (default, most-recent-first), status, property.
     * Filter: status, trade type, date range, property, paid_by, overdue.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        // AT-439 — own/branch/all "Showing:" control, same pattern as
        // RentalApplicationController::index()/LeaseController::index().
        $maxScope = \App\Services\PermissionService::getDataScope($user, 'rental_work_orders');
        $resolvedScope = \App\Services\PermissionService::clampScope($request->get('scope'), $maxScope);
        $scopeOptions = match ($maxScope) {
            'all' => ['own', 'branch', 'all'],
            'branch' => ['own', 'branch'],
            default => ['own'],
        };

        $sort = $request->get('sort', 'reported_at');
        $direction = $request->get('direction', 'desc');
        $allowedSorts = ['reported_at', 'property', 'status', 'priority'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'reported_at';
        }
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        $showArchived = $request->boolean('archived');
        $query = $this->filteredWorkOrdersQuery($request, $showArchived);

        $propertyId = $request->get('property_id');
        $leaseId = $request->get('lease_id');

        if ($sort === 'property') {
            $query->join('properties', 'properties.id', '=', 'rental_work_orders.property_id')
                ->orderBy('properties.title', $direction)
                ->select('rental_work_orders.*');
        } else {
            $query->orderBy("rental_work_orders.{$sort}", $direction);
        }

        $hasAnyWorkOrders = RentalWorkOrder::query()
            ->when($showArchived, fn ($q) => $q->onlyTrashed())
            ->visibleTo($user, $request->get('scope'))->exists();

        $perPage = (int) $request->get('per_page', 25);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 25;
        }

        $workOrders = $query->paginate($perPage)->withQueryString();

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
        $woTileBase = fn () => RentalWorkOrder::query()
            ->when($showArchived, fn ($q) => $q->onlyTrashed())
            ->visibleTo($user, $request->get('scope'));
        $tileCounts = [
            'total' => $woTileBase()->count(),
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
            'showArchived' => $showArchived,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'filters' => $request->only(['q', 'status', 'trade_type', 'priority', 'property_id', 'lease_id', 'paid_by', 'date_from', 'date_to', 'overdue']),
            'filteredProperty' => $filteredProperty,
            'filteredLease' => $filteredLease,
            'tileCounts' => $tileCounts,
            'resolvedScope' => $resolvedScope,
            'scopeOptions' => $scopeOptions,
        ]);
    }

    /**
     * Shared scoped+filtered query, reused by index()/printList()/export().
     * Returns an UNSORTED, UNPAGINATED builder.
     */
    private function filteredWorkOrdersQuery(Request $request, bool $onlyArchived = false)
    {
        $user = $request->user();

        $query = RentalWorkOrder::query()
            ->when($onlyArchived, fn ($q) => $q->onlyTrashed())
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
        if ($leaseId = $request->get('lease_id')) {
            $query->where('rental_work_orders.lease_id', $leaseId);
        }
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
            $query->where('rental_work_orders.reported_at', '<=', $dateTo);
        }
        if ($request->boolean('overdue')) {
            $query->overdue(RentalWorkOrderSetting::overdueReminderDaysFor($user->effectiveAgencyId()));
        }

        return $query;
    }

    private function activeWorkOrderFiltersSummary(Request $request): array
    {
        $out = [];
        if ($q = $request->get('q')) {
            $out['Search'] = $q;
        }
        if ($status = $request->get('status')) {
            $out['Status'] = ucfirst(str_replace('_', ' ', $status));
        }
        if ($tradeType = $request->get('trade_type')) {
            $out['Trade type'] = $tradeType;
        }
        if ($priority = $request->get('priority')) {
            $out['Priority'] = ucfirst($priority);
        }
        if ($paidBy = $request->get('paid_by')) {
            $out['Paid by'] = ucfirst(str_replace('_', ' ', $paidBy));
        }
        if ($request->boolean('overdue')) {
            $out['Overdue'] = 'Yes';
        }
        if ($df = $request->get('date_from')) {
            $out['Reported from'] = $df;
        }
        if ($dt = $request->get('date_to')) {
            $out['Reported to'] = $dt;
        }
        if ($request->boolean('archived')) {
            $out['Archived'] = 'Yes';
        }
        $out['Scope'] = ucfirst(\App\Services\PermissionService::clampScope(
            $request->get('scope'),
            \App\Services\PermissionService::getDataScope($request->user(), 'rental_work_orders')
        ));

        return $out;
    }

    /** req — print the current filtered list, same scoping as index(), filters shown in the header. */
    public function printList(Request $request): View
    {
        $workOrders = $this->filteredWorkOrdersQuery($request, $request->boolean('archived'))
            ->orderBy('rental_work_orders.reported_at', 'desc')
            ->get();

        return view('corex.rental-work-orders.print-list', [
            'workOrders' => $workOrders,
            'printFilters' => $this->activeWorkOrderFiltersSummary($request),
        ]);
    }

    /** req — export the current filtered list as xlsx/csv, same scoping as index(). */
    public function export(Request $request)
    {
        $workOrders = $this->filteredWorkOrdersQuery($request, $request->boolean('archived'))
            ->orderBy('rental_work_orders.reported_at', 'desc')
            ->get();

        $headers = ['Property', 'Title', 'Status', 'Priority', 'Supplier', 'Paid by', 'Reported'];
        $rows = $workOrders->map(fn (RentalWorkOrder $wo) => [
            $wo->property?->buildDisplayAddress() ?? 'Unknown property',
            $wo->title,
            ucfirst(str_replace('_', ' ', $wo->status)),
            $wo->priority ? ucfirst($wo->priority) : '',
            $wo->supplier?->name ?? '',
            $wo->paid_by ? ucfirst(str_replace('_', ' ', $wo->paid_by)) : '',
            $wo->reported_at?->format('Y-m-d') ?? '',
        ]);

        $filename = 'rental-work-orders-' . now()->format('Y-m-d');

        return $request->get('format') === 'csv'
            ? $this->streamRentalListCsv($filename . '.csv', $headers, $rows)
            : $this->streamRentalListXlsx($filename . '.xlsx', $headers, $rows);
    }

    /**
     * §6 — "Work Order" button, reachable from the property tab (pre-filled
     * property_id/lease_id).
     *
     * AT-442 fix #1/#3 — lease_id WINS and derives the property, same
     * pattern as RentalInspectionController::create(): a lease and a
     * property passed independently (one stale, one freshly picked) is
     * exactly how job card #1 ended up pointed at another property's lease
     * (QA1 walk, 2026-10-05). Resolved only inside the user's own scope —
     * a lease/property outside it silently falls back to no pre-selection
     * (BUILD_STANDARD §3, absorb) rather than a 403/500.
     */
    public function create(Request $request): View
    {
        $user = $request->user();

        $lease = null;
        if ($leaseId = $request->get('lease_id')) {
            $lease = Lease::query()->visibleTo($user, null)->find($leaseId);
        }

        $property = $lease
            ? $lease->property
            : ($request->get('property_id') ? Property::visibleTo($user)->find($request->get('property_id')) : null);

        return view('corex.rental-work-orders.create', [
            'property' => $property,
            'lease' => $lease,
        ]);
    }

    /** AT-442 fix #2 — searchable property picker, same pattern as RentalApplicationController::searchProperties(). */
    public function searchProperties(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $properties = Property::query()
            ->where('listing_type', 'rental')
            ->visibleTo($request->user())
            ->searchAddress($q)
            ->with('agent')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json($properties->map(fn (Property $p) => $p->toSearchResult([
            'ref' => $p->property_number,
        ])));
    }

    /**
     * A work order raised DIRECTLY — not from a fault report. See
     * RentalFaultReportController::raiseWorkOrder().
     *
     * AT-442 req #1 — "who does the work" is the FIRST choice. When
     * assignment_type='internal', this creates the work order AND its
     * linked job card together (RentalJobCardService::createForProperty())
     * rather than a bare work order — the two are built together, never a
     * work order that later needs a job card bolted on.
     */
    public function store(Request $request, RentalWorkOrderService $service): RedirectResponse
    {
        $validated = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'lease_id' => ['nullable', 'exists:leases,id'],
            'rental_inspection_item_id' => ['nullable', 'exists:rental_inspection_items,id'],
            'assignment_type' => ['nullable', 'in:' . implode(',', [
                RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER,
                RentalWorkOrder::ASSIGNMENT_INTERNAL,
            ])],
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

        // AT-442 fix #1 — "the lease wins" (Johan's ruling): never trust a
        // property_id posted alongside a lease_id without checking they
        // agree. A stale/independently-resolved lease_id (e.g. left over
        // from a different property's link) must never attach to whatever
        // property happens to be selected — the lease's OWN property is
        // authoritative. Same class of guard belongs wherever a lease_id
        // and a property_id can arrive independently (RentalJobCardController
        // carries the identical fix).
        if (!empty($validated['lease_id'])) {
            $lease = Lease::findOrFail($validated['lease_id']);
            if ($lease->property_id !== $property->id) {
                $property = $lease->property;
                $validated['property_id'] = $property->id;
            }
        }

        if (($validated['assignment_type'] ?? RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER) === RentalWorkOrder::ASSIGNMENT_INTERNAL) {
            $jobCard = app(\App\Services\Rentals\RentalJobCardService::class)->createForProperty($property, $validated, $user);

            return redirect()->route('corex.rental-job-cards.show', $jobCard)->with('success', 'Work order logged — job card created.');
        }

        $attributes = $validated;
        unset($attributes['property_id'], $attributes['assignment_type']);
        $attributes['created_by_user_id'] = $user->id;
        if ($validated['reported_by_type'] === RentalWorkOrder::REPORTED_BY_AGENT_NOTICED) {
            $attributes['reported_by_user_id'] = $user->id;
        }

        $workOrder = $service->report($property, $attributes);

        return redirect()->route('corex.rental-work-orders.show', $workOrder)->with('success', 'Work order logged.');
    }

    public function show(Request $request, RentalWorkOrder $rentalWorkOrder): View
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

        $rentalWorkOrder->load([
            'property', 'lease.tenants.contact', 'inspectionItem', 'supplier',
            'reportedByContact', 'reportedByUser', 'reportedFaultReport', 'cancelledByUser',
            'createdByUser', 'photos.uploadedBy', 'updates.createdByUser', 'approvals.recordedByUser',
            'quotes.supplier', 'quotes.capturedByUser',
        ]);

        return view('corex.rental-work-orders.show', [
            'workOrder' => $rentalWorkOrder,
            'completionRequiresPhoto' => RentalWorkOrderSetting::completionRequiresPhotoFor($rentalWorkOrder->agency_id),
            'noApprovalThreshold' => \App\Models\RentalWorkOrderSetting::thresholdFor($rentalWorkOrder->property),
            // §3.4c full-CRUD floor — archived quotes stay reachable with a
            // restore path on this same screen (no separate quotes index).
            'archivedQuotes' => $rentalWorkOrder->quotes()->onlyTrashed()->with('supplier')->get(),
        ]);
    }

    /**
     * §"Printing" — a work order handed to a supplier. Same query-layer
     * scoping as show() above (route-model-binding + the global AgencyScope) —
     * a user who cannot open this record's own detail page cannot download
     * it either, since both resolve the SAME bound model the SAME way.
     */
    public function pdf(RentalWorkOrder $rentalWorkOrder, RentalDocumentPdfService $service)
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

        $pdf = $service->workOrderPdf($rentalWorkOrder);

        return request()->boolean('dl')
            ? $pdf->download($service->workOrderFilename($rentalWorkOrder))
            : $pdf->stream($service->workOrderFilename($rentalWorkOrder));
    }

    /** Editable only while status='reported' — the reportable facts, not the lifecycle. */
    public function update(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

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
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

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
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

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
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

        try {
            $rentalWorkOrder->startProgress($request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_work_order' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Marked in progress.');
    }

    public function complete(Request $request, RentalWorkOrderService $service, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

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
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

        $validated = $request->validate(['note' => ['required', 'string']]);

        $rentalWorkOrder->addNote($validated['note'], $request->user());

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Note added.');
    }

    public function cancel(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

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
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

        if (!$rentalWorkOrder->isDeletable()) {
            return back()->withErrors(['rental_work_order' => 'This work order has evidence logged against it and cannot be deleted — cancel it instead.']);
        }

        $rentalWorkOrder->archive($request->user());

        return redirect()->route('corex.rental-work-orders.index')->with('success', 'Work order archived.');
    }

    public function restore(Request $request, int $rentalWorkOrder): RedirectResponse
    {
        $workOrder = RentalWorkOrder::withTrashed()->findOrFail($rentalWorkOrder);
        $this->guardRentalRecordScope($workOrder, 'rental_work_orders', $workOrder->property?->branch_id);

        $workOrder->restoreRecord($request->user());

        return redirect()->route('corex.rental-work-orders.show', $workOrder)->with('success', 'Work order restored.');
    }

    /** §3.4 — 'reported' and 'in_progress' photo types upload the same way; 'completed' feeds the completion gate. */
    public function storePhoto(Request $request, RentalWorkOrderService $service, RentalWorkOrder $rentalWorkOrder): JsonResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

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
