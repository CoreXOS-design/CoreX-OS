<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ResolvesPortalContact;
use App\Http\Controllers\Api\V1\Concerns\ServesPortalDocuments;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Services\Images\PrivateImageService;
use App\Services\Rentals\RentalFaultReportService;
use App\Services\Rentals\RentalJobCardClientViewService;
use App\Services\Rentals\RentalFaultTypeService;
use App\Services\Rentals\RentalPortalDocumentService;
use App\Services\Rentals\RentalPortalOverviewService;
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
        private readonly RentalPortalOverviewService $overview,
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

        // §20 — only inspections that have been sent, plus booked future dates (type + date, no content). The scope service
        // applies the rule, so the web page and the mobile app read the same list. Old keys kept; `date` / `time` / `when` added.
        return response()->json([
            'inspections' => $this->scope->tenantInspections($contact)
                ->map(fn ($i) => collect($this->overview->inspectionRow($i))->except('date_sort')->all())->values(),
        ]);
    }

    /** §20 — the tenant's portal home: who to call, the inspection dates, and where the lease stands, one block per property. */
    public function overview(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        return response()->json($this->overview->forTenant($contact));
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

        $view = app(\App\Services\Rentals\RentalFaultTypePortalView::class);

        return response()->json([
            // §22 — steps for THIS property + urgency + the agency's documents / video links + the valve and DB-board photos.
            'fault_types' => $view->payload($types, $propertyModel),
            'limits' => $view->limits((int) $contact->agency_id),
        ]);
    }

    /** §22 — the agency's logo and name for the portal header (the signed-in person's own agency). */
    public function branding(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $b = \App\Models\Agency::publicBrandingFor((int) $contact->agency_id);

        // Small copy for the header (App\Support\PortalLogo): the original can be tens of megapixels and froze real browsers.
        return response()->json(['branding' => ['name' => $b['name'], 'logo_url' => $b['logoUrl'] ? (\App\Support\PortalLogo::urlFor((int) $contact->agency_id) ?? $b['logoUrl']) : null]]);
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
            // The agent's outcome 'owner_declined' carries the owner's decision word: the tenant reads the same neutral 'not_approved'.
            'outcome' => $fault->outcome === RentalFaultReport::OUTCOME_OWNER_DECLINED ? 'not_approved' : $fault->outcome,
            // An agent's note on a declined fault can carry the owner's reason or internal remarks: the tenant gets the neutral progress line instead.
            'outcome_note' => ($fault->outcome === RentalFaultReport::OUTCOME_OWNER_DECLINED || $fault->status === RentalFaultReport::STATUS_DECLINED) ? null : $fault->outcome_note,
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
            // AT-452 - "Other - not on the list": no catalogue type, a required description, no self-checks, and it can never be "That fixed it"
            'other_type' => 'nullable|boolean',
            'rental_fault_type_id' => 'required_without:other_type|nullable|integer',
            'resolution' => 'required|in:first_aid_resolved,still_a_problem',
            'follows_fault_report_id' => 'nullable|integer',
            'title' => 'required_without:other_type|nullable|string|max:191',
            'description' => 'required_if:other_type,1,true|nullable|string|max:5000',
            'photos' => 'nullable|array|max:10',
            'photos.*' => 'file|image|max:15360',
            // section 22.3 - what the tenant ticked: JSON [{text, ticked}] (a snapshot of the steps they were shown)
            'self_checks' => 'nullable|string|max:20000',
        ]);

        $isOther = $request->boolean('other_type');
        $faultType = null;
        if ($isOther) {
            if (trim((string) ($data['description'] ?? '')) === '') {
                return response()->json(['message' => 'Tell us what needs attention.'], 422);
            }
            if ($data['resolution'] !== 'still_a_problem') {
                return response()->json(['message' => 'Unknown fault type.'], 422);
            }
        } else {
            $faultType = RentalFaultType::withoutGlobalScopes()
                ->where('agency_id', $contact->agency_id)
                ->where('is_active', true)
                ->find($data['rental_fault_type_id']);
            if (!$faultType) {
                return response()->json(['message' => 'Unknown fault type.'], 422);
            }
        }

        $leaseId = $this->scope->tenantLeaseIdForProperty($contact, $property);

        $attributes = [
            'lease_id' => $leaseId,
            'rental_fault_type_id' => $faultType?->id,
            'fault_type_other' => $isOther,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT,
            'reported_by_contact_id' => $contact->id,
            'reported_channel' => RentalFaultReport::CHANNEL_APP,
            // an "Other" report has no title field on the form: the title is the start of what the tenant wrote
            'title' => $isOther ? 'Other: ' . \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) $data['description']))), 90, '...') : $data['title'],
            'description' => $data['description'] ?? '',
            'tenant_self_checks' => $isOther ? null : $this->selfChecksFrom($data['self_checks'] ?? null),
        ];
        // "Still a problem? Report it again" on a fault the tenant fixed themselves: the new report is linked back to it (their own, on this property).
        if (! empty($data['follows_fault_report_id'])) {
            $follows = $this->scope->tenantFaultReport($contact, (int) $data['follows_fault_report_id']);
            if ($follows && $follows->isTenantSelfResolved() && (int) $follows->property_id === (int) $property) {
                $attributes['follows_fault_report_id'] = $follows->id;
            }
        }

        // AT-452 round 2 (F) - "That fixed it": logged for the agent to see, but born closed - no alert, no queue, no owner step.
        $faultReport = $data['resolution'] === 'first_aid_resolved'
            ? $this->faultReportService->reportSelfResolved($propertyModel, $attributes)
            : $this->faultReportService->report($propertyModel, $attributes);

        foreach ($request->file('photos', []) as $photo) {
            // D14 - a picture a tenant / owner sends is kept on the PRIVATE disk (stored as a `private:` ref), never at a public /storage address.
            $path = app(PrivateImageService::class)->storeUpload($photo, $propertyModel->id);
            $faultReport->photos()->create([
                'agency_id' => $contact->agency_id,
                'storage_path' => $path,
                'client_idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
                'file_size_bytes' => $photo->getSize(),
            ]);
        }

        return response()->json(['fault_report' => $this->faultReportSummary($faultReport->refresh())], 201);
    }

    /** Section 22.3 - the tenant's ticks, cleaned: at most 30 steps, plain text, ticked true/false. Anything that is not that shape is dropped. */
    private function selfChecksFrom(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        $rows = json_decode($json, true);
        if (! is_array($rows)) {
            return null;
        }
        $out = [];
        foreach (array_slice($rows, 0, 30) as $r) {
            $text = is_array($r) ? trim(strip_tags((string) ($r['text'] ?? ''))) : '';
            if ($text !== '') {
                $out[] = ['text' => mb_substr($text, 0, 500), 'ticked' => (bool) ($r['ticked'] ?? false)];
            }
        }

        return $out === [] ? null : $out;
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

        // A write: only on a lease still in force (audit 2026-10-19 D17).
        $order = $this->scope->tenantActionableWorkOrder($contact, $workOrder);
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

    /** @return array<string, mixed>|null the fault's work order summary, in plain words (§17.3.5, W2) */
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

        // W2 (8 Oct 2026): the work order beside its fault - stage, who is doing it, the appointment. (`id` and
        // `stage_label` stay for older consumers.)
        return app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)
            ->summary($order, \App\Services\Rentals\RentalWorkOrderClientViewService::AUDIENCE_TENANT);
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

    /** The fault's status as a TENANT may read it: the owner's decision is reduced to what the tenant is told (approved / not approved). */
    private function tenantFaultStatus(RentalFaultReport $fault): string
    {
        return match ($fault->status) {
            RentalFaultReport::STATUS_DECLINED => 'not_approved',
            RentalFaultReport::STATUS_OWNER_HANDLING => RentalFaultReport::STATUS_APPROVED,
            default => (string) $fault->status,
        };
    }

    private function faultReportSummary(RentalFaultReport $fault): array
    {
        return [
            'id' => $fault->id,
            'title' => $fault->title,
            // NEVER the owner's decision word: a declined fault reads as the neutral 'not_approved' (the progress line's own key), an owner
            // who handles the repair themselves as plain 'approved'. The reason and the word 'declined' stay with the owner and the office.
            'status' => $this->tenantFaultStatus($fault),
            'reported_at' => $fault->reported_at?->toIso8601String(),
            'rental_work_order_id' => $fault->rental_work_order_id,
            // AT-452 round 2 (F) - the tenant closed it themselves: the page shows ONE quiet line under "Resolved", never the fault's timeline.
            'self_resolved' => $fault->isTenantSelfResolved(),
            // AT-452 round 2 (G) - closed faults are ONE collapsed line ("<type> - reported <date> - <end state>"); open ones stay expanded.
            'closed' => $fault->isClosedState(),
            'end_state' => $fault->endStateFor('tenant'),
            'resolved_at' => $fault->resolved_at?->toIso8601String(),
            'fault_type_id' => $fault->rental_fault_type_id,
            'fault_type_name' => $fault->typeName(),
            // AT-452 - the tenant's OWN photos (what they sent), opened in the shared viewer; the agent's marks / notes ride along only when the agency allows it.
            'reported_photos' => app(\App\Services\Rentals\RentalFaultPhotoAnnotationService::class)->payload($fault, app(\App\Services\Rentals\RentalFaultPhotoAnnotationService::class)->tenantPhotos($fault), \App\Services\Rentals\RentalFaultPhotoAnnotationService::AUDIENCE_TENANT),
            // BUILD 3 — §17.3.5: the linked work order's plain stage ("Being arranged", "In progress", …), never a price.
            'work_order_stage' => $this->workOrderStageFor($fault),
            // section 22.13.4 - the "Who pays" ruling in the tenant's words: factual, never the agent's notes or evidence
            'payer_ruling' => app(\App\Services\Rentals\RentalWhoPaysService::class)->portalPayload($fault, 'tenant'),
            // Johan, 8 Oct 2026: where this fault is, step by step, fault AND work order in one line (derived from the real records;
            // the words are data: config/rental-fault-progress.php). Never the owner's reason or the agent's notes.
            'progress' => $fault->isTenantSelfResolved() ? null : app(\App\Services\Rentals\RentalFaultProgressService::class)->forFault($fault, \App\Services\Rentals\RentalFaultProgressService::AUDIENCE_TENANT),
        ];
    }
}
