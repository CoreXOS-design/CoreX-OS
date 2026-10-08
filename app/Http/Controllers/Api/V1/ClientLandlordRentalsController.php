<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ResolvesPortalContact;
use App\Http\Controllers\Api\V1\Concerns\ServesPortalDocuments;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Models\RentalWorkOrderVariation;
use App\Services\Rentals\RentalApprovalGateService;
use App\Services\Rentals\StaleVariationRevision;
use App\Services\Images\PropertyImageStorer;
use App\Services\Rentals\RentalFaultReportService;
use App\Services\Rentals\RentalFaultContractorService;
use App\Services\Rentals\RentalFaultTypeService;
use App\Services\Rentals\RentalJobCardClientViewService;
use App\Services\Rentals\RentalPortalDocumentService;
use App\Services\Rentals\RentalPortalOverviewService;
use App\Services\Rentals\RentalPortalScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-portal-access.md §3/§6 — AT-445. Landlord-facing rentals
 * endpoints under /api/v1/client/rentals/landlord/*. Same scoping
 * discipline as ClientTenantRentalsController: every record resolved
 * through RentalPortalScopeService, never a bare Model::find().
 *
 * Decisions drive the EXISTING owner-approval mechanism
 * (RentalFaultReport::recordApproval()/RentalWorkOrder::recordApproval(),
 * widened this ticket to accept a Contact) — no second approval system.
 *
 * §15 (AT-447 follow-up, 2026-10-05) — faultReportStore() adds the
 * landlord's own "Request work / report a problem," mirroring
 * ClientTenantRentalsController::faultReportStore() but with no
 * first-aid/resolution step (a landlord isn't asked "did that fix it" the
 * way a tenant is) and `reported_by_type = REPORTED_BY_LANDLORD`. The
 * landlord never picks a supplier and never creates a work order
 * directly — this only ever produces a rental_fault_reports row, which
 * lands in the agency's normal Fault Reports list / Command Centre
 * needs-action exactly like any other (both already query by status, not
 * reported_by_type); an agent raises the work order from it via the
 * EXISTING raise-work-order action.
 */
class ClientLandlordRentalsController extends Controller
{
    use ResolvesPortalContact;
    use ServesPortalDocuments;

    public function __construct(
        private readonly RentalPortalScopeService $scope,
        private readonly RentalFaultReportService $faultReportService,
        private readonly RentalJobCardClientViewService $jobCardView,
        private readonly RentalPortalOverviewService $overview,
    ) {}

    public function properties(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        return response()->json([
            'properties' => $this->scope->landlordProperties($contact)->map(fn ($p) => [
                'id' => $p->id,
                'address' => $p->buildDisplayAddress(),
            ])->values(),
        ]);
    }

    public function propertyShow(Request $request, int $property): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $propertyModel = $this->scope->landlordProperty($contact, $property);
        if (!$propertyModel) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        $leases = $this->scope->landlordPropertyLeases($contact, $propertyModel->id);
        $current = $leases->firstWhere('status', 'active');

        return response()->json(['property' => [
            'id' => $propertyModel->id,
            'address' => $propertyModel->buildDisplayAddress(),
            'current_tenancy' => $current ? [
                // Name only — no tenant ID numbers, application documents,
                // payslips, bank statements, or FICA files. §4: "tenant
                // contact details limited to name only for privacy."
                'tenant_names' => $current->tenantNames(),
                'start_date' => optional($current->start_date)->toDateString(),
                'end_date' => optional($current->end_date)->toDateString(),
            ] : null,
            // §12.6 — occupancy history, landlord-facing version: name +
            // dates only, no internal agent notes.
            'occupancy_history' => $leases->map(fn ($l) => [
                'id' => $l->id,
                'tenant_names' => $l->tenantNames(),
                'start_date' => optional($l->start_date)->toDateString(),
                'end_date' => optional($l->end_date)->toDateString(),
                'status' => $l->status,
            ])->values(),
        ]]);
    }

    /**
     * §15 (AT-447 follow-up) — "Request work / report a problem," the
     * landlord's own counterpart to ClientTenantRentalsController::
     * faultReportStore(). Attached to the active lease if one exists, else
     * the property alone (vacancy-period fault, same as the agent-side
     * create form already allows). Isolation: landlordProperty() 404s for
     * any property this contact isn't a landlord/lessor on — including a
     * property belonging to a different agency — same as every other
     * landlord-portal lookup in this controller.
     */
    /**
     * §12 (AT-447, portal frontend follow-up, 2026-10-05) — fault-type
     * catalogue + rendered first-aid content, for ONE of the landlord's own
     * properties. Mirrors ClientTenantRentalsController::faultTypes()
     * exactly, substituting landlordProperty() for tenantOwnsProperty() —
     * the catalogue itself is agency-scoped, not role-scoped, so this is
     * the same data the tenant endpoint already serves, only the ownership
     * gate differs. Lets the landlord's "Request work" form give the same
     * what's-wrong/category/urgency context a fault TYPE already carries
     * (`RentalFaultType::category`/`urgency`) without a second, free-form
     * set of fields with nowhere on `rental_fault_reports` to live.
     */
    public function faultTypes(Request $request, int $property): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $propertyModel = $this->scope->landlordProperty($contact, $property);
        if (!$propertyModel) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        $types = RentalFaultType::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $view = app(\App\Services\Rentals\RentalFaultTypePortalView::class);

        return response()->json([
            // §22 — the same view the tenant picker reads (steps for THIS property, urgency, documents, valve / board photos).
            'fault_types' => $view->payload($types, $propertyModel),
            'limits' => $view->limits((int) $contact->agency_id),
        ]);
    }

    public function faultReportStore(Request $request, int $property): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $propertyModel = $this->scope->landlordProperty($contact, $property);
        if (!$propertyModel) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        $data = $request->validate([
            'rental_fault_type_id' => 'nullable|integer',
            'title' => 'required|string|max:191',
            'description' => 'nullable|string|max:5000',
            'photos' => 'nullable|array|max:10',
            'photos.*' => 'file|image|max:15360',
        ]);

        $faultType = !empty($data['rental_fault_type_id'])
            ? RentalFaultType::withoutGlobalScopes()->where('agency_id', $contact->agency_id)->where('is_active', true)->find($data['rental_fault_type_id'])
            : null;

        $leaseId = $this->scope->landlordActiveLeaseId($contact, $propertyModel->id);

        $attributes = [
            'lease_id' => $leaseId,
            'rental_fault_type_id' => $faultType?->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_LANDLORD,
            'reported_by_contact_id' => $contact->id,
            'reported_channel' => RentalFaultReport::CHANNEL_APP,
            'title' => $data['title'],
            'description' => $data['description'] ?? '',
        ];

        $faultReport = $this->faultReportService->report($propertyModel, $attributes);

        foreach ($request->file('photos', []) as $photo) {
            $path = app(PropertyImageStorer::class)->store($photo, $propertyModel->id);
            $faultReport->photos()->create([
                'agency_id' => $contact->agency_id,
                'storage_path' => $path,
                'client_idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
                'file_size_bytes' => $photo->getSize(),
            ]);
        }

        return response()->json(['fault_report' => [
            'id' => $faultReport->id,
            'title' => $faultReport->title,
            'status' => $faultReport->status,
            'reported_at' => $faultReport->reported_at?->toIso8601String(),
        ]], 201);
    }

    public function faultReports(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        return response()->json([
            // Fault flow F2: the owner only ever sees faults the agent SENT (or that they reported / that are decided),
            // and only the agent's sanitised wording - never the tenant's original.
            'fault_reports' => $this->scope->landlordFaultReports($contact)->map(fn ($f) => [
                'id' => $f->id,
                'title' => $f->ownerVersion()['title'],
                'status' => $f->status,
                'status_label' => $f->ownerStatusLabel(),
                'owner_approval_status' => $f->owner_approval_status,
                // Waiting on THIS owner: the Faults tab marks it and counts it.
                'needs_decision' => $f->owner_approval_status === RentalFaultReport::APPROVAL_PENDING,
                'work_order_id' => $f->rental_work_order_id,
                'reported_at' => $f->reported_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function workOrders(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        return response()->json([
            'work_orders' => $this->scope->landlordWorkOrders($contact)->map(fn ($w) => [
                'id' => $w->id,
                'title' => $w->title,
                'status' => $w->status,
                'owner_approval_status' => $w->owner_approval_status,
                // Amounts ARE shown to the landlord (§3: "work orders with amounts").
                'selected_quote_amount' => optional($w->quotes()->where('is_selected', true)->first())?->ownerFacingAmount(),
                // §14.29 — photos of the work (own + linked job card), filtered by the agency's visibility rule.
                'photos' => $this->jobCardView->photosPayload($this->jobCardView->photosForWorkOrder($w)),
                // BUILD 3 — §17.3.5: the portal's Jobs list reads these (plain stage, who, rounds); amounts stay owner-facing only.
                'client' => app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)
                    ->payload($w, \App\Services\Rentals\RentalWorkOrderClientViewService::AUDIENCE_LANDLORD),
            ])->values(),
        ]);
    }

    public function inspections(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        // §20 — same rule as the tenant's list: sent inspections and booked future dates only.
        return response()->json([
            'inspections' => $this->scope->landlordInspections($contact)
                ->map(fn ($i) => collect($this->overview->inspectionRow($i))->except('date_sort')->all())->values(),
        ]);
    }

    /** §20 — the owner's portal home: who to call, the inspection dates, and where the lease stands, one block per property. */
    public function overview(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        return response()->json($this->overview->forLandlord($contact));
    }

    /** §19 — the owner's Documents area: signed lease agreements and distributed inspection reports on their own properties, plus documents the agency shared. */
    public function documents(Request $request): JsonResponse
    {
        return $this->portalDocumentList($request, RentalPortalDocumentService::ROLE_LANDLORD, 'client.rentals.landlord.documents.file');
    }

    public function documentFile(Request $request, int $document): \Symfony\Component\HttpFoundation\Response|JsonResponse
    {
        return $this->portalDocumentFile($request, RentalPortalDocumentService::ROLE_LANDLORD, $document);
    }

    /** §3 — "items needing my decision": fault reports awaiting the owner-approval route, and work-order quotes over the spend limit. */
    public function decisions(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $faultReports = $this->scope->landlordPendingFaultReports($contact);
        $workOrders = $this->scope->landlordPendingWorkOrders($contact);

        return response()->json([
            'fault_reports' => $faultReports->map(fn ($f) => [
                'id' => $f->id, 'title' => $f->ownerVersion()['title'], 'kind' => 'fault_report',
            ])->values(),
            'work_orders' => $workOrders->map(fn ($w) => [
                'id' => $w->id, 'title' => $w->title, 'kind' => 'work_order',
                'selected_quote_amount' => optional($w->quotes()->where('is_selected', true)->first())?->ownerFacingAmount(),
                // §17.8.3 — emergency work is flagged for the owner on their own work-order card.
                'emergency' => $w->approval_basis === RentalWorkOrder::BASIS_EMERGENCY,
            ])->values(),
            // BUILD 2 (§17.7.4) — extra work beyond the owner's agreed terms, waiting for their decision.
            'variations' => $this->scope->landlordPendingVariations($contact)->map(fn ($v) => $this->variationPayload($v))->values(),
        ]);
    }

    /** §17.7.4 — the owner's view of a request for extra work: original, the extra work (SELLING only), photos, note, new total. */
    private function variationPayload(RentalWorkOrderVariation $variation): array
    {
        $workOrder = RentalWorkOrder::withoutGlobalScopes()->find($variation->rental_work_order_id);
        $lines = $variation->lines()->withoutGlobalScopes()->where('office_status', \App\Models\RentalJobCardLine::OFFICE_ACCEPTED)->orderBy('id')->get();
        $photos = $lines->isEmpty() ? collect() : RentalWorkOrderPhoto::withoutGlobalScopes()
            ->whereIn('rental_job_card_line_id', $lines->pluck('id'))->orderBy('id')->limit(6)->get();

        return [
            'id' => $variation->id,
            'kind' => 'variation',
            'revision' => (int) $variation->revision,
            'status' => $variation->status,
            'work_order_id' => $variation->rental_work_order_id,
            'title' => $workOrder?->title,
            'baseline_amount' => (float) $variation->baseline_amount,
            'extra_amount' => (float) $variation->extra_amount,
            'new_total' => (float) $variation->new_total,
            'lines' => $lines->map(fn ($l) => [
                'description' => $l->description,
                'quantity' => rtrim(rtrim(number_format((float) $l->quantity, 2, '.', ''), '0'), '.'),
                'unit' => $l->unit,
                'total' => $l->line_total !== null ? (float) $l->line_total : null,
                'note' => $l->crew_note,
            ])->values(),
            'photos' => $photos->map(fn ($p) => ['id' => $p->id, 'url' => $p->storage_path])->values(),
            'term_text' => (string) $variation->term_text,
        ];
    }

    /**
     * Fault flow F2/F3/F7 - ONE fault as the owner may see it: the agent's sanitised version (title, description,
     * chosen photos, agent note), where it stands in plain words, the contractor choices when a decision is waiting,
     * and - once decided, by anyone - the decision read-only (who, how, when, reason, contractor). The tenant's
     * original wording and photos are never part of this payload.
     */
    public function faultReportShow(Request $request, int $faultReport): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $fault = $this->scope->landlordFaultReport($contact, $faultReport);
        if (! $fault) {
            return response()->json(['message' => 'Fault report not found.'], 404);
        }

        $version = $fault->ownerVersion();
        $awaiting = $fault->owner_approval_status === RentalFaultReport::APPROVAL_PENDING;
        $summary = $fault->decisionSummary();

        return response()->json(['fault_report' => [
            'id' => $fault->id,
            'title' => $version['title'],
            'description' => $version['description'],
            'agent_note' => $version['agent_note'],
            'photos' => $version['photos']->map(fn ($p) => ['id' => $p->id, 'url' => $p->storage_path])->values(),
            'property' => $fault->property?->buildDisplayAddress(),
            'category' => $fault->faultType?->category,
            'status' => $fault->status,
            'status_label' => $fault->ownerStatusLabel(),
            // W2: once approved, the work order for this fault - stage, who is doing it, the appointment.
            'work_order' => $this->workOrderSummaryFor($fault),
            // The same progress line the tenant sees, in the owner's words.
            'progress' => app(\App\Services\Rentals\RentalFaultProgressService::class)->forFault($fault, \App\Services\Rentals\RentalFaultProgressService::AUDIENCE_OWNER),
            'reported_at' => $fault->reported_at?->toIso8601String(),
            'sent_to_owner_at' => $fault->sent_to_owner_at?->toIso8601String(),
            'awaiting_decision' => $awaiting,
            // The supplier list for THIS type of work (empty = the owner is offered the other routes only).
            'contractors' => $awaiting ? app(RentalFaultContractorService::class)->optionsFor($fault)->values() : [],
            'decision' => $summary ? [
                'decision' => $summary['decision'],
                'by' => $summary['by'],
                'how' => $summary['how'],
                'via_link' => $summary['via_link'],
                'at' => $summary['at']?->toIso8601String(),
                'reason' => $summary['reason'],
                'contractor' => $summary['contractor'],
            ] : null,
        ]]);
    }

    /**
     * §3 / fault flow F3-F4 - the owner decides: Approve or Decline (a reason is REQUIRED). If approving, who handles
     * the repair: their OWN contractor (optional name + phone, so the agent can talk to them), a contractor picked from
     * the agency's list for this type of work, or "my agent arranges it" (the way out when the list is empty).
     *
     * Payload: decision=approve|decline; handled_by=own|list|agency (approve only); contractor_name / contractor_phone
     * (own); agency_service_provider_id (list); note (decline reason, required). The pre-flow values
     * approve_agency_appoints / approve_owner_handles are still accepted. One decision, ever: whoever is second is told.
     */
    public function faultReportDecision(Request $request, int $faultReport): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $fault = $this->scope->landlordFaultReport($contact, $faultReport);
        if (!$fault) {
            return response()->json(['message' => 'Fault report not found.'], 404);
        }

        // §17.6.6 - a decision is only taken on a record that is actually waiting for one.
        if ($fault->owner_approval_status !== RentalFaultReport::APPROVAL_PENDING) {
            return response()->json(['message' => 'This is not waiting for your decision any more.'], 422);
        }

        $data = $request->validate([
            'decision' => 'required|in:approve,decline,approve_agency_appoints,approve_owner_handles',
            'handled_by' => 'nullable|in:own,list,agency',
            'contractor_name' => 'nullable|string|max:191',
            'contractor_phone' => 'nullable|string|max:40',
            'agency_service_provider_id' => 'nullable|integer',
            'note' => 'nullable|string|max:2000',
        ]);

        // Map the pre-flow values onto the new shape.
        $handledBy = $data['handled_by'] ?? null;
        if ($data['decision'] === 'approve_owner_handles') {
            $data['decision'] = 'approve';
            $handledBy = 'own';
        } elseif ($data['decision'] === 'approve_agency_appoints') {
            $data['decision'] = 'approve';
            $handledBy = $handledBy ?: (! empty($data['agency_service_provider_id']) ? 'list' : 'agency');
        }

        if ($data['decision'] === 'decline') {
            if (trim((string) ($data['note'] ?? '')) === '') {
                return response()->json(['message' => 'Please tell us why you are declining.'], 422);
            }
            $decision = RentalFaultReport::APPROVAL_DECLINED;
            $route = null;
        } else {
            if (! in_array($handledBy, ['own', 'list', 'agency'], true)) {
                return response()->json(['message' => 'Please say who should handle the repair.'], 422);
            }
            $decision = RentalFaultReport::APPROVAL_APPROVED;
            $route = $handledBy === 'own' ? RentalFaultReport::ROUTE_OWNER_HANDLES : RentalFaultReport::ROUTE_AGENCY_APPOINTS;
            if ($handledBy === 'list') {
                $supplierId = (int) ($data['agency_service_provider_id'] ?? 0);
                if ($supplierId <= 0 || ! app(RentalFaultContractorService::class)->isOption($fault, $supplierId)) {
                    return response()->json(['message' => 'Please choose a contractor from the list.'], 422);
                }
            }
        }

        try {
            $fault->recordApproval($contact, [
                'decision' => $decision,
                'approval_route' => $route,
                'evidence_type' => \App\Models\RentalApproval::EVIDENCE_PORTAL,
                'evidence_text' => $data['note'] ?? null,
                'contractor_name' => $handledBy === 'own' ? ($data['contractor_name'] ?? null) : null,
                'contractor_phone' => $handledBy === 'own' ? ($data['contractor_phone'] ?? null) : null,
                'agency_service_provider_id' => $handledBy === 'list' ? ($data['agency_service_provider_id'] ?? null) : null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['fault_report' => [
            'id' => $fault->id,
            'status' => $fault->status,
            'status_label' => $fault->ownerStatusLabel(),
            'owner_approval_status' => $fault->owner_approval_status,
        ]]);
    }

    /** @return array<string, mixed>|null */
    private function workOrderSummaryFor(RentalFaultReport $fault): ?array
    {
        if (! $fault->rental_work_order_id) {
            return null;
        }
        $order = RentalWorkOrder::withoutGlobalScopes()->where('agency_id', $fault->agency_id)->whereNull('deleted_at')->find($fault->rental_work_order_id);

        return $order ? app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)
            ->summary($order, \App\Services\Rentals\RentalWorkOrderClientViewService::AUDIENCE_LANDLORD) : null;
    }

    /**
     * W6 (Johan, 8 Oct 2026) - the owner books (or moves) the repair appointment from the portal. The same service as the
     * agent's screen: it is written to the work order's history and the tenant is emailed. `appointment_at` is read in the
     * agency's timezone (a datetime-local value) or as a full ISO-8601 moment.
     */
    public function workOrderAppointment(Request $request, int $workOrder): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }
        $order = $this->scope->landlordWorkOrder($contact, $workOrder);
        if (! $order) {
            return response()->json(['message' => 'Work order not found.'], 404);
        }

        $data = $request->validate([
            'appointment_at' => 'required|date',
            'note' => 'nullable|string|max:500',
        ]);
        $tz = $order->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
        $at = \Illuminate\Support\Carbon::parse($data['appointment_at'], $tz)->utc();

        try {
            app(\App\Services\Rentals\RentalWorkOrderService::class)->setAppointment($order, $at, $data['note'] ?? null, $contact);
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['work_order' => app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)
            ->summary($order->fresh(), \App\Services\Rentals\RentalWorkOrderClientViewService::AUDIENCE_LANDLORD)]);
    }

    /** W6 - the owner reports progress: action = started | finished (the normal tenant check follows "finished"). */
    public function workOrderProgress(Request $request, int $workOrder): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }
        $order = $this->scope->landlordWorkOrder($contact, $workOrder);
        if (! $order) {
            return response()->json(['message' => 'Work order not found.'], 404);
        }

        $data = $request->validate([
            'action' => 'required|in:started,finished',
            'note' => 'nullable|string|max:500',
        ]);

        try {
            app(\App\Services\Rentals\RentalWorkOrderService::class)->recordOwnerProgress($order, $contact, $data['action'], $data['note'] ?? null);
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['work_order' => app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)
            ->summary($order->fresh(), \App\Services\Rentals\RentalWorkOrderClientViewService::AUDIENCE_LANDLORD)]);
    }

    /** §3 — Approve/Decline a quote already over the spend limit. No "handle it myself" here — a contractor is already engaged. */
    public function workOrderDecision(Request $request, int $workOrder): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $order = $this->scope->landlordWorkOrder($contact, $workOrder);
        if (!$order) {
            return response()->json(['message' => 'Work order not found.'], 404);
        }

        // §17.6.6 — a decision is only taken on a record that is actually waiting for one.
        if ($order->owner_approval_status !== RentalWorkOrder::APPROVAL_PENDING) {
            return response()->json(['message' => 'This is not waiting for your decision any more.'], 422);
        }

        $data = $request->validate([
            'decision' => 'required|in:approve,decline',
            'note' => 'nullable|string|max:2000',
        ]);

        try {
            $order->recordApproval($contact, [
                'decision' => $data['decision'] === 'approve' ? RentalWorkOrder::APPROVAL_APPROVED : RentalWorkOrder::APPROVAL_DECLINED,
                'evidence_type' => \App\Models\RentalApproval::EVIDENCE_PORTAL,
                'evidence_text' => $data['note'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['work_order' => [
            'id' => $order->id,
            'owner_approval_status' => $order->owner_approval_status,
        ]]);
    }

    /**
     * BUILD 2 (§17.7.4) — Approve / Decline extra work beyond the owner's agreed terms. Body: decision (approve|decline), revision
     * (the revision the owner saw — a stale one answers 409), note. Resolved through the portal scope (the owner's own properties
     * only), then the same writer the office's "Record variation decision" uses.
     */
    public function variationDecision(Request $request, int $variation): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $record = $this->scope->landlordVariation($contact, $variation);
        if (! $record) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        $data = $request->validate([
            'decision' => 'required|in:approve,decline',
            // the revision the owner SAW — a decision on something that has changed since (more extra work joined it) is refused with 409
            'revision' => 'required|integer|min:1',
            'note' => 'nullable|string|max:2000',
        ]);

        if (! $record->isAwaitingOwner()) {
            return response()->json(['message' => 'This is not waiting for your decision any more.'], 422);
        }

        try {
            app(RentalApprovalGateService::class)->recordVariationDecision($record, $data['decision'], [
                'via' => RentalWorkOrderVariation::VIA_PORTAL,
                'revision' => $data['revision'] ?? null,
                'note' => $data['note'] ?? null,
            ], ['contact' => $contact]);
        } catch (StaleVariationRevision $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $record->refresh();

        return response()->json(['variation' => ['id' => $record->id, 'status' => $record->status, 'revision' => (int) $record->revision]]);
    }
}
