<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Services\Images\PropertyImageStorer;
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

        $sort = $request->get('sort', 'reported_at');
        $direction = $request->get('direction', 'desc');
        $allowedSorts = ['reported_at', 'property', 'status'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'reported_at';
        }
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        $query = RentalFaultReport::query()
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

        if ($dateFrom = $request->get('date_from')) {
            $query->where('rental_fault_reports.reported_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->get('date_to')) {
            $query->where('rental_fault_reports.reported_at', '<=', $dateTo);
        }

        if ($sort === 'property') {
            $query->join('properties', 'properties.id', '=', 'rental_fault_reports.property_id')
                ->orderBy('properties.title', $direction)
                ->select('rental_fault_reports.*');
        } else {
            $query->orderBy("rental_fault_reports.{$sort}", $direction);
        }

        $hasAnyFaultReports = RentalFaultReport::query()->visibleTo($user, $request->get('scope'))->exists();

        $faultReports = $query->paginate(25)->withQueryString();

        return view('corex.rental-fault-reports.index', [
            'faultReports' => $faultReports,
            'sort' => $sort,
            'direction' => $direction,
            'hasAnyFaultReports' => $hasAnyFaultReports,
            'filters' => $request->only(['q', 'status', 'outcome', 'date_from', 'date_to']),
        ]);
    }

    /**
     * §3.2a/§6a — "Report a Fault" form, reachable from the property tab
     * (pre-filled property_id/lease_id) or the list screen's own "New" button.
     */
    public function create(Request $request): View
    {
        $property = $request->get('property_id') ? Property::findOrFail($request->get('property_id')) : null;
        $lease = $request->get('lease_id') ? Lease::findOrFail($request->get('lease_id')) : null;

        return view('corex.rental-fault-reports.create', [
            'property' => $property,
            'lease' => $lease,
        ]);
    }

    public function store(Request $request, RentalFaultReportService $service): RedirectResponse
    {
        $validated = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'lease_id' => ['nullable', 'exists:leases,id'],
            'rental_inspection_item_id' => ['nullable', 'exists:rental_inspection_items,id'],
            'reported_by_type' => ['required', 'in:' . implode(',', [
                RentalFaultReport::REPORTED_BY_TENANT,
                RentalFaultReport::REPORTED_BY_AGENT_NOTICED,
                RentalFaultReport::REPORTED_BY_OWNER_INSTRUCTED,
            ])],
            'reported_by_contact_id' => ['nullable', 'exists:contacts,id'],
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
        $rentalFaultReport->load([
            'property', 'lease.tenants.contact', 'inspectionItem',
            // 'workOrder' — added in Stage 4 once App\Models\RentalWorkOrder
            // exists (see RentalFaultReport::workOrder()'s own note).
            'reportedByContact', 'reportedByUser', 'capturedByUser', 'cancelledByUser',
            'createdByUser', 'photos.uploadedBy', 'approvals.recordedByUser',
        ]);

        return view('corex.rental-fault-reports.show', ['faultReport' => $rentalFaultReport]);
    }

    /**
     * §3a schema block — editable only while the reportable facts themselves
     * are still current, i.e. before it has moved past 'reported'. Approval
     * and outcome are not edited here (Stage 2's own action). Inline edit on
     * the show page, same pattern as LeaseController — no separate edit view.
     */
    public function update(Request $request, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        abort_unless($rentalFaultReport->status === RentalFaultReport::STATUS_REPORTED, 409, 'This fault report has moved on and can no longer be edited here.');

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
        try {
            $rentalFaultReport->requestApproval();
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_fault_report' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-fault-reports.show', $rentalFaultReport)->with('success', 'Marked as awaiting owner approval.');
    }

    /**
     * §3.4a/§0c — always in writing. evidence_text is required regardless
     * of channel (a short, human-readable account of what was said or
     * sent); evidence_file is an optional supplementary screenshot/forward,
     * reusing PropertyImageStorer like every other upload in this feature.
     */
    public function recordApproval(Request $request, RentalFaultReportService $service, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
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
        ]);

        if ($request->hasFile('evidence_file')) {
            $validated['evidence_file_path'] = $service->storeApprovalScreenshot($request->file('evidence_file'), $rentalFaultReport->property_id);
        }
        unset($validated['evidence_file']);

        try {
            $rentalFaultReport->recordApproval($request->user(), $validated);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return back()->withErrors(['rental_fault_report' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-fault-reports.show', $rentalFaultReport)->with('success', 'Approval decision recorded.');
    }

    /**
     * §3a.2/§0c — the spine. Callable regardless of approval state (see
     * RentalFaultReport::setOutcome()'s own note).
     */
    public function setOutcome(Request $request, RentalFaultReportService $service, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', 'in:' . implode(',', [
                RentalFaultReport::OUTCOME_REPAIRED,
                RentalFaultReport::OUTCOME_REPAIRED_PARTIALLY,
                RentalFaultReport::OUTCOME_NOT_REPAIRED,
                RentalFaultReport::OUTCOME_OWNER_DECLINED,
                RentalFaultReport::OUTCOME_TENANT_LIABLE,
            ])],
            'outcome_note' => ['nullable', 'string'],
            'repaired_at' => ['nullable', 'date'],
        ]);

        try {
            $rentalFaultReport->setOutcome($validated);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return back()->withErrors(['rental_fault_report' => $e->getMessage()]);
        }

        $service->notifyResolved($rentalFaultReport);

        return redirect()->route('corex.rental-fault-reports.show', $rentalFaultReport)->with('success', 'Outcome recorded.');
    }

    /**
     * Stage 4 — §3a.1/§0c, the agency_appoints route. Only valid once this
     * fault report has already been approved that way; RentalFaultReport
     * itself has no method to reach a mandatory work order because none
     * exists — this is one specific agency's choice to make on one
     * specific approved report, not a required step.
     */
    public function raiseWorkOrder(Request $request, \App\Services\Rentals\RentalWorkOrderService $service, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        $validated = $request->validate([
            'trade_type' => ['nullable', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:191'],
            'description' => ['required', 'string'],
        ]);

        try {
            $workOrder = $service->fromFaultReport($rentalFaultReport, $request->user(), $validated);
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_fault_report' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-work-orders.show', $workOrder)->with('success', 'Work order raised.');
    }

    public function cancel(Request $request, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:500'],
        ]);

        $rentalFaultReport->cancel($request->user(), $validated['cancel_reason']);

        return redirect()->route('corex.rental-fault-reports.show', $rentalFaultReport)->with('success', 'Fault report cancelled.');
    }

    public function destroy(Request $request, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        if (!$rentalFaultReport->isDeletable()) {
            return back()->withErrors(['rental_fault_report' => 'This fault report has photos or a linked work order and cannot be deleted — cancel it instead.']);
        }

        $rentalFaultReport->delete();

        return redirect()->route('corex.rental-fault-reports.index')->with('success', 'Fault report archived.');
    }

    public function restore(Request $request, int $rentalFaultReport): RedirectResponse
    {
        $faultReport = RentalFaultReport::withTrashed()->findOrFail($rentalFaultReport);
        $faultReport->restore();

        return redirect()->route('corex.rental-fault-reports.show', $faultReport)->with('success', 'Fault report restored.');
    }

    /**
     * §3a.3 — same photo pipeline as everywhere else in this feature family:
     * PropertyImageStorer, client_idempotency_key dedup, no second pipeline.
     */
    public function storePhoto(Request $request, RentalFaultReport $rentalFaultReport): JsonResponse
    {
        $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:51200',
            'client_idempotency_key' => 'nullable|uuid',
        ]);

        $clientKey = $request->input('client_idempotency_key');
        if ($clientKey) {
            $existing = RentalFaultReportPhoto::where('client_idempotency_key', $clientKey)->first();
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
