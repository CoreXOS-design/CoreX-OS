<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesPortalContact;
use App\Http\Controllers\Controller;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalPortalScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AT-508 (P1) — "waiting for you" for a tenant / an owner: the count for the app's badge plus the items, from the SAME scope service the
 * portal's lists use (so the badge can never disagree with the list). .ai/specs/at-508-mobile-rentals.md §4.
 *   tenant  : finished work waiting for their check.
 *   owner   : repairs, quotes and extra costs waiting for their decision (the same three lists as GET landlord/decisions).
 */
class ClientRentalWaitingController extends Controller
{
    use ResolvesPortalContact;

    public function __construct(private readonly RentalPortalScopeService $scope) {}

    public function tenant(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $orders = RentalWorkOrder::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)->whereNull('deleted_at')
            ->whereIn('lease_id', $this->scope->tenantActiveLeaseIds($contact))
            ->get()->keyBy('id');

        $rounds = $orders->isEmpty() ? collect() : RentalWorkCompletionRound::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('rental_work_order_id', $orders->keys())
            ->where('outcome', RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT)
            ->orderBy('created_at')->get();

        $items = $rounds->map(fn ($r) => [
            'kind' => 'work_check', 'work_order_id' => $r->rental_work_order_id,
            'title' => $orders[$r->rental_work_order_id]->title,
            'waiting_since' => $r->created_at?->toIso8601String(),
        ])->values();

        return response()->json(['waiting_count' => $items->count(), 'items' => $items]);
    }

    public function landlord(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $faults = $this->scope->landlordPendingFaultReports($contact)->map(fn ($f) => [
            'kind' => 'fault_report', 'id' => $f->id, 'title' => $f->ownerVersion()['title'], 'waiting_since' => $f->sent_to_owner_at?->toIso8601String(),
        ]);
        $orders = $this->scope->landlordPendingWorkOrders($contact)->map(fn ($w) => [
            'kind' => 'work_order', 'id' => $w->id, 'title' => $w->title, 'waiting_since' => $w->created_at?->toIso8601String(),
            'emergency' => $w->approval_basis === RentalWorkOrder::BASIS_EMERGENCY,
        ]);
        $variations = $this->scope->landlordPendingVariations($contact)->map(fn ($v) => [
            'kind' => 'variation', 'id' => $v->id, 'work_order_id' => $v->rental_work_order_id, 'title' => RentalWorkOrder::withoutGlobalScopes()->whereKey($v->rental_work_order_id)->value('title'),
            'waiting_since' => $v->created_at?->toIso8601String(),
        ]);

        $items = $faults->concat($orders)->concat($variations)->sortBy('waiting_since')->values();

        return response()->json(['waiting_count' => $items->count(), 'items' => $items]);
    }
}
