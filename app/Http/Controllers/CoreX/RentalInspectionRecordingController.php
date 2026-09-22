<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionDiscrepancy;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionRoomNote;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInspectionSignature;
use App\Services\Images\PropertyImageStorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        return response()->json(RentalInspection::tabPayloadFor($property));
    }

    /** POST /corex/properties/{property}/rental-inspections/start — §0.5, the deliberate action that begins one. */
    public function start(Request $request, Property $property): JsonResponse
    {
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
     * Response shape is always {items: [...]} — a space add returns the
     * checklist rows created under the new room; a meter add returns its
     * one bare item — so the frontend has one push path for both.
     */
    public function storeItem(Request $request, Property $property): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:' . RentalInspectionItem::KIND_SPACE . ',' . RentalInspectionItem::KIND_METER],
            'label' => ['required', 'string', 'max:191'],
            'space_type' => [
                Rule::requiredIf($request->input('kind') === RentalInspectionItem::KIND_SPACE),
                'nullable', 'string', 'max:60',
                Rule::in(config('property-spaces.all_space_types', [])),
            ],
        ]);

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
        abort_if($item->property_id !== $property->id, 404);
        abort_if($item->kind !== RentalInspectionItem::KIND_SPACE, 422, 'Only a space can be given a room type.');
        abort_if($item->property_room_id !== null, 422, 'This space already has a room type.');

        $validated = $request->validate([
            'space_type' => ['required', 'string', 'max:60', Rule::in(config('property-spaces.all_space_types', []))],
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
        foreach ($facetLabels as $facetLabel) {
            $created[] = RentalInspectionItem::create([
                'agency_id' => $property->agency_id,
                'property_id' => $property->id,
                'property_room_id' => $room->id,
                'kind' => RentalInspectionItem::KIND_SPACE,
                'label' => $facetLabel,
                'space_type' => $type,
                'source' => 'manual',
                'created_by_user_id' => $byUserId,
            ])->load('room');
        }

        return $created;
    }

    /** POST /corex/properties/{property}/rental-inspection-items/{item}/retire — §3.3, never deleted, only retired. */
    public function retireItem(Request $request, Property $property, RentalInspectionItem $item): JsonResponse
    {
        abort_if($item->property_id !== $property->id, 404);

        $item->update(['is_retired' => true]);

        return response()->json(['message' => 'Item retired.']);
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
        // 2026-09-21, Johan from Retha's real paper form — the condition
        // vocabulary itself (which states exist, which need a reason) is
        // agency-configurable (RentalInspectionSetting::conditionStatesFor()),
        // never this hardcoded six-item list.
        $conditionStates = RentalInspectionSetting::conditionStatesFor($rentalInspection->agency_id);

        $validated = $request->validate([
            'rental_inspection_item_id' => ['required', 'integer', 'exists:rental_inspection_items,id'],
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
        abort_if($room->property_id !== $rentalInspection->property_id, 404);

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
        abort_if($room->property_id !== $rentalInspection->property_id, 404);

        $baselineKey = RentalInspectionSetting::baselineConditionKeyFor($rentalInspection->agency_id);

        $source = match ($rentalInspection->type) {
            RentalInspection::TYPE_IN => RentalInspectionObservation::SOURCE_IN_INSPECTION,
            RentalInspection::TYPE_OUT => RentalInspectionObservation::SOURCE_OUT_INSPECTION,
            default => RentalInspectionObservation::SOURCE_AD_HOC,
        };

        $alreadyRecordedItemIds = RentalInspectionObservation::where('rental_inspection_id', $rentalInspection->id)
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
        $baselineKey = RentalInspectionSetting::baselineConditionKeyFor($rentalInspection->agency_id);

        $source = match ($rentalInspection->type) {
            RentalInspection::TYPE_IN => RentalInspectionObservation::SOURCE_IN_INSPECTION,
            RentalInspection::TYPE_OUT => RentalInspectionObservation::SOURCE_OUT_INSPECTION,
            default => RentalInspectionObservation::SOURCE_AD_HOC,
        };

        $alreadyRecordedItemIds = RentalInspectionObservation::where('rental_inspection_id', $rentalInspection->id)
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
        abort_if($room->property_id !== $rentalInspection->property_id, 404);

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
        $validated = $request->validate(['overall_notes' => ['nullable', 'string', 'max:4000']]);

        $rentalInspection->update(['overall_notes' => $validated['overall_notes'] ?? null]);

        return response()->json($rentalInspection->fresh());
    }

    /** POST /corex/rental-inspections/{inspection}/observations/{observation}/photos — §14.5, reuses PropertyImageStorer, never a second pipeline. */
    public function storePhoto(Request $request, RentalInspection $rentalInspection, RentalInspectionObservation $observation): JsonResponse
    {
        abort_if($observation->rental_inspection_id !== $rentalInspection->id, 404);

        $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:51200',
            'client_idempotency_key' => 'nullable|uuid',
        ]);

        $clientKey = $request->input('client_idempotency_key');
        if ($clientKey) {
            $existing = RentalInspectionPhoto::where('client_idempotency_key', $clientKey)->first();
            if ($existing) {
                return response()->json($existing, 200);
            }
        }

        $url = app(PropertyImageStorer::class)->store($request->file('photo'), $rentalInspection->property_id);

        $photo = RentalInspectionPhoto::create([
            'agency_id' => $rentalInspection->agency_id,
            'rental_inspection_observation_id' => $observation->id,
            'storage_path' => $url,
            'uploaded_by_user_id' => $request->user()->id,
            'client_idempotency_key' => $clientKey,
            'file_size_bytes' => $request->file('photo')->getSize(),
        ]);

        return response()->json($photo, 201);
    }

    /** POST /corex/rental-inspections/{inspection}/discrepancies/{discrepancy}/resolve */
    public function resolveDiscrepancy(Request $request, RentalInspection $rentalInspection, RentalInspectionDiscrepancy $discrepancy): JsonResponse
    {
        abort_if($discrepancy->rental_inspection_id !== $rentalInspection->id, 404);

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
    public function storeSignature(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
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
            ])],
            'party_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'signature_image' => ['nullable', 'string'],
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
        if ($validated['disposition'] === RentalInspectionSignature::DISPOSITION_WET_INK
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

        if (!empty($validated['signature_image'])) {
            $attributes['party_signature_path'] = RentalInspectionSignature::storeCanvasImage($validated['signature_image'], $rentalInspection->property_id);
        }
        if ($request->hasFile('wet_ink_file')) {
            $attributes['wet_ink_upload_path'] = RentalInspectionSignature::storeWetInkUpload($request->file('wet_ink_file'), $rentalInspection->property_id);
        }

        try {
            $signature = RentalInspectionSignature::capture($rentalInspection, $validated['party_role'], $validated['disposition'], $attributes);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
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
    public function supersedeWetInkSignature(Request $request, RentalInspection $rentalInspection, RentalInspectionSignature $signature): JsonResponse
    {
        abort_unless((int) $signature->rental_inspection_id === (int) $rentalInspection->id, 404);

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
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($replacement, 201);
    }

    /** POST /corex/rental-inspections/{inspection}/start-awaiting-signature */
    public function startAwaitingSignature(RentalInspection $rentalInspection): JsonResponse
    {
        try {
            $rentalInspection->startAwaitingSignature();
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($rentalInspection->fresh());
    }

    /** POST /corex/rental-inspections/{inspection}/complete */
    public function complete(RentalInspection $rentalInspection): JsonResponse
    {
        try {
            $rentalInspection->markCompleted();
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($rentalInspection->fresh());
    }
}
