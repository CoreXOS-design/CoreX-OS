<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Concerns\ExportsRentalList;
use App\Http\Controllers\Concerns\SearchesQualifyingRentalProperties;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderQuote;
use App\Models\RentalWorkOrderPhoto;
use App\Models\RentalWorkOrderSetting;
use App\Services\Rentals\RentalApprovalGateService;
use App\Services\Rentals\RentalDocumentPdfService;
use App\Services\Rentals\RentalInspectionFollowUpService;
use App\Services\Rentals\RentalJobCardService;
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
    use AuthorizesRentalRecordScope;
    use ExportsRentalList;
    use SearchesQualifyingRentalProperties;

    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    /**
     * The LIST screen's own property-filter picker — only properties that
     * actually have a work order visible to this user, never every rental
     * property. Deliberately separate from searchProperties() below (the
     * CREATE screen's own picker, AT-442 fix #2), which keeps offering
     * every rental property, unchanged.
     */
    public function searchFilterProperties(Request $request): JsonResponse
    {
        return $this->searchQualifyingRentalProperties($request, RentalWorkOrder::class);
    }

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
        $maxScope = \App\Services\Rentals\RentalDataScope::ceiling($user, 'rental_work_orders');
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
            'disputed' => $woTileBase()->where('rental_work_orders.status', RentalWorkOrder::STATUS_DISPUTED)->count(),
            'overdue' => $woTileBase()->overdue(RentalWorkOrderSetting::overdueReminderDaysFor($user->effectiveAgencyId()))->count(),
            // §17.17 — the owner owes an answer: the quote is out / extra work was put to him. Same own/branch/agency base as every tile.
            'awaiting_owner' => $woTileBase()->awaitingOwner()->count(),
            'variation_pending' => $woTileBase()->variationPending()->count(),
        ];

        return view('corex.rental-work-orders.index', [
            'workOrders' => $workOrders,
            'sort' => $sort,
            'direction' => $direction,
            'hasAnyWorkOrders' => $hasAnyWorkOrders,
            'showArchived' => $showArchived,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'filters' => $request->only(['q', 'status', 'trade_type', 'priority', 'property_id', 'lease_id', 'paid_by', 'date_from', 'date_to', 'overdue', 'awaiting_owner', 'variation_pending']),
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
            // Inclusive end date: a bare 'YYYY-MM-DD' compares as midnight,
            // which would drop every record reported ON the end date.
            $query->where('rental_work_orders.reported_at', '<=', preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo . ' 23:59:59' : $dateTo);
        }
        if ($request->boolean('overdue')) {
            $query->overdue(RentalWorkOrderSetting::overdueReminderDaysFor($user->effectiveAgencyId()));
        }
        // §17.17 — "Awaiting owner" / "Variation pending". Applied here so the screen, the print list and the export all agree.
        if ($request->boolean('awaiting_owner')) {
            $query->awaitingOwner();
        }
        if ($request->boolean('variation_pending')) {
            $query->variationPending();
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
        if ($request->boolean('awaiting_owner')) {
            $out['Awaiting owner'] = 'Yes';
        }
        if ($request->boolean('variation_pending')) {
            $out['Variation pending'] = 'Yes';
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
        $out['Scope'] = ucfirst(\App\Services\Rentals\RentalDataScope::resolve($request->user(), 'rental_work_orders', $request->get('scope')));

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
     *
     * §15 (AT-447) — ALSO reachable from an inspection's Follow-up block,
     * carrying rental_inspection_id + observation_ids[] (+ combine)
     * instead: $followUp then resolves property/lease/title/description
     * from the marked item(s) rather than the query string (applied AFTER
     * the lease-wins resolution above, so the same guard covers both
     * entry points), and the create view switches to a prefilled
     * single-record form or a batch (one work order per item) form
     * accordingly. assignment_type (?='internal' for the "Create job card"
     * shortcut) only ever pre-selects the existing "Who does the work?"
     * radio — it never skips that choice.
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

        $followUp = null;
        $observationIds = array_filter((array) $request->get('observation_ids', []));
        if ($request->filled('rental_inspection_id') && !empty($observationIds)) {
            $inspection = \App\Models\RentalInspection::find($request->get('rental_inspection_id'));
            if ($inspection) {
                $followUp = app(RentalInspectionFollowUpService::class)->buildWorkOrderPrefill(
                    $inspection,
                    array_map('intval', $observationIds),
                    $request->boolean('combine')
                );
                $property = $followUp['property'] ?? $property;
                $lease = $followUp['lease'] ?? $lease;
            }
        }

        return view('corex.rental-work-orders.create', [
            'property' => $property,
            'lease' => $lease,
            'followUp' => $followUp,
            'presetAssignmentType' => $request->get('assignment_type'),
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
        // §15 (AT-447) — the Follow-up block's batch path: several marked
        // items, "combine into one" NOT ticked, so each becomes its own
        // work order (or job card), sharing only the fields a human must
        // still decide (who does the work, trade type) rather than one
        // free-text title/description per item. Short-circuits before the
        // single-item validation below — storeBatch() has its own.
        if ($request->has('batch_items')) {
            return $this->storeBatch($request);
        }

        $agencyId = $request->user()->effectiveAgencyId();
        $propertyId = $request->input('property_id');

        $validated = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            // The lease / inspection item must belong to THIS property — a
            // same-agency lease on another property would otherwise make
            // notifyTenant() email the wrong tenant (audit L2).
            'lease_id' => ['nullable', Rule::exists('leases', 'id')->where('property_id', $propertyId)],
            'rental_inspection_item_id' => ['nullable', Rule::exists('rental_inspection_items', 'id')->where('property_id', $propertyId)],
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

    /**
     * §15 (AT-447) — one work order (or job card, when assignment_type is
     * internal) per batch_items row, reusing the EXACT SAME creation calls
     * store() above uses for a single record — no second lifecycle/
     * notification path. Idempotent: an observation that already has a
     * work order by the time this runs (a resubmit, or two tabs) is simply
     * skipped, never duplicated.
     */
    private function storeBatch(Request $request): RedirectResponse
    {
        $propertyId = $request->input('property_id');

        $validated = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            // Same audit L2 cross-contamination guard the single-item path
            // above just gained (AT-442 QA1-walk follow-up) — the lease and
            // every batch item's inspection item must belong to THIS
            // property, never a same-agency one left over from elsewhere.
            'lease_id' => ['nullable', Rule::exists('leases', 'id')->where('property_id', $propertyId)],
            'rental_inspection_id' => ['nullable', 'exists:rental_inspections,id'],
            'assignment_type' => ['required', 'in:' . implode(',', [
                RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER,
                RentalWorkOrder::ASSIGNMENT_INTERNAL,
            ])],
            'trade_type' => ['nullable', 'string', 'max:60'],
            'batch_items' => ['required', 'array', 'min:1'],
            'batch_items.*.observation_id' => ['required', 'integer', 'exists:rental_inspection_observations,id'],
            'batch_items.*.title' => ['required', 'string', 'max:191'],
            'batch_items.*.description' => ['nullable', 'string'],
            'batch_items.*.rental_inspection_item_id' => ['nullable', 'integer', Rule::exists('rental_inspection_items', 'id')->where('property_id', $propertyId)],
        ]);

        $property = Property::findOrFail($validated['property_id']);
        // Same own/branch/agency visibility check as the single-item path.
        abort_unless(
            Property::query()->visibleTo($request->user())->whereKey($property->id)->exists(),
            404
        );
        $user = $request->user();
        $created = [];

        foreach ($validated['batch_items'] as $item) {
            // Idempotent — same skip-not-duplicate rule as
            // RentalInspectionFollowUpService::buildWorkOrderPrefill()'s own
            // already-raised filter, re-checked here against a possible
            // race/resubmit between rendering the form and this submit.
            if (RentalWorkOrder::where('reported_inspection_observation_id', $item['observation_id'])->exists()) {
                continue;
            }

            $attributes = [
                'lease_id' => $validated['lease_id'] ?? null,
                'rental_inspection_item_id' => $item['rental_inspection_item_id'] ?? null,
                'reported_inspection_observation_id' => $item['observation_id'],
                'reported_by_type' => RentalWorkOrder::REPORTED_BY_INSPECTION,
                'trade_type' => $validated['trade_type'] ?? null,
                'title' => $item['title'],
                'description' => $item['description'] ?: $item['title'],
                'created_by_user_id' => $user->id,
            ];

            $created[] = $validated['assignment_type'] === RentalWorkOrder::ASSIGNMENT_INTERNAL
                ? app(RentalJobCardService::class)->createForProperty($property, $attributes, $user)
                : app(RentalWorkOrderService::class)->report($property, $attributes);
        }

        if (empty($created)) {
            return redirect()->back()->with('error', 'Nothing to create — every selected item already has a work order.');
        }

        $noun = $validated['assignment_type'] === RentalWorkOrder::ASSIGNMENT_INTERNAL ? 'job card(s)' : 'work order(s)';
        $message = count($created) . " {$noun} created.";

        if (!empty($validated['rental_inspection_id'])) {
            return redirect()->route('corex.rental-inspections.show', $validated['rental_inspection_id'])->with('success', $message);
        }

        return redirect()->route('corex.rental-work-orders.index')->with('success', $message);
    }

    public function show(Request $request, RentalWorkOrder $rentalWorkOrder): View
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

        $rentalWorkOrder->load([
            'property', 'lease.tenants.contact', 'inspectionItem', 'supplier',
            'reportedByContact', 'reportedByUser', 'reportedFaultReport', 'cancelledByUser',
            // §15 (AT-447) — "From inspection <type> <date>" back-link.
            'reportedInspectionObservation.inspection',
            'createdByUser', 'photos.uploadedBy', 'updates.createdByUser', 'approvals.recordedByUser',
            'quotes.supplier', 'quotes.capturedByUser',
        ]);

        $gate = app(RentalApprovalGateService::class);

        return view('corex.rental-work-orders.show', [
            'workOrder' => $rentalWorkOrder,
            'completionRequiresPhoto' => RentalWorkOrderSetting::completionRequiresPhotoFor($rentalWorkOrder->agency_id),
            'noApprovalThreshold' => $rentalWorkOrder->spendThreshold(),
            // BUILD 2 — a pure read of "may work start?" for the screen (it writes nothing), the owner's contacts for the emergency
            // form, and the agency's external-quote fee settings. The fee is the agency's margin: shown only to staff who can see costs.
            'proceed' => $gate->authoriseToProceed($rentalWorkOrder, false),
            'selectedQuote' => $rentalWorkOrder->quotes->firstWhere('is_selected', true),
            'ownerContacts' => $gate->ownerContacts($rentalWorkOrder),
            'externalFeeType' => $rentalWorkOrder->external_markup_type ?? RentalWorkOrderSetting::externalQuoteMarkupTypeFor($rentalWorkOrder->agency_id),
            'externalFeeValue' => $rentalWorkOrder->external_markup_value !== null
                ? (float) $rentalWorkOrder->external_markup_value
                : RentalWorkOrderSetting::externalQuoteMarkupValueFor($rentalWorkOrder->agency_id),
            'canSeeFee' => \App\Services\PermissionService::userHasPermission($request->user(), 'rental_job_cards.view_costs'),
            // §3.4c full-CRUD floor — archived quotes stay reachable with a
            // restore path on this same screen (no separate quotes index).
            'archivedQuotes' => $rentalWorkOrder->quotes()->onlyTrashed()->with('supplier')->get(),
        ]);
    }

    /**
     * §"Printing" — a work order handed to a supplier. Same OWN/BRANCH/AGENCY
     * check as show() above (guardRentalRecordScope) — a user who cannot open this
     * record's own detail page cannot download it either.
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

    /**
     * §17.9.5 — "Send work order to contractor": assigns the selected quote's supplier (reported → ordered, as built; refused
     * without an authorisation — the owner's approval, the no-approval limit or an emergency agreement) and emails the
     * contractor the work order PDF, showing the owner approved. Replaces the old "Assign supplier" + plain supplier mail.
     * Used again later it re-sends the same work order.
     */
    public function assignSupplier(Request $request, RentalWorkOrderService $service, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

        $validated = $request->validate([
            'agency_service_provider_id' => ['required', Rule::exists('agency_service_providers', 'id')->where('agency_id', $request->user()->effectiveAgencyId())],
            'trade_type' => ['nullable', 'string', 'max:60'],
        ]);

        try {
            $rentalWorkOrder->assignSupplier((int) $validated['agency_service_provider_id'], $validated['trade_type'] ?? null, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_work_order' => $e->getMessage()]);
        }

        $mailed = $service->sendContractorWorkOrder($rentalWorkOrder->fresh(), $request->user());

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with(
            $mailed ? 'success' : 'warning',
            $mailed
                ? 'Work order sent to the contractor.'
                : 'The work order is with this contractor, but they have no email address on file — download the work order PDF and send it to them yourself.',
        );
    }

    /**
     * §17.9.1a — a work order's own override of the agency's fee on an outside contractor's quote (null/blank = inherit the agency
     * setting). Staff who can price (rental_job_cards.price). Only while the owner has not approved an amount yet — afterwards the
     * owner-facing figure is fixed and a change would be a variation. Recomputes the fee on this work order's quotes.
     */
    public function updateExternalFee(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

        if ($rentalWorkOrder->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL) {
            abort(404);
        }
        if ($rentalWorkOrder->hasApprovedBaseline() || in_array($rentalWorkOrder->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true)) {
            return back()->withErrors(['rental_work_order' => 'The owner has already approved an amount for this work order, so the fee can no longer be changed.']);
        }

        $validated = $request->validate([
            'external_markup_type' => ['nullable', 'in:' . RentalWorkOrderSetting::EXTERNAL_MARKUP_PERCENT . ',' . RentalWorkOrderSetting::EXTERNAL_MARKUP_AMOUNT],
            'external_markup_value' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ]);
        $type = $validated['external_markup_type'] ?? null;
        $value = $validated['external_markup_value'] ?? null;
        if ($type === RentalWorkOrderSetting::EXTERNAL_MARKUP_PERCENT && $value !== null && (float) $value > 1000) {
            return back()->withErrors(['external_markup_value' => 'A percentage fee cannot be more than 1000 %.'])->withInput();
        }
        // blank value = inherit the agency setting (both columns cleared); a value needs a type
        if ($value === null || $value === '') {
            $type = null;
            $value = null;
        } elseif ($type === null) {
            $type = RentalWorkOrderSetting::externalQuoteMarkupTypeFor($rentalWorkOrder->agency_id);
        }

        $rentalWorkOrder->forceFill(['external_markup_type' => $type, 'external_markup_value' => $value])->save();

        $selected = null;
        foreach ($rentalWorkOrder->quotes()->whereNull('rental_job_card_id')->whereNull('superseded_at')->get() as $quote) {
            $fee = RentalWorkOrderQuote::feeAttributes($rentalWorkOrder, (float) $quote->amount, false);
            unset($fee['term_text']);
            $quote->update($fee);
            if ($quote->is_selected) {
                $selected = $quote->fresh();
            }
        }
        // the selected quote's owner-facing figure may have moved: run it through the gate again (nothing is approved yet)
        if ($selected) {
            $rentalWorkOrder->selectQuote($selected, $request->user());
        }
        $rentalWorkOrder->updates()->create([
            'agency_id' => $rentalWorkOrder->agency_id, 'update_type' => 'note', 'created_by_user_id' => $request->user()->id,
            'note' => $type === null ? 'Fee on the contractor\'s quote set back to the agency default' : 'Fee on the contractor\'s quote set to ' . ($type === RentalWorkOrderSetting::EXTERNAL_MARKUP_PERCENT ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') . ' %' : 'R' . number_format((float) $value, 2)) . ' for this work order',
        ]);

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Fee updated.');
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

        // §17.12 — an internal job is closed from its JOB CARD, which closes the card and this work order together (or neither).
        // This Complete form is the outside contractor's close; posting it for an internal job would close the work order and
        // leave its job card open — the half-closed pair §17.10.9 / §17.23 defect #1 forbids.
        // (An open tenant dispute is refused by the model with its own, more specific words — let that message win.)
        $openCard = $rentalWorkOrder->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL && ! $rentalWorkOrder->hasOpenDispute() ? $rentalWorkOrder->jobCard : null;
        if ($openCard && ! $openCard->isClosed()) {
            return back()->withErrors(['rental_work_order' => 'Our own team\'s job is closed from its job card — use "Complete job card" there, which closes this work order with it.']);
        }

        try {
            $rentalWorkOrder->complete($request->user(), $validated);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return back()->withErrors(['rental_work_order' => $e->getMessage()]);
        }

        $service->notifyCompleted($rentalWorkOrder->fresh());

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Work order completed.');
    }

    /**
     * W2/W6 - book (or re-book) the repair appointment. The agent normally coordinates it with the tenant; the tenant is
     * emailed when it is set or changed. (The owner can do the same from the portal.)
     */
    public function setAppointment(Request $request, RentalWorkOrderService $service, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

        $validated = $request->validate([
            'appointment_at' => ['required', 'date'],
            'appointment_note' => ['nullable', 'string', 'max:500'],
        ]);

        // The box is read in the AGENCY timezone (the same convention as the job card's scheduling box).
        $tz = $rentalWorkOrder->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
        $at = \Illuminate\Support\Carbon::parse($validated['appointment_at'], $tz)->utc();

        try {
            $changed = $service->setAppointment($rentalWorkOrder, $at, $validated['appointment_note'] ?? null, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_work_order' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)
            ->with('success', $changed ? 'Appointment saved. The tenant has been emailed.' : 'Nothing changed.');
    }

    /** W3 - the owner's own contractor's details (name / phone, both optional) on an owner-contractor work order. */
    public function updateOwnerContractor(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);
        abort_unless($rentalWorkOrder->isOwnerContractor(), 409, "Only a work order done by the owner's own contractor has these details.");

        $validated = $request->validate([
            'contractor_name' => ['nullable', 'string', 'max:191'],
            'contractor_phone' => ['nullable', 'string', 'max:40'],
        ]);
        $rentalWorkOrder->forceFill([
            'contractor_name' => trim((string) ($validated['contractor_name'] ?? '')) ?: null,
            'contractor_phone' => trim((string) ($validated['contractor_phone'] ?? '')) ?: null,
        ])->save();
        $rentalWorkOrder->addNote("Owner's contractor details updated.", $request->user());

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Contractor details saved.');
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

    /**
     * §3.4 — 'reported' and 'in_progress' photo types upload the same way; 'completed' feeds the completion gate.
     *
     * One action, two callers (§17.30, same fix as the job card's §17.29 A): the office's photo form on the work order
     * (a normal browser POST — it must save the photo and bring the person BACK TO THE WORK ORDER with a message, never
     * show the raw JSON a script would get) and a script/fetch client that asks for JSON (Accept: application/json —
     * keeps the 201 / 200 + photo body and the 422).
     */
    public function storePhoto(Request $request, RentalWorkOrderService $service, RentalWorkOrder $rentalWorkOrder): JsonResponse|RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:51200',
            'photo_type' => ['required', 'in:' . implode(',', [
                RentalWorkOrder::PHOTO_REPORTED, RentalWorkOrder::PHOTO_IN_PROGRESS, RentalWorkOrder::PHOTO_COMPLETED,
            ])],
            'client_idempotency_key' => 'nullable|uuid',
        ], [
            'photo.required' => 'Choose a photo to upload.',
            'photo.mimes' => 'The photo must be a JPG, PNG, WEBP or HEIC image.',
            'photo.max' => 'That photo is too large — the limit is 50 MB.',
            'photo.uploaded' => 'The photo did not upload — it may be too large for the server. Try a smaller photo.',
            'photo_type.required' => 'Choose whether this is a Before, In progress or Completed photo.',
            'photo_type.in' => 'Choose whether this is a Before, In progress or Completed photo.',
        ]);

        $wantsJson = $request->expectsJson();
        // The photo block sits low on the page, so the redirect lands on it and the message is shown inside it ('photo' error bag).
        $back = route('corex.rental-work-orders.show', $rentalWorkOrder) . '#wo-photos';

        if ($validator->fails()) {
            if ($wantsJson) {
                throw new \Illuminate\Validation\ValidationException($validator);
            }

            return redirect($back)->withErrors($validator, 'photo');
        }

        $validated = $validator->validated();

        $clientKey = $validated['client_idempotency_key'] ?? null;
        if ($clientKey) {
            $existing = RentalWorkOrderPhoto::where('client_idempotency_key', $clientKey)
                ->where('rental_work_order_id', $rentalWorkOrder->id)->first();
            if ($existing) {
                return $wantsJson
                    ? response()->json($existing, 200)
                    : redirect($back)->with('wo_photo_message', 'That photo was already uploaded.');
            }
        }

        try {
            $photo = $service->storePhoto($rentalWorkOrder, $request->file('photo'), $validated['photo_type'], $request->user(), $clientKey);
        } catch (\Throwable $e) {
            if ($wantsJson) {
                throw $e;
            }
            report($e);

            return redirect($back)->withErrors(['photo' => 'The photo could not be saved. Nothing was uploaded — please try again.'], 'photo');
        }

        if ($wantsJson) {
            return response()->json($photo, 201);
        }

        $label = ['reported' => 'Before', 'in_progress' => 'In progress', 'completed' => 'Completed'][$validated['photo_type']];

        return redirect($back)->with('wo_photo_message', $label . ' photo uploaded.');
    }
}
