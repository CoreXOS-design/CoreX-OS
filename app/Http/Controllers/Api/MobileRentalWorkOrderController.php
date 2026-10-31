<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\FormatsMobileRentalResponses;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Property;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Models\User;
use App\Services\Rentals\RentalContractorJobService;
use App\Services\Rentals\RentalDataScope;
use App\Services\Rentals\RentalOwnerHoldService;
use App\Services\Rentals\RentalWorkOrderInvoiceService;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * AT-508 P2 - the staff mobile API for work orders (.ai/specs/rentals-faults-work-orders.md section 7.1, .ai/specs/at-508-mobile-rentals.md
 * sections 4-7). The web screens (CoreX\RentalWorkOrderController) are the reference: this class validates input and DELEGATES to the same
 * model / service methods - no second implementation of any rule. Scoping, offline and error rules are exactly those of
 * {@see MobileRentalFaultController}. Money (cost, approved amount, quotes, invoice amounts) is shown only with `rental_job_cards.view_costs`,
 * as on the job card.
 */
class MobileRentalWorkOrderController extends Controller
{
    use AuthorizesRentalRecordScope;
    use FormatsMobileRentalResponses;

    private const PER_PAGE = 25;

    private const STATUSES = [
        RentalWorkOrder::STATUS_REPORTED, RentalWorkOrder::STATUS_ORDERED, RentalWorkOrder::STATUS_IN_PROGRESS,
        RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED, RentalWorkOrder::STATUS_DISPUTED,
    ];

    // ── list / detail ────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Search `q`: property address, tenant name, contractor, title, reference ("WO-12" or "12"). Sort: newest (default) | due (booked appointment,
     * soonest first, unbooked last) | status. Filters: status, date_from/date_to (on the reported date), archived=1 (needs rental_work_orders.create).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['newest', 'due', 'status'])],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'],
            'archived' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $archived = $request->boolean('archived');
        if ($archived && ! $user->hasPermission('rental_work_orders.create')) {
            return $this->message('Archived work orders can only be listed by someone who may edit work orders.', 403);
        }

        $query = RentalWorkOrder::query()
            ->when($archived, fn ($q) => $q->onlyTrashed())
            ->visibleTo($user)
            ->with(['property', 'lease.tenants.contact', 'supplier']);
        RentalDataScope::pinOwnerToWorkingAgency($query, $user, 'rental_work_orders.agency_id');

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $like = self::likeTerm($term);
            $query->where(function ($q) use ($term, $like) {
                $q->whereHas('property', fn ($p) => $p->searchAddress($term))
                    ->orWhereHas('lease.tenants.contact', fn ($c) => $c->whereRaw("CONCAT_WS(' ', first_name, last_name) like ?", [$like]))
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $like))
                    ->orWhere('rental_work_orders.contractor_name', 'like', $like)
                    ->orWhere('rental_work_orders.title', 'like', $like);
                if (preg_match('/^(?:WO-?)?(\d+)$/i', $term, $m)) {
                    $q->orWhere('rental_work_orders.id', (int) $m[1]);
                }
            });
        }
        if (! empty($data['status'])) {
            $query->where('rental_work_orders.status', $data['status']);
        }
        $this->applyDateRange($query, $request, 'rental_work_orders.reported_at');

        match ($data['sort'] ?? 'newest') {
            'due' => $query->orderByRaw('rental_work_orders.appointment_at is null')->orderBy('rental_work_orders.appointment_at')->orderByDesc('rental_work_orders.reported_at'),
            'status' => $query->orderBy('rental_work_orders.status')->orderByDesc('rental_work_orders.reported_at'),
            default => $query->orderByDesc('rental_work_orders.reported_at')->orderByDesc('rental_work_orders.id'),
        };

        $page = $query->paginate(self::PER_PAGE, ['*'], 'page', $this->pageNumber($request));

        return $this->listResponse(
            $page->getCollection()->map(fn (RentalWorkOrder $w) => $this->summary($w))->values()->all(),
            $page,
            $archived ? 'There are no archived work orders.' : 'No work orders match. Clear the search or filters to see more.'
        );
    }

    public function show(Request $request, RentalWorkOrder $workOrder): JsonResponse
    {
        $this->guardWorkOrder($workOrder);

        return response()->json($this->detail($workOrder, $request->user()));
    }

    // ── writes ───────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** Log a work order on a property (RentalWorkOrderService::report - the call the web form makes). Online only: it carries no idempotency key. */
    public function store(Request $request, RentalWorkOrderService $service, Property $property): JsonResponse
    {
        $user = $request->user();
        $agencyId = (int) $user->effectiveAgencyId();
        abort_unless((int) $property->agency_id === $agencyId, 404);   // another agency's property is simply not there

        $validated = $request->validate([
            'lease_id' => ['nullable', Rule::exists('leases', 'id')->where('property_id', $property->id)],
            'reported_by_type' => ['nullable', Rule::in([
                RentalWorkOrder::REPORTED_BY_TENANT, RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, RentalWorkOrder::REPORTED_BY_OWNER_INSTRUCTED,
            ])],
            'reported_by_contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('agency_id', $agencyId)],
            'trade_type' => ['nullable', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:191'],
            'description' => ['required', 'string'],
            'priority' => ['nullable', 'in:low,normal,urgent'],
            'captured_at' => ['nullable', 'date'],
        ]);

        if ($this->isArchived($property)) {
            return $this->archivedResponse('property');
        }
        abort_unless(Property::query()->visibleTo($user)->whereKey($property->id)->exists(), 404);

        $attributes = [
            'lease_id' => $validated['lease_id'] ?? null,
            'reported_by_type' => $validated['reported_by_type'] ?? RentalWorkOrder::REPORTED_BY_AGENT_NOTICED,
            'reported_by_contact_id' => $validated['reported_by_contact_id'] ?? null,
            'trade_type' => $validated['trade_type'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'created_by_user_id' => $user->id,
        ];
        if (! empty($validated['priority'])) {
            $attributes['priority'] = $validated['priority'];
        }
        if ($attributes['reported_by_type'] === RentalWorkOrder::REPORTED_BY_AGENT_NOTICED) {
            $attributes['reported_by_user_id'] = $user->id;
        }
        if (! empty($validated['captured_at'])) {
            $attributes['reported_at'] = $this->capturedAt($validated['captured_at']);
        }

        $workOrder = $service->report($property, $attributes);

        return response()->json($this->detail($workOrder->fresh(), $user), 201);
    }

    /** A photo for a work order (RentalWorkOrderService::storePhoto - the call the web upload makes). Offline-safe. */
    public function storePhoto(Request $request, RentalWorkOrderService $service, RentalWorkOrder $workOrder): JsonResponse
    {
        $this->guardWorkOrder($workOrder);
        $validated = $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:51200'],
            'photo_type' => ['required', Rule::in([RentalWorkOrder::PHOTO_REPORTED, RentalWorkOrder::PHOTO_IN_PROGRESS, RentalWorkOrder::PHOTO_COMPLETED])],
            'client_idempotency_key' => ['nullable', 'uuid'],
            'captured_at' => ['nullable', 'date'],
        ]);
        if ($this->isArchived($workOrder)) {
            return $this->archivedResponse('work order');
        }

        $key = $validated['client_idempotency_key'] ?? null;
        if ($key && ($existing = RentalWorkOrderPhoto::where('client_idempotency_key', $key)->first())) {
            // the key is unique across photos: reused on ANOTHER work order it is the app's mistake, not a replay
            if ((int) $existing->rental_work_order_id !== (int) $workOrder->id) {
                return $this->message('That upload key has already been used for a different work order.', 409, 'key_reused');
            }

            return response()->json($this->photoRow($existing) + ['duplicate' => true], 200);
        }

        try {
            $photo = $service->storePhoto(
                $workOrder, $request->file('photo'), $validated['photo_type'], $request->user(), $key, false, null,
                ! empty($validated['captured_at']) ? $this->capturedAt($validated['captured_at']) : null
            );
        } catch (QueryException $e) {
            // two sends of the same queued photo racing each other: the second finds the first
            if ($key && ($existing = RentalWorkOrderPhoto::where('client_idempotency_key', $key)->where('rental_work_order_id', $workOrder->id)->first())) {
                return response()->json($this->photoRow($existing) + ['duplicate' => true], 200);
            }
            throw $e;
        }

        return response()->json($this->photoRow($photo) + ['duplicate' => false], 201);
    }

    /** Appoint a contractor (RentalWorkOrderService::assignSupplierAndDispatch - the call the web form makes; the contractor is told and sent the work order). */
    public function assignSupplier(Request $request, RentalWorkOrderService $service, RentalWorkOrder $workOrder): JsonResponse
    {
        $this->guardWorkOrder($workOrder);
        $validated = $request->validate([
            'agency_service_provider_id' => ['required', Rule::exists('agency_service_providers', 'id')->where('agency_id', $request->user()->effectiveAgencyId())],
            'trade_type' => ['nullable', 'string', 'max:60'],
        ]);
        if ($this->isArchived($workOrder)) {
            return $this->archivedResponse('work order');
        }

        try {
            $mailed = $service->assignSupplierAndDispatch($workOrder, (int) $validated['agency_service_provider_id'], $validated['trade_type'] ?? null, $request->user());
        } catch (\LogicException $e) {
            return $this->message($e->getMessage(), 422);
        }

        return response()->json($this->detail($workOrder->fresh(), $request->user()) + ['contractor_emailed' => $mailed]);
    }

    /**
     * The agent records the contractor's answer (accepted / declined) on their behalf, e.g. after a phone call
     * (RentalContractorJobService::recordResponse - the call the contractor's own link makes, with the same refusals). The history says who recorded it.
     */
    public function contractorResponse(Request $request, RentalContractorJobService $jobs, RentalWorkOrder $workOrder): JsonResponse
    {
        $this->guardWorkOrder($workOrder);
        $validated = $request->validate([
            'response' => ['required', Rule::in([RentalContractorJobService::RESPONSE_ACCEPTED, RentalContractorJobService::RESPONSE_DECLINED])],
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:response,' . RentalContractorJobService::RESPONSE_DECLINED],
        ], ['reason.required_if' => 'Please say briefly why the contractor cannot take the job.']);
        if ($this->isArchived($workOrder)) {
            return $this->archivedResponse('work order');
        }

        // the contractor's own link refuses exactly these cases
        if ($workOrder->agency_service_provider_id === null || $workOrder->assignment_type !== RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER) {
            return $this->message('There is no agency contractor on this work order to record an answer for. Appoint one first.', 422);
        }
        if (in_array($workOrder->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true)) {
            return $this->message('This job is already closed.', 422);
        }
        if ($validated['response'] === RentalContractorJobService::RESPONSE_DECLINED && app(RentalOwnerHoldService::class)->workStarted($workOrder)) {
            return $this->message('The work has already started, so the job can no longer be declined.', 422);
        }

        $jobs->recordResponse($workOrder, $validated['response'], $validated['reason'] ?? null);
        $workOrder->addNote("The contractor's answer was recorded by " . $request->user()->name . ' on the contractor\'s behalf.', $request->user());

        return response()->json($this->detail($workOrder->fresh(), $request->user()));
    }

    /** File a supplier's invoice (RentalWorkOrderInvoiceService::upload - the call the web form makes). A repeat is refused by the invoice-number rule. */
    public function storeInvoice(Request $request, RentalWorkOrderInvoiceService $invoices, RentalWorkOrder $workOrder): JsonResponse
    {
        $this->guardWorkOrder($workOrder);
        $validated = $request->validate([
            'invoice_number' => ['required', 'string', 'max:100'],
            'invoice_date' => ['required', 'date', 'after_or_equal:2000-01-01', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'agency_service_provider_id' => ['nullable', AgencyServiceProvider::maintenanceExistsRule((int) $workOrder->agency_id)],
            'supplier_name' => ['nullable', 'string', 'max:191'],
            'share_with_owner' => ['nullable', 'boolean'],
            'document' => $invoices->fileRules($workOrder->agency_id, true),
        ]);
        if ($this->isArchived($workOrder)) {
            return $this->archivedResponse('work order');
        }

        $facts = $validated;
        unset($facts['document']);
        if ($request->has('share_with_owner')) {
            $facts['share_with_owner'] = $request->boolean('share_with_owner');
        } else {
            unset($facts['share_with_owner']);
        }

        try {
            $invoice = $invoices->upload($workOrder, $facts, $request->file('document'), $request->user());
        } catch (\InvalidArgumentException $e) {
            return $this->message($e->getMessage(), 422);
        }

        $canMoney = $request->user()->hasPermission('rental_job_cards.view_costs');

        return response()->json([
            'id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'invoice_date' => $invoice->invoice_date?->toDateString(),
            'supplier_name' => $invoice->supplier_name, 'share_with_owner' => (bool) $invoice->share_with_owner,
            'document_name' => $invoice->document_original_name,
        ] + ($canMoney ? ['amount' => $invoice->amount] : []), 201);
    }

    /**
     * Close the work order (RentalWorkOrderService::completeByStaff - the call the web form makes). `pending_client_items`: how many photos / items the
     * app still holds unsent; more than 0 refuses the close (409 `items_pending`) so nothing queued is stranded behind a closed job (spec section 5.3).
     */
    public function complete(Request $request, RentalWorkOrderService $service, RentalWorkOrder $workOrder): JsonResponse
    {
        $this->guardWorkOrder($workOrder);
        [$rules, $messages] = $service->completionRules($workOrder);
        $validated = $request->validate($rules + ['pending_client_items' => ['nullable', 'integer', 'min:0']], $messages);
        if ($this->isArchived($workOrder)) {
            return $this->archivedResponse('work order');
        }
        if ((int) ($validated['pending_client_items'] ?? 0) > 0) {
            return $this->message('The app still has items waiting to be sent for this work order. Send them first, then complete it.', 409, 'items_pending');
        }
        unset($validated['pending_client_items']);

        try {
            $service->completeByStaff($workOrder, $request->user(), $validated);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return $this->message($e->getMessage(), 422);
        }

        return response()->json($this->detail($workOrder->fresh(), $request->user()));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function guardWorkOrder(RentalWorkOrder $workOrder): void
    {
        $this->guardRentalRecordScope($workOrder, 'rental_work_orders', $workOrder->property?->branch_id);
    }

    /** The phone's capture time as a moment; never in the future of the server. */
    private function capturedAt(string $value): Carbon
    {
        $at = Carbon::parse($value);

        return $at->greaterThan(now()) ? now() : $at;
    }

    /** @return array<string,mixed> */
    private function summary(RentalWorkOrder $w): array
    {
        return [
            'id' => $w->id,
            'reference' => 'WO-' . $w->id,
            'title' => $w->title,
            'status' => $w->status,
            'status_label' => $w->statusLabel(),
            'priority' => $w->priority,
            'trade_type' => $w->trade_type,
            'property' => $w->property ? ['id' => $w->property->id, 'address' => $w->property->buildDisplayAddress()] : null,
            'lease_id' => $w->lease_id,
            'tenant_names' => $w->lease?->tenantNames(),
            'assignment_type' => $w->assignment_type,
            'who' => $w->whoLabel(),
            'contractor' => $w->isOwnerContractor() ? ($w->contractor_name ?: null) : $w->supplier?->name,
            'reported_at' => $w->reported_at?->toIso8601String(),
            'appointment_at' => $w->appointment_at?->toIso8601String(),
            'fault_report_id' => $w->reported_fault_report_id,
            'archived' => $this->isArchived($w),
        ];
    }

    /** @return array<string,mixed> */
    private function detail(RentalWorkOrder $w, User $user): array
    {
        $w->load(['property', 'lease.tenants.contact', 'supplier', 'photos', 'quotes.supplier', 'invoices', 'jobCard']);
        $canMoney = $user->hasPermission('rental_job_cards.view_costs');

        $out = $this->summary($w) + [
            'description' => $w->description,
            // the ids the action routes need: owner questions (answer), variations (decision / resend), completion rounds (tenant answer), contractor sends (WhatsApp)
            'owner_questions' => \App\Models\RentalOwnerQuery::query()->where('rental_work_order_id', $w->id)->orderByDesc('asked_at')->get()->map(fn ($q) => [
                'id' => $q->id, 'question' => $q->question, 'asked_at' => $q->asked_at?->toIso8601String(),
                'answer' => $q->answer, 'answered_at' => $q->answered_at?->toIso8601String(),
            ])->values()->all(),
            'variations' => $w->variations()->orderBy('revision')->get()->map(fn ($v) => [
                'id' => $v->id, 'revision' => $v->revision, 'status' => $v->status, 'origin' => $v->origin,
            ] + ($canMoney ? ['extra_amount' => (float) $v->extra_amount, 'new_total' => (float) $v->new_total] : []))->values()->all(),
            'completion_rounds' => $w->completionRounds()->orderBy('round_no')->get()->map(fn ($r) => [
                'id' => $r->id, 'round_no' => $r->round_no, 'outcome' => $r->outcome, 'opened_at' => $r->opened_at?->toIso8601String(),
                'responded_at' => $r->responded_at?->toIso8601String(), 'window_ends_at' => $r->window_ends_at?->toIso8601String(),
            ])->values()->all(),
            'dispatches' => \App\Models\RentalJobDispatch::query()->where('rental_work_order_id', $w->id)->orderByDesc('id')->get()->map(fn ($d) => [
                'id' => $d->id, 'recipient_kind' => $d->recipient_kind, 'recipient_name' => $d->recipient_name, 'channel' => $d->channel,
                'status' => $d->status, 'sent_at' => $d->sent_at?->toIso8601String(), 'opened_at' => $d->opened_at?->toIso8601String(),
            ])->values()->all(),
            'reported_by_type' => $w->reported_by_type,
            'owner_approval_status' => $w->owner_approval_status,
            'ordered_at' => $w->ordered_at?->toIso8601String(),
            'appointment_note' => $w->appointment_note,
            'access_contact_name' => $w->access_contact_name,
            'access_contact_phone' => $w->access_contact_phone,
            'access_note' => $w->access_note,
            'completed_at' => $w->completed_at?->toIso8601String(),
            'completion_notes' => $w->completion_notes,
            'cancelled_at' => $w->cancelled_at?->toIso8601String(),
            'cancel_reason' => $w->cancel_reason,
            'paid_by' => $w->paid_by,
            'supplier' => $w->supplier ? ['id' => $w->supplier->id, 'name' => $w->supplier->name] : null,
            'contractor_phone' => $w->isOwnerContractor() ? $w->contractor_phone : null,
            'contractor_response' => $w->contractor_response,
            'contractor_response_label' => RentalContractorJobService::responseLabel($w->contractor_response),
            'contractor_decline_reason' => $w->contractor_decline_reason,
            'job_card_id' => $w->jobCard?->id,
            'photos' => $w->photos->map(fn ($p) => $this->photoRow($p) + ['photo_type' => $p->photo_type])->values()->all(),
            'quotes' => $w->quotes->map(fn ($q) => [
                'id' => $q->id, 'supplier' => $q->supplier?->name, 'quote_date' => $q->quote_date?->toDateString(),
                'is_selected' => (bool) $q->is_selected, 'declined' => $q->declined_at !== null,
            ] + ($canMoney ? ['amount' => $q->amount, 'selling_amount' => $q->selling_amount] : []))->values()->all(),
            'invoices' => $w->invoices->map(fn ($i) => [
                'id' => $i->id, 'invoice_number' => $i->invoice_number, 'invoice_date' => $i->invoice_date?->toDateString(),
                'supplier_name' => $i->supplier_name, 'share_with_owner' => (bool) $i->share_with_owner, 'document_name' => $i->document_original_name,
            ] + ($canMoney ? ['amount' => $i->amount] : []))->values()->all(),
        ];
        if ($canMoney) {
            $out['cost_amount'] = $w->cost_amount;
            $out['approved_amount'] = $w->approved_amount;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function photoRow(RentalWorkOrderPhoto $p): array
    {
        return [
            'id' => $p->id,
            'url' => self::absoluteUrl($p->displayUrl()),
            'photo_type' => $p->photo_type,
            'captured_at' => $p->created_at?->toIso8601String(),
            'client_idempotency_key' => $p->client_idempotency_key,
        ];
    }
}
