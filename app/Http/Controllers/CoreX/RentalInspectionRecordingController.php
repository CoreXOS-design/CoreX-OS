<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesPropertyAccess;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Models\DocumentType;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionDiscrepancy;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionRoomNote;
use App\Models\RentalInspectionScreenPreference;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInspectionSignature;
use App\Models\User;
use App\Services\Distribution\SignedDocumentDistributionService;
use App\Services\Images\PropertyImageStorer;
use App\Services\RentalInspectionPhotoAutoPairService;
use App\Support\Distribution\FileableDocumentAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * .ai/specs/rental-inspections.md §14.1/§14.2 — where an inspection is
 * actually RECORDED: adding items, recording observations, resolving
 * discrepancies, capturing signatures, uploading photos. Deliberately
 * separate from RentalInspectionController (the agency-level Read +
 * administrative-lifecycle surface, per that controller's own docblock).
 *
 * Every action here is a thin call into a model method — the same methods
 * a future mobile API controller will call (§14.2's endpoint table lists
 * the exact mobile equivalents). No business rule is decided in this
 * controller; it validates the request shape and hands off.
 */
class RentalInspectionRecordingController extends Controller
{
    use AuthorizesRentalRecordScope;
    use AuthorizesPropertyAccess;

    /**
     * rental-inspections.md §45.8 H2 — the guard for every action reached through a PROPERTY
     * rather than a bound inspection (the property tab's data, start, item/room edits, seeding,
     * photo matching). Route binding on {property} only enforces the agency boundary; without
     * this, any same-agency user holding rental_inspections.create could read or change the
     * inspection checklist of a colleague's or another branch's listing by id.
     *
     * Two layers, both required: (1) the property's own own/branch/agency scope — exactly what
     * the property page itself applies, so the tab is never reachable here when the page is not;
     * (2) the rental_inspections scope ceiling — a user held to their BRANCH's inspections cannot
     * act on another branch's property even if their properties scope is wider. `own` needs no
     * property-level check beyond (1): "own" for inspections is creator/inspector, enforced per
     * inspection wherever one is addressed (guardRentalRecordScope on every {rentalInspection}
     * action). Reads use view breadth, writes use mutation breadth (assistants), as the property
     * page does.
     */
    private function authorizePropertyForInspections(Property $property, bool $forEdit = true): void
    {
        $this->authorizeProperty($property, $forEdit);

        /** @var User $user */
        $user = auth()->user();
        $inspectionScope = \App\Services\PermissionService::getDataScope($user, 'rental_inspections');

        $allowed = match ($inspectionScope) {
            'all', 'own' => true,
            'branch' => (int) $property->branch_id === (int) $user->effectiveBranchId(),
            default => false,
        };

        abort_unless($allowed, 403);
    }

    /**
     * §45.8 H2 — photo matching writes to the property's CURRENT inspection (the chain tail —
     * assertPhotoMatchingUnlocked() already locks against it), so the actor must be allowed to
     * work on that inspection under the rental_inspections own/branch/agency scope. Only the
     * tail is guarded, deliberately: the other side of a match is the PREDECESSOR, usually a
     * colleague's finished inspection, and comparing against it is exactly what the chain is for.
     */
    private function guardPhotoMatchTail(Property $property): void
    {
        $tail = RentalInspection::chainTailFor($property);
        if ($tail) {
            $this->guardRentalRecordScope($tail, 'rental_inspections', $property->branch_id);
        }
    }

    /**
     * GET /corex/properties/{property}/rental-inspection-tab — the data
     * layer the rebuilt tab (§4) reads from: this property's items (every
     * one, including retired — §3.3, retiring never hides history) and
     * whichever in/out inspection is currently under way, each with its
     * observations, discrepancies, and signatures loaded. Read-only:
     * currentFor() never creates an inspection just because the tab was
     * opened (§0.5).
     */
    public function tabData(Request $request, Property $property): JsonResponse
    {
        $this->authorizePropertyForInspections($property, forEdit: false);
        return response()->json(RentalInspection::tabPayloadFor($property));
    }

    /**
     * POST /corex/rental-inspections/screen-preference — .ai/specs/
     * rental-inspections.md §27.7. A per-user UI preference for the
     * recording screen (photos shown/hidden, the problem filter), not
     * inspection data — no {rentalInspection} in the path, same reasoning
     * as RentalApplicationReviewController::updatePanelPreference(). Not
     * scoped to one property either: an agent's own preference follows
     * them to every property's recording screen, which is the whole point
     * of moving this off localStorage.
     */
    public function updateScreenPreference(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preference_key' => ['required', 'string', 'in:photos_visible,filter_mode,tray_tile_size'],
            'value' => ['required'],
        ]);

        $key = $validated['preference_key'];
        $value = match ($key) {
            'filter_mode' => in_array($request->input('value'), ['all', 'attention', 'unrecorded'], true) ? $request->input('value') : 'all',
            'tray_tile_size' => in_array($request->input('value'), ['small', 'large'], true) ? $request->input('value') : 'small',
            default => $request->boolean('value'),
        };

        RentalInspectionScreenPreference::setFor($request->user()->id, $key, $value);

        return response()->json(['ok' => true]);
    }

    /** POST /corex/properties/{property}/rental-inspections/start — §0.5, the deliberate action that begins one. */
    public function start(Request $request, Property $property): JsonResponse
    {
        $this->authorizePropertyForInspections($property);
        $validated = $request->validate([
            'type' => ['required', 'in:' . implode(',', [RentalInspection::TYPE_IN, RentalInspection::TYPE_OUT, RentalInspection::TYPE_AD_HOC])],
        ]);

        try {
            $inspection = RentalInspection::start($property, $validated['type'], $request->user());
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        // §15.4 — the per-tenant signing UI reads lease.tenants.contact off
        // this same-page response (tabPayloadFor()'s own eager-load covers
        // the full-reload case, but a freshly-started inspection never goes
        // through that path).
        $inspection->load('lease.tenants.contact', 'createdBy');

        return response()->json($inspection, 201);
    }

    /**
     * POST /corex/properties/{property}/rental-inspections/{rentalInspection}/next
     * — Johan's ruling, 2026-09-23: "Next inspection" from the tab's own
     * live recording surface. Same shared RentalInspection::startNext()
     * the agency-level RentalInspectionController::next() also calls — a
     * second caller, not a second implementation (§14.1). The tab's own
     * refreshInspectionData() re-fetches the canonical tabPayloadFor()
     * payload afterward (same pattern start() above already established),
     * so the response here only needs to signal success/failure, not
     * carry the new inspection's own full detail.
     */
    public function next(Request $request, Property $property, RentalInspection $rentalInspection): JsonResponse
    {
        $this->authorizePropertyForInspections($property);
        abort_if($rentalInspection->property_id !== $property->id, 404);
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $validated = $request->validate([
            'type' => ['required', 'in:' . implode(',', [RentalInspection::TYPE_OUT, RentalInspection::TYPE_AD_HOC])],
        ]);

        try {
            $next = RentalInspection::startNext($rentalInspection, $validated['type'], $request->user());
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($next, 201);
    }

    /**
     * POST /corex/properties/{property}/rental-inspection-items — Johan's
     * ruling §0.6: the agent adds items per property. AT-current (2026-09-21)
     * fix: a `kind=space` add now goes through the exact same
     * PropertyRoom + RentalInspectionSetting::roomTypeItemsFor() contract
     * RentalInspectionFormSeeder already uses (rental-property-tab.md §8) —
     * without a room type, the agency's configured checklist defaults had
     * nowhere to be called from (root cause: the manual path never carried
     * a space_type, so roomTypeItemsFor($agencyId, $spaceType) was never
     * reachable). `kind=meter` is unaffected — a meter has no room concept
     * (RentalInspectionItem's own docblock).
     *
     * `kind=item`, 2026-09-22 — Johan, property 4862: "how do I add to a
     * room, not a new room." The Space/Meter picker only ever created a
     * NEW room; there was no path at all to add one facet to a room that
     * already exists. This is a THIRD, additive request-level `kind` —
     * `RentalInspectionItem::KIND_SPACE`/`KIND_METER` (the two stored
     * values) are UNCHANGED; an `item` add still stores `kind='space'`
     * (it behaves exactly like any other facet under that room, §3.1) — it
     * targets an EXISTING `property_room_id` instead of creating a new
     * PropertyRoom, and creates exactly the one row asked for, no
     * checklist reseed. The `space`/`meter` branches below are byte-for-
     * byte unchanged from before this addition.
     *
     * Response shape is always {items: [...]} — a space add returns the
     * checklist rows created under the new room, a meter add returns its
     * one bare item, an item add returns the one new facet — one push path
     * for all three.
     */
    public function storeItem(Request $request, Property $property): JsonResponse
    {
        $this->authorizePropertyForInspections($property);
        $validated = $request->validate([
            'kind' => ['required', 'in:' . RentalInspectionItem::KIND_SPACE . ',' . RentalInspectionItem::KIND_METER . ',item'],
            'label' => ['required', 'string', 'max:191'],
            'space_type' => [
                Rule::requiredIf($request->input('kind') === RentalInspectionItem::KIND_SPACE),
                'nullable', 'string', 'max:60',
                Rule::in(RentalInspectionSetting::selectableRoomTypeKeysFor($property->agency_id)),
            ],
            'property_room_id' => [
                Rule::requiredIf($request->input('kind') === 'item'),
                'nullable', 'integer',
            ],
        ]);

        if ($validated['kind'] === 'item') {
            $room = PropertyRoom::where('id', $validated['property_room_id'])
                ->where('property_id', $property->id)
                ->first();
            abort_if(!$room, 404, 'That room could not be found on this property.');
            abort_if($room->is_retired, 422, 'This room has been retired.');

            $item = RentalInspectionItem::addToRoom($room, $validated['label'], $request->user())->load('room');

            return response()->json(['items' => [$item]]);
        }

        if ($validated['kind'] === RentalInspectionItem::KIND_METER) {
            $item = RentalInspectionItem::create([
                'agency_id' => $property->agency_id,
                'property_id' => $property->id,
                'kind' => RentalInspectionItem::KIND_METER,
                'label' => $validated['label'],
                'created_by_user_id' => $request->user()->id,
            ]);

            return response()->json(['items' => [$item]]);
        }

        $items = DB::transaction(function () use ($property, $validated, $request) {
            $room = PropertyRoom::create([
                'agency_id' => $property->agency_id,
                'property_id' => $property->id,
                'type' => $validated['space_type'],
                'label' => $validated['label'],
                'source' => 'manual',
                'sort_order' => RentalInspectionSetting::defaultRoomSortOrderFor($property->agency_id, $validated['space_type'], $validated['label']),
                'created_by_user_id' => $request->user()->id,
            ]);

            return $this->createRoomChecklist($property, $room, $validated['space_type'], $request->user()->id);
        });

        return response()->json(['items' => $items]);
    }

    /**
     * POST /corex/properties/{property}/rental-inspection-items/{item}/assign-type
     * — 2026-09-21 fix, the "do not orphan them" half of the room-type-picker
     * gap: an item created before this fix (kind=space, no property_room_id,
     * no space_type — e.g. "Bedroom 1" on property 5792) has no checklist and
     * no way to get one. This gives it a type retroactively: a real
     * PropertyRoom is created from the item's own label, the agency's
     * configured checklist is generated under it exactly like a fresh add,
     * and the legacy item is RETIRED (never deleted, §3.3) rather than
     * mutated in place — any observation history already recorded against
     * it stays exactly where it is and stays queryable (carryForwardItems()
     * / fullHistory() both explicitly include retired items), while the new
     * room's items become the live checklist going forward.
     */
    public function assignType(Request $request, Property $property, RentalInspectionItem $item): JsonResponse
    {
        $this->authorizePropertyForInspections($property);
        abort_if($item->property_id !== $property->id, 404);
        abort_if($item->kind !== RentalInspectionItem::KIND_SPACE, 422, 'Only a space can be given a room type.');
        abort_if($item->property_room_id !== null, 422, 'This space already has a room type.');

        $validated = $request->validate([
            'space_type' => ['required', 'string', 'max:60', Rule::in(RentalInspectionSetting::selectableRoomTypeKeysFor($property->agency_id))],
        ]);

        $items = DB::transaction(function () use ($property, $item, $validated, $request) {
            $room = PropertyRoom::create([
                'agency_id' => $property->agency_id,
                'property_id' => $property->id,
                'type' => $validated['space_type'],
                'label' => $item->label,
                'source' => 'manual',
                'sort_order' => RentalInspectionSetting::defaultRoomSortOrderFor($property->agency_id, $validated['space_type'], $item->label),
                'created_by_user_id' => $request->user()->id,
            ]);

            $created = $this->createRoomChecklist($property, $room, $validated['space_type'], $request->user()->id);

            $item->update(['is_retired' => true]);

            return $created;
        });

        return response()->json(['items' => $items, 'retired_item_id' => $item->id]);
    }

    /**
     * Shared by storeItem() (fresh add) and assignType() (retrofit) — the
     * one place a room's default checklist is generated from
     * RentalInspectionSetting::roomTypeItemsFor(), so the two callers can
     * never drift into two different item shapes for the same room type.
     *
     * @return array<int, RentalInspectionItem>
     */
    private function createRoomChecklist(Property $property, PropertyRoom $room, string $type, int $byUserId): array
    {
        $facetLabels = RentalInspectionSetting::roomTypeItemsFor($property->agency_id, $type);

        $created = [];
        foreach ($facetLabels as $index => $facetLabel) {
            $created[] = RentalInspectionItem::create([
                'agency_id' => $property->agency_id,
                'property_id' => $property->id,
                'property_room_id' => $room->id,
                'kind' => RentalInspectionItem::KIND_SPACE,
                'label' => $facetLabel,
                'space_type' => $type,
                'source' => 'manual',
                'sort_order' => $index,
                'created_by_user_id' => $byUserId,
            ])->load('room');
        }

        return $created;
    }

    /** POST /corex/properties/{property}/rental-inspection-items/{item}/retire — §3.3, never deleted, only retired. */
    public function retireItem(Request $request, Property $property, RentalInspectionItem $item): JsonResponse
    {
        $this->authorizePropertyForInspections($property);
        abort_if($item->property_id !== $property->id, 404);

        $item->update(['is_retired' => true]);

        return response()->json(['message' => 'Item retired.']);
    }

    /** POST /corex/properties/{property}/rental-inspection-items/{item}/restore — the reverse of retire(), never a hard delete. */
    public function restoreItem(Request $request, Property $property, RentalInspectionItem $item): JsonResponse
    {
        $this->authorizePropertyForInspections($property);
        abort_if($item->property_id !== $property->id, 404);

        $item->restoreItem();

        return response()->json($item->load('room'));
    }

    /** POST /corex/properties/{property}/rental-inspection-items/{item}/rename — label only, never touches observation history. */
    public function renameItem(Request $request, Property $property, RentalInspectionItem $item): JsonResponse
    {
        $this->authorizePropertyForInspections($property);
        abort_if($item->property_id !== $property->id, 404);

        $validated = $request->validate(['label' => ['required', 'string', 'max:191']]);

        $item->rename($validated['label']);

        return response()->json($item->load('room'));
    }

    /**
     * POST /corex/properties/{property}/rental-inspection-items/reorder —
     * mirrors reorderRooms() below exactly, one level down: the agent's own
     * full ordering of one room's items, rewritten to sort_order to match.
     * Scoped to property_room_id so one room's reorder can never touch
     * another room's items, even within the same property.
     */
    public function reorderItems(Request $request, Property $property): JsonResponse
    {
        $this->authorizePropertyForInspections($property);
        $validated = $request->validate([
            'property_room_id' => ['required', 'integer'],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'distinct'],
        ]);

        $room = PropertyRoom::where('id', $validated['property_room_id'])->where('property_id', $property->id)->first();
        abort_if(!$room, 404, 'That room could not be found on this property.');

        $items = RentalInspectionItem::where('property_room_id', $room->id)
            ->whereIn('id', $validated['item_ids'])
            ->get()->keyBy('id');
        abort_if($items->count() !== count($validated['item_ids']), 422, 'One or more items do not belong to this room.');

        foreach (array_values($validated['item_ids']) as $index => $itemId) {
            $items[$itemId]->update(['sort_order' => $index]);
        }

        return response()->json([
            'items' => RentalInspectionItem::where('property_room_id', $room->id)->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    /**
     * POST /corex/properties/{property}/rental-inspection-rooms/apply-default-order
     * — Johan, 2026-09-21, property 5792: existing rooms keep whatever
     * sort_order they already have (never silently recomputed by a deploy —
     * an agency may have deliberately ordered a property already), but he
     * needs an explicit, one-click way to bring an existing property's
     * rooms onto the agency's current walking order. Idempotent — safe to
     * call more than once, and safe to call again after the agency edits
     * its walking order in settings.
     */
    public function applyDefaultRoomOrder(Request $request, Property $property): JsonResponse
    {
        $this->authorizePropertyForInspections($property);
        $rooms = PropertyRoom::where('property_id', $property->id)->get();

        foreach ($rooms as $room) {
            $room->update([
                'sort_order' => RentalInspectionSetting::defaultRoomSortOrderFor($property->agency_id, $room->type, $room->label),
            ]);
        }

        return response()->json([
            // 2026-09-21, Johan on property 5792 — `id` is a required secondary
            // sort key, not decoration: two rooms of the same type that both
            // lack a number in their label (or share the same number) resolve
            // to the EXACT SAME sort_order from defaultRoomSortOrderFor(), and
            // MySQL does not guarantee tie-break order is stable across
            // requests. Matches the box-wide `orderBy('sort_order')->orderBy('id')`
            // convention already used everywhere else sort_order drives a query
            // (e.g. Contact.php:275, RentalInventory.php:71/77).
            'rooms' => PropertyRoom::where('property_id', $property->id)->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    /**
     * POST /corex/properties/{property}/rental-inspection-rooms/reorder —
     * Johan, 2026-09-21: "the agent must be able to reorder rooms
     * themselves and have it stick." Takes the agent's own full ordering of
     * this property's rooms and rewrites sort_order to match it exactly —
     * an explicit, persisted action on the SAME column the default-order
     * logic uses, so every consumer (roomGroups() in both views) reflects
     * it automatically with no further change on their side. A room id
     * that isn't this property's own is rejected outright rather than
     * silently ignored or allowed to move another property's data.
     */
    public function reorderRooms(Request $request, Property $property): JsonResponse
    {
        $this->authorizePropertyForInspections($property);
        $validated = $request->validate([
            'room_ids' => ['required', 'array', 'min:1'],
            'room_ids.*' => ['integer', 'distinct'],
        ]);

        $rooms = PropertyRoom::where('property_id', $property->id)->whereIn('id', $validated['room_ids'])->get()->keyBy('id');
        abort_if($rooms->count() !== count($validated['room_ids']), 422, 'One or more rooms do not belong to this property.');

        foreach (array_values($validated['room_ids']) as $index => $roomId) {
            $rooms[$roomId]->update(['sort_order' => $index]);
        }

        return response()->json([
            // 2026-09-21, Johan on property 5792 — `id` is a required secondary
            // sort key, not decoration: two rooms of the same type that both
            // lack a number in their label (or share the same number) resolve
            // to the EXACT SAME sort_order from defaultRoomSortOrderFor(), and
            // MySQL does not guarantee tie-break order is stable across
            // requests. Matches the box-wide `orderBy('sort_order')->orderBy('id')`
            // convention already used everywhere else sort_order drives a query
            // (e.g. Contact.php:275, RentalInventory.php:71/77).
            'rooms' => PropertyRoom::where('property_id', $property->id)->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    /**
     * POST /corex/properties/{property}/rental-inspection-items/seed-from-advertising
     * — Stage 2, Johan: "use the advertising details to build the
     * inspection report as a basic." One-time only — RentalInspectionFormSeeder
     * itself refuses a second call, this action just surfaces that as a 409.
     */
    public function seedFromAdvertising(Request $request, Property $property, \App\Services\Rentals\RentalInspectionFormSeeder $seeder): JsonResponse
    {
        $this->authorizePropertyForInspections($property);
        try {
            $seeder->seedFromAdvertising($property, $request->user());
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            // 2026-09-21 — without eager-loading room here, a freshly-seeded
            // item pushed straight into the client's items array has no
            // item.room to compose itemDisplayLabel() with, so it briefly
            // shows as a bare "Ceiling" instead of "Bedroom 1 — Ceiling"
            // until the next full page load re-fetches via tabPayloadFor()
            // (which already eager-loads it). Caught verifying the seed
            // button end-to-end in a real browser, not by any server-side
            // check.
            'items' => RentalInspectionItem::where('property_id', $property->id)->notRetired()->with('room')->orderBy('id')->get(),
            'rooms' => \App\Models\PropertyRoom::where('property_id', $property->id)->where('is_retired', false)->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    /**
     * POST /corex/rental-inspections/{inspection}/details — §17, the header
     * block: meter readings (free text — a body corporate property reads
     * "BODY CORP", not a number), furnished state + property type (agency-
     * configurable, same PropertySettingItem groups Property itself uses),
     * keys/remotes as count + description, and — out-inspections only —
     * the original move-in date. Every field optional per request: an agent
     * confirming just the meter readings doesn't have to resend everything
     * else.
     */
    public function updateDetails(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $validated = $request->validate([
            'electricity_meter_reading' => ['nullable', 'string', 'max:100'],
            'water_meter_reading' => ['nullable', 'string', 'max:100'],
            'furnished_status' => ['nullable', 'string', 'max:60'],
            'property_type' => ['nullable', 'string', 'max:60'],
            'keys_count' => ['nullable', 'integer', 'min:0'],
            'keys_description' => ['nullable', 'string', 'max:191'],
            'remotes_count' => ['nullable', 'integer', 'min:0'],
            'remotes_description' => ['nullable', 'string', 'max:191'],
            'move_in_date_recorded' => ['nullable', 'date'],
        ]);

        try {
            $rentalInspection->updateDetails($validated);
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json($rentalInspection->fresh());
    }

    /**
     * POST /corex/rental-inspections/{inspection}/observations — the ONE
     * path (§14.1 fix 2): RentalInspectionObservation::record() creates the
     * observation and runs discrepancy detection atomically.
     */
    public function storeObservation(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        // 2026-09-21, Johan from Retha's real paper form — the condition
        // vocabulary itself (which states exist, which need a reason) is
        // agency-configurable (RentalInspectionSetting::conditionStatesFor()),
        // never this hardcoded six-item list.
        $conditionStates = RentalInspectionSetting::conditionStatesFor($rentalInspection->agency_id);

        $validated = $request->validate([
            'rental_inspection_item_id' => ['required', 'integer', Rule::exists('rental_inspection_items', 'id')
                ->where('property_id', $rentalInspection->property_id)
                ->where('agency_id', $rentalInspection->agency_id)],
            'condition' => ['required', 'string', Rule::in(array_column($conditionStates, 'key'))],
            'notes' => ['nullable', 'string'],
            'source' => ['required', 'string', 'in:' . implode(',', [
                RentalInspectionObservation::SOURCE_IN_INSPECTION,
                RentalInspectionObservation::SOURCE_TENANT_FAULT_REPORT,
                RentalInspectionObservation::SOURCE_OUT_INSPECTION,
                RentalInspectionObservation::SOURCE_AD_HOC,
            ])],
            'client_idempotency_key' => ['nullable', 'uuid'],
        ]);

        // Audit H1 — the one carve-out: a tenant fault report is legitimately
        // filed AFTER an in-inspection completes, inside its fault-report window.
        if (! $this->isTenantFaultReportInWindow($rentalInspection, $validated['source'])
            && ($refused = $this->refuseIfNotRecordable($rentalInspection))) {
            return $refused;
        }

        // §0.3 — a state that needs a reason (per the agency's OWN
        // vocabulary) must have one on record. Good needs none; N/A needs
        // none either — Johan: "not an argument at all."
        if (RentalInspectionSetting::conditionRequiresNotesFor($rentalInspection->agency_id, $validated['condition']) && empty($validated['notes'])) {
            return response()->json(['message' => 'Notes are required for this condition.'], 422);
        }

        $observation = RentalInspectionObservation::record(array_merge($validated, [
            'agency_id' => $rentalInspection->agency_id,
            'rental_inspection_id' => $rentalInspection->id,
            'observed_by_user_id' => $request->user()->id,
        ]));

        return response()->json($observation->load('item'));
    }

    /**
     * POST /corex/rental-inspections/{inspection}/rooms/{room}/mark-na —
     * Johan, 2026-09-21, from Retha's real paper form: she strikes ENTIRE
     * rooms out with one big N/A across the table (Bedroom 3, Bedroom 4).
     * Records one N/A observation per active item in the room, through the
     * exact same atomic record() path a single-item observation uses — a
     * genuine conflict with an EARLIER observation on the same item in
     * this inspection (e.g. already graded "Ceiling: Fair") still raises a
     * real discrepancy, exactly as it should; this is a bulk convenience
     * over the one real recording path, never a second one.
     */
    public function markRoomNa(Request $request, RentalInspection $rentalInspection, PropertyRoom $room): JsonResponse
    {
        if ($refused = $this->refuseIfNotRecordable($rentalInspection)) {
            return $refused;
        }

        abort_if($room->property_id !== $rentalInspection->property_id, 404);
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $conditionStates = RentalInspectionSetting::conditionStatesFor($rentalInspection->agency_id);
        abort_unless(
            collect($conditionStates)->contains('key', RentalInspectionObservation::CONDITION_NA),
            422,
            'N/A is not a configured condition for this agency.'
        );

        $source = match ($rentalInspection->type) {
            RentalInspection::TYPE_IN => RentalInspectionObservation::SOURCE_IN_INSPECTION,
            RentalInspection::TYPE_OUT => RentalInspectionObservation::SOURCE_OUT_INSPECTION,
            default => RentalInspectionObservation::SOURCE_AD_HOC,
        };

        $items = RentalInspectionItem::where('property_room_id', $room->id)->notRetired()->get();

        $observations = $items->map(fn (RentalInspectionItem $item) => RentalInspectionObservation::record([
            'agency_id' => $rentalInspection->agency_id,
            'rental_inspection_id' => $rentalInspection->id,
            'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $request->user()->id,
            'condition' => RentalInspectionObservation::CONDITION_NA,
            'source' => $source,
        ])->load('item'));

        return response()->json(['observations' => $observations->values()]);
    }

    /**
     * POST /corex/rental-inspections/{inspection}/rooms/{room}/mark-good —
     * Inspections-tab rebuild, item 5, 2026-09-22: "every single item has
     * its own Save button... 90 clicks." One tap fills every item in this
     * room that has NO observation yet on THIS inspection to the agency's
     * configured baseline state (RentalInspectionSetting::
     * baselineConditionKeyFor() — never hardcoded to 'Good'); an item
     * already recorded this inspection is left untouched, never overwritten.
     * Same atomic record() path as a single-item observation and as
     * markRoomNa() above — a bulk convenience over the one real recording
     * path, never a second one.
     */
    public function markRoomGood(Request $request, RentalInspection $rentalInspection, PropertyRoom $room): JsonResponse
    {
        if ($refused = $this->refuseIfNotRecordable($rentalInspection)) {
            return $refused;
        }

        abort_if($room->property_id !== $rentalInspection->property_id, 404);
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $baselineKey = RentalInspectionSetting::baselineConditionKeyFor($rentalInspection->agency_id);

        $source = match ($rentalInspection->type) {
            RentalInspection::TYPE_IN => RentalInspectionObservation::SOURCE_IN_INSPECTION,
            RentalInspection::TYPE_OUT => RentalInspectionObservation::SOURCE_OUT_INSPECTION,
            default => RentalInspectionObservation::SOURCE_AD_HOC,
        };

        // AT-433, 2026-09-26 — ->recorded() so an item that only has a
        // photo-anchor observation (no real condition yet) is still filled
        // in here, not silently skipped as if it were already done.
        $alreadyRecordedItemIds = RentalInspectionObservation::recorded()
            ->where('rental_inspection_id', $rentalInspection->id)
            ->pluck('rental_inspection_item_id');

        $items = RentalInspectionItem::where('property_room_id', $room->id)
            ->notRetired()
            ->whereNotIn('id', $alreadyRecordedItemIds)
            ->get();

        $observations = $items->map(fn (RentalInspectionItem $item) => RentalInspectionObservation::record([
            'agency_id' => $rentalInspection->agency_id,
            'rental_inspection_id' => $rentalInspection->id,
            'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $request->user()->id,
            'condition' => $baselineKey,
            'source' => $source,
        ])->load('item'));

        return response()->json(['observations' => $observations->values()]);
    }

    /**
     * POST /corex/rental-inspections/{inspection}/mark-all-good — the same
     * bulk-fill as markRoomGood() above, scoped to every unrecorded item on
     * the WHOLE inspection (every room and every meter), not just one room —
     * Johan's own "one for the whole inspection" requirement, item 5.
     */
    public function markAllGood(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        if ($refused = $this->refuseIfNotRecordable($rentalInspection)) {
            return $refused;
        }
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $baselineKey = RentalInspectionSetting::baselineConditionKeyFor($rentalInspection->agency_id);

        $source = match ($rentalInspection->type) {
            RentalInspection::TYPE_IN => RentalInspectionObservation::SOURCE_IN_INSPECTION,
            RentalInspection::TYPE_OUT => RentalInspectionObservation::SOURCE_OUT_INSPECTION,
            default => RentalInspectionObservation::SOURCE_AD_HOC,
        };

        // AT-433, 2026-09-26 — same reasoning as markRoomGood() above.
        $alreadyRecordedItemIds = RentalInspectionObservation::recorded()
            ->where('rental_inspection_id', $rentalInspection->id)
            ->pluck('rental_inspection_item_id');

        $items = RentalInspectionItem::where('property_id', $rentalInspection->property_id)
            ->notRetired()
            ->whereNotIn('id', $alreadyRecordedItemIds)
            ->get();

        $observations = $items->map(fn (RentalInspectionItem $item) => RentalInspectionObservation::record([
            'agency_id' => $rentalInspection->agency_id,
            'rental_inspection_id' => $rentalInspection->id,
            'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $request->user()->id,
            'condition' => $baselineKey,
            'source' => $source,
        ])->load('item'));

        return response()->json(['observations' => $observations->values()]);
    }

    /**
     * POST /corex/rental-inspections/{inspection}/rooms/{room}/notes —
     * Johan, 2026-09-21, from Retha's real paper form: every room table
     * has its own notes box, holding evidence that belongs to the whole
     * room, not any single item. Immutable, same convention as an
     * observation (§3.3) — a correction is a NEW row, never an edit; "the
     * room's current note" is simply the latest one for this inspection.
     */
    public function storeRoomNote(Request $request, RentalInspection $rentalInspection, PropertyRoom $room): JsonResponse
    {
        if ($refused = $this->refuseIfNotRecordable($rentalInspection)) {
            return $refused;
        }

        abort_if($room->property_id !== $rentalInspection->property_id, 404);
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $validated = $request->validate(['note' => ['required', 'string', 'max:4000']]);

        $note = RentalInspectionRoomNote::create([
            'agency_id' => $rentalInspection->agency_id,
            'rental_inspection_id' => $rentalInspection->id,
            'property_room_id' => $room->id,
            'note' => $validated['note'],
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->json($note, 201);
    }

    /**
     * POST /corex/rental-inspections/{inspection}/overall-notes — Johan,
     * 2026-09-21, from Retha's real paper form: a single free-text summary
     * for the whole inspection, at the foot. Plain mutable field on the
     * inspection itself (like cancel_reason) — editable any time before
     * completion, not an append-only evidentiary history.
     */
    public function updateOverallNotes(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        if ($refused = $this->refuseIfNotRecordable($rentalInspection)) {
            return $refused;
        }
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $validated = $request->validate(['overall_notes' => ['nullable', 'string', 'max:4000']]);

        $rentalInspection->update(['overall_notes' => $validated['overall_notes'] ?? null]);

        return response()->json($rentalInspection->fresh());
    }

    /**
     * POST /corex/rental-inspections/{inspection}/observations/{observation}/photos
     * — §14.5, reuses PropertyImageStorer, never a second pipeline. Kept
     * exactly as it was (single file, one observation) for backward
     * compatibility — a future mobile caller can still use it — but the web
     * UI no longer calls it (see storePhotos() below, which the rebuilt
     * item/room/tray controls all call through instead, per §20.13). Still
     * populates the new tagging columns so a photo created here shows up
     * correctly everywhere the new columns are read.
     */
    public function storePhoto(Request $request, RentalInspection $rentalInspection, RentalInspectionObservation $observation): JsonResponse
    {
        if ($refused = $this->refuseIfNotRecordable($rentalInspection)) {
            return $refused;
        }

        abort_if($observation->rental_inspection_id !== $rentalInspection->id, 404);
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:51200',
            'client_idempotency_key' => 'nullable|uuid',
            // §45.3 — optional ISO-8601 capture time from the app. Deliberately NOT format-validated:
            // a malformed value is absorbed (ignored) by RentalInspectionPhotoCaptureTime, never a 422
            // on an evidence upload.
            'captured_at' => 'nullable',
        ]);

        $clientKey = $request->input('client_idempotency_key');
        if ($clientKey) {
            $existing = RentalInspectionPhoto::where('rental_inspection_id', $rentalInspection->id)->where('client_idempotency_key', $clientKey)->first();
            if ($existing) {
                return response()->json($existing, 200);
            }
        }

        // §45.3 — read the capture time from the ORIGINAL upload before the storer re-encodes it.
        $capture = app(\App\Services\Rentals\RentalInspectionPhotoCaptureTime::class)->resolve(
            $request->file('photo'),
            $this->scalarOrNull($request->input('captured_at')),
            ['rental_inspection_id' => $rentalInspection->id, 'user_id' => $request->user()->id],
        );

        $url = app(PropertyImageStorer::class)->store($request->file('photo'), $rentalInspection->property_id);

        $photo = RentalInspectionPhoto::create([
            'agency_id' => $rentalInspection->agency_id,
            'rental_inspection_id' => $rentalInspection->id,
            'rental_inspection_observation_id' => $observation->id,
            'property_room_id' => $observation->item?->property_room_id,
            'storage_path' => $url,
            'uploaded_by_user_id' => $request->user()->id,
            'tagged_at' => now(),
            'tagged_by_user_id' => $request->user()->id,
            'client_idempotency_key' => $clientKey,
            'file_size_bytes' => $request->file('photo')->getSize(),
            'taken_at' => $capture['taken_at'],
            'taken_at_source' => $capture['taken_at_source'],
        ]);

        return response()->json($photo, 201);
    }

    /**
     * POST /corex/rental-inspections/{inspection}/photos — §20.13, items
     * 1/2/3: ONE endpoint for every photo upload on this surface — the item
     * camera control (multi-file now, item 1), the room heading's own
     * upload control (item 2), and the whole-inspection bulk dump (item 3,
     * no tag fields sent, lands untagged in the tray). Same client-batching
     * contract cc6 already shipped for rental-inventory photos this same
     * session (up to 10 files per request — PHP's max_file_uploads=20 makes
     * a single 90-file request impossible regardless of connection) and the
     * same one this app already used for property galleries: N files in,
     * N results out, each independently idempotent via its own
     * client_idempotency_key so a retried batch never double-uploads a file
     * that actually landed.
     *
     * property_room_id / rental_inspection_observation_id / rental_inspection_
     * item_id are all optional: neither sent = untagged (tray); room only =
     * a general room shot; observation_id = filed against that exact
     * observation (the pre-AT-433 path, still used whenever the caller
     * already has a real one); item_id = filed against that item — AT-433,
     * 2026-09-26, Johan ("adding a photo uploads it. Immediately. Always"):
     * resolves (or creates, if none exists yet — see
     * currentOrPendingObservationFor()) the item's own observation on THIS
     * inspection rather than requiring the caller to have recorded a real
     * condition first. A room/item id is validated to actually belong to
     * the given/resolved room and to this inspection's own property — never
     * trusted blindly from the client.
     */
    public function storePhotos(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        if ($refused = $this->refuseIfNotRecordable($rentalInspection)) {
            return $refused;
        }
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $validated = $request->validate([
            'property_room_id' => ['nullable', 'integer', 'exists:property_rooms,id'],
            'rental_inspection_observation_id' => ['nullable', 'integer', 'exists:rental_inspection_observations,id'],
            'rental_inspection_item_id' => ['nullable', 'integer', 'exists:rental_inspection_items,id'],
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:51200'],
            'client_idempotency_keys' => ['nullable', 'array'],
            'client_idempotency_keys.*' => ['nullable', 'uuid'],
            // §45.3 — one optional ISO-8601 capture time per file, parallel to photos[] (same index
            // contract as client_idempotency_keys). Not format-validated — see storePhoto().
            'captured_at' => ['nullable', 'array'],
        ]);

        $roomId = isset($validated['property_room_id']) ? (int) $validated['property_room_id'] : null;
        $observationId = $validated['rental_inspection_observation_id'] ?? null;
        $itemId = $validated['rental_inspection_item_id'] ?? null;
        // AT-436, 2026-09-27 — returned in the response below whenever this
        // request resolved or created one, so the client can merge it into
        // its own insp.observations without a full page reload: a BRAND NEW
        // photo-anchor observation (currentOrPendingObservationFor() below)
        // has never been seen by the client before this exact request.
        $observation = null;

        if ($observationId) {
            $observation = RentalInspectionObservation::findOrFail($observationId);
            abort_if((int) $observation->rental_inspection_id !== (int) $rentalInspection->id, 404, 'That observation is not part of this inspection.');
            // The item's own room wins — a client-supplied room_id that
            // disagrees with the item's real room is never trusted.
            $roomId = $observation->item?->property_room_id;
        } elseif ($itemId) {
            $item = RentalInspectionItem::where('id', $itemId)
                ->where('property_id', $rentalInspection->property_id)
                ->firstOrFail();
            $observation = $this->currentOrPendingObservationFor($rentalInspection, $item, $request->user());
            $observationId = $observation->id;
            $roomId = $item->property_room_id;
        } elseif ($roomId) {
            abort_unless(
                PropertyRoom::where('id', $roomId)->where('property_id', $rentalInspection->property_id)->exists(),
                404,
                'That room does not belong to this inspection\'s property.'
            );
        }

        $storer = app(PropertyImageStorer::class);
        $captureTime = app(\App\Services\Rentals\RentalInspectionPhotoCaptureTime::class);
        $now = now();
        $created = [];

        foreach ($validated['photos'] as $i => $file) {
            $clientKey = $validated['client_idempotency_keys'][$i] ?? null;
            if ($clientKey) {
                $existing = RentalInspectionPhoto::where('rental_inspection_id', $rentalInspection->id)->where('client_idempotency_key', $clientKey)->first();
                if ($existing) {
                    $created[] = $existing;
                    continue;
                }
            }

            // §45.3 — before store(): the storer re-encodes the image and drops its metadata.
            $capture = $captureTime->resolve(
                $file,
                $this->scalarOrNull($validated['captured_at'][$i] ?? null),
                ['rental_inspection_id' => $rentalInspection->id, 'user_id' => $request->user()->id],
            );

            $url = $storer->store($file, $rentalInspection->property_id);

            $created[] = RentalInspectionPhoto::create([
                'agency_id' => $rentalInspection->agency_id,
                'rental_inspection_id' => $rentalInspection->id,
                'rental_inspection_observation_id' => $observationId,
                'property_room_id' => $roomId,
                'storage_path' => $url,
                'uploaded_by_user_id' => $request->user()->id,
                'tagged_at' => ($roomId || $observationId) ? $now : null,
                'tagged_by_user_id' => ($roomId || $observationId) ? $request->user()->id : null,
                'client_idempotency_key' => $clientKey,
                'file_size_bytes' => $file->getSize(),
                'taken_at' => $capture['taken_at'],
                'taken_at_source' => $capture['taken_at_source'],
            ]);
        }

        return response()->json(['photos' => $created, 'observation' => $observation], 201);
    }

    /** §45.3 — a client-supplied capture time is only ever a string; anything else is "no claim". */
    private function scalarOrNull(mixed $value): ?string
    {
        return is_string($value) ? substr($value, 0, 64) : null;
    }

    /** Audit H1 — 409 for any write against a completed / cancelled / archived inspection. */
    private function refuseIfNotRecordable(RentalInspection $rentalInspection): ?JsonResponse
    {
        try {
            $rentalInspection->assertRecordable();
        } catch (\App\Exceptions\RentalInspectionNotRecordableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return null;
    }

    /** A tenant fault report on a completed in-inspection, inside its fault-report window. */
    private function isTenantFaultReportInWindow(RentalInspection $rentalInspection, string $source): bool
    {
        return $source === RentalInspectionObservation::SOURCE_TENANT_FAULT_REPORT
            && $rentalInspection->type === RentalInspection::TYPE_IN
            && $rentalInspection->status === RentalInspection::STATUS_COMPLETED
            && ! $rentalInspection->trashed()
            && $rentalInspection->fault_report_deadline_at !== null
            && $rentalInspection->fault_report_deadline_at->isFuture();
    }

    /**
     * AT-433, 2026-09-26, Johan (property 5792, a staged photo silently lost
     * on reload — "adding a photo uploads it. Immediately. Always... no
     * staging, no hidden dependency on a separate deliberate action.").
     * Finds the item's own observation on THIS inspection — real or an
     * earlier photo-anchor row — or creates a new photo-anchor row
     * (RentalInspectionObservation::CONDITION_PENDING) so the photo always
     * has somewhere to attach, whether or not a condition has been recorded.
     *
     * Plain create(), never record(): a photo-anchor row is not an
     * assessment and must never run discrepancy detection —
     * RentalInspectionDiscrepancy::detectFor() also independently refuses a
     * pending observation on either side of a comparison (see its own
     * comment), so this is belt-and-suspenders, not the only guard.
     *
     * Known, accepted race: two near-simultaneous uploads to the SAME
     * never-before-touched item can each see "none exists yet" and each
     * create their own photo-anchor row. Harmless — RentalInspectionObservation
     * ::scopeRecorded() excludes every photo-anchor row regardless of how
     * many exist, and every "this item's photos" read already aggregates
     * across ALL of an item's observations (§20.20's itemPhotosFor()) — not
     * engineered away with a DB-level lock, since a unique index on
     * (inspection, item, condition) would also block the legitimate case of
     * re-confirming the same real condition twice.
     */
    private function currentOrPendingObservationFor(RentalInspection $rentalInspection, RentalInspectionItem $item, User $user): RentalInspectionObservation
    {
        $existing = RentalInspectionObservation::where('rental_inspection_id', $rentalInspection->id)
            ->where('rental_inspection_item_id', $item->id)
            ->latest('id')
            ->first();
        if ($existing) {
            return $existing;
        }

        $source = match ($rentalInspection->type) {
            RentalInspection::TYPE_IN => RentalInspectionObservation::SOURCE_IN_INSPECTION,
            RentalInspection::TYPE_OUT => RentalInspectionObservation::SOURCE_OUT_INSPECTION,
            default => RentalInspectionObservation::SOURCE_AD_HOC,
        };

        return RentalInspectionObservation::create([
            'agency_id' => $rentalInspection->agency_id,
            'rental_inspection_id' => $rentalInspection->id,
            'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $user->id,
            'condition' => RentalInspectionObservation::CONDITION_PENDING,
            'notes' => null,
            'source' => $source,
        ]);
    }

    /**
     * POST /corex/rental-inspections/{inspection}/photos/{photo}/tag — file
     * ONE photo (from the tray, or re-filing an already-tagged one) to a
     * room and/or a specific item. Supersedes whatever it was tagged to
     * before (RentalInspectionPhoto::tagTo() is a plain update) — never a
     * second row, so re-tagging back is the exact same call in reverse.
     */
    public function tagPhoto(Request $request, RentalInspection $rentalInspection, RentalInspectionPhoto $photo): JsonResponse
    {
        if ($refused = $this->refuseIfNotRecordable($rentalInspection)) {
            return $refused;
        }

        abort_if((int) $photo->rental_inspection_id !== (int) $rentalInspection->id, 404);
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $validated = $request->validate([
            'property_room_id' => ['nullable', 'integer', 'exists:property_rooms,id'],
            'rental_inspection_observation_id' => ['nullable', 'integer', 'exists:rental_inspection_observations,id'],
        ]);

        $roomId = $validated['property_room_id'] ?? null;
        $observationId = $validated['rental_inspection_observation_id'] ?? null;

        if ($observationId) {
            $observation = RentalInspectionObservation::findOrFail($observationId);
            abort_if((int) $observation->rental_inspection_id !== (int) $rentalInspection->id, 404, 'That observation is not part of this inspection.');
            $roomId = $observation->item?->property_room_id;
        } elseif ($roomId) {
            abort_unless(
                PropertyRoom::where('id', $roomId)->where('property_id', $rentalInspection->property_id)->exists(),
                404,
                'That room does not belong to this inspection\'s property.'
            );
        }

        $photo->tagTo($roomId, $observationId, $request->user());

        return response()->json($photo->fresh());
    }

    /**
     * POST /corex/rental-inspections/{inspection}/photos/tag-bulk — item 3:
     * the tray's own multi-select-then-drop-onto-a-room action. Every id
     * not genuinely untagged AND belonging to this inspection is silently
     * skipped rather than failing the whole batch — a stale tray selection
     * (another tab already tagged one of them) should not block filing the
     * rest.
     */
    public function tagPhotosBulk(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        if ($refused = $this->refuseIfNotRecordable($rentalInspection)) {
            return $refused;
        }
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $validated = $request->validate([
            'photo_ids' => ['required', 'array', 'min:1'],
            'photo_ids.*' => ['integer'],
            'property_room_id' => ['required', 'integer', 'exists:property_rooms,id'],
        ]);

        abort_unless(
            PropertyRoom::where('id', $validated['property_room_id'])->where('property_id', $rentalInspection->property_id)->exists(),
            404,
            'That room does not belong to this inspection\'s property.'
        );

        $photos = RentalInspectionPhoto::where('rental_inspection_id', $rentalInspection->id)
            ->whereIn('id', $validated['photo_ids'])
            ->get();

        $tagged = $photos->map(function (RentalInspectionPhoto $photo) use ($validated, $request) {
            $photo->tagTo($validated['property_room_id'], null, $request->user());

            return $photo->fresh();
        });

        return response()->json(['photos' => $tagged->values()]);
    }

    /** POST /corex/rental-inspections/{inspection}/photos/{photo}/untag — back to the tray. The exact reverse of tag/tag-bulk. */
    public function untagPhoto(Request $request, RentalInspection $rentalInspection, RentalInspectionPhoto $photo): JsonResponse
    {
        if ($refused = $this->refuseIfNotRecordable($rentalInspection)) {
            return $refused;
        }

        abort_if((int) $photo->rental_inspection_id !== (int) $rentalInspection->id, 404);
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $photo->untag($request->user());

        return response()->json($photo->fresh());
    }

    /**
     * DELETE /corex/rental-inspections/{inspection}/photos/{photo} —
     * archived (soft delete), never hard-deleted (non-negotiable #1). A
     * mistakenly-uploaded photo (duplicate, wrong property, blurry) is the
     * one thing on this surface that genuinely needs removing; the photo
     * itself (unlike an observation) is not itself evidence of a graded
     * fact, so this is a narrower exception to §3.3's original "no delete
     * path at all" reasoning, not a repeal of it.
     */
    public function archivePhoto(Request $request, RentalInspection $rentalInspection, RentalInspectionPhoto $photo): JsonResponse
    {
        if ($refused = $this->refuseIfNotRecordable($rentalInspection)) {
            return $refused;
        }

        abort_if((int) $photo->rental_inspection_id !== (int) $rentalInspection->id, 404);
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $photo->archive($request->user());

        return response()->json(['message' => 'Photo archived.']);
    }

    /**
     * POST /corex/properties/{property}/rental-inspection-photo-matches —
     * §20.16, the viewer's "match photos" control. `anchor_photo_id` is
     * whichever photo is already showing on the other side of the
     * comparison; `photo_id` is the one being brought in — it joins the
     * anchor's group (creating one if the anchor doesn't have one yet).
     * Property-scoped (not inspection-scoped, like the routes above)
     * because a match genuinely spans two different inspections on the
     * same property — the same pattern already used for items/rooms.
     */
    public function storePhotoMatch(Request $request, Property $property): JsonResponse
    {
        $this->authorizePropertyForInspections($property);
        $this->guardPhotoMatchTail($property);
        $validated = $request->validate([
            'photo_id' => ['required', 'integer', 'different:anchor_photo_id'],
            'anchor_photo_id' => ['required', 'integer'],
        ]);

        $photo = RentalInspectionPhoto::findOrFail($validated['photo_id']);
        $anchor = RentalInspectionPhoto::findOrFail($validated['anchor_photo_id']);

        abort_if((int) $photo->inspection?->property_id !== (int) $property->id, 404, 'That photo is not part of this property.');
        abort_if((int) $anchor->inspection?->property_id !== (int) $property->id, 404, 'That photo is not part of this property.');
        abort_if((int) $photo->rental_inspection_id === (int) $anchor->rental_inspection_id, 422, 'Photos on the same inspection cannot be matched to each other.');

        // §41, 2026-09-29, Johan — manual linking must respect the same
        // completed/cancelled lock the rest of this inspection's recording
        // surface already does (item-cell.blade.php's own readOnly branch
        // once the tail completes). This endpoint has no Blade rendering to
        // fall back on, so it is the one place that MUST check server-side
        // — a client that still has the modal open (stale tab, or simply
        // never reloaded) must not be able to re-pair evidence after the
        // record is done.
        $this->assertPhotoMatchingUnlocked($property, $photo, $anchor);

        try {
            $group = \App\Models\RentalInspectionPhotoMatchGroup::linkPhotos($photo, $anchor, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($group->toComparePayload(), 201);
    }

    /**
     * §41 — the one place BOTH storePhotoMatch() and destroyPhotoMatch()
     * refuse once matching should no longer be touched. Two DIFFERENT
     * checks, deliberately not symmetric:
     *
     * - CANCELLED is checked per-photo, directly off each photo's OWN
     *   inspection — cancelling an inspection voids it outright, so none
     *   of ITS OWN photos should ever be a valid match participant again,
     *   regardless of which role (predecessor or tail) they'd otherwise
     *   play. chainTailFor() cannot be used for this half: its own query
     *   structurally EXCLUDES cancelled inspections from ever being
     *   resolved as "the tail" (`where('status', '!=', CANCELLED)`), so a
     *   check routed through chainTailFor() would silently stop firing the
     *   moment the very inspection it needs to catch gets cancelled —
     *   found by a real test failure while building this (a cancelled
     *   inspection's own unmatch call returned 200, not 409, because
     *   chainTailFor() had already moved on to treating the OTHER,
     *   non-cancelled inspection as "the tail").
     * - COMPLETED is checked via chainTailFor($property) — scoped to the
     *   property's own CURRENT inspection only. The predecessor side of
     *   any pair is, by definition, always already completed (that is how
     *   it became a predecessor); checking "either photo's own inspection
     *   is completed" would make matching permanently impossible. A
     *   completed inspection is still real, valid comparison evidence
     *   (unlike cancelled, which is void) — only the property's own
     *   CURRENT cycle being done locks further linking against it.
     */
    private function assertPhotoMatchingUnlocked(Property $property, RentalInspectionPhoto ...$photos): void
    {
        foreach ($photos as $p) {
            if ($p->inspection && $p->inspection->status === RentalInspection::STATUS_CANCELLED) {
                abort(409, 'One of these photos belongs to a cancelled inspection — photos can no longer be linked or unlinked.');
            }
        }

        $tail = RentalInspection::chainTailFor($property);
        if ($tail && $tail->status === RentalInspection::STATUS_COMPLETED) {
            abort(409, 'This inspection is completed — photos can no longer be linked or unlinked.');
        }
    }

    /**
     * DELETE /corex/properties/{property}/rental-inspection-photo-matches/{member}
     * — unmatch ONE photo out of its group (the exact reverse of joining
     * it). A group with two members behaves exactly like the old pairwise
     * unmatch; a larger group just loses that one photo, the rest stay
     * together — auto-archived if that leaves one or zero members left.
     */
    public function destroyPhotoMatch(Request $request, Property $property, \App\Models\RentalInspectionPhotoMatchGroupMember $member): JsonResponse
    {
        $this->authorizePropertyForInspections($property);
        $this->guardPhotoMatchTail($property);
        abort_if((int) $member->group?->property_id !== (int) $property->id, 404);

        // §41 — same lock as storePhotoMatch() above; unlinking after the
        // record is done is just as much a retroactive edit to the
        // comparison as linking would be. Only the member's OWN photo is
        // checked for the cancelled half — the one actually being removed;
        // a larger group's other members, if any, are each still subject
        // to this exact same check the next time THEY are individually
        // touched.
        $this->assertPhotoMatchingUnlocked($property, $member->photo);

        $groupId = $member->rental_inspection_photo_match_group_id;
        $member->removeAndMaybeArchiveGroup($request->user());

        // Report the group's own state back rather than leaving the caller
        // to guess: a larger group just lost one member (return what's
        // left so it can patch its local state precisely); a two-member
        // group auto-archived (group: null — nothing left to show).
        $group = \App\Models\RentalInspectionPhotoMatchGroup::find($groupId);

        return response()->json(['message' => 'Photo unmatched.', 'group' => $group?->toComparePayload()]);
    }

    /**
     * POST /corex/properties/{property}/rental-inspection-photo-matches/auto-pair
     * — §24.5, AT-433 Part B. Runs RentalInspectionPhotoAutoPairService
     * against the property's current chain predecessor/tail pair
     * (RentalInspection::chainTailFor()/previousInspection()/
     * inferredPredecessorFor() — the exact same resolution
     * RentalInspection::tabPayloadFor() already uses for `photo_matches`).
     *
     * Always available on request, regardless of
     * RentalInspectionSetting::autoPairPhotosEnabledFor() — that setting
     * only governs whether a caller invokes this AUTOMATICALLY on first
     * view (the frontend reads it via tabPayloadFor()'s own
     * `auto_pair_photos_enabled` key); Johan's explicit "Auto-pair" button
     * (approved mockup) always works, safe to re-run after new photos are
     * added — see the service's own docblock for why it is idempotent.
     */
    public function autoPairPhotoMatches(Request $request, Property $property): JsonResponse
    {
        $this->authorizePropertyForInspections($property);
        $this->guardPhotoMatchTail($property);
        $tail = RentalInspection::chainTailFor($property);
        abort_if(! $tail, 422, 'No current inspection to auto-pair against.');

        $predecessor = $tail->previousInspection ?? RentalInspection::inferredPredecessorFor($tail);
        abort_if(! $predecessor, 422, 'No predecessor inspection to compare against yet.');

        $groups = app(RentalInspectionPhotoAutoPairService::class)->runFor($predecessor, $tail, $request->user());

        return response()->json([
            'created_count' => $groups->count(),
            'groups' => $groups->map->toComparePayload()->values(),
        ]);
    }

    /** POST /corex/rental-inspections/{inspection}/discrepancies/{discrepancy}/resolve */
    public function resolveDiscrepancy(Request $request, RentalInspection $rentalInspection, RentalInspectionDiscrepancy $discrepancy): JsonResponse
    {
        if ($refused = $this->refuseIfNotRecordable($rentalInspection)) {
            return $refused;
        }

        abort_if($discrepancy->rental_inspection_id !== $rentalInspection->id, 404);
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $validated = $request->validate([
            'accepted_observation_id' => ['required', 'integer', 'exists:rental_inspection_observations,id'],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $accepted = RentalInspectionObservation::findOrFail($validated['accepted_observation_id']);
        abort_if(!$discrepancy->observations()->where('rental_inspection_observations.id', $accepted->id)->exists(), 422, 'That observation is not one of this discrepancy\'s participants.');

        $discrepancy->resolve($accepted, $request->user(), $validated['resolution_note'] ?? null);

        return response()->json($discrepancy->fresh());
    }

    /**
     * POST /corex/rental-inspections/{inspection}/signatures
     *
     * .ai/specs/rental-inspections.md §15.10's canonical shape —
     * `party_role`/`disposition`/`party_contact_id` — what both sections'
     * rebuilt signing UI (Stages 2-3) and the future mobile API call.
     *
     * Stage 1 briefly kept a translation layer here for the OLD
     * `signer_role`/`refused_note` request shape the property tab's
     * pre-§15 out-inspection UI used to send. Stage 3 replaced that UI with
     * the same shared per-party pattern in-inspection already used since
     * Stage 2, so nothing sends the old shape any more — removed rather
     * than left as unreachable dead code.
     *
     * §15.5, Stage 4 — `rental_inspections.sign_on_behalf` gates a REFUSED
     * disposition specifically (never a signed one, never the agent's own
     * row — the agent has no refusal option at all, §15.2a). Stage 3 found
     * this permission had no caller left after the old agent_on_behalf
     * branch was removed; this is its real successor, matching what it was
     * always for — an agent asserting something ON BEHALF of a party who
     * isn't signing, which recording a refusal genuinely is.
     */
    public function storeSignature(Request $request, RentalInspection $rentalInspection, SignedDocumentDistributionService $distributionService): JsonResponse
    {
        if ($refused = $this->refuseIfNotRecordable($rentalInspection)) {
            return $refused;
        }
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $validated = $request->validate([
            'party_role' => ['required', 'string', 'in:' . implode(',', [
                RentalInspectionSignature::PARTY_TENANT,
                RentalInspectionSignature::PARTY_LANDLORD,
                RentalInspectionSignature::PARTY_AGENT,
            ])],
            'disposition' => ['required', 'string', 'in:' . implode(',', [
                RentalInspectionSignature::DISPOSITION_SIGNED,
                RentalInspectionSignature::DISPOSITION_REFUSED,
                RentalInspectionSignature::DISPOSITION_WET_INK,
                // Conductor brief 2026-09-29 — "the agent marks a party as
                // sent [for wet-ink]." A tracking marker; the actual scan
                // arrives later via supersedeWetInkSignature() below.
                RentalInspectionSignature::DISPOSITION_AWAITING_WET_INK,
            ])],
            'party_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            // Audit M5 — ~1.4M base64 chars ≈ the 1MB decoded cap enforced in storeCanvasImage().
            'signature_image' => ['nullable', 'string', 'max:1450000'],
            // §16 — a photo/scan of a signed paper page. image or pdf; 10MB
            // matches FicaController::agentUpload()'s own per-file ceiling
            // for the same class of upload (a photographed document).
            'wet_ink_file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,heic'],
            'refusal_reason_preset' => ['nullable', 'string', 'max:60'],
            'refusal_reason_note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validated['disposition'] === RentalInspectionSignature::DISPOSITION_REFUSED) {
            abort_unless($request->user()->hasPermission('rental_inspections.sign_on_behalf'), 403);
        }
        // §16 [design call] — wet-ink is not gated behind sign_on_behalf.
        // That permission exists for an agent ASSERTING something on a
        // party's behalf with no evidence but their word (§15.5's own
        // docblock). A wet-ink upload is the opposite: it arrives WITH
        // evidence (the scan itself), same as recording a signed
        // disposition — so it stays behind the base rental_inspections.create
        // permission this whole endpoint already requires (route middleware).
        // awaiting_wet_ink carries no evidence yet, but is likewise never a
        // stand-in assertion on the party's behalf — it just records that
        // the agent handed them paper, same permission level.
        if (in_array($validated['disposition'], [RentalInspectionSignature::DISPOSITION_WET_INK, RentalInspectionSignature::DISPOSITION_AWAITING_WET_INK], true)
            && $validated['party_role'] === RentalInspectionSignature::PARTY_AGENT) {
            return response()->json(['message' => 'The agent is never wet-ink — the agent is always present and signs live.'], 422);
        }

        if ($validated['party_role'] !== RentalInspectionSignature::PARTY_AGENT) {
            $attributes = [
                'party_contact_id' => $validated['party_contact_id'] ?? null,
                'refusal_reason_preset' => $validated['refusal_reason_preset'] ?? null,
                'refusal_reason_note' => $validated['refusal_reason_note'] ?? null,
                'recorded_by_user_id' => $request->user()->id,
            ];
        } else {
            $attributes = ['recorded_by_user_id' => $request->user()->id];
        }

        try {
            if (!empty($validated['signature_image'])) {
                $attributes['party_signature_path'] = RentalInspectionSignature::storeCanvasImage($validated['signature_image'], $rentalInspection->property_id);
            }
            if ($request->hasFile('wet_ink_file')) {
                $attributes['wet_ink_upload_path'] = RentalInspectionSignature::storeWetInkUpload($request->file('wet_ink_file'), $rentalInspection->property_id);
            }

            $signature = RentalInspectionSignature::capture($rentalInspection, $validated['party_role'], $validated['disposition'], $attributes);
        } catch (\App\Exceptions\RentalInspectionNotRecordableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($validated['disposition'] === RentalInspectionSignature::DISPOSITION_WET_INK && $request->hasFile('wet_ink_file')) {
            $this->fileInspectionWetInkScan($rentalInspection, $signature, $request->file('wet_ink_file'), $distributionService);
        }

        return response()->json($signature, 201);
    }

    /**
     * POST /corex/rental-inspections/{inspection}/signatures/{signature}/supersede-wet-ink
     *
     * §16 — correcting a wrong or unreadable wet-ink upload. The old row is
     * never edited or destroyed (non-negotiable #1); RentalInspectionSignature
     * ::supersedeWetInk() marks it superseded and creates the replacement.
     */
    public function supersedeWetInkSignature(Request $request, RentalInspection $rentalInspection, RentalInspectionSignature $signature, SignedDocumentDistributionService $distributionService): JsonResponse
    {
        if ($refused = $this->refuseIfNotRecordable($rentalInspection)) {
            return $refused;
        }

        abort_unless((int) $signature->rental_inspection_id === (int) $rentalInspection->id, 404);
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $validated = $request->validate([
            'wet_ink_file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,heic'],
        ]);

        try {
            $replacement = RentalInspectionSignature::supersedeWetInk(
                $signature,
                $rentalInspection,
                $validated['wet_ink_file'],
                $request->user()->id,
            );
        } catch (\App\Exceptions\RentalInspectionNotRecordableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Conductor brief 2026-09-29 — covers BOTH the "awaiting → wet_ink"
        // first-real-upload transition and a wet_ink → wet_ink correction;
        // either way the freshly-uploaded file gets filed as its own
        // Document. The superseded row's own earlier filing (if any) is
        // left exactly as it was — never removed (non-negotiable #1).
        $this->fileInspectionWetInkScan($rentalInspection, $replacement, $request->file('wet_ink_file'), $distributionService);

        return response()->json($replacement, 201);
    }

    /**
     * Conductor brief 2026-09-29 — files a party's wet-ink scan to the
     * property's Document store, via the SHARED SignedDocumentDistributionService
     * (never a bespoke second filing path). fileToProperty() only ever
     * writes a PDF, so an image upload is wrapped in a one-page PDF first;
     * an already-PDF upload is filed as its own raw bytes. Keyed on
     * (source_type, source_id) = ('rental_inspection_signature_wet_ink',
     * $signature->id) — a NEW signature id every time (the initial capture,
     * or supersedeWetInk()'s replacement row), so a re-upload files its OWN
     * new Document; the superseded row's earlier filing is untouched.
     * Wrapped in its own try/catch — a filing failure must never fail the
     * signature capture that already succeeded (same resilience pattern as
     * fileAndMaybeEmailReport()'s own distribution try/catch in complete()).
     */
    private function fileInspectionWetInkScan(
        RentalInspection $rentalInspection,
        RentalInspectionSignature $signature,
        \Illuminate\Http\UploadedFile $file,
        SignedDocumentDistributionService $distributionService,
    ): void {
        try {
            $pdfBytes = $this->wetInkScanAsPdfBytes($file, $rentalInspection->property?->buildDisplayAddress() ?? '', ucfirst($signature->party_role) . ' — wet-ink signature');

            $adapter = new FileableDocumentAdapter(
                $rentalInspection->property,
                'rental_inspection_signature_wet_ink',
                $signature->id,
            );
            $filename = "wet-ink-{$signature->party_role}-{$signature->id}.pdf";
            $document = $distributionService->fileToProperty($adapter, $pdfBytes, $filename);

            if ($document && ! $document->document_type_id) {
                $typeId = DocumentType::withTrashed()->where('slug', 'inspection_report')->value('id');
                if ($typeId) {
                    $document->forceFill(['document_type_id' => $typeId])->save();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Rental inspection wet-ink scan filing failed', [
                'inspection_id' => $rentalInspection->id,
                'signature_id' => $signature->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Conductor brief 2026-09-29 — shared by both inspections and inventory
     * (the sibling helper on RentalInventoryRecordingController is
     * byte-for-byte the same logic, kept duplicated per this module's own
     * established precedent of two separate but mirrored implementations
     * rather than a shared cross-module service — see RentalInventorySignature's
     * own docblock for why). Already-PDF passthrough; an image is wrapped
     * in the shared corex.rental-signatures.wet-ink-scan-pdf view via a
     * base64 data: URI, so DomPDF never fetches it over HTTP.
     */
    private function wetInkScanAsPdfBytes(\Illuminate\Http\UploadedFile $file, string $propertyAddress, string $partyLabel): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        if ($extension === 'pdf') {
            return file_get_contents($file->getRealPath());
        }

        $mime = $file->getMimeType() ?: 'image/jpeg';
        $dataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file->getRealPath()));

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('corex.rental-signatures.wet-ink-scan-pdf', [
            'imageDataUri' => $dataUri,
            'propertyAddress' => $propertyAddress,
            'partyLabel' => $partyLabel,
            'uploadedAt' => now()->format('d M Y, H:i'),
        ])->output();
    }

    /** POST /corex/rental-inspections/{inspection}/start-awaiting-signature */
    public function startAwaitingSignature(RentalInspection $rentalInspection): JsonResponse
    {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        try {
            $rentalInspection->startAwaitingSignature();
        } catch (\App\Exceptions\RentalInspectionItemsUngradedException $e) {
            return response()->json(['message' => $e->getMessage(), 'ungraded_items' => $e->ungradedItems], 409);
        } catch (\App\Exceptions\RentalInspectionRequiredNotesMissingException $e) {
            return response()->json(['message' => $e->getMessage(), 'missing_required_notes' => $e->missingNotes], 409);
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($rentalInspection->fresh());
    }

    /**
     * POST /corex/rental-inspections/{inspection}/complete
     *
     * §41, 2026-09-28, Johan's ruling — the moment every required party
     * has signed or been dispositioned (markCompleted()'s own guard, the
     * exact trigger named), the signed report is filed to the property
     * AND — when the agency's own auto_send_report_enabled setting is on
     * (default) — emailed automatically to every party via the SHARED
     * App\Services\Distribution\SignedDocumentDistributionService (never
     * an inspection-only mail path; see that service's own docblock —
     * Inventory wires into the same service next). Filing itself is
     * never optional; only the automatic EMAIL is gated on the setting.
     * Wrapped in its own try/catch — a distribution failure must never
     * undo or fail the completion the agent just successfully performed.
     */
    public function complete(
        RentalInspection $rentalInspection,
        \App\Services\Rentals\RentalInspectionReportPdfService $pdfService,
        \App\Services\Distribution\SignedDocumentDistributionService $distributionService,
    ): JsonResponse {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        try {
            $rentalInspection->markCompleted();
        } catch (\App\Exceptions\RentalInspectionItemsUngradedException $e) {
            return response()->json(['message' => $e->getMessage(), 'ungraded_items' => $e->ungradedItems], 409);
        } catch (\App\Exceptions\RentalInspectionRequiredNotesMissingException $e) {
            return response()->json(['message' => $e->getMessage(), 'missing_required_notes' => $e->missingNotes], 409);
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        try {
            $this->fileAndMaybeEmailReport($rentalInspection, $pdfService, $distributionService, autoOnly: true);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Rental inspection completion distribution failed', [
                'inspection_id' => $rentalInspection->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json($rentalInspection->fresh());
    }

    /**
     * POST /corex/rental-inspections/{inspection}/resend-report
     *
     * §41 — the manual path: always sends (never gated on
     * auto_send_report_enabled — that setting only governs the
     * AUTOMATIC send at completion), always logs mode='manual' with the
     * triggering user, always re-files first (a no-op if already filed —
     * fileToProperty() is idempotent).
     */
    public function resendReport(
        Request $request,
        RentalInspection $rentalInspection,
        \App\Services\Rentals\RentalInspectionReportPdfService $pdfService,
        \App\Services\Distribution\SignedDocumentDistributionService $distributionService,
    ): JsonResponse {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        if ($rentalInspection->status !== RentalInspection::STATUS_COMPLETED) {
            return response()->json(['message' => 'This inspection is not yet completed.'], 409);
        }

        $results = $this->fileAndMaybeEmailReport($rentalInspection, $pdfService, $distributionService, autoOnly: false, triggeredBy: $request->user());

        return response()->json(['results' => $results]);
    }

    /**
     * Shared by complete() (auto path) and resendReport() (manual path) so
     * the two can never drift on WHAT gets filed/emailed — only whether
     * the auto_send_report_enabled gate applies (auto path only) and
     * which mode/triggering user gets logged.
     *
     * @return array<int, array{role:string, email:string, status:string, message_id:?string, error:?string}>
     */
    private function fileAndMaybeEmailReport(
        RentalInspection $rentalInspection,
        \App\Services\Rentals\RentalInspectionReportPdfService $pdfService,
        \App\Services\Distribution\SignedDocumentDistributionService $distributionService,
        bool $autoOnly,
        ?User $triggeredBy = null,
    ): array {
        $distributionService->ensurePublicLink($rentalInspection);
        $pdf = $pdfService->generate($rentalInspection);
        $pdfBytes = $pdf->output();
        $filename = $pdfService->filenameFor($rentalInspection);

        $distributionService->fileToProperty($rentalInspection, $pdfBytes, $filename);

        if ($autoOnly && ! RentalInspectionSetting::autoSendReportEnabledFor($rentalInspection->agency_id)) {
            return [];
        }

        $pdfPath = tempnam(sys_get_temp_dir(), 'insp-report-') . '.pdf';
        file_put_contents($pdfPath, $pdfBytes);

        try {
            return $distributionService->emailParties(
                $rentalInspection,
                $pdfPath,
                $filename,
                mode: $autoOnly ? 'auto' : 'manual',
                triggeredBy: $triggeredBy,
            );
        } finally {
            @unlink($pdfPath);
        }
    }
}
