<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\AuthorizesPropertyAccess;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\RentalInventory;
use App\Models\RentalInventorySetting;
use App\Services\Rentals\RentalInventoryComparisonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mobile parity, inventories: start one on site, cancel, archive, restore, the move-out comparison (read), and the two move-out steps.
 * .ai/specs/at-508-mobile-parity-addendum.md; rule source .ai/specs/rental-inventory.md §15 (start), §8/§14 (move-out), §25 (cancel).
 *
 * NO RULE IS DECIDED HERE. Each action validates what the web action validates and calls the SAME model method
 * (RentalInventory::resolveOrStartFor / cancel / completeMoveOut / recordMoveOutSignOff, SoftDeletes delete / restore). The routes carry the
 * web's own permission (.create). Everything else the web inventory does (rooms marked empty, copy from last, photos, signatures, completion,
 * resend, buyer acceptance) is routed straight at the web controller in routes/api.php, which already answers JSON.
 */
class MobileRentalInventoryLifecycleController extends Controller
{
    use AuthorizesPropertyAccess;
    use AuthorizesRentalRecordScope;

    /** POST /mobile/properties/{property}/inventory — start (or open) the property's inventory. Two taps at once get the same one. */
    public function start(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property, true);

        $inventory = DB::transaction(function () use ($request, $property) {
            Property::withoutGlobalScopes()->whereKey($property->id)->lockForUpdate()->first();

            return RentalInventory::resolveOrStartFor($property, $request->user());
        });

        return response()->json($this->detail($request, $inventory), $inventory->wasRecentlyCreated ? 201 : 200);
    }

    /** POST /mobile/rental-inventories/{inventory}/cancel — a reason is required, as on the web. */
    public function cancel(Request $request, int $inventory): JsonResponse
    {
        $inv = $this->find($inventory);
        $v = $request->validate(['cancel_reason' => ['required', 'string', 'max:500']]);

        try {
            $inv->cancel($request->user(), $v['cancel_reason']);
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'cannot_cancel'], 409);
        }

        return response()->json($this->detail($request, $inv->fresh()));
    }

    /** DELETE /mobile/rental-inventories/{inventory} — archive (soft delete). */
    public function archive(Request $request, int $inventory): JsonResponse
    {
        $inv = $this->find($inventory);
        $inv->forceFill(['archived_by_user_id' => $request->user()->id])->save();
        $inv->delete();

        return response()->json(['ok' => true, 'id' => $inv->id, 'archived' => true]);
    }

    /** POST /mobile/rental-inventories/{inventory}/restore */
    public function restore(Request $request, int $inventory): JsonResponse
    {
        $inv = RentalInventory::withTrashed()->find($inventory);
        abort_if($inv === null, 404);
        $this->guardRentalRecordScope($inv, 'rental_inventories', $inv->property?->branch_id);

        $inv->restore();
        $inv->forceFill(['archived_by_user_id' => null])->save();

        return response()->json($this->detail($request, $inv->fresh()));
    }

    /** GET /mobile/rental-inventories/{inventory}/comparison — the move-out review: per line "at move-in / found", disposition, photos. Read-only. */
    public function comparison(Request $request, int $inventory, RentalInventoryComparisonService $service): JsonResponse
    {
        $inv = $this->find($inventory);

        if ($inv->status !== RentalInventory::STATUS_COMPLETED) {
            return response()->json(['message' => 'The move-out comparison is only available once the inventory itself is completed.', 'code' => 'not_completed'], 409);
        }
        if ($inv->lease_id === null) {
            return response()->json(['message' => 'The move-out comparison is a rental-only feature — this inventory has no lease to compare against.', 'code' => 'no_lease'], 409);
        }

        return response()->json([
            'inventory_id' => $inv->id,
            'rows' => $service->compare($inv),
            'disposition_presets' => RentalInventorySetting::dispositionPresetsFor($inv->agency_id),
        ]);
    }

    /** POST /mobile/rental-inventories/{inventory}/move-out/complete — every active line needs a finding first (the model refuses otherwise). */
    public function completeMoveOut(Request $request, int $inventory): JsonResponse
    {
        $inv = $this->find($inventory);

        try {
            $inv->completeMoveOut($request->user());
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'cannot_complete_move_out'], 409);
        }

        return response()->json($this->detail($request, $inv->fresh()));
    }

    /** POST /mobile/rental-inventories/{inventory}/move-out/sign-off — tenant / landlord / agent signed or refused, with the signed name. */
    public function signOffMoveOut(Request $request, int $inventory): JsonResponse
    {
        $inv = $this->find($inventory);
        $v = $request->validate([
            'party_role' => ['required', 'in:tenant,landlord,agent'],
            'party_contact_id' => ['nullable', 'integer'],
            'disposition' => ['required', 'in:signed,refused'],
            'signed_name' => ['required', 'string', 'max:191'],
            'refusal_reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $inv->recordMoveOutSignOff(
                $v['party_role'],
                isset($v['party_contact_id']) ? (int) $v['party_contact_id'] : null,
                $v['disposition'],
                $v['signed_name'],
                $v['refusal_reason'] ?? null,
                $request->user(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (\LogicException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'cannot_sign_off'], 409);
        }

        return response()->json($this->detail($request, $inv->fresh()));
    }

    private function find(int $id): RentalInventory
    {
        $inventory = RentalInventory::withTrashed()->find($id);
        abort_if($inventory === null, 404);
        $this->guardRentalRecordScope($inventory, 'rental_inventories', $inventory->property?->branch_id);
        if ($inventory->trashed()) {
            abort(response()->json(['message' => 'This inventory was archived. Restore it first.', 'code' => 'archived'], 410));
        }

        return $inventory;
    }

    private function detail(Request $request, RentalInventory $inventory): array
    {
        return (array) app(MobileRentalInventoryController::class)->show($request, $inventory->id)->getData(true);
    }
}
