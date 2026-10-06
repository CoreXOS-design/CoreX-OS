<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesPortalContact;
use App\Http\Controllers\Controller;
use App\Models\RentalWorkCompletionRound;
use App\Services\Rentals\RentalCompletionService;
use App\Services\Rentals\RentalPortalScopeService;
use App\Services\Rentals\RentalWorkOrderClientViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-work-orders.md §17.3.5 / §17.10.4 — the portal's work-order endpoints under /api/v1/client/rentals/*:
 * the tenant's work-order list, the tenant's answer to "is this finished?" (confirm, or "not complete" with a note and
 * photos), and the landlord's single work order. Every record is fetched through RentalPortalScopeService — never a
 * bare Model::find(): a work order or round that belongs to another tenant, lease, property or agency is a 404.
 * What each audience sees is decided by RentalWorkOrderClientViewService (tenant: never a price; landlord: the
 * owner-facing amount only).
 *
 * The same answer rules as the response link and the office's "Record tenant's answer" — all three call
 * RentalCompletionService::respond().
 */
class ClientRentalWorkOrdersController extends Controller
{
    use ResolvesPortalContact;

    public function __construct(
        private readonly RentalPortalScopeService $scope,
        private readonly RentalWorkOrderClientViewService $view,
        private readonly RentalCompletionService $completion,
    ) {
    }

    /** GET /api/v1/client/rentals/work-orders — the tenant's work orders, newest first. */
    public function tenantIndex(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        return response()->json([
            'work_orders' => $this->scope->tenantWorkOrders($contact)
                ->map(fn ($w) => $this->view->payload($w, RentalWorkOrderClientViewService::AUDIENCE_TENANT))->values(),
        ]);
    }

    /**
     * POST /api/v1/client/rentals/work-orders/{workOrder}/completion-response — body `fixed` (boolean), `note`,
     * `photos[]`. A "not complete" answer needs a note of at least five characters and takes up to ten photos.
     */
    public function completionResponse(Request $request, int $workOrder): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $order = $this->scope->tenantWorkOrder($contact, $workOrder);
        $round = $order ? $this->scope->tenantCompletionRound($contact, $workOrder) : null;
        if (! $order || ! $round) {
            return response()->json(['message' => 'Work order not found.'], 404);
        }

        $data = $request->validate([
            'fixed' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:' . RentalCompletionService::MAX_DISPUTE_PHOTOS],
            'photos.*' => ['file', 'image', 'max:15360'],
        ]);

        return $this->answer($order, $round, (bool) $data['fixed'], $data['note'] ?? null, $request->file('photos', []), $contact, $request);
    }

    /** GET /api/v1/client/rentals/landlord/work-orders/{workOrder} — one work order on the landlord's own property. */
    public function landlordShow(Request $request, int $workOrder): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $order = $this->scope->landlordWorkOrder($contact, $workOrder);
        if (! $order) {
            return response()->json(['message' => 'Work order not found.'], 404);
        }

        return response()->json(['work_order' => $this->view->payload($order, RentalWorkOrderClientViewService::AUDIENCE_LANDLORD)]);
    }

    /**
     * Shared by the new endpoint and the old `…/confirm` alias (ClientTenantRentalsController): one place that turns
     * the service's refusals into plain 422 messages.
     *
     * @param array<int, mixed> $photos
     */
    public function answer($order, RentalWorkCompletionRound $round, bool $fixed, ?string $note, array $photos, $contact, Request $request): JsonResponse
    {
        try {
            $this->completion->respond($round, $fixed, $note, $photos, [
                'contact' => $contact,
                'via' => RentalWorkCompletionRound::RESPONDED_PORTAL,
                'ip' => $request->ip(),
            ]);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['work_order' => $this->view->payload($order->fresh(), RentalWorkOrderClientViewService::AUDIENCE_TENANT)]);
    }
}
