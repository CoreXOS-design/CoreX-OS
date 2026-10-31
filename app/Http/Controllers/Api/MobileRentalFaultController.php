<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\FormatsMobileRentalResponses;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Models\RentalFaultType;
use App\Models\User;
use App\Services\Rentals\RentalDataScope;
use App\Services\Rentals\RentalFaultContractorService;
use App\Services\Rentals\RentalFaultProgressService;
use App\Services\Rentals\RentalFaultReportService;
use App\Services\Rentals\RentalFaultTypePortalView;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * AT-508 P2 - the staff mobile API for fault reports (.ai/specs/rentals-faults-work-orders.md section 7.1,
 * .ai/specs/at-508-mobile-rentals.md sections 4-7). The web screens (CoreX\RentalFaultReportController) are the reference: this class validates
 * input and DELEGATES to the same model / service methods - there is no second implementation of any rule here.
 *
 * Scoping is TWO layers, same as MobileRentalJobCardController: route-model binding's global AgencyScope 404s another agency's id before this
 * class is reached; guardRentalRecordScope() then 403s a same-agency id outside the user's own / branch ceiling. Lists use the model's own
 * scopeVisibleTo(), so a list never holds a record the detail would refuse.
 *
 * Offline (spec section 5): the report create and the photo upload take `client_idempotency_key` (a repeat returns the stored row, HTTP 200,
 * `duplicate: true`) and `captured_at` (when the phone captured it); a queued write against a record archived meanwhile is 410 `code: archived`.
 */
class MobileRentalFaultController extends Controller
{
    use AuthorizesRentalRecordScope;
    use FormatsMobileRentalResponses;

    private const PER_PAGE = 25;

    private const STATUSES = [
        RentalFaultReport::STATUS_REPORTED, RentalFaultReport::STATUS_UNDER_REVIEW, RentalFaultReport::STATUS_AWAITING_APPROVAL,
        RentalFaultReport::STATUS_APPROVED, RentalFaultReport::STATUS_DECLINED, RentalFaultReport::STATUS_WORK_ORDER_RAISED,
        RentalFaultReport::STATUS_OWNER_HANDLING, RentalFaultReport::STATUS_RESOLVED, RentalFaultReport::STATUS_CANCELLED,
    ];

    // ── catalogue ────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** The agency's own active fault catalogue - the picker a tenant sees - plus the always-there "Other" choice. `property_id` renders the first-aid steps for that property. */
    public function types(Request $request, RentalFaultTypePortalView $view): JsonResponse
    {
        $user = $request->user();
        $request->validate(['property_id' => ['nullable', 'integer']]);

        $types = RentalFaultType::query()->where('agency_id', $user->effectiveAgencyId())->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->get();

        if ($request->filled('property_id')) {
            $property = $this->usableProperty($user, (int) $request->input('property_id'));
            $rows = $view->payload($types, $property);
        } else {
            $rows = $types->map(fn (RentalFaultType $t) => [
                'id' => $t->id, 'name' => $t->name, 'category' => $t->category, 'urgency' => $t->urgency, 'first_aid_steps' => $t->first_aid_steps,
            ])->values()->all();
        }

        return response()->json([
            'data' => $rows,
            'other_option' => ['id' => 'other', 'name' => RentalFaultReport::OTHER_TYPE_OPTION],
        ]);
    }

    // ── list / detail ────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Search `q`: property address, tenant name, fault type, title, reference ("FR-12" or "12"). Sort: newest (default) | oldest | status | property.
     * Filters: status, urgency (the fault type's), date_from/date_to (on the reported date), archived=1 (needs rental_fault_reports.create).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'status', 'property'])],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'urgency' => ['nullable', Rule::in([RentalFaultType::URGENCY_ROUTINE, RentalFaultType::URGENCY_URGENT, RentalFaultType::URGENCY_EMERGENCY])],
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'],
            'archived' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $archived = $request->boolean('archived');
        if ($archived && ! $user->hasPermission('rental_fault_reports.create')) {
            return $this->message('Archived fault reports can only be listed by someone who may edit faults.', 403);
        }

        $query = RentalFaultReport::query()
            ->when($archived, fn ($q) => $q->onlyTrashed())
            ->visibleTo($user)
            ->with(['property', 'lease.tenants.contact', 'faultType', 'workOrder']);
        RentalDataScope::pinOwnerToWorkingAgency($query, $user, 'rental_fault_reports.agency_id');

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $like = self::likeTerm($term);
            $query->where(function ($q) use ($term, $like) {
                $q->whereHas('property', fn ($p) => $p->searchAddress($term))
                    ->orWhereHas('lease.tenants.contact', fn ($c) => $c->whereRaw("CONCAT_WS(' ', first_name, last_name) like ?", [$like]))
                    ->orWhereHas('faultType', fn ($t) => $t->where('name', 'like', $like))
                    ->orWhere('rental_fault_reports.title', 'like', $like);
                if (preg_match('/^(?:FR-?)?(\d+)$/i', $term, $m)) {
                    $q->orWhere('rental_fault_reports.id', (int) $m[1]);
                }
            });
        }
        if (! empty($data['status'])) {
            $query->where('rental_fault_reports.status', $data['status']);
        }
        if (! empty($data['urgency'])) {
            $query->whereHas('faultType', fn ($t) => $t->where('urgency', $data['urgency']));
        }
        $this->applyDateRange($query, $request, 'rental_fault_reports.reported_at');

        match ($data['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('rental_fault_reports.reported_at')->orderBy('rental_fault_reports.id'),
            'status' => $query->orderBy('rental_fault_reports.status')->orderByDesc('rental_fault_reports.reported_at'),
            'property' => $query->leftJoin('properties', 'properties.id', '=', 'rental_fault_reports.property_id')
                ->select('rental_fault_reports.*')->orderBy('properties.title')->orderByDesc('rental_fault_reports.reported_at'),
            default => $query->orderByDesc('rental_fault_reports.reported_at')->orderByDesc('rental_fault_reports.id'),
        };

        $page = $query->paginate(self::PER_PAGE, ['*'], 'page', $this->pageNumber($request));

        return $this->listResponse(
            $page->getCollection()->map(fn (RentalFaultReport $f) => $this->summary($f))->values()->all(),
            $page,
            $archived ? 'There are no archived fault reports.' : 'No fault reports match. Clear the search or filters to see more.'
        );
    }

    public function show(Request $request, RentalFaultReport $faultReport): JsonResponse
    {
        $this->guardFault($faultReport);

        return response()->json($this->detail($faultReport));
    }

    // ── writes ───────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** Report a fault on a property (RentalFaultReportService::report - the call the web form makes). */
    public function store(Request $request, RentalFaultReportService $service, Property $property): JsonResponse
    {
        $user = $request->user();
        $agencyId = (int) $user->effectiveAgencyId();
        // another agency's property is simply not there (an owner-role token is not narrowed by the global scope, so this is explicit)
        abort_unless((int) $property->agency_id === $agencyId, 404);

        // AT-452 - "Other - not on the list" is the form's last choice: no catalogue type, the report is marked Other until the agent re-classifies it.
        $isOther = $request->input('rental_fault_type_id') === 'other';
        if ($isOther) {
            $request->merge(['rental_fault_type_id' => null]);
        }

        $validated = $request->validate([
            'lease_id' => ['nullable', Rule::exists('leases', 'id')->where('property_id', $property->id)],
            'rental_fault_type_id' => ['nullable', Rule::exists('rental_fault_types', 'id')->where('agency_id', $agencyId)->whereNull('deleted_at')],
            'reported_by_type' => ['nullable', Rule::in([
                RentalFaultReport::REPORTED_BY_TENANT, RentalFaultReport::REPORTED_BY_AGENT_NOTICED, RentalFaultReport::REPORTED_BY_OWNER_INSTRUCTED,
            ])],
            'reported_by_contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('agency_id', $agencyId)],
            'reported_channel' => ['nullable', Rule::in([
                RentalFaultReport::CHANNEL_PHONE, RentalFaultReport::CHANNEL_WHATSAPP, RentalFaultReport::CHANNEL_EMAIL,
                RentalFaultReport::CHANNEL_IN_PERSON, RentalFaultReport::CHANNEL_APP, RentalFaultReport::CHANNEL_OTHER,
            ])],
            'title' => ['required', 'string', 'max:191'],
            'description' => ['required', 'string'],
            'client_idempotency_key' => ['nullable', 'uuid'],
            'captured_at' => ['nullable', 'date'],
        ]);
        $key = $validated['client_idempotency_key'] ?? null;

        // a repeat of a report already stored is that report (spec section 5.1) - looked up before anything else can refuse it
        if ($key && ($existing = $this->faultForKey($agencyId, $key))) {
            return $this->replayOf($existing, (int) $property->id);
        }

        if ($this->isArchived($property)) {
            return $this->archivedResponse('property');
        }
        abort_unless(Property::query()->visibleTo($user)->whereKey($property->id)->exists(), 404);

        $attributes = [
            'lease_id' => $validated['lease_id'] ?? null,
            'rental_fault_type_id' => $validated['rental_fault_type_id'] ?? null,
            'reported_by_type' => $validated['reported_by_type'] ?? RentalFaultReport::REPORTED_BY_AGENT_NOTICED,
            'reported_by_contact_id' => $validated['reported_by_contact_id'] ?? null,
            'reported_channel' => $validated['reported_channel'] ?? RentalFaultReport::CHANNEL_APP,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'captured_by_user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'client_idempotency_key' => $key,
            // the phone's capture time; a phone clock ahead of the server is clamped, never refused (that would strand the queue)
            'reported_at' => $this->capturedAt($validated['captured_at'] ?? null),
        ];
        if ($attributes['reported_by_type'] === RentalFaultReport::REPORTED_BY_AGENT_NOTICED) {
            $attributes['reported_by_user_id'] = $user->id;
        }
        if ($isOther) {
            $attributes['fault_type_other'] = true;
        }

        try {
            $fault = $service->report($property, $attributes);
        } catch (QueryException $e) {
            // two sends of the same queued report racing each other: the second finds the first
            if ($key && ($existing = $this->faultForKey($agencyId, $key))) {
                return $this->replayOf($existing, (int) $property->id);
            }
            throw $e;
        }

        return response()->json($this->detail($fault->fresh()) + ['duplicate' => false], 201);
    }

    /** A photo for a fault (RentalFaultReportService::storePhoto - the call the web upload makes). Offline-safe. */
    public function storePhoto(Request $request, RentalFaultReportService $service, RentalFaultReport $faultReport): JsonResponse
    {
        $this->guardFault($faultReport);
        $validated = $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:51200'],
            'client_idempotency_key' => ['nullable', 'uuid'],
            'captured_at' => ['nullable', 'date'],
        ]);
        if ($this->isArchived($faultReport)) {
            return $this->archivedResponse('fault report');
        }

        $key = $validated['client_idempotency_key'] ?? null;
        // the key is unique across photos: reused on ANOTHER fault it is the app's mistake, not a replay
        if ($key && ($other = RentalFaultReportPhoto::where('client_idempotency_key', $key)->first()) && (int) $other->rental_fault_report_id !== (int) $faultReport->id) {
            return $this->message('That upload key has already been used for a different fault report.', 409, 'key_reused');
        }

        [$photo, $duplicate] = $service->storePhoto(
            $faultReport, $request->file('photo'), $request->user(), $key,
            ! empty($validated['captured_at']) ? $this->capturedAt($validated['captured_at']) : null
        );

        return response()->json($this->photoRow($photo) + ['duplicate' => $duplicate], $duplicate ? 200 : 201);
    }

    /** Record the owner's decision (RentalFaultReport::recordApproval - the call the web form makes). */
    public function recordApproval(Request $request, RentalFaultReportService $service, RentalFaultReport $faultReport): JsonResponse
    {
        $this->guardFault($faultReport);

        $validated = $request->validate([
            'decision' => ['required', Rule::in([\App\Models\RentalApproval::DECISION_APPROVED, \App\Models\RentalApproval::DECISION_DECLINED])],
            'approval_route' => ['nullable', Rule::in([RentalFaultReport::ROUTE_AGENCY_APPOINTS, RentalFaultReport::ROUTE_OWNER_HANDLES])],
            'evidence_type' => ['required', Rule::in([
                \App\Models\RentalApproval::EVIDENCE_WHATSAPP, \App\Models\RentalApproval::EVIDENCE_EMAIL, \App\Models\RentalApproval::EVIDENCE_VERBAL_NOTE,
            ])],
            'evidence_text' => ['required', 'string'],
            'evidence_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:51200'],
            'decided_at' => ['nullable', 'date'],
            'contractor_name' => ['nullable', 'string', 'max:191'],
            'contractor_phone' => ['nullable', 'string', 'max:40'],
            'agency_service_provider_id' => ['nullable', 'integer'],
        ]);
        if ($this->isArchived($faultReport)) {
            return $this->archivedResponse('fault report');
        }

        if ($validated['decision'] === \App\Models\RentalApproval::DECISION_APPROVED && empty($validated['approval_route'])) {
            return $this->message('Say who appoints the contractor: the owner or the agency.', 422);
        }
        if (($validated['approval_route'] ?? null) === RentalFaultReport::ROUTE_AGENCY_APPOINTS && ! empty($validated['agency_service_provider_id'])
            && ! app(RentalFaultContractorService::class)->isOption($faultReport, (int) $validated['agency_service_provider_id'])) {
            return $this->message('Please choose a contractor from the list for this type of work.', 422);
        }

        if ($request->hasFile('evidence_file')) {
            $validated['evidence_file_path'] = $service->storeApprovalScreenshot($request->file('evidence_file'), $faultReport->property_id);
        }
        unset($validated['evidence_file']);

        try {
            $faultReport->recordApproval($request->user(), $validated);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return $this->message($e->getMessage(), 422);
        }

        return response()->json($this->detail($faultReport->fresh()));
    }

    /** Close the fault with its outcome (RentalFaultReport::setOutcome + the "resolved" alert - the calls the web form makes). */
    public function setOutcome(Request $request, RentalFaultReportService $service, RentalFaultReport $faultReport): JsonResponse
    {
        $this->guardFault($faultReport);

        $validated = $request->validate([
            'outcome' => ['required', Rule::in([
                RentalFaultReport::OUTCOME_REPAIRED, RentalFaultReport::OUTCOME_REPAIRED_PARTIALLY, RentalFaultReport::OUTCOME_NOT_REPAIRED,
                RentalFaultReport::OUTCOME_OWNER_DECLINED, RentalFaultReport::OUTCOME_TENANT_LIABLE, RentalFaultReport::OUTCOME_RESOLVED_BY_FIRST_AID,
            ])],
            'outcome_note' => ['nullable', 'string'],
            'repaired_at' => ['nullable', 'date'],
        ]);
        if ($this->isArchived($faultReport)) {
            return $this->archivedResponse('fault report');
        }

        try {
            $faultReport->setOutcome($validated, $request->user());
        } catch (\LogicException|\InvalidArgumentException $e) {
            return $this->message($e->getMessage(), 422);
        }

        $service->notifyResolved($faultReport, $request->user());

        return response()->json($this->detail($faultReport->fresh()));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function guardFault(RentalFaultReport $fault): void
    {
        $this->guardRentalRecordScope($fault, 'rental_fault_reports', $fault->property?->branch_id);
    }

    /** A property of the user's own agency that they may see (404 otherwise). */
    private function usableProperty(User $user, int $propertyId): Property
    {
        $property = Property::query()->visibleTo($user)->where('agency_id', $user->effectiveAgencyId())->find($propertyId);
        abort_unless($property, 404);

        return $property;
    }

    /** The report stored under this key in this agency - archived ones included (they are answered with 410, not with a second row). */
    private function faultForKey(int $agencyId, string $key): ?RentalFaultReport
    {
        return RentalFaultReport::withTrashed()->where('agency_id', $agencyId)->where('client_idempotency_key', $key)->first();
    }

    private function replayOf(RentalFaultReport $existing, int $propertyId): JsonResponse
    {
        if ((int) $existing->property_id !== $propertyId) {
            return $this->message('That upload key has already been used for a different property.', 409, 'key_reused');
        }
        $this->guardFault($existing);
        if ($this->isArchived($existing)) {
            return $this->archivedResponse('fault report');
        }

        return response()->json($this->detail($existing) + ['duplicate' => true], 200);
    }

    /** The phone's capture time as a moment; never in the future of the server. */
    private function capturedAt(?string $value): Carbon
    {
        $at = $value ? Carbon::parse($value) : now();

        return $at->greaterThan(now()) ? now() : $at;
    }

    private function typeName(RentalFaultReport $f): ?string
    {
        return $f->faultType?->name ?: ($f->isOtherType() ? RentalFaultReport::OTHER_TYPE_NAME : null);
    }

    /** @return array<string,mixed> */
    private function summary(RentalFaultReport $f): array
    {
        return [
            'id' => $f->id,
            'reference' => 'FR-' . $f->id,
            'title' => $f->title,
            'status' => $f->status,
            'status_label' => $f->statusLabel(),
            'urgency' => $f->faultType?->urgency,
            'is_emergency' => $f->isEmergencyType(),
            'fault_type' => $this->typeName($f),
            'property' => $f->property ? ['id' => $f->property->id, 'address' => $f->property->buildDisplayAddress()] : null,
            'lease_id' => $f->lease_id,
            'tenant_names' => $f->lease?->tenantNames(),
            'reported_at' => $f->reported_at?->toIso8601String(),
            'owner_approval_status' => $f->owner_approval_status,
            'outcome' => $f->outcome,
            'work_order_id' => $f->rental_work_order_id,
            'archived' => $this->isArchived($f),
        ];
    }

    /** @return array<string,mixed> */
    private function detail(RentalFaultReport $f): array
    {
        $f->load(['property', 'lease.tenants.contact', 'faultType', 'workOrder', 'reportedByContact', 'reportedByUser', 'photos', 'approvals.recordedByUser']);

        return $this->summary($f) + [
            'description' => $f->description,
            // the owner's questions, with the ids the answer route needs (POST /mobile/rental-fault-reports/{id}/owner-questions/{query}/answer)
            'owner_questions' => \App\Models\RentalOwnerQuery::query()->where('rental_fault_report_id', $f->id)->orderByDesc('asked_at')->get()->map(fn ($q) => [
                'id' => $q->id, 'question' => $q->question, 'asked_at' => $q->asked_at?->toIso8601String(),
                'answer' => $q->answer, 'answered_at' => $q->answered_at?->toIso8601String(),
            ])->values()->all(),
            'fault_type_detail' => $f->faultType ? [
                'id' => $f->faultType->id, 'name' => $f->faultType->name, 'category' => $f->faultType->category, 'urgency' => $f->faultType->urgency,
            ] : null,
            'reported_by_type' => $f->reported_by_type,
            'reported_channel' => $f->reported_channel,
            'reported_by' => $f->reportedByContact?->full_name ?: $f->reportedByUser?->name,
            'approval_route' => $f->approval_route,
            'outcome_note' => $f->outcome_note,
            'repaired_at' => $f->repaired_at?->toDateString(),
            'resolved_at' => $f->resolved_at?->toIso8601String(),
            'cancelled_at' => $f->cancelled_at?->toIso8601String(),
            'cancel_reason' => $f->cancel_reason,
            'work_order' => $f->workOrder ? [
                'id' => $f->workOrder->id, 'reference' => 'WO-' . $f->workOrder->id, 'status' => $f->workOrder->status, 'status_label' => $f->workOrder->statusLabel(),
            ] : null,
            'photos' => $f->photos->map(fn ($p) => $this->photoRow($p))->values()->all(),
            'approvals' => $f->approvals->map(fn ($a) => [
                'id' => $a->id, 'decision' => $a->decision, 'approval_route' => $a->approval_route, 'evidence_type' => $a->evidence_type,
                'evidence_text' => $a->evidence_text, 'decided_at' => ($a->decided_at ?? $a->created_at)?->toIso8601String(),
                'recorded_by' => $a->recordedByUser?->name, 'contractor_name' => $a->contractor_name, 'contractor_phone' => $a->contractor_phone,
                'agency_service_provider_id' => $a->agency_service_provider_id,
            ])->values()->all(),
            // the same progress line the agent's web screen shows, as a flat list (P1); a fault the tenant closed themselves has none
            'timeline' => $f->isTenantSelfResolved() ? [] : app(RentalFaultProgressService::class)->timeline($f, RentalFaultProgressService::AUDIENCE_AGENT),
        ];
    }

    /** @return array<string,mixed> */
    private function photoRow(RentalFaultReportPhoto $p): array
    {
        return [
            'id' => $p->id,
            'url' => self::absoluteUrl($p->displayUrl()),
            'captured_at' => $p->created_at?->toIso8601String(),
            'client_idempotency_key' => $p->client_idempotency_key,
        ];
    }
}
