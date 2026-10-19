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
    /**
     * .ai/specs/rentals-faults-work-orders.md section 22.4 - the owner's "How I want faults handled" for one of THEIR properties: what is recorded,
     * who recorded it and on what basis, the agency's suggestion (only while nothing is recorded), who looks at faults first (read-only), the history.
     */
    public function faultSettingsShow(Request $request, int $property): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }
        $propertyModel = $this->scope->landlordProperty($contact, $property);
        if (! $propertyModel) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        return response()->json(['fault_settings' => $this->faultSettingsPayload($propertyModel)]);
    }

    /** The owner saves all three answers as their own (a changed value is logged as a change; confirming what is recorded is logged as a confirmation). */
    public function faultSettingsSave(Request $request, int $property): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }
        $propertyModel = $this->scope->landlordProperty($contact, $property);
        if (! $propertyModel) {
            return response()->json(['message' => 'Property not found.'], 404);
        }
        $data = $request->validate([
            'repair_limit' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'emergency_limit' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'callout_mode' => ['required', 'in:yes,no'],
            'callout_limit' => ['nullable', 'required_if:callout_mode,yes', 'numeric', 'gt:0', 'max:99999999.99'],
        ], [
            'repair_limit.required' => 'Enter an amount for repairs (R0 if you want to be asked every time).',
            'emergency_limit.required' => 'Enter an amount for emergency repairs (R0 if you want to be asked every time).',
            'callout_mode.required' => 'Choose Yes or No for call-out fees.',
            'callout_limit.required_if' => 'Enter the call-out amount you allow, or choose No.',
        ]);

        $result = app(\App\Services\Rentals\RentalOwnerFaultSettingsService::class)->ownerConfirm($propertyModel, $contact, $data);

        return response()->json(['ok' => true, 'result' => $result, 'fault_settings' => $this->faultSettingsPayload($propertyModel->fresh())]);
    }

    /** "Ask for a change" to who looks at faults first: nothing changes by itself; both lease agents are told. */
    public function faultSettingsChangeRequest(Request $request, int $property): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }
        $propertyModel = $this->scope->landlordProperty($contact, $property);
        if (! $propertyModel) {
            return response()->json(['message' => 'Property not found.'], 404);
        }
        $data = $request->validate(['note' => 'nullable|string|max:1000']);
        app(\App\Services\Rentals\RentalOwnerFaultSettingsService::class)->requestFirstResponderChange($propertyModel, $contact, $data['note'] ?? null);

        return response()->json(['ok' => true, 'fault_settings' => $this->faultSettingsPayload($propertyModel->fresh())]);
    }

    /** @return array<string, mixed> */
    private function faultSettingsPayload(\App\Models\Property $property): array
    {
        $svc = app(\App\Services\Rentals\RentalOwnerFaultSettingsService::class);
        $r = \App\Services\Rentals\RentalOwnerFaultSettings::for($property);
        $row = $r->row();
        $status = $r->status();
        $suggested = $status === 'not_set';
        $shown = $suggested ? $r->proposed() : $r->values();
        $basis = $row?->capture_basis;

        return [
            'property_id' => $property->id,
            'address' => $property->buildDisplayAddress(),
            'status' => $status,
            'status_label' => match ($status) {
                'confirmed_by_owner' => 'Confirmed by you',
                'captured_by_agent' => 'Recorded by your agent - please confirm or change it',
                'disputed' => 'You told us you do not agree - confirm your own settings below',
                default => 'Not set yet - until you set these, your agent will ask you before every decision',
            },
            'version' => $r->version(),
            'values' => $shown,
            'suggested' => $suggested,
            'captured_line' => ($row && $basis) ? ($r->capturedBy() ?: 'Your agent') . ' recorded this' . ($row->captured_at ? ' on ' . $row->captured_at->format('j M Y') : '')
                . ($basis === 'carried_over' ? ' - carried over from the existing work terms' : ' - you told us by ' . strtolower((string) $row->basisLabel()) . ($row->capture_occurred_at ? ' on ' . $row->capture_occurred_at->format('j M Y') : ''))
                . ($row->capture_note && $basis !== 'carried_over' ? '. Note: "' . $row->capture_note . '"' : '') . '.' : null,
            'confirmed_at' => $r->confirmedAt()?->toIso8601String(),
            'first_responders' => $r->firstResponders(null)->map(fn ($f) => ['name' => $f['name'], 'kind_label' => $f['kind_label'], 'tell_label' => $f['tell_label']])->values(),
            'history' => $svc->history($property, 12)->values(),
        ];
    }

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
            // AT-452 (§19.14): the owner form has no title box - the type's name (or the start of the details) is the title.
            'title' => 'nullable|string|max:191',
            'description' => 'nullable|string|max:5000',
            'comment' => 'nullable|string|max:2000',
            'follows_fault_report_id' => 'nullable|integer',
            'photos' => 'nullable|array|max:10',
            'photos.*' => 'file|mimes:' . \App\Services\Images\HeicConverter::UPLOAD_MIMES . '|max:15360',
        ]);

        // AT-452 round 2 (F-OWNER) - "Have this repaired anyway": the new request follows a report the tenant resolved themselves. The owner must be able
        // to see that report (their own property); the link is the whole of it - the resolved report itself is never changed.
        $follows = null;
        if (! empty($data['follows_fault_report_id'])) {
            $follows = $this->scope->landlordFaultReport($contact, (int) $data['follows_fault_report_id']);
            if (! $follows || ! $follows->isTenantSelfResolved() || (int) $follows->property_id !== (int) $propertyModel->id) {
                return response()->json(['message' => 'That report cannot be followed up.'], 422);
            }
        }

        $faultType = !empty($data['rental_fault_type_id'])
            ? RentalFaultType::withoutGlobalScopes()->where('agency_id', $contact->agency_id)->where('is_active', true)->find($data['rental_fault_type_id'])
            : null;

        $leaseId = $this->scope->landlordActiveLeaseId($contact, $propertyModel->id);

        $details = trim((string) ($data['description'] ?? ''));
        $title = trim((string) ($data['title'] ?? '')) ?: ($faultType?->name ?: \Illuminate\Support\Str::limit($details, 80, ''));
        if ($title === '') {
            return response()->json(['message' => 'Choose what needs doing, or write the details.'], 422);
        }

        $attributes = [
            'lease_id' => $leaseId,
            'rental_fault_type_id' => $faultType?->id,
            'title' => $title,
            'description' => $details,
            'follows_fault_report_id' => $follows?->id,
        ];
        if ($follows && ! str_contains((string) $attributes['description'], 'Follows the report of')) {
            $note = 'Follows the report of ' . ($follows->reported_at ?? $follows->created_at)->format('j M Y') . ' that the tenant resolved themselves.';
            $attributes['description'] = trim($note . ' ' . $attributes['description']);
        }

        // AT-452 (§19.14): an owner's work request - their instruction is their authority to go to a quote.
        $faultReport = $this->faultReportService->reportOwnerRequest($propertyModel, $contact, $attributes, $data['comment'] ?? null);

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
            'fault_reports' => $this->scope->landlordFaultReports($contact)->map(fn ($f) => array_merge([
                'id' => $f->id,
                'title' => $f->isTenantSelfResolved() ? ($f->faultType?->name ?: $f->title) : $f->ownerVersion()['title'],
                'status' => $f->status,
                'status_label' => $f->ownerStatusLabel(),
                'owner_approval_status' => $f->owner_approval_status,
                // Waiting on THIS owner: the Faults tab marks it and counts it.
                'needs_decision' => $f->owner_approval_status === RentalFaultReport::APPROVAL_PENDING,
                'work_order_id' => $f->rental_work_order_id,
                'reported_at' => $f->reported_at?->toIso8601String(),
                // AT-452 round 2 (G) - closed ones are one collapsed line each; open ones stay expanded.
                'closed' => $f->isClosedState(),
                'end_state' => $f->endStateFor('owner'),
                'arranged_by_agent' => $f->isArrangedByAgent(),
            ], $this->selfResolvedSummary($f)))->values(),
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
                // Audit fixes (D26): the quote the owner is looking at - the decision carries this back
                'quote' => ($sq = $w->quotes()->where('is_selected', true)->first()) ? ['id' => $sq->id, 'revision' => (int) $sq->revision, 'amount' => round((float) $sq->ownerFacingAmount(), 2)] : null,
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
                // Audit fixes (D26): the quote the owner is looking at - the decision carries this back
                'quote' => ($sq = $w->quotes()->where('is_selected', true)->first()) ? ['id' => $sq->id, 'revision' => (int) $sq->revision, 'amount' => round((float) $sq->ownerFacingAmount(), 2)] : null,
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
            ->whereIn('rental_job_card_line_id', $lines->pluck('id'))
            ->where('photo_type', \App\Models\RentalJobCardLine::PHOTO_TYPE)   // AT-459: a crew receipt is the office's, never the owner's
            ->orderBy('id')->limit(6)->get();

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
            'photos' => $photos->map(fn ($p) => ['id' => $p->id] + app(\App\Services\Images\PortalImageService::class)->urls($p->storage_path))->values(),
            'term_text' => (string) $variation->term_text,
            // spec 22.17 - when the CONTRACTOR asked for the extra, their own words (amount and reason)
            'reason' => $variation->origin === \App\Models\RentalWorkOrderVariation::ORIGIN_CONTRACTOR ? (string) $variation->note : null,
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

        // AT-452 round 2 (F-OWNER) - a fault the tenant fixed themselves: shown, never a decision.
        if ($fault->isTenantSelfResolved()) {
            return response()->json(['fault_report' => array_merge($this->selfResolvedSummary($fault), [
                'id' => $fault->id,
                'title' => $fault->faultType?->name ?: $fault->title,
                'description' => null, 'agent_note' => null, 'photos' => [],
                'property' => $fault->property?->buildDisplayAddress(),
                'status' => $fault->status, 'status_label' => 'Resolved without a repair',
                'reported_at' => $fault->reported_at?->toIso8601String(),
                'awaiting_decision' => false, 'decision' => null, 'work_order' => null, 'progress' => null,
                'contractors' => [], 'has_contractor' => true, 'reported_by_owner' => false,
                'reported_before' => null,
            ])]);
        }

        $version = $fault->ownerVersion();
        $awaiting = $fault->owner_approval_status === RentalFaultReport::APPROVAL_PENDING;
        $summary = $fault->decisionSummary();

        return response()->json(['fault_report' => [
            'id' => $fault->id,
            'title' => $version['title'],
            'description' => $version['description'],
            'agent_note' => $version['agent_note'],
            // AT-452 - the shared photos, with the agent's marks and notes ONLY once the fault has been sent (RentalFaultPhotoAnnotationService decides).
            'photos' => app(\App\Services\Rentals\RentalFaultPhotoAnnotationService::class)->payload($fault, $version['photos'], \App\Services\Rentals\RentalFaultPhotoAnnotationService::AUDIENCE_OWNER),
            // The "What the tenant reported" section reads "What you reported" when the owner raised it themselves.
            'reported_by_owner' => $fault->reported_by_type === RentalFaultReport::REPORTED_BY_LANDLORD,
            'self_resolved' => false,
            // item I - informed, not asked: the sentence, who, the amounts, the questions asked so far (null for every other fault)
            'arranged' => $this->arrangedFor($fault),
            // section 22 - what an AGENT did on the owner's behalf (recorded their instruction): the sentence, the agent's note, "Query this"
            'authorisation' => $this->authorisationFor($fault),
            // section 22.7 - "Stop this" / "Query this": the buttons' state, the hold, and the questions with the agents' answers
            'owner_actions' => $this->ownerActionsFor($fault),
            // section 22.13.4 - the "Who pays" ruling in the owner's words (null until the agent has ruled)
            'payer_ruling' => app(\App\Services\Rentals\RentalWhoPaysService::class)->portalPayload($fault, 'owner'),
            // section 22.4 - the contractor the agent proposes (and the call-out fee) while the owner is asked to authorise
            'proposed' => $awaiting ? app(\App\Services\Rentals\FaultInspectionService::class)->proposedFor($fault) : null,
            // AT-452 round 2 (F-OWNER) - "Reported before on <date> ..." when the same kind of fault was on this property earlier.
            'reported_before' => $this->reportedBeforeFor($fault),
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
            'owner_comment' => ($oc = $fault->ownerComment()) ? ['text' => $oc['text'], 'by' => $oc['by'], 'at' => $oc['at']?->toIso8601String()] : null,
            // The supplier list for THIS type of work (empty = the owner is offered the other routes only).
            'contractors' => $awaiting ? app(RentalFaultContractorService::class)->optionsFor($fault)->values() : [],
            // AT-452 item 9 - the SAME test the agent's warning on the fault page uses; the note under the contractor choice reads this.
            'has_contractor' => $awaiting ? app(RentalFaultContractorService::class)->hasContractorFor($fault) : true,
            'decision' => $summary ? [
                'decision' => $summary['decision'],
                'by' => $summary['by'],
                'how' => null,   // the owner is never told "decided by the owner on the portal link" / "captured by the agent (verbal note)"
                'via_link' => $summary['via_link'],
                'at' => $summary['at']?->toIso8601String(),
                'reason' => $summary['reason'],
                'contractor' => $summary['contractor'],
            ] : null,
        ]]);
    }

    /**
     * AT-452 round 2 (F-OWNER) - what the owner reads about a fault the tenant fixed themselves with the suggested step: the type, the property,
     * when it was reported, the advice the tenant was shown, any words the tenant typed, "No cost", and whether the owner has since asked for a
     * repair anyway. Empty for every other fault.
     *
     * @return array<string,mixed>
     */
    private function selfResolvedSummary(RentalFaultReport $f): array
    {
        if (! $f->isTenantSelfResolved()) {
            return ['self_resolved' => false];
        }
        $property = $f->property;
        $repair = $f->followUps()->where('status', '!=', RentalFaultReport::STATUS_CANCELLED)->first();

        return [
            'self_resolved' => true,
            'type_name' => $f->faultType?->name ?: $f->title,
            'fault_type_id' => $f->rental_fault_type_id,
            'property_id' => $f->property_id,
            'property' => $property?->buildDisplayAddress(),
            'resolved_at' => $f->resolved_at?->toIso8601String(),
            'advice' => ($f->faultType && $property) ? app(\App\Services\Rentals\RentalFaultTypePortalView::class)->adviceFor($f->faultType, $property) : null,
            'tenant_description' => trim((string) $f->description) !== '' ? trim((string) $f->description) : null,
            // "Owner asked for a repair on <date>" + the link to the new request (visible to this owner: they raised it, or it follows their own report)
            'repair_asked' => $repair ? ['id' => $repair->id, 'at' => ($repair->reported_at ?? $repair->created_at)?->toIso8601String(),
                'owner_comment' => ($c = $repair->ownerComment()) ? ['text' => $c['text'], 'by' => $c['by'], 'at' => $c['at']?->toIso8601String()] : null] : null,
            // AT-452 (§19.12) - "What the tenant reported", READ-ONLY: their words, the date, their photos (if any). Nothing here is ever written by the owner.
            'tenant_report' => [
                'text' => trim((string) $f->description) !== '' ? trim((string) $f->description) : null,
                'at' => ($f->reported_at ?? $f->created_at)?->toIso8601String(),
                'photos' => app(\App\Services\Rentals\RentalFaultPhotoAnnotationService::class)->payload($f, $f->photos()->get(), \App\Services\Rentals\RentalFaultPhotoAnnotationService::AUDIENCE_OWNER),
            ],
        ];
    }

    /**
     * Item I - what the owner reads about a fault their agent ARRANGED without asking (inside the no-approval limit, or an emergency): the sentence, who
     * does the work, the quoted / approved amount, the final cost once it is done, whether the quote has since gone over the limit (then it IS the owner's
     * to approve), and the questions they have already put to the agent.
     *
     * @return array<string,mixed>|null
     */
    private function arrangedFor(RentalFaultReport $fault): ?array
    {
        if (! $fault->isArrangedByAgent()) {
            return null;
        }
        $wo = $fault->rental_work_order_id
            ? RentalWorkOrder::withoutGlobalScopes()->where('agency_id', $fault->agency_id)->whereNull('deleted_at')->find($fault->rental_work_order_id)
            : null;
        $quote = $wo?->quotes()->where('is_selected', true)->first();
        $amount = $wo && $wo->approved_amount !== null ? (float) $wo->approved_amount : ($quote ? $quote->ownerFacingAmount() : null);

        return [
            'at' => $fault->arranged_by_agent_at?->toIso8601String(),
            'basis' => $fault->arranged_basis,
            'limit' => $fault->arranged_limit_amount !== null ? (float) $fault->arranged_limit_amount : null,
            'text' => $fault->arrangedText(),
            'amount' => $amount,
            'final_cost' => ($wo && $wo->status === RentalWorkOrder::STATUS_COMPLETED && $wo->cost_amount !== null) ? (float) $wo->cost_amount : null,
            // over the limit later (a dearer quote, extra work): the normal approval rule applies and the owner is asked on the work order
            'needs_owner_approval' => (bool) ($wo && $wo->owner_approval_status === RentalWorkOrder::APPROVAL_PENDING),
            'queries' => $fault->updates()->where('update_type', \App\Models\RentalFaultReportUpdate::TYPE_OWNER_QUERY)->orderBy('id')->get(['note', 'created_at'])
                ->map(fn ($u) => ['note' => $u->note, 'at' => $u->created_at?->toIso8601String()])->values()->all(),
        ];
    }

    /**
     * Section 22.5 - the standing Decision 1 record an agent made on the owner's behalf, in the owner's own words (the same sentence they were emailed),
     * the agent's note, and the questions the owner has put about it. Null when the owner decided on their own link or nothing was recorded.
     *
     * @return array<string,mixed>|null
     */
    private function authorisationFor(RentalFaultReport $fault): ?array
    {
        $payload = app(\App\Services\Rentals\RentalAuthorisationService::class)->ownerPayload($fault);
        if (! $payload) {
            return null;
        }
        $payload['title'] = $payload['outcome'] === \App\Models\RentalAuthorisation::OUTCOME_DECLINED ? 'Declined on your instruction' : 'Authorised on your instruction';
        $payload['queries'] = $fault->updates()->where('update_type', \App\Models\RentalFaultReportUpdate::TYPE_OWNER_QUERY)->orderBy('id')->get(['note', 'created_at'])
            ->map(fn ($u) => ['note' => $u->note, 'at' => $u->created_at?->toIso8601String()])->values()->all();

        return $payload;
    }

    /**
     * AT-452 (§19.12) - "Ask my agent to have this repaired": the owner's request on a fault the tenant resolved themselves, with their own optional
     * comment. The tenant's report is never changed (this route writes only to the NEW request it raises).
     */
    public function faultReportRepairRequest(Request $request, int $faultReport): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }
        $fault = $this->scope->landlordFaultReport($contact, $faultReport);
        if (! $fault) {
            return response()->json(['message' => 'Fault report not found.'], 404);
        }
        $data = $request->validate(['comment' => 'nullable|string|max:2000']);

        try {
            $new = $this->faultReportService->raiseOwnerRepairRequest($fault, $contact, $data['comment'] ?? null);
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['fault_report' => ['id' => $new->id, 'title' => $new->title, 'status' => $new->status], 'repair_asked' => ['id' => $new->id, 'at' => $new->reported_at?->toIso8601String()]], 201);
    }

    /**
     * Section 22.7 - what the owner's "Stop this" / "Query this" buttons need: whether there is an agent-made notice to act on, whether the owner can still
     * stop it (the work has not started), the hold if there is one, and every question asked with its answer. One function, one answer for every screen.
     *
     * @return array<string,mixed>
     */
    private function ownerActionsFor(RentalFaultReport $fault): array
    {
        $holds = app(\App\Services\Rentals\RentalOwnerHoldService::class);
        $hold = $holds->activeFor($fault);
        $block = $holds->stopBlock($fault);
        $tz = $fault->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');

        return [
            'show' => $hold !== null || $fault->isArrangedByAgent() || $this->authorisationFor($fault) !== null,
            'can_stop' => $block === null,
            'work_started' => $block !== null && $block['code'] === 'started',
            'closed' => $block !== null && $block['code'] === 'closed',
            'hold' => $hold ? ['reason' => $hold->reason, 'at' => $hold->stopped_at?->toIso8601String(), 'line' => $holds->stopLine($hold)] : null,
            'queries' => $holds->queriesFor($fault)->map(fn ($q) => [
                'id' => $q->id, 'question' => $q->question, 'at' => $q->asked_at?->toIso8601String(), 'answer' => $q->answer, 'answered_at' => $q->answered_at?->toIso8601String(),
                'answered_by' => $q->answeredBy?->name,
            ])->values()->all(),
        ];
    }

    /** Resolve the owner (portal contact) and their fault for the three "Stop this" / "Query this" calls: [contact, fault] or a JsonResponse. */
    private function ownerNoticeContext(Request $request, int $faultReport): array|JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }
        $fault = $this->scope->landlordFaultReport($contact, $faultReport);
        if (! $fault) {
            return response()->json(['message' => 'Fault report not found.'], 404);
        }

        return [$contact, $fault];
    }

    /** The answer the three calls share: the refreshed fault state for the owner's screen. */
    private function ownerNoticeState(RentalFaultReport $fault, int $status = 201): JsonResponse
    {
        $fault = $fault->fresh();

        return response()->json([
            'ok' => true, 'owner_actions' => $this->ownerActionsFor($fault), 'arranged' => $this->arrangedFor($fault), 'authorisation' => $this->authorisationFor($fault),
            'progress' => app(\App\Services\Rentals\RentalFaultProgressService::class)->forFault($fault, \App\Services\Rentals\RentalFaultProgressService::AUDIENCE_OWNER),
        ], $status);
    }

    /**
     * Item I / section 22.7 - "Query this": the owner asks their agent a question about a repair the agent arranged. A note, not a decision - nothing stops. It is
     * saved, both lease agents are told (in-app and by email), and the agent answers from the fault page.
     */
    public function faultReportQuery(Request $request, int $faultReport): JsonResponse
    {
        $ctx = $this->ownerNoticeContext($request, $faultReport);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$contact, $fault] = $ctx;
        if (! $this->ownerActionsFor($fault)['show']) {
            return response()->json(['message' => 'There is nothing to query on this one.'], 422);
        }
        $data = $request->validate(['note' => 'required|string|min:3|max:1000']);

        try {
            app(\App\Services\Rentals\RentalOwnerHoldService::class)->ask($fault, $contact, $data['note'], \App\Models\RentalOwnerQuery::VIA_PORTAL);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->ownerNoticeState($fault);
    }

    /** Section 22.7 - "Stop this": a reason is required; refused once the work has started. */
    public function faultReportStop(Request $request, int $faultReport): JsonResponse
    {
        $ctx = $this->ownerNoticeContext($request, $faultReport);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$contact, $fault] = $ctx;
        if (! $this->ownerActionsFor($fault)['show']) {
            return response()->json(['message' => 'There is nothing to stop on this one.'], 422);
        }
        $data = $request->validate(['reason' => 'required|string|min:3|max:500'], ['reason.required' => 'Please say why - a short reason is required.', 'reason.min' => 'Please say why - a short reason is required.']);

        try {
            $r = app(\App\Services\Rentals\RentalOwnerHoldService::class)->stop($fault, $contact, $data['reason'], \App\Models\RentalOwnerHold::VIA_PORTAL);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if ($r['result'] === 'started') {
            return response()->json(['message' => 'Work has started - contact your agent.', 'owner_actions' => $this->ownerActionsFor($fault->fresh())], 422);
        }
        if ($r['result'] === 'closed') {
            return response()->json(['message' => 'This job is closed, so there is nothing to stop.', 'owner_actions' => $this->ownerActionsFor($fault->fresh())], 422);
        }

        return $this->ownerNoticeState($fault);
    }

    /** Section 22.7 - "Go ahead after all": the owner's own press is the fresh authority that lifts their hold. */
    public function faultReportResume(Request $request, int $faultReport): JsonResponse
    {
        $ctx = $this->ownerNoticeContext($request, $faultReport);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$contact, $fault] = $ctx;
        try {
            $r = app(\App\Services\Rentals\RentalOwnerHoldService::class)->resumeByOwner($fault, $contact, \App\Models\RentalOwnerHold::VIA_PORTAL);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if ($r === 'not_held') {
            return response()->json(['message' => 'This is not on hold.', 'owner_actions' => $this->ownerActionsFor($fault->fresh())], 422);
        }

        return $this->ownerNoticeState($fault);
    }

    /** "Reported before on <date> ..." for the owner's decision screen - linked only to an earlier report this owner can open themselves. */
    private function reportedBeforeFor(RentalFaultReport $f): ?array
    {
        $before = $f->reportedBefore();
        if (! $before) {
            return null;
        }
        $earlier = RentalFaultReport::withoutGlobalScopes()->find($before['id']);

        return ['text' => $before['text'], 'at' => $before['at']->toIso8601String(), 'how' => $before['how'], 'fault_id' => $earlier && $earlier->isVisibleToOwner() ? $before['id'] : null];
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
            'handled_by' => 'nullable|in:own,list,agency,proposed',
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
            // "Yes, send <name>": the contractor (or our own team) the agent suggested when the fault was sent for the owner's go-ahead
            if ($handledBy === 'proposed') {
                if ($fault->proposed_assignment === \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER && $fault->proposed_supplier_id) {
                    $handledBy = 'suggested_supplier';
                    $data['agency_service_provider_id'] = (int) $fault->proposed_supplier_id;
                } elseif ($fault->proposed_assignment) {
                    $handledBy = 'agency';   // our own team: the agency arranges it
                } else {
                    return response()->json(['message' => 'Your agent has not suggested anyone for this one - please choose who should look and quote.'], 422);
                }
            }
            if (! in_array($handledBy, ['own', 'list', 'agency', 'suggested_supplier'], true)) {
                return response()->json(['message' => 'Please say who should handle the repair.'], 422);
            }
            $decision = RentalFaultReport::APPROVAL_APPROVED;
            $route = $handledBy === 'own' ? RentalFaultReport::ROUTE_OWNER_HANDLES : RentalFaultReport::ROUTE_AGENCY_APPOINTS;
            if ($handledBy === 'suggested_supplier') {
                $handledBy = 'list';   // recorded exactly like a pick from the list - the agent chose this contractor, so it need not be on the type-of-work list
            } elseif ($handledBy === 'list') {
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

        // AT-452 (§19.12): whatever the owner wrote (the reason for a decline, or an optional note on an approval) is ALSO their comment to the agent -
        // saved with their name and the time, shown to the agent in its own block and read back to the owner.
        $fault->refresh()->recordOwnerComment($contact, $data['note'] ?? null, 'wrote with their decision');

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
        $at = \App\Support\AppDate::parse($data['appointment_at'], $tz);   // stored in the app timezone (->utc() saved 10:00 as 08:00)

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

        // Audit fixes (D26): when a quote is selected the owner's decision must carry the quote they SAW (id, revision, amount). A work order with no selected quote
        // (an old record) has nothing to snapshot and decides as before. A quote that appeared since they loaded the screen makes the fields required -> 422, look again.
        $current = $order->quotes()->where('is_selected', true)->whereNull('declined_at')->first();
        $snap = $current ? 'required' : 'nullable';
        $data = $request->validate([
            'decision' => 'required|in:approve,decline',
            // Q4 (Johan, 9 Oct 2026): a decline always carries the owner's reason.
            'note' => 'nullable|required_if:decision,decline|string|max:2000',
            'quote_id' => $snap . '|integer',
            'quote_revision' => $snap . '|integer|min:0',
            'quote_amount' => $snap . '|numeric',
        ]);

        if ($current && ((int) $current->id !== (int) $data['quote_id'] || (int) $current->revision !== (int) $data['quote_revision']
            || round((float) $current->ownerFacingAmount() * 100) !== round((float) $data['quote_amount'] * 100))) {
            return response()->json(['message' => 'The quote has changed since you looked at it - please look again before you decide.'], 409);
        }

        try {
            $order->recordApproval($contact, [
                'decision' => $data['decision'] === 'approve' ? RentalWorkOrder::APPROVAL_APPROVED : RentalWorkOrder::APPROVAL_DECLINED,
                'evidence_type' => \App\Models\RentalApproval::EVIDENCE_PORTAL,
                'evidence_text' => $data['note'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Johan, 9 Oct 2026: the agent is told when the owner decides on the quote (before this nothing told them, so an approved work
        // order waited until somebody happened to open it). Settings per user, through the one dispatcher.
        $fresh = $order->fresh();
        $quote = $fresh->quotes()->where('is_selected', true)->first();
        $money = $quote ? ' (R' . number_format((float) $quote->ownerFacingAmount(), 2) . ')' : '';
        // AT-460: what the agent does next depends on the route - our own crew is booked, a contractor is sent the work order
        $nextStep = $fresh->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL ? 'book the date with the crew' : 'send it to the contractor';
        app(\App\Services\Rentals\RentalWorkOrderService::class)->notifyAgentOfOwnerAction(
            $fresh, 'rental_work_order.owner_decided',
            $data['decision'] === 'approve' ? 'Owner approved the quote' . $money . ' - ' . $nextStep : 'Owner declined the quote' . $money,
            $fresh->title . ($data['decision'] === 'decline' && trim((string) ($data['note'] ?? '')) !== '' ? ' - reason: ' . trim((string) $data['note']) : ''),
            now()
        );

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
