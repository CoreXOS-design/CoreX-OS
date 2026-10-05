<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ResolvesPortalContact;
use App\Models\RentalFaultReport;
use App\Models\RentalWorkOrder;
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
 */
class ClientLandlordRentalsController extends Controller
{
    use ResolvesPortalContact;

    public function __construct(private readonly RentalPortalScopeService $scope) {}

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

        $leases = \App\Models\Lease::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->where('property_id', $propertyModel->id)
            ->orderByDesc('id')
            ->get();
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

    public function faultReports(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        return response()->json([
            'fault_reports' => $this->scope->landlordFaultReports($contact)->map(fn ($f) => [
                'id' => $f->id,
                'title' => $f->title,
                'status' => $f->status,
                'owner_approval_status' => $f->owner_approval_status,
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
                'selected_quote_amount' => optional($w->quotes()->where('is_selected', true)->first())->amount,
            ])->values(),
        ]);
    }

    public function inspections(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        return response()->json([
            'inspections' => $this->scope->landlordInspections($contact)->map(fn ($i) => [
                'id' => $i->id,
                'type' => $i->type,
                'status' => $i->status,
                'completed_at' => $i->completed_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function documents(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        return response()->json([
            'documents' => $this->scope->landlordDocuments($contact)->map(fn ($doc) => [
                'id' => $doc->id,
                'name' => $doc->original_name,
                'uploaded_at' => $doc->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** §3 — "items needing my decision": fault reports awaiting the owner-approval route, and work-order quotes over the spend limit. */
    public function decisions(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $propertyIds = $this->scope->landlordPropertyIds($contact);

        $faultReports = RentalFaultReport::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('property_id', $propertyIds)
            ->where('owner_approval_status', RentalFaultReport::APPROVAL_PENDING)
            ->get();

        $workOrders = RentalWorkOrder::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('property_id', $propertyIds)
            ->where('owner_approval_status', RentalWorkOrder::APPROVAL_PENDING)
            ->get();

        return response()->json([
            'fault_reports' => $faultReports->map(fn ($f) => [
                'id' => $f->id, 'title' => $f->title, 'kind' => 'fault_report',
            ])->values(),
            'work_orders' => $workOrders->map(fn ($w) => [
                'id' => $w->id, 'title' => $w->title, 'kind' => 'work_order',
                'selected_quote_amount' => optional($w->quotes()->where('is_selected', true)->first())->amount,
            ])->values(),
        ]);
    }

    /**
     * §3 — Approve (agency_appoints) / Decline / "I'll handle it myself"
     * (owner_handles, note required). This is the FAULT-REPORT-level
     * decision — the one that decides whether a contractor gets involved
     * at all, made before any work order exists.
     */
    public function faultReportDecision(Request $request, int $faultReport): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $propertyIds = $this->scope->landlordPropertyIds($contact);
        $fault = RentalFaultReport::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('property_id', $propertyIds)
            ->find($faultReport);
        if (!$fault) {
            return response()->json(['message' => 'Fault report not found.'], 404);
        }

        $data = $request->validate([
            'decision' => 'required|in:approve_agency_appoints,approve_owner_handles,decline',
            'note' => 'nullable|string|max:2000',
        ]);

        if ($data['decision'] === 'approve_owner_handles' && empty($data['note'])) {
            return response()->json(['message' => 'A note is required for "I\'ll handle it myself".'], 422);
        }

        [$decision, $route] = match ($data['decision']) {
            'approve_agency_appoints' => [RentalFaultReport::APPROVAL_APPROVED, RentalFaultReport::ROUTE_AGENCY_APPOINTS],
            'approve_owner_handles' => [RentalFaultReport::APPROVAL_APPROVED, RentalFaultReport::ROUTE_OWNER_HANDLES],
            'decline' => [RentalFaultReport::APPROVAL_DECLINED, null],
        };

        try {
            $fault->recordApproval($contact, [
                'decision' => $decision,
                'approval_route' => $route,
                'evidence_type' => \App\Models\RentalApproval::EVIDENCE_PORTAL,
                'evidence_text' => $data['note'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['fault_report' => [
            'id' => $fault->id,
            'status' => $fault->status,
            'owner_approval_status' => $fault->owner_approval_status,
        ]]);
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
}
