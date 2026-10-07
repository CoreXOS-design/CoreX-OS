<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ResolvesPortalContact;
use App\Http\Controllers\Api\V1\Concerns\ServesPortalDocuments;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Services\Images\PropertyImageStorer;
use App\Services\Rentals\RentalFaultReportService;
use App\Services\Rentals\RentalJobCardClientViewService;
use App\Services\Rentals\RentalFaultTypeService;
use App\Services\Rentals\RentalPortalDocumentService;
use App\Services\Rentals\RentalPortalScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-portal-access.md §2/§6 — AT-445. Tenant-facing rentals
 * endpoints under /api/v1/client/rentals/*. Every method resolves the
 * caller's own Contact first (ResolvesPortalContact) and every record is
 * fetched through RentalPortalScopeService — never a bare Model::find().
 */
class ClientTenantRentalsController extends Controller
{
    use ResolvesPortalContact;
    use ServesPortalDocuments;

    public function __construct(
        private readonly RentalPortalScopeService $scope,
        private readonly RentalFaultReportService $faultReportService,
        private readonly RentalJobCardClientViewService $jobCardView,
    ) {}

    public function leases(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        return response()->json([
            'leases' => $this->scope->tenantLeases($contact)->map(fn ($lease) => $this->leaseSummary($lease))->values(),
        ]);
    }

    public function leaseShow(Request $request, int $lease): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $leaseModel = $this->scope->tenantLease($contact, $lease);
        if (!$leaseModel) {
            return response()->json(['message' => 'Lease not found.'], 404);
        }

        return response()->json(['lease' => array_merge($this->leaseSummary($leaseModel), [
            'rent_amount' => (float) $leaseModel->rental_amount,
            'deposit_amount' => (float) $leaseModel->deposit_amount,
            'start_date' => optional($leaseModel->start_date)->toDateString(),
            'end_date' => optional($leaseModel->end_date)->toDateString(),
            'is_month_to_month' => (bool) $leaseModel->is_month_to_month,
            // Landlord contact details for maintenance purposes only — name
            // only, never anything else (phone/email intentionally omitted
            // from the portal; an agent reaches the landlord off-portal).
            'landlord_names' => $leaseModel->landlordContacts()->pluck('full_name')->values(),
            'property' => [
                'id' => $leaseModel->property?->id,
                'address' => $leaseModel->property?->buildDisplayAddress(),
            ],
        ])]);
    }

    /** §19 — the tenant's Documents area: signed lease agreements, distributed inspection reports, documents the agency shared. */
    public function documents(Request $request): JsonResponse
    {
        return $this->portalDocumentList($request, RentalPortalDocumentService::ROLE_TENANT, 'client.rentals.documents.file');
    }

    public function documentFile(Request $request, int $document): \Symfony\Component\HttpFoundation\Response|JsonResponse
    {
        return $this->portalDocumentFile($request, RentalPortalDocumentService::ROLE_TENANT, $document);
    }

    public function inspections(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        return response()->json([
            'inspections' => $this->scope->tenantInspections($contact)->map(fn ($i) => [
                'id' => $i->id,
                'type' => $i->type,
                'status' => $i->status,
                'completed_at' => $i->completed_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function inventories(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        return response()->json([
            'inventories' => $this->scope->tenantInventories($contact)->map(fn ($inv) => [
                'id' => $inv->id,
                'status' => $inv->status,
                'completed_at' => $inv->completed_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** Fault-type catalogue + rendered first-aid content, for ONE of the tenant's own properties. */
    public function faultTypes(Request $request, int $property): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        /** @var Property|null $propertyModel */
        $propertyModel = $this->scope->tenantProperty($contact, $property);
        if (!$propertyModel) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        $types = RentalFaultType::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $renderer = app(RentalFaultTypeService::class);

        return response()->json([
            'fault_types' => $types->map(fn ($type) => [
                'id' => $type->id,
                'name' => $type->name,
                'category' => $type->category,
                'first_aid_steps' => $renderer->renderFirstAidSteps($type, $propertyModel),
            ])->values(),
        ]);
    }

    public function faultReports(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        return response()->json([
            'fault_reports' => $this->scope->tenantFaultReports($contact)->map(fn ($f) => $this->faultReportSummary($f))->values(),
        ]);
    }

    public function faultReportShow(Request $request, int $faultReport): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $fault = $this->scope->tenantFaultReport($contact, $faultReport);
        if (!$fault) {
            return response()->json(['message' => 'Fault report not found.'], 404);
        }

        return response()->json(['fault_report' => array_merge($this->faultReportSummary($fault), [
            'description' => $fault->description,
            'outcome' => $fault->outcome,
            'outcome_note' => $fault->outcome_note,
            // Tenant never sees quote amounts — not their spend to approve.
            // §14.29 — photos of the WORK done for this fault (crew / contractor),
            // filtered by the agency's crew_photos_visible_to_clients rule.
            'photos' => $this->jobCardView->photosPayload($this->jobCardView->photosForFaultReport($fault)),
        ])]);
    }

    /**
     * §2 — first-aid is shown first (GET faultTypes above); this is the
     * one submit action, branching on which button the tenant pressed.
     * 'first_aid_resolved' => RentalFaultReport::OUTCOME_RESOLVED_BY_FIRST_AID,
     * created and closed in one step (§4.4). 'still_a_problem' => a normal
     * report via the EXACT same RentalFaultReportService::report() an
     * agent call uses, with captured_by_user_id omitted and
     * reported_by_contact_id set to this tenant.
     */
    public function faultReportStore(Request $request, int $property): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        /** @var Property|null $propertyModel */
        $propertyModel = $this->scope->tenantProperty($contact, $property);
        if (!$propertyModel) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        $data = $request->validate([
            'rental_fault_type_id' => 'required|integer',
            'resolution' => 'required|in:first_aid_resolved,still_a_problem',
            'title' => 'required|string|max:191',
            'description' => 'nullable|string|max:5000',
            'photos' => 'nullable|array|max:10',
            'photos.*' => 'file|image|max:15360',
        ]);

        $faultType = RentalFaultType::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->where('is_active', true)
            ->find($data['rental_fault_type_id']);
        if (!$faultType) {
            return response()->json(['message' => 'Unknown fault type.'], 422);
        }

        $leaseId = $this->scope->tenantLeaseIdForProperty($contact, $property);

        $attributes = [
            'lease_id' => $leaseId,
            'rental_fault_type_id' => $faultType->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT,
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

        if ($data['resolution'] === 'first_aid_resolved') {
            $faultReport->setOutcome([
                'outcome' => RentalFaultReport::OUTCOME_RESOLVED_BY_FIRST_AID,
            ]);
        }

        return response()->json(['fault_report' => $this->faultReportSummary($faultReport->refresh())], 201);
    }

    public function workOrderShow(Request $request, int $workOrder): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $order = $this->scope->tenantWorkOrder($contact, $workOrder);
        if (!$order) {
            return response()->json(['message' => 'Work order not found.'], 404);
        }

        return response()->json(['work_order' => array_merge([
            'id' => $order->id,
            'title' => $order->title,
            'status' => $order->status,
            'completed_at' => $order->completed_at?->toIso8601String(),
            'tenant_confirmed_at' => $order->tenant_confirmed_at?->toIso8601String(),
            'tenant_confirmed_fixed' => $order->tenant_confirmed_fixed,
            // Never the quote amount — not the tenant's spend to see.
            // §14.29 — photos of the work (own + linked job card), same visibility rule.
            'photos' => $this->jobCardView->photosPayload($this->jobCardView->photosForWorkOrder($order)),
        // BUILD 3 — §17.3.5: the plain stage, who is doing it, the completion rounds and the open "is this finished?" question.
        ], app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)->payload($order, \App\Services\Rentals\RentalWorkOrderClientViewService::AUDIENCE_TENANT))]);
    }

    public function workOrderConfirm(Request $request, int $workOrder): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $order = $this->scope->tenantWorkOrder($contact, $workOrder);
        if (!$order) {
            return response()->json(['message' => 'Work order not found.'], 404);
        }

        $data = $request->validate([
            'fixed' => 'required|boolean',
            'note' => 'nullable|string|max:2000',
        ]);

        // BUILD 3 — §17.10.4: a compatibility alias. With a completion round waiting for this tenant it answers THAT round
        // (the same rules as the response link and `…/completion-response`); with none open it behaves as before — a
        // completed work order's plain fixed / not-fixed sign-off.
        $round = $this->scope->tenantCompletionRound($contact, $order->id);
        if ($round && $round->isAwaitingTenant()) {
            try {
                app(\App\Services\Rentals\RentalCompletionService::class)->respond($round, (bool) $data['fixed'], $data['note'] ?? null, [], [
                    'contact' => $contact,
                    'via' => \App\Models\RentalWorkCompletionRound::RESPONDED_PORTAL,
                    'ip' => $request->ip(),
                ]);
            } catch (\InvalidArgumentException|\LogicException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            $order = $order->fresh();
        } else {
            try {
                $order->confirmByTenant($contact, (bool) $data['fixed'], $data['note'] ?? null);
            } catch (\LogicException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response()->json(['work_order' => [
            'id' => $order->id,
            'tenant_confirmed_at' => $order->tenant_confirmed_at?->toIso8601String(),
            'tenant_confirmed_fixed' => $order->tenant_confirmed_fixed,
        ]]);
    }

    /**
     * §14.29 — job cards on this tenant's own lease(s): status, schedule,
     * completion and the photos the agency allows. Never a price.
     */
    public function jobCards(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        return response()->json([
            'job_cards' => $this->scope->tenantJobCards($contact)
                ->map(fn ($card) => $this->jobCardView->payload($card))->values(),
        ]);
    }

    public function jobCardShow(Request $request, int $jobCard): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $card = $this->scope->tenantJobCard($contact, $jobCard);
        if (!$card) {
            return response()->json(['message' => 'Job card not found.'], 404);
        }

        return response()->json(['job_card' => $this->jobCardView->payload($card)]);
    }

    /** @return array{id: int, stage_label: string}|null the fault's work order, in plain words (§17.3.5) */
    private function workOrderStageFor(RentalFaultReport $fault): ?array
    {
        if (! $fault->rental_work_order_id) {
            return null;
        }
        $order = \App\Models\RentalWorkOrder::withoutGlobalScopes()
            ->where('agency_id', $fault->agency_id)->whereNull('deleted_at')->find($fault->rental_work_order_id);
        if (! $order) {
            return null;
        }

        return [
            'id' => $order->id,
            'stage_label' => app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)
                ->stageLabel($order, \App\Services\Rentals\RentalWorkOrderClientViewService::AUDIENCE_TENANT),
        ];
    }

    private function leaseSummary($lease): array
    {
        return [
            'id' => $lease->id,
            'status' => $lease->status,
            'property_id' => $lease->property_id,
            'property_address' => $lease->property?->buildDisplayAddress(),
        ];
    }

    private function faultReportSummary(RentalFaultReport $fault): array
    {
        return [
            'id' => $fault->id,
            'title' => $fault->title,
            'status' => $fault->status,
            'reported_at' => $fault->reported_at?->toIso8601String(),
            'rental_work_order_id' => $fault->rental_work_order_id,
            // BUILD 3 — §17.3.5: the linked work order's plain stage ("Being arranged", "In progress", …), never a price.
            'work_order_stage' => $this->workOrderStageFor($fault),
        ];
    }
}
