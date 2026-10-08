<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Concerns\ExportsRentalList;
use App\Http\Controllers\Concerns\SearchesQualifyingRentalProperties;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Services\Images\PropertyImageStorer;
use App\Services\Rentals\RentalDocumentPdfService;
use App\Services\Rentals\RentalFaultReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-work-orders.md §3a/§6a — full CRUD + list screen per
 * BUILD_STANDARD §1a-§1d. Stage 1: the record itself — report, view, edit
 * the reportable facts, cancel/archive/restore, photos. Stage 2 (this
 * revision): the lifecycle — requestApproval/recordApproval/setOutcome,
 * thin calls into RentalFaultReport's own model methods (§13's own
 * discipline: no business rule decided in this controller).
 */
class RentalFaultReportController extends Controller
{
    use AuthorizesRentalRecordScope;
    use ExportsRentalList;
    use SearchesQualifyingRentalProperties;

    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    /**
     * The LIST screen's own property-filter picker — only properties that
     * actually have a fault report visible to this user, never every
     * rental property. Distinct from the create screen's own inline
     * property `<select>` (rental-fault-reports/create.blade.php), which
     * deliberately keeps offering every rental property, unchanged.
     */
    public function searchProperties(Request $request): JsonResponse
    {
        return $this->searchQualifyingRentalProperties($request, RentalFaultReport::class);
    }

    /**
     * Search: property address, title/description. Sort: reported_at
     * (default, most-recent-first), property, status. Filter: status,
     * outcome, date range. §3a.4 — this is the AGENCY-WIDE, property-wide
     * view; the out-inspection's attached block (Stage 5) is lease-scoped
     * instead, deliberately narrower.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        // AT-439 — own/branch/all "Showing:" control, same pattern as
        // RentalApplicationController::index()/LeaseController::index().
        $maxScope = \App\Services\Rentals\RentalDataScope::ceiling($user, 'rental_fault_reports');
        $resolvedScope = \App\Services\PermissionService::clampScope($request->get('scope'), $maxScope);
        $scopeOptions = match ($maxScope) {
            'all' => ['own', 'branch', 'all'],
            'branch' => ['own', 'branch'],
            default => ['own'],
        };

        $sort = $request->get('sort', 'reported_at');
        $direction = $request->get('direction', 'desc');
        $allowedSorts = ['reported_at', 'property', 'status'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'reported_at';
        }
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        $showArchived = $request->boolean('archived');
        $query = $this->filteredFaultReportsQuery($request, $showArchived);

        $propertyId = $request->get('property_id');
        $leaseId = $request->get('lease_id');

        if ($sort === 'property') {
            $query->join('properties', 'properties.id', '=', 'rental_fault_reports.property_id')
                ->orderBy('properties.title', $direction)
                ->select('rental_fault_reports.*');
        } else {
            $query->orderBy("rental_fault_reports.{$sort}", $direction);
        }

        $hasAnyFaultReports = RentalFaultReport::query()
            ->when($showArchived, fn ($q) => $q->onlyTrashed())
            ->visibleTo($user, $request->get('scope'))->exists();

        $perPage = (int) $request->get('per_page', 25);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 25;
        }

        $faultReports = $query->paginate($perPage)->withQueryString();

        $filteredProperty = $propertyId ? Property::find($propertyId) : null;
        $filteredLease = $leaseId ?? null ? Lease::find($leaseId) : null;

        // §39, 2026-09-28 — summary tiles row, same reused FICA/rental-
        // applications pattern (§39 note on RentalInspectionController).
        // Status tiles are the real enum (RentalFaultReport::STATUS_*).
        $frTileBase = fn () => RentalFaultReport::query()
            ->when($showArchived, fn ($q) => $q->onlyTrashed())
            ->visibleTo($user, $request->get('scope'));
        $tileCounts = [
            'total' => $frTileBase()->count(),
            'reported' => $frTileBase()->where('rental_fault_reports.status', RentalFaultReport::STATUS_REPORTED)->count(),
            'under_review' => $frTileBase()->where('rental_fault_reports.status', RentalFaultReport::STATUS_UNDER_REVIEW)->count(),
            'awaiting_approval' => $frTileBase()->where('rental_fault_reports.status', RentalFaultReport::STATUS_AWAITING_APPROVAL)->count(),
            'approved' => $frTileBase()->where('rental_fault_reports.status', RentalFaultReport::STATUS_APPROVED)->count(),
            'declined' => $frTileBase()->where('rental_fault_reports.status', RentalFaultReport::STATUS_DECLINED)->count(),
            'work_order_raised' => $frTileBase()->where('rental_fault_reports.status', RentalFaultReport::STATUS_WORK_ORDER_RAISED)->count(),
            'owner_handling' => $frTileBase()->where('rental_fault_reports.status', RentalFaultReport::STATUS_OWNER_HANDLING)->count(),
            'resolved' => $frTileBase()->where('rental_fault_reports.status', RentalFaultReport::STATUS_RESOLVED)->count(),
            'cancelled' => $frTileBase()->where('rental_fault_reports.status', RentalFaultReport::STATUS_CANCELLED)->count(),
            'open_no_work_order' => $frTileBase()
                ->whereNotIn('rental_fault_reports.status', [
                    RentalFaultReport::STATUS_RESOLVED,
                    RentalFaultReport::STATUS_CANCELLED,
                    RentalFaultReport::STATUS_DECLINED,
                ])->whereDoesntHave('workOrder')->count(),
        ];

        return view('corex.rental-fault-reports.index', [
            'faultReports' => $faultReports,
            'sort' => $sort,
            'direction' => $direction,
            'hasAnyFaultReports' => $hasAnyFaultReports,
            'showArchived' => $showArchived,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'filters' => $request->only(['q', 'status', 'outcome', 'property_id', 'lease_id', 'date_from', 'date_to', 'open_no_work_order']),
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
    private function filteredFaultReportsQuery(Request $request, bool $onlyArchived = false)
    {
        $user = $request->user();

        $query = RentalFaultReport::query()
            ->when($onlyArchived, fn ($q) => $q->onlyTrashed())
            ->visibleTo($user, $request->get('scope'))
            ->with(['property', 'lease.tenants.contact', 'createdByUser']);

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('property', function ($p) use ($search) {
                    $p->searchAddress($search);
                })->orWhere('rental_fault_reports.title', 'like', "%{$search}%")
                    ->orWhere('rental_fault_reports.description', 'like', "%{$search}%");
            });
        }

        if ($status = $request->get('status')) {
            $query->where('rental_fault_reports.status', $status);
        }

        if ($outcome = $request->get('outcome')) {
            $query->where('rental_fault_reports.outcome', $outcome);
        }

        // §39, 2026-09-28 — the summary tiles' own "Open, no work order"
        // exception tile: still active (not resolved/cancelled/declined)
        // and nothing raised against it yet.
        if ($request->boolean('open_no_work_order')) {
            $query->whereNotIn('rental_fault_reports.status', [
                RentalFaultReport::STATUS_RESOLVED,
                RentalFaultReport::STATUS_CANCELLED,
                RentalFaultReport::STATUS_DECLINED,
            ])->whereDoesntHave('workOrder');
        }

        // Navigation, 2026-09-22 — reached from a property/lease/contact's
        // own detail page (Johan: "every feature needs a navigation link
        // where the work happens"), not picked from a dropdown.
        if ($propertyId = $request->get('property_id')) {
            $query->where('rental_fault_reports.property_id', $propertyId);
        }
        if ($leaseId = $request->get('lease_id')) {
            $query->where('rental_fault_reports.lease_id', $leaseId);
        }
        // Reached from a contact's own detail page — a contact can be a
        // tenant (via lease_tenants) or a landlord (via contact_property).
        if ($contactId = $request->get('contact_id')) {
            $query->where(function ($q) use ($contactId) {
                $q->whereHas('lease.tenants', fn ($t) => $t->where('contact_id', $contactId))
                    ->orWhereHas('property.contacts', fn ($c) => $c->where('contacts.id', $contactId));
            });
        }

        if ($dateFrom = $request->get('date_from')) {
            $query->where('rental_fault_reports.reported_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->get('date_to')) {
            // Inclusive end date (a bare date compares as midnight).
            $query->where('rental_fault_reports.reported_at', '<=', preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo . ' 23:59:59' : $dateTo);
        }

        return $query;
    }

    private function activeFaultReportFiltersSummary(Request $request): array
    {
        $out = [];
        if ($q = $request->get('q')) {
            $out['Search'] = $q;
        }
        if ($status = $request->get('status')) {
            $out['Status'] = ucfirst(str_replace('_', ' ', $status));
        }
        if ($outcome = $request->get('outcome')) {
            $out['Outcome'] = ucfirst(str_replace('_', ' ', $outcome));
        }
        if ($request->boolean('open_no_work_order')) {
            $out['Open, no work order'] = 'Yes';
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
        $out['Scope'] = ucfirst(\App\Services\Rentals\RentalDataScope::resolve($request->user(), 'rental_fault_reports', $request->get('scope')));

        return $out;
    }

    /** req — print the current filtered list, same scoping as index(), filters shown in the header. */
    public function printList(Request $request): View
    {
        $faultReports = $this->filteredFaultReportsQuery($request, $request->boolean('archived'))
            ->orderBy('rental_fault_reports.reported_at', 'desc')
            ->get();

        return view('corex.rental-fault-reports.print-list', [
            'faultReports' => $faultReports,
            'printFilters' => $this->activeFaultReportFiltersSummary($request),
        ]);
    }

    /** req — export the current filtered list as xlsx/csv, same scoping as index(). */
    public function export(Request $request)
    {
        $faultReports = $this->filteredFaultReportsQuery($request, $request->boolean('archived'))
            ->orderBy('rental_fault_reports.reported_at', 'desc')
            ->get();

        $headers = ['Property', 'Title', 'Status', 'Outcome', 'Reported'];
        $rows = $faultReports->map(fn (RentalFaultReport $fr) => [
            $fr->property?->buildDisplayAddress() ?? 'Unknown property',
            $fr->title,
            ucfirst(str_replace('_', ' ', $fr->status)),
            $fr->outcome ? ucfirst(str_replace('_', ' ', $fr->outcome)) : '',
            $fr->reported_at?->format('Y-m-d') ?? '',
        ]);

        $filename = 'rental-fault-reports-' . now()->format('Y-m-d');

        return $request->get('format') === 'csv'
            ? $this->streamRentalListCsv($filename . '.csv', $headers, $rows)
            : $this->streamRentalListXlsx($filename . '.xlsx', $headers, $rows);
    }

    /**
     * §3.2a/§6a — "Report a Fault" form, reachable from the property tab
     * (pre-filled property_id/lease_id) or the list screen's own "New" button.
     */
    /**
     * Johan, 2026-09-21: "the lease already knows the tenant and the
     * property already knows the owner. Selecting 'tenant' should resolve
     * the tenant from the lease, not open a contact search." leaseTenants/
     * landlordContact are the known, already-on-file people this form
     * defaults to; the free-text search stays available underneath for a
     * genuine override (sublet, family member reporting on the tenant's
     * behalf).
     */
    public function create(Request $request): View
    {
        $property = $request->get('property_id') ? Property::findOrFail($request->get('property_id')) : null;
        $lease = $request->get('lease_id') ? Lease::findOrFail($request->get('lease_id')) : null;
        $lease?->load('tenants.contact');

        return view('corex.rental-fault-reports.create', [
            'property' => $property,
            'lease' => $lease,
            'leaseTenants' => $lease ? $lease->tenants->pluck('contact')->filter()->values() : collect(),
            'landlordContact' => $property?->sellerOwnerContact(),
        ]);
    }

    public function store(Request $request, RentalFaultReportService $service): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();
        $propertyId = $request->input('property_id');

        $validated = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            // Lease / inspection item must belong to THIS property (audit L2).
            'lease_id' => ['nullable', \Illuminate\Validation\Rule::exists('leases', 'id')->where('property_id', $propertyId)],
            'rental_inspection_item_id' => ['nullable', \Illuminate\Validation\Rule::exists('rental_inspection_items', 'id')->where('property_id', $propertyId)],
            // .ai/specs/rentals-faults-work-orders.md §2.2 — optional; a
            // report can still be free-text-only if nothing in the catalogue
            // fits.
            'rental_fault_type_id' => ['nullable', 'exists:rental_fault_types,id'],
            'reported_by_type' => ['required', 'in:' . implode(',', [
                RentalFaultReport::REPORTED_BY_TENANT,
                RentalFaultReport::REPORTED_BY_AGENT_NOTICED,
                RentalFaultReport::REPORTED_BY_OWNER_INSTRUCTED,
            ])],
            'reported_by_contact_id' => ['nullable', \Illuminate\Validation\Rule::exists('contacts', 'id')->where('agency_id', $agencyId)],
            'reported_channel' => ['required', 'in:' . implode(',', [
                RentalFaultReport::CHANNEL_PHONE,
                RentalFaultReport::CHANNEL_WHATSAPP,
                RentalFaultReport::CHANNEL_EMAIL,
                RentalFaultReport::CHANNEL_IN_PERSON,
                RentalFaultReport::CHANNEL_APP,
                RentalFaultReport::CHANNEL_OTHER,
            ])],
            'title' => ['required', 'string', 'max:191'],
            'description' => ['required', 'string'],
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

        // §3.2a — every row is agent-captured today; the reporter (contact/
        // user) is a separate fact from who typed it in.
        $attributes['captured_by_user_id'] = $user->id;
        $attributes['created_by_user_id'] = $user->id;
        if ($validated['reported_by_type'] === RentalFaultReport::REPORTED_BY_AGENT_NOTICED) {
            $attributes['reported_by_user_id'] = $user->id;
        }

        $faultReport = $service->report($property, $attributes);

        return redirect()->route('corex.rental-fault-reports.show', $faultReport)->with('success', 'Fault report logged.');
    }

    public function show(Request $request, RentalFaultReport $rentalFaultReport): View
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);

        $rentalFaultReport->load([
            'property', 'lease.tenants.contact', 'inspectionItem', 'faultType',
            // 'workOrder' — added in Stage 4 once App\Models\RentalWorkOrder
            // exists (see RentalFaultReport::workOrder()'s own note).
            'reportedByContact', 'reportedByUser', 'capturedByUser', 'cancelledByUser',
            'createdByUser', 'photos.uploadedBy', 'approvals.recordedByUser', 'updates.createdByUser',
            // §15 (AT-447) — "From inspection <type> <date>" back-link.
            'reportedInspectionObservation.inspection',
        ]);

        $contractors = app(\App\Services\Rentals\RentalFaultContractorService::class)->optionsFor($rentalFaultReport);

        return view('corex.rental-fault-reports.show', [
            'faultReport' => $rentalFaultReport,
            // Fault flow F3/F5 - the supplier list for this fault's type of work (empty = say so, offer the other routes).
            'contractors' => $contractors,
            'decisionSummary' => $rentalFaultReport->decisionSummary(),
        ]);
    }

    /**
     * §"Printing" — a fault report handed to a landlord. Same query-layer
     * scoping as show() above (route-model-binding + the global AgencyScope).
     */
    public function pdf(RentalFaultReport $rentalFaultReport, RentalDocumentPdfService $service)
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);

        $pdf = $service->faultReportPdf($rentalFaultReport);

        return request()->boolean('dl')
            ? $pdf->download($service->faultReportFilename($rentalFaultReport))
            : $pdf->stream($service->faultReportFilename($rentalFaultReport));
    }

    /**
     * §3a schema block — editable only while the reportable facts themselves
     * are still current, i.e. before it has moved past 'reported'. Approval
     * and outcome are not edited here (Stage 2's own action). Inline edit on
     * the show page, same pattern as LeaseController — no separate edit view.
     */
    public function update(Request $request, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);

        abort_unless($rentalFaultReport->status === RentalFaultReport::STATUS_REPORTED, 409, 'This fault report has moved on and can no longer be edited here.');
        abort_if(
            in_array($rentalFaultReport->reported_by_type, [RentalFaultReport::REPORTED_BY_TENANT, RentalFaultReport::REPORTED_BY_LANDLORD], true),
            409,
            'A report made by the tenant or owner is kept as they wrote it - prepare the owner version instead.'
        );

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'description' => ['required', 'string'],
        ]);

        $rentalFaultReport->update($validated);

        return redirect()->route('corex.rental-fault-reports.show', $rentalFaultReport)->with('success', 'Fault report updated.');
    }

    /**
     * §3a.1 — purely a status marker, no evidence. See
     * RentalFaultReport::requestApproval()'s own note on why this is
     * optional, not a required gate before recordApproval() below.
     */
    public function requestApproval(Request $request, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);

        // Fault flow F2: the owner sees nothing until the agent has REVIEWED the report and prepared the owner's version.
        if ($rentalFaultReport->owner_version_saved_at === null) {
            return back()->withErrors(['rental_fault_report' => 'Prepare the owner version first (check the wording and photos the owner will see), then send it.']);
        }

        try {
            $rentalFaultReport->requestApproval($request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_fault_report' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-fault-reports.show', $rentalFaultReport)->with('success', 'Sent to the owner. They can now see it and decide.');
    }

    /**
     * Fault flow F2 - save what the owner will see (the agent's sanitised version). The tenant's original title,
     * description and photos stay exactly as reported. Allowed until the report is sent.
     */
    public function saveOwnerVersion(Request $request, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);

        $validated = $request->validate([
            'owner_title' => ['required', 'string', 'max:191'],
            'owner_description' => ['nullable', 'string', 'max:5000'],
            'owner_agent_note' => ['nullable', 'string', 'max:3000'],
            'owner_photo_ids' => ['nullable', 'array'],
            'owner_photo_ids.*' => ['integer'],
        ]);

        try {
            $rentalFaultReport->saveOwnerVersion($validated, $request->user());
        } catch (\LogicException|\InvalidArgumentException $e) {
            return back()->withErrors(['rental_fault_report' => $e->getMessage()])->withInput();
        }

        // "Save and send" posts send_now=1: same two steps, one click, and the send step keeps its own permission.
        if ($request->boolean('send_now')) {
            abort_unless($request->user()->hasPermission('rental_fault_reports.send_to_owner'), 403);

            return $this->requestApproval($request, $rentalFaultReport->fresh());
        }

        return redirect()->route('corex.rental-fault-reports.show', $rentalFaultReport)->with('success', 'Owner version saved. Nothing has been sent to the owner yet.');
    }

    /**
     * §3.4a/§0c — always in writing. evidence_text is required regardless
     * of channel (a short, human-readable account of what was said or
     * sent); evidence_file is an optional supplementary screenshot/forward,
     * reusing PropertyImageStorer like every other upload in this feature.
     */
    public function recordApproval(Request $request, RentalFaultReportService $service, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);

        $validated = $request->validate([
            'decision' => ['required', 'in:' . implode(',', [
                \App\Models\RentalApproval::DECISION_APPROVED,
                \App\Models\RentalApproval::DECISION_DECLINED,
            ])],
            'approval_route' => ['nullable', 'in:' . implode(',', [
                RentalFaultReport::ROUTE_AGENCY_APPOINTS,
                RentalFaultReport::ROUTE_OWNER_HANDLES,
            ])],
            'evidence_type' => ['required', 'in:' . implode(',', [
                \App\Models\RentalApproval::EVIDENCE_WHATSAPP,
                \App\Models\RentalApproval::EVIDENCE_EMAIL,
                \App\Models\RentalApproval::EVIDENCE_VERBAL_NOTE,
            ])],
            'evidence_text' => ['required', 'string'],
            // Image only, same as every other upload in this feature family
            // (§3a.3's own "no second pipeline" reasoning) — a screenshot of
            // the WhatsApp/email is the natural artifact here; a document
            // attachment (e.g. a forwarded .eml/.pdf) is a real but separate
            // future need, not quietly bolted on as a second storage path.
            'evidence_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:51200'],
            'decided_at' => ['nullable', 'date'],
            // Fault flow F5: who appoints the contractor - the owner (their own: optional name + number) or the agency
            // (a supplier from the same list the owner is offered).
            'contractor_name' => ['nullable', 'string', 'max:191'],
            'contractor_phone' => ['nullable', 'string', 'max:40'],
            'agency_service_provider_id' => ['nullable', 'integer'],
            'after' => ['nullable', 'in:create_work_order'],
        ]);

        if ($validated['decision'] === \App\Models\RentalApproval::DECISION_DECLINED && trim((string) $validated['evidence_text']) === '') {
            return back()->withErrors(['rental_fault_report' => 'A reason is required when declining.'])->withInput();
        }
        if ($validated['decision'] === \App\Models\RentalApproval::DECISION_APPROVED && empty($validated['approval_route'])) {
            return back()->withErrors(['rental_fault_report' => 'Say who appoints the contractor: the owner or the agency.'])->withInput();
        }
        if (($validated['approval_route'] ?? null) === RentalFaultReport::ROUTE_AGENCY_APPOINTS && ! empty($validated['agency_service_provider_id'])
            && ! app(\App\Services\Rentals\RentalFaultContractorService::class)->isOption($rentalFaultReport, (int) $validated['agency_service_provider_id'])) {
            return back()->withErrors(['rental_fault_report' => 'Please choose a contractor from the list for this type of work.'])->withInput();
        }
        $afterCreateWorkOrder = ($validated['after'] ?? null) === 'create_work_order';
        unset($validated['after']);

        if ($request->hasFile('evidence_file')) {
            $validated['evidence_file_path'] = $service->storeApprovalScreenshot($request->file('evidence_file'), $rentalFaultReport->property_id);
        }
        unset($validated['evidence_file']);

        try {
            $rentalFaultReport->recordApproval($request->user(), $validated);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return back()->withErrors(['rental_fault_report' => $e->getMessage()]);
        }

        // F6: "Save decision and create work order" only HANDS OVER to the existing work-order creation (the form on this
        // screen, opened and pre-filled with the chosen contractor). It never creates one itself, and only when the
        // existing rules allow a work order (not when the owner arranges the repair, not when declined).
        if ($afterCreateWorkOrder) {
            if ($rentalFaultReport->fresh()->workOrderBlockReason() === null) {
                return redirect()->route('corex.rental-fault-reports.show', ['rentalFaultReport' => $rentalFaultReport, 'create_work_order' => 1])
                    ->with('success', 'Decision saved. Confirm the work order below.');
            }

            return redirect()->route('corex.rental-fault-reports.show', $rentalFaultReport)
                ->with('success', 'Decision saved. ' . $rentalFaultReport->fresh()->workOrderBlockReason());
        }

        return redirect()->route('corex.rental-fault-reports.show', $rentalFaultReport)->with('success', 'Approval decision recorded.');
    }

    /**
     * §3a.2/§0c — the spine. Callable regardless of approval state (see
     * RentalFaultReport::setOutcome()'s own note).
     */
    public function setOutcome(Request $request, RentalFaultReportService $service, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);

        $validated = $request->validate([
            'outcome' => ['required', 'in:' . implode(',', [
                RentalFaultReport::OUTCOME_REPAIRED,
                RentalFaultReport::OUTCOME_REPAIRED_PARTIALLY,
                RentalFaultReport::OUTCOME_NOT_REPAIRED,
                RentalFaultReport::OUTCOME_OWNER_DECLINED,
                RentalFaultReport::OUTCOME_TENANT_LIABLE,
                // .ai/specs/rentals-faults-work-orders.md §4.4 — still logged,
                // for evidence, when the tenant's first-aid steps were enough.
                RentalFaultReport::OUTCOME_RESOLVED_BY_FIRST_AID,
            ])],
            'outcome_note' => ['nullable', 'string'],
            'repaired_at' => ['nullable', 'date'],
        ]);

        try {
            $rentalFaultReport->setOutcome($validated, $request->user());
        } catch (\LogicException|\InvalidArgumentException $e) {
            return back()->withErrors(['rental_fault_report' => $e->getMessage()]);
        }

        $service->notifyResolved($rentalFaultReport);

        return redirect()->route('corex.rental-fault-reports.show', $rentalFaultReport)->with('success', 'Outcome recorded.');
    }

    /**
     * §17.3 (R0) — ONE "Create work order" action. Who does the work: Internal crew (the default) creates the work
     * order AND its draft job card in one go and lands on the job card; External contractor creates the work order only
     * (no job card) and lands on the work order. Either way the gate is {@see RentalFaultReport::workOrderBlockReason()}:
     * allowed from a fault that is reported, awaiting approval, or approved (agency appoints) — not once the owner has
     * declined it or is handling it themselves. Permission stays `rental_fault_reports.raise_work_order`.
     *
     * AT-442 req #4 — one click, pre-filled with property/lease/fault/description; both paths go through
     * RentalWorkOrderService::fromFaultReport(), never a second implementation of the rules.
     */
    public function raiseWorkOrder(Request $request, \App\Services\Rentals\RentalWorkOrderService $service, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);

        $validated = $request->validate([
            'trade_type' => ['nullable', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:191'],
            'description' => ['required', 'string'],
            'assignment_type' => ['nullable', 'in:' . implode(',', [
                \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER,
                \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL,
            ])],
            // Fault flow F6 - the contractor chosen on the owner's decision, pre-selected on the external work order.
            'agency_service_provider_id' => ['nullable', 'integer'],
        ]);
        if (! empty($validated['agency_service_provider_id'])) {
            $ok = \App\Models\DealV2\AgencyServiceProvider::active()->whereKey($validated['agency_service_provider_id'])->exists();
            if (! $ok) {
                return back()->withErrors(['rental_fault_report' => 'That contractor is not on the supplier list.'])->withInput();
            }
        }
        // §17.3.1 — Internal crew is the default when the choice is not posted.
        $validated['assignment_type'] = $validated['assignment_type'] ?? \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL;

        try {
            if ($validated['assignment_type'] === \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL) {
                $jobCard = app(\App\Services\Rentals\RentalJobCardService::class)
                    ->createFromFaultReport($rentalFaultReport, $validated, $request->user());

                return redirect()->route('corex.rental-job-cards.show', $jobCard)->with('success', 'Work order created — job card ready for the crew.');
            }

            $workOrder = $service->fromFaultReport($rentalFaultReport, $request->user(), $validated);
            if (! empty($validated['agency_service_provider_id'])) {
                $workOrder->updates()->create([
                    'agency_id' => $workOrder->agency_id, 'update_type' => 'supplier_assigned', 'created_by_user_id' => $request->user()->id,
                    'note' => 'Pre-selected from the decision on the fault report. Ordering still follows the usual quote and authorisation steps.',
                ]);
            }
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_fault_report' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-work-orders.show', $workOrder)->with('success', 'Work order created.');
    }

    public function cancel(Request $request, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);

        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $rentalFaultReport->cancel($request->user(), $validated['cancel_reason']);
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_fault_report' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-fault-reports.show', $rentalFaultReport)->with('success', 'Fault report cancelled.');
    }

    public function destroy(Request $request, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);

        if (!$rentalFaultReport->isDeletable()) {
            return back()->withErrors(['rental_fault_report' => 'This fault report has photos or a linked work order and cannot be deleted — cancel it instead.']);
        }

        $rentalFaultReport->archive($request->user());

        return redirect()->route('corex.rental-fault-reports.index')->with('success', 'Fault report archived.');
    }

    public function restore(Request $request, int $rentalFaultReport): RedirectResponse
    {
        $faultReport = RentalFaultReport::withTrashed()->findOrFail($rentalFaultReport);
        $this->guardRentalRecordScope($faultReport, 'rental_fault_reports', $faultReport->property?->branch_id);

        $faultReport->restoreRecord($request->user());

        return redirect()->route('corex.rental-fault-reports.show', $faultReport)->with('success', 'Fault report restored.');
    }

    /**
     * §3a.3 — same photo pipeline as everywhere else in this feature family:
     * PropertyImageStorer, client_idempotency_key dedup, no second pipeline.
     */
    public function storePhoto(Request $request, RentalFaultReport $rentalFaultReport): JsonResponse
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);

        $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:51200',
            'client_idempotency_key' => 'nullable|uuid',
        ]);

        $clientKey = $request->input('client_idempotency_key');
        if ($clientKey) {
            $existing = RentalFaultReportPhoto::where('client_idempotency_key', $clientKey)
                ->where('rental_fault_report_id', $rentalFaultReport->id)->first();
            if ($existing) {
                return response()->json($existing, 200);
            }
        }

        $url = app(PropertyImageStorer::class)->store($request->file('photo'), $rentalFaultReport->property_id);

        $photo = RentalFaultReportPhoto::create([
            'agency_id' => $rentalFaultReport->agency_id,
            'rental_fault_report_id' => $rentalFaultReport->id,
            'storage_path' => $url,
            'uploaded_by_user_id' => $request->user()->id,
            'client_idempotency_key' => $clientKey,
            'file_size_bytes' => $request->file('photo')->getSize(),
        ]);

        return response()->json($photo, 201);
    }
}
