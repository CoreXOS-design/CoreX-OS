<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesPropertyAccess;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspectionItem;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-inspections.md §45.4 item 2 (Build I-2) — "Add missing
 * standard items", the agent-triggered top-up for a room whose checklist was
 * built before the agency's template improved. Two steps on purpose: a read-
 * only preview of the difference, then the confirmed add — nothing changes
 * until the agent confirms, and nothing is ever changed in bulk.
 *
 * Its own controller (not more methods on RentalInspectionRecordingController)
 * so this build shares no lines with the property-scoping work landing in that
 * file at the same time (Build I-6a). Scoping here is the same two layers I-6a
 * puts on every property-level action: the property's own own/branch/agency
 * scope, then the rental_inspections scope ceiling.
 */
class RentalInspectionRoomChecklistController extends Controller
{
    use AuthorizesPropertyAccess;

    /** GET /corex/properties/{property}/rental-inspection-rooms/{room}/missing-standard-items — the diff, read-only. */
    public function missing(Request $request, Property $property, int $room): JsonResponse
    {
        $this->authorizeForInspections($property, forEdit: false);
        $room = $this->resolveRoom($property, $room);

        $diff = RentalInspectionItem::standardItemDiffFor($room);

        return response()->json([
            'room_id' => $room->id,
            'room_label' => $room->label,
            'missing' => $diff['missing'],
            'present' => $diff['present'],
        ]);
    }

    /** POST /corex/properties/{property}/rental-inspection-rooms/{room}/missing-standard-items — add the confirmed ones. */
    public function topUp(Request $request, Property $property, int $room): JsonResponse
    {
        $this->authorizeForInspections($property);
        $room = $this->resolveRoom($property, $room);

        $validated = $request->validate([
            'labels' => ['required', 'array', 'min:1', 'max:100'],
            'labels.*' => ['string', 'max:191'],
        ], [
            'labels.required' => 'Tick at least one item to add.',
            'labels.min' => 'Tick at least one item to add.',
        ]);

        $created = RentalInspectionItem::addMissingStandardItems($room, $validated['labels'], $request->user());

        return response()->json(['items' => $created, 'added' => count($created)]);
    }

    private function resolveRoom(Property $property, int $roomId): PropertyRoom
    {
        $room = PropertyRoom::where('id', $roomId)->where('property_id', $property->id)->first();
        abort_if(! $room, 404, 'That room could not be found on this property.');
        abort_if($room->is_retired, 422, 'This room has been retired.');

        return $room;
    }

    /** Layer 1: the property's own scope. Layer 2: a user held to their BRANCH's inspections stays in their branch. */
    private function authorizeForInspections(Property $property, bool $forEdit = true): void
    {
        $this->authorizeProperty($property, $forEdit);

        /** @var User $user */
        $user = auth()->user();
        $scope = PermissionService::getDataScope($user, 'rental_inspections');

        $allowed = match ($scope) {
            'all', 'own' => true,
            'branch' => (int) $property->branch_id === (int) $user->effectiveBranchId(),
            default => false,
        };

        abort_unless($allowed, 403);
    }
}
