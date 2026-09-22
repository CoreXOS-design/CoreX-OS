<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionDiscrepancy;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInspectionSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Stage 3 (recording) verification for .ai/specs/rental-inspections.md §14.
 * RentalInspectionRecordingController is deliberately thin — every test here
 * is really proving the controller calls the right model method with the
 * right data, not re-testing the model logic itself (already covered by
 * RentalInspectionDataModelTest/RentalInspectionWorkflowTest).
 */
final class RentalInspectionRecordingControllerTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'RI Recording Agency', 'slug' => 'ri-recording-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'RI Recording Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12000, 'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeItem(): RentalInspectionItem
    {
        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Bedroom 1', 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeInspection(string $type = RentalInspection::TYPE_IN): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => $type,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    /** One tiny valid base64 PNG, reused wherever a test needs a real (if trivial) signature image. */
    private const TEST_SIGNATURE_IMAGE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private function makeTenant(): \App\Models\Contact
    {
        $contact = \App\Models\Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Thabo', 'last_name' => 'Tenant', 'email' => uniqid() . '@example.test',
        ]);
        \App\Models\LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);

        return $contact;
    }

    // ── Items ────────────────────────────────────────────────────────

    public function test_agent_can_add_a_meter_item_to_the_property(): void
    {
        $this->postJson(route('corex.properties.rental-inspection-items.store', $this->property), [
            'kind' => RentalInspectionItem::KIND_METER, 'label' => 'Water meter',
        ])->assertOk();

        $this->assertDatabaseHas('rental_inspection_items', [
            'property_id' => $this->property->id, 'label' => 'Water meter', 'agency_id' => $this->agency->id,
        ]);
        $this->assertSame(0, PropertyRoom::where('property_id', $this->property->id)->count());
    }

    /**
     * AT-current (2026-09-21) — root cause: this manual add path never
     * carried a space_type, so RentalInspectionSetting::roomTypeItemsFor()
     * was never reachable and a manually-added space got zero checklist
     * items. This is THE regression test for that fix.
     */
    public function test_adding_a_space_without_a_room_type_is_rejected(): void
    {
        $this->postJson(route('corex.properties.rental-inspection-items.store', $this->property), [
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Bedroom 2',
        ])->assertStatus(422);
    }

    public function test_adding_a_space_with_an_unrecognised_room_type_is_rejected(): void
    {
        $this->postJson(route('corex.properties.rental-inspection-items.store', $this->property), [
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Bedroom 2', 'space_type' => 'Made Up Room',
        ])->assertStatus(422);
    }

    public function test_adding_a_space_with_a_room_type_creates_a_real_room_and_seeds_its_checklist(): void
    {
        $this->postJson(route('corex.properties.rental-inspection-items.store', $this->property), [
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Bedroom 2', 'space_type' => 'Bedroom',
        ])->assertOk();

        $room = PropertyRoom::where('property_id', $this->property->id)->where('label', 'Bedroom 2')->first();
        $this->assertNotNull($room, 'a real PropertyRoom must be created for a manually-added space');
        $this->assertSame('Bedroom', $room->type);

        $items = RentalInspectionItem::where('property_room_id', $room->id)->pluck('label')->all();
        $this->assertEqualsCanonicalizing(['Ceiling', 'Walls', 'Floors', 'Windows', 'Doors'], $items);
    }

    /**
     * The actual complaint from the field: an agency configures its own
     * checklist for a room type on /corex/settings/rental-inspections, and
     * the manual add path must pull those defaults through, not the
     * generic baseline — exactly what was unreachable before this fix.
     */
    public function test_adding_a_space_pulls_the_agencys_own_configured_room_type_defaults(): void
    {
        RentalInspectionSetting::create([
            'agency_id' => $this->agency->id,
            'room_type_item_defaults' => ['Bedroom' => ['Built-in Cupboard', 'Aircon']],
        ]);

        $this->postJson(route('corex.properties.rental-inspection-items.store', $this->property), [
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Bedroom 1', 'space_type' => 'Bedroom',
        ])->assertOk();

        $room = PropertyRoom::where('property_id', $this->property->id)->where('label', 'Bedroom 1')->first();
        $items = RentalInspectionItem::where('property_room_id', $room->id)->pluck('label')->all();
        $this->assertEqualsCanonicalizing(['Built-in Cupboard', 'Aircon'], $items);
    }

    public function test_assigning_a_room_type_to_a_legacy_typeless_space_creates_a_room_and_retires_the_old_item(): void
    {
        $legacy = $this->makeItem(); // kind=space, no property_room_id, no space_type — the pre-fix shape

        $this->postJson(
            route('corex.properties.rental-inspection-items.assign-type', [$this->property, $legacy]),
            ['space_type' => 'Bedroom']
        )->assertOk();

        $this->assertTrue($legacy->fresh()->is_retired, 'the legacy item must be retired, never deleted (§3.3)');
        $this->assertNull($legacy->fresh()->property_room_id, 'the legacy item itself is left untouched, preserving any observation history');

        $room = PropertyRoom::where('property_id', $this->property->id)->where('label', $legacy->label)->first();
        $this->assertNotNull($room);
        $this->assertSame('Bedroom', $room->type);
        $this->assertSame(5, RentalInspectionItem::where('property_room_id', $room->id)->count());
    }

    public function test_assign_type_is_refused_for_an_item_that_already_has_a_room(): void
    {
        $this->postJson(route('corex.properties.rental-inspection-items.store', $this->property), [
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Bedroom 1', 'space_type' => 'Bedroom',
        ])->assertOk();
        $typedItem = RentalInspectionItem::where('space_type', 'Bedroom')->first();

        $this->postJson(
            route('corex.properties.rental-inspection-items.assign-type', [$this->property, $typedItem]),
            ['space_type' => 'Kitchen']
        )->assertStatus(422);
    }

    public function test_assign_type_is_refused_for_a_meter(): void
    {
        $meter = RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'kind' => RentalInspectionItem::KIND_METER, 'label' => 'Water meter', 'created_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(
            route('corex.properties.rental-inspection-items.assign-type', [$this->property, $meter]),
            ['space_type' => 'Bedroom']
        )->assertStatus(422);
    }

    // ── Room walking order — 2026-09-21, Johan on property 5792 ────────

    public function test_adding_a_space_gets_a_sort_order_from_the_walking_order_not_creation_order(): void
    {
        // Kitchen sits earlier than Bedroom in DEFAULT_ROOM_TYPE_WALKING_ORDER,
        // so adding it SECOND must still sort BEFORE the bedroom added first —
        // proving sort_order comes from the walking order, not from an
        // append-to-the-end counter.
        $this->postJson(route('corex.properties.rental-inspection-items.store', $this->property), [
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Bedroom 1', 'space_type' => 'Bedroom',
        ])->assertOk();
        $this->postJson(route('corex.properties.rental-inspection-items.store', $this->property), [
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Kitchen', 'space_type' => 'Kitchen',
        ])->assertOk();

        $bedroom = PropertyRoom::where('property_id', $this->property->id)->where('type', 'Bedroom')->first();
        $kitchen = PropertyRoom::where('property_id', $this->property->id)->where('type', 'Kitchen')->first();

        $this->assertLessThan($bedroom->sort_order, $kitchen->sort_order);
    }

    public function test_bedrooms_sort_naturally_by_number_not_alphabetically(): void
    {
        foreach (['Bedroom 10', 'Bedroom 2', 'Bedroom 1'] as $label) {
            $this->postJson(route('corex.properties.rental-inspection-items.store', $this->property), [
                'kind' => RentalInspectionItem::KIND_SPACE, 'label' => $label, 'space_type' => 'Bedroom',
            ])->assertOk();
        }

        $labelsInOrder = PropertyRoom::where('property_id', $this->property->id)
            ->orderBy('sort_order')->pluck('label')->all();

        // Natural-numeric: 1, 2, 10 — never alphabetical (which would give 1, 10, 2).
        $this->assertSame(['Bedroom 1', 'Bedroom 2', 'Bedroom 10'], $labelsInOrder);
    }

    public function test_apply_default_room_order_recomputes_existing_rooms_without_touching_items(): void
    {
        // Simulate pre-fix creation-order rooms — Kitchen created first
        // (sort_order 0) even though it should walk before Bedroom by type,
        // and a Bedroom created second (sort_order 1) sitting "wrong" already
        // by luck. Use a case where creation order actively disagrees with
        // the walking order: Bedroom first, then Kitchen.
        $bedroom = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'Bedroom', 'label' => 'Bedroom 1', 'source' => 'manual', 'sort_order' => 0,
            'created_by_user_id' => $this->agent->id,
        ]);
        $kitchen = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'Kitchen', 'label' => 'Kitchen', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $this->agent->id,
        ]);
        RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $bedroom->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Ceiling', 'space_type' => 'Bedroom',
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.properties.rental-inspection-rooms.apply-default-order', $this->property))
            ->assertOk();

        // Kitchen walks before Bedroom in the default order — apply-default-order
        // must flip their relative sort_order.
        $this->assertLessThan($bedroom->fresh()->sort_order, $kitchen->fresh()->sort_order);
        $this->assertDatabaseHas('rental_inspection_items', ['id' => RentalInspectionItem::first()->id, 'label' => 'Ceiling']);
    }

    /**
     * The exact bug the conductor found live on property 5792: "bedroom 2"
     * (lowercase, created first) sorting ahead of "Bedroom 1" (capitalised,
     * created second). Confirms case has no bearing on the natural-numeric
     * extraction and that apply-default-order actually reorders them.
     */
    public function test_apply_default_order_sorts_bedroom_1_before_lowercase_bedroom_2_regardless_of_casing(): void
    {
        // Creation order deliberately matches the reported bug: "bedroom 2" first.
        $bedroom2 = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'Bedroom', 'label' => 'bedroom 2', 'source' => 'manual', 'sort_order' => 0,
            'created_by_user_id' => $this->agent->id,
        ]);
        $bedroom1 = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'Bedroom', 'label' => 'Bedroom 1', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.properties.rental-inspection-rooms.apply-default-order', $this->property))
            ->assertOk();

        $this->assertLessThan($bedroom2->fresh()->sort_order, $bedroom1->fresh()->sort_order);
    }

    /**
     * Two same-type rooms that BOTH carry no number (or the same number)
     * resolve to the identical sort_order from defaultRoomSortOrderFor() —
     * Johan's requirement #3: their relative order must still be stable and
     * predictable, never left to flip between requests. `id` is the
     * required secondary tiebreak, matching the box-wide
     * orderBy('sort_order')->orderBy('id') convention used everywhere else
     * sort_order drives a query.
     */
    public function test_two_same_type_rooms_with_no_number_get_a_stable_id_ordered_tiebreak(): void
    {
        $first = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'Bedroom', 'label' => 'Bedroom', 'source' => 'manual', 'sort_order' => 0,
            'created_by_user_id' => $this->agent->id,
        ]);
        $second = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'Bedroom', 'label' => 'Bedroom', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $this->agent->id,
        ]);

        $response = $this->postJson(route('corex.properties.rental-inspection-rooms.apply-default-order', $this->property))
            ->assertOk();

        // Both resolve to the exact same sort_order — proving the tiebreak matters here.
        $this->assertSame($first->fresh()->sort_order, $second->fresh()->sort_order);

        $ids = collect($response->json('rooms'))->pluck('id')->all();
        $this->assertSame([$first->id, $second->id], $ids, 'a tied sort_order must still order by id, stably');
    }

    public function test_reorder_rooms_persists_the_agents_own_chosen_order(): void
    {
        $roomA = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'Bedroom', 'label' => 'Bedroom A', 'source' => 'manual', 'sort_order' => 0,
            'created_by_user_id' => $this->agent->id,
        ]);
        $roomB = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'Bedroom', 'label' => 'Bedroom B', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $this->agent->id,
        ]);

        // Agent moves B above A, against the walking order's own natural read.
        $this->postJson(route('corex.properties.rental-inspection-rooms.reorder', $this->property), [
            'room_ids' => [$roomB->id, $roomA->id],
        ])->assertOk();

        $this->assertLessThan($roomA->fresh()->sort_order, $roomB->fresh()->sort_order);
    }

    public function test_reorder_rejects_a_room_id_from_a_different_property(): void
    {
        $room = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'Bedroom', 'label' => 'Bedroom A', 'source' => 'manual', 'sort_order' => 0,
            'created_by_user_id' => $this->agent->id,
        ]);
        $otherProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Other', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $foreignRoom = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $otherProperty->id,
            'type' => 'Kitchen', 'label' => 'Kitchen', 'source' => 'manual', 'sort_order' => 0,
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.properties.rental-inspection-rooms.reorder', $this->property), [
            'room_ids' => [$room->id, $foreignRoom->id],
        ])->assertStatus(422);
    }

    public function test_retiring_an_item_from_a_different_property_404s(): void
    {
        $item = $this->makeItem();
        $otherProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Other', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $this->postJson(route('corex.properties.rental-inspection-items.retire', [$otherProperty, $item]))
            ->assertNotFound();
    }

    public function test_retiring_an_item_sets_is_retired(): void
    {
        $item = $this->makeItem();

        $this->postJson(route('corex.properties.rental-inspection-items.retire', [$this->property, $item]))
            ->assertOk();

        $this->assertTrue($item->fresh()->is_retired);
    }

    // ── Starting an inspection (§0.5 — deliberate, never auto-created) ──

    public function test_the_tab_has_no_current_inspection_until_one_is_explicitly_started(): void
    {
        $response = $this->getJson(route('corex.properties.rental-inspection-tab.data', $this->property));

        $response->assertOk();
        $this->assertNull($response->json('in_inspection'));
        $this->assertSame(0, RentalInspection::count(), 'opening the tab must never silently create an inspection');
    }

    public function test_starting_an_in_inspection_makes_it_the_current_one(): void
    {
        $this->postJson(route('corex.properties.rental-inspections.start', $this->property), ['type' => RentalInspection::TYPE_IN])
            ->assertStatus(201);

        $response = $this->getJson(route('corex.properties.rental-inspection-tab.data', $this->property));
        $this->assertNotNull($response->json('in_inspection'));
        $this->assertSame($this->lease->id, $response->json('in_inspection.lease_id'));
    }

    public function test_starting_a_second_in_inspection_while_one_is_already_under_way_is_refused(): void
    {
        $this->postJson(route('corex.properties.rental-inspections.start', $this->property), ['type' => RentalInspection::TYPE_IN])
            ->assertStatus(201);

        $this->postJson(route('corex.properties.rental-inspections.start', $this->property), ['type' => RentalInspection::TYPE_IN])
            ->assertStatus(409);

        $this->assertSame(1, RentalInspection::where('type', RentalInspection::TYPE_IN)->count());
    }

    public function test_multiple_ad_hoc_inspections_can_be_started_at_once(): void
    {
        $this->postJson(route('corex.properties.rental-inspections.start', $this->property), ['type' => RentalInspection::TYPE_AD_HOC])
            ->assertStatus(201);
        $this->postJson(route('corex.properties.rental-inspections.start', $this->property), ['type' => RentalInspection::TYPE_AD_HOC])
            ->assertStatus(201);

        $this->assertSame(2, RentalInspection::where('type', RentalInspection::TYPE_AD_HOC)->count());
    }

    public function test_starting_an_inspection_with_no_active_lease_is_refused(): void
    {
        $this->lease->update(['status' => Lease::STATUS_EXPIRED]);

        $this->postJson(route('corex.properties.rental-inspections.start', $this->property), ['type' => RentalInspection::TYPE_IN])
            ->assertStatus(409);
    }

    // ── Observations ────────────────────────────────────────────────

    public function test_recording_an_observation_calls_the_atomic_record_path(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id,
            'condition' => RentalInspectionObservation::CONDITION_GOOD,
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ])->assertOk();

        $this->assertDatabaseHas('rental_inspection_observations', [
            'rental_inspection_item_id' => $item->id, 'condition' => 'good', 'observed_by_user_id' => $this->agent->id,
        ]);
    }

    public function test_recording_a_bad_condition_without_notes_is_rejected(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id,
            'condition' => RentalInspectionObservation::CONDITION_DAMAGED,
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('rental_inspection_observations', ['rental_inspection_item_id' => $item->id]);
    }

    /**
     * §0.4 — the ORIGINAL scenario this whole mechanism exists for: two
     * DIFFERENT agents recording conflicting conditions for the same item.
     * Was written using ONE acting user for both POSTs (a same-author
     * self-correction, not a real conflict) until 2026-09-22 — see the
     * new test directly below, which now covers that case explicitly and
     * asserts the opposite outcome, per Johan's ruling on property 5792.
     */
    public function test_two_different_agents_recording_conflicting_conditions_produce_one_discrepancy(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $secondAgent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);

        $this->actingAs($this->agent);
        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => 'good', 'source' => 'in_inspection',
        ])->assertOk();

        $this->actingAs($secondAgent);
        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => 'damaged', 'notes' => 'Cracked tile.', 'source' => 'in_inspection',
        ])->assertOk();

        $this->assertSame(1, RentalInspectionDiscrepancy::count());
    }

    /**
     * 2026-09-22, Johan (property 5792, live during his demo) — a single
     * agent correcting their own earlier tap on the SAME item was being
     * treated as a conflict needing resolution, growing by one option on
     * every recorded condition. His ruling, verbatim in substance: "a
     * genuine concurrent-edit conflict (two people editing the same item
     * at once) may well deserve a prompt, but an item's own history never
     * does. Latest-wins is the rule this surface already uses everywhere
     * else." RentalInspectionDiscrepancy::sameAuthor() is the fix.
     */
    public function test_the_same_agent_correcting_their_own_earlier_condition_does_not_create_a_discrepancy(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => 'good', 'source' => 'in_inspection',
        ])->assertOk();
        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => 'n_a', 'source' => 'in_inspection',
        ])->assertOk();
        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => 'good', 'source' => 'in_inspection',
        ])->assertOk();

        $this->assertSame(0, RentalInspectionDiscrepancy::count());
        $this->assertSame(3, RentalInspectionObservation::where('rental_inspection_item_id', $item->id)->count());
    }

    // ── Photos ──────────────────────────────────────────────────────

    public function test_uploading_a_photo_attaches_it_to_the_observation(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $observation = RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'good', 'source' => 'in_inspection',
        ]);

        $this->postJson(
            route('corex.rental-inspections.observations.photos.store', [$inspection, $observation]),
            ['photo' => UploadedFile::fake()->image('geyser.jpg', 800, 600)]
        )->assertStatus(201);

        $this->assertSame(1, RentalInspectionPhoto::where('rental_inspection_observation_id', $observation->id)->count());
    }

    public function test_a_retried_photo_upload_with_the_same_key_returns_the_existing_record_not_a_duplicate(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $observation = RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'good', 'source' => 'in_inspection',
        ]);
        $key = (string) \Illuminate\Support\Str::uuid();

        $this->postJson(
            route('corex.rental-inspections.observations.photos.store', [$inspection, $observation]),
            ['photo' => UploadedFile::fake()->image('a.jpg'), 'client_idempotency_key' => $key]
        )->assertStatus(201);

        $this->postJson(
            route('corex.rental-inspections.observations.photos.store', [$inspection, $observation]),
            ['photo' => UploadedFile::fake()->image('a.jpg'), 'client_idempotency_key' => $key]
        )->assertOk();

        $this->assertSame(1, RentalInspectionPhoto::where('client_idempotency_key', $key)->count());
    }

    // ── Discrepancy resolution ──────────────────────────────────────

    public function test_resolving_a_discrepancy_requires_the_accepted_observation_to_be_a_participant(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $secondAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'good', 'source' => 'in_inspection',
        ]);
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $secondAgent->id, 'condition' => 'damaged', 'notes' => 'x', 'source' => 'in_inspection',
        ]);
        $discrepancy = RentalInspectionDiscrepancy::first();

        $outsideObservation = RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $this->makeInspection()->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'good', 'source' => 'ad_hoc',
        ]);

        $this->postJson(route('corex.rental-inspections.discrepancies.resolve', [$inspection, $discrepancy]), [
            'accepted_observation_id' => $outsideObservation->id,
        ])->assertStatus(422);
    }

    public function test_resolving_a_discrepancy_with_a_real_participant_succeeds(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $secondAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'good', 'source' => 'in_inspection',
        ]);
        $winner = RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $secondAgent->id, 'condition' => 'damaged', 'notes' => 'x', 'source' => 'in_inspection',
        ]);
        $discrepancy = RentalInspectionDiscrepancy::first();

        $this->postJson(route('corex.rental-inspections.discrepancies.resolve', [$inspection, $discrepancy]), [
            'accepted_observation_id' => $winner->id, 'resolution_note' => 'Confirmed damaged.',
        ])->assertOk();

        $this->assertNotNull($discrepancy->fresh()->resolved_at);
    }

    // ── Signatures ──────────────────────────────────────────────────

    /**
     * §15, Stage 3 (2026-09-20) — the old single-tenant, auto-resolving
     * signer_role/refused_note request shape (and the sign_on_behalf
     * permission check that lived only inside its agent_on_behalf branch)
     * is fully retired now that out-inspection uses the same shared
     * per-party UI in-inspection has used since Stage 2. Replaces three
     * obsolete tests that covered that old shape's specific limitations
     * (permission bypass, temporarily-disabled refusal, multi-tenant
     * refusal) — the multi-tenant case in particular is no longer a
     * limitation at all: the new shape asks for an explicit
     * party_contact_id, so it never had to guess which tenant signed.
     *
     * NOTE for Stage 4: rental_inspections.sign_on_behalf has no caller at
     * all right now (its only check lived in the removed old branch) —
     * real refusal-recording needs to decide whether/how that permission
     * gates it, not assume it's already wired.
     */
    public function test_multiple_tenants_on_the_same_out_inspection_each_sign_independently(): void
    {
        $tenantOne = $this->makeTenant();
        $tenantTwo = $this->makeTenant();
        $inspection = $this->makeInspection(RentalInspection::TYPE_OUT);
        $inspection->startAwaitingSignature();

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_TENANT,
            'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
            'party_contact_id' => $tenantOne->id,
            'signature_image' => self::TEST_SIGNATURE_IMAGE,
        ])->assertStatus(201);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_TENANT,
            'disposition' => RentalInspectionSignature::DISPOSITION_REFUSED,
            'party_contact_id' => $tenantTwo->id,
            'refusal_reason_preset' => 'not_present',
        ])->assertStatus(201);

        $this->assertCount(2, $inspection->signatures()->where('party_role', 'tenant')->get());
    }

    public function test_the_landlord_can_sign_an_out_inspection_over_real_http(): void
    {
        $property = $this->property;
        $landlord = \App\Models\Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'first_name' => 'Lindiwe', 'last_name' => 'Landlord', 'email' => uniqid() . '@example.test',
        ]);
        \App\Models\ContactProperty::create(['contact_id' => $landlord->id, 'property_id' => $property->id, 'role' => 'landlord']);
        $inspection = $this->makeInspection(RentalInspection::TYPE_OUT);
        $inspection->startAwaitingSignature();

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_LANDLORD,
            'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
            'party_contact_id' => $landlord->id,
            'signature_image' => self::TEST_SIGNATURE_IMAGE,
        ])->assertStatus(201)->assertJsonFragment(['disposition' => 'signed', 'party_role' => 'landlord']);
    }

    public function test_the_new_canonical_signature_shape_works_directly(): void
    {
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection(RentalInspection::TYPE_OUT);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_TENANT,
            'disposition' => RentalInspectionSignature::DISPOSITION_REFUSED,
            'party_contact_id' => $tenant->id,
            'refusal_reason_preset' => 'not_present',
        ])->assertStatus(201)
          ->assertJsonFragment(['disposition' => 'refused', 'party_role' => 'tenant']);
    }

    // ── §15.5, Stage 4 — refusal capture, gated on sign_on_behalf ───────

    /**
     * Seeding .view/.create ONLY (never .sign_on_behalf) flips the table
     * from unseeded (allow-all fallback) to strictly-enrolled — the same
     * technique the old agent_on_behalf test used, now proving the real
     * successor permission genuinely gates the new refusal action.
     */
    public function test_a_refusal_requires_the_sign_on_behalf_permission(): void
    {
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'rental_inspections.view', 'scope' => 'own']);
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'rental_inspections.create', 'scope' => 'own']);
        \App\Services\PermissionService::clearCache();
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection(RentalInspection::TYPE_OUT);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_TENANT,
            'disposition' => RentalInspectionSignature::DISPOSITION_REFUSED,
            'party_contact_id' => $tenant->id,
            'refusal_reason_preset' => 'not_present',
        ])->assertStatus(403);
    }

    public function test_signing_does_not_require_the_sign_on_behalf_permission(): void
    {
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'rental_inspections.view', 'scope' => 'own']);
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'rental_inspections.create', 'scope' => 'own']);
        \App\Services\PermissionService::clearCache();
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection(RentalInspection::TYPE_OUT);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_TENANT,
            'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
            'party_contact_id' => $tenant->id,
            'signature_image' => self::TEST_SIGNATURE_IMAGE,
        ])->assertStatus(201);
    }

    public function test_the_agent_can_never_be_refused_over_real_http(): void
    {
        $inspection = $this->makeInspection(RentalInspection::TYPE_OUT);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_AGENT,
            'disposition' => RentalInspectionSignature::DISPOSITION_REFUSED,
            'signature_image' => self::TEST_SIGNATURE_IMAGE,
        ])->assertStatus(422);
    }

    public function test_tab_payload_exposes_the_refusal_reason_presets(): void
    {
        $response = $this->getJson(route('corex.properties.rental-inspection-tab.data', $this->property));

        $response->assertOk();
        $presets = collect($response->json('refusal_reason_presets'));
        $this->assertSame('other', $presets->last()['key']);
    }

    // ── §15.3, Stage 2 — in-inspection signing, the whole new path ──────

    public function test_an_in_inspection_can_start_its_own_signing_window_over_real_http(): void
    {
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);

        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $inspection))
            ->assertOk()
            ->assertJsonFragment(['status' => RentalInspection::STATUS_AWAITING_SIGNATURE]);
    }

    public function test_a_tenant_can_sign_an_in_inspection_over_real_http(): void
    {
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);
        $inspection->startAwaitingSignature();

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_TENANT,
            'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
            'party_contact_id' => $tenant->id,
            'signature_image' => self::TEST_SIGNATURE_IMAGE,
        ])->assertStatus(201)->assertJsonFragment(['disposition' => 'signed', 'party_role' => 'tenant']);
    }

    public function test_the_agent_cannot_sign_an_in_inspection_until_the_tenant_has_over_real_http(): void
    {
        $this->makeTenant();
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);
        $inspection->startAwaitingSignature();

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_AGENT,
            'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
            'signature_image' => self::TEST_SIGNATURE_IMAGE,
        ])->assertStatus(422);
    }

    public function test_the_agent_can_sign_an_in_inspection_once_the_tenant_has_over_real_http(): void
    {
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);
        $inspection->startAwaitingSignature();
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $tenant->id, 'party_signature_path' => 'signatures/tenant.png',
        ]);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_AGENT,
            'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
            'signature_image' => self::TEST_SIGNATURE_IMAGE,
        ])->assertStatus(201)->assertJsonFragment(['disposition' => 'signed', 'party_role' => 'agent']);
    }

    /**
     * Stage 5 (§15.7) is what makes signing MANDATORY on an in-inspection —
     * this stage only adds the ABILITY to sign. Proving the old, unchanged
     * guard still lets an unsigned in-inspection complete, exactly as
     * before, so nothing here silently starts enforcing early.
     */
    /**
     * §15.7, Stage 5 — this is the exact opposite of what this test used to
     * prove in Stage 2 ("still completes without any signature in THIS
     * stage"). Now that the completion guard is real, an unsigned
     * in-inspection must genuinely be refused, over real HTTP, not just at
     * the model layer.
     */
    public function test_an_in_inspection_cannot_complete_over_real_http_without_the_agents_signature(): void
    {
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);

        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertStatus(409);
    }

    public function test_an_in_inspection_completes_over_real_http_once_the_agent_signs(): void
    {
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);
        $inspection->startAwaitingSignature();
        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_AGENT,
            'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
            'signature_image' => self::TEST_SIGNATURE_IMAGE,
        ])->assertStatus(201);

        $this->postJson(route('corex.rental-inspections.complete', $inspection))
            ->assertOk()
            ->assertJsonFragment(['status' => RentalInspection::STATUS_COMPLETED]);
    }

    // ── Lifecycle passthroughs ──────────────────────────────────────

    public function test_completing_an_inspection_with_an_unresolved_discrepancy_returns_409_not_500(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $secondAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'good', 'source' => 'in_inspection',
        ]);
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $secondAgent->id, 'condition' => 'damaged', 'notes' => 'x', 'source' => 'in_inspection',
        ]);

        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertStatus(409);
    }

    // ── §17, Johan 2026-09-21, from Retha's real paper form: N/A, room
    // notes, overall notes ──────────────────────────────────────────

    private function makeRoomWithItems(int $itemCount = 2): PropertyRoom
    {
        $room = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'Bedroom', 'label' => 'Bedroom 3', 'source' => 'manual', 'sort_order' => 0,
            'created_by_user_id' => $this->agent->id,
        ]);
        for ($i = 0; $i < $itemCount; $i++) {
            RentalInspectionItem::create([
                'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $room->id,
                'kind' => RentalInspectionItem::KIND_SPACE, 'label' => "Facet $i", 'space_type' => 'Bedroom',
                'created_by_user_id' => $this->agent->id,
            ]);
        }

        return $room;
    }

    public function test_condition_validation_accepts_na_by_default(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id,
            'condition' => RentalInspectionObservation::CONDITION_NA,
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ])->assertOk();
    }

    /** Johan: N/A is "not an argument at all" — unlike Missing, it needs no reason on record. */
    public function test_na_does_not_require_notes(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id,
            'condition' => RentalInspectionObservation::CONDITION_NA,
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ])->assertOk();
    }

    public function test_a_condition_key_the_agency_has_removed_is_rejected(): void
    {
        RentalInspectionSetting::create([
            'agency_id' => $this->agency->id,
            'condition_states' => [['key' => 'good', 'label' => 'Good', 'requires_notes' => false]],
        ]);
        $item = $this->makeItem();
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id,
            'condition' => RentalInspectionObservation::CONDITION_NA,
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ])->assertStatus(422);
    }

    /** Retha's Good/OK/Bad — a fully custom 3-state vocabulary, distinct keys, distinct requires_notes. */
    public function test_a_fully_custom_condition_vocabulary_is_honoured(): void
    {
        RentalInspectionSetting::create([
            'agency_id' => $this->agency->id,
            'condition_states' => [
                ['key' => 'good', 'label' => 'Good', 'requires_notes' => false],
                ['key' => 'ok', 'label' => 'OK', 'requires_notes' => false],
                ['key' => 'bad', 'label' => 'Bad', 'requires_notes' => true],
            ],
        ]);
        $item = $this->makeItem();
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => 'ok',
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ])->assertOk();

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => 'bad',
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ])->assertStatus(422, 'Bad requires a reason for this agency, even though Missing/Damaged do not exist in its vocabulary at all');
    }

    public function test_mark_room_na_creates_an_observation_for_every_active_item_in_the_room(): void
    {
        $room = $this->makeRoomWithItems(2);
        $inspection = $this->makeInspection();

        $response = $this->postJson(route('corex.rental-inspections.rooms.mark-na', [$inspection, $room]))
            ->assertOk();

        $this->assertCount(2, $response->json('observations'));
        $this->assertSame(2, RentalInspectionObservation::where('condition', RentalInspectionObservation::CONDITION_NA)->count());
    }

    public function test_mark_room_na_skips_retired_items(): void
    {
        $room = $this->makeRoomWithItems(2);
        $retired = RentalInspectionItem::where('property_room_id', $room->id)->first();
        $retired->update(['is_retired' => true]);
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.rooms.mark-na', [$inspection, $room]))->assertOk();

        $this->assertSame(1, RentalInspectionObservation::where('condition', RentalInspectionObservation::CONDITION_NA)->count());
    }

    public function test_mark_room_na_is_rejected_when_the_agency_has_removed_na(): void
    {
        RentalInspectionSetting::create([
            'agency_id' => $this->agency->id,
            'condition_states' => [['key' => 'good', 'label' => 'Good', 'requires_notes' => false]],
        ]);
        $room = $this->makeRoomWithItems(1);
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.rooms.mark-na', [$inspection, $room]))->assertStatus(422);
    }

    public function test_mark_room_na_rejects_a_room_from_a_different_property(): void
    {
        $room = $this->makeRoomWithItems(1);
        $otherProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Other', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $otherLease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $otherProperty->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
        $otherInspection = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $otherLease->id, 'type' => RentalInspection::TYPE_IN,
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.rental-inspections.rooms.mark-na', [$otherInspection, $room]))->assertNotFound();
    }

    /**
     * Johan: "a genuine conflict with an EARLIER observation ... still
     * raises a real discrepancy." Genuine means a DIFFERENT agent's
     * earlier entry — the earlier observation here used $this->agent (the
     * SAME agent the mark-na POST below is authenticated as) until
     * 2026-09-22, which the sameAuthor() fix correctly stopped flagging as
     * a conflict (self-correction, not disagreement). Using a second
     * agent for the earlier entry keeps this test's own stated intent.
     */
    public function test_mark_room_na_after_an_earlier_different_observation_raises_a_discrepancy(): void
    {
        $room = $this->makeRoomWithItems(1);
        $item = RentalInspectionItem::where('property_room_id', $room->id)->first();
        $inspection = $this->makeInspection();
        $earlierAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $earlierAgent->id, 'condition' => 'fair', 'notes' => 'x', 'source' => 'in_inspection',
        ]);

        $this->postJson(route('corex.rental-inspections.rooms.mark-na', [$inspection, $room]))->assertOk();

        $this->assertSame(1, RentalInspectionDiscrepancy::count());
    }

    public function test_agent_can_save_a_room_note(): void
    {
        $room = $this->makeRoomWithItems(1);
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.rooms.notes.store', [$inspection, $room]), [
            'note' => '3x nails in wall',
        ])->assertStatus(201)->assertJsonFragment(['note' => '3x nails in wall']);

        $this->assertDatabaseHas('rental_inspection_room_notes', [
            'rental_inspection_id' => $inspection->id, 'property_room_id' => $room->id, 'note' => '3x nails in wall',
        ]);
    }

    /** A correction is a NEW row, matching Observation's own immutability convention (§3.3) — never an edit. */
    public function test_a_second_room_note_is_a_new_row_not_an_edit(): void
    {
        $room = $this->makeRoomWithItems(1);
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.rooms.notes.store', [$inspection, $room]), ['note' => 'First note'])->assertStatus(201);
        $this->postJson(route('corex.rental-inspections.rooms.notes.store', [$inspection, $room]), ['note' => 'Corrected note'])->assertStatus(201);

        $this->assertSame(2, \App\Models\RentalInspectionRoomNote::where('property_room_id', $room->id)->count());
    }

    public function test_room_note_rejects_a_room_from_a_different_property(): void
    {
        $room = $this->makeRoomWithItems(1);
        $otherProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Other', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $otherLease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $otherProperty->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
        $otherInspection = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $otherLease->id, 'type' => RentalInspection::TYPE_IN,
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.rental-inspections.rooms.notes.store', [$otherInspection, $room]), ['note' => 'x'])
            ->assertNotFound();
    }

    public function test_agent_can_save_overall_notes(): void
    {
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.overall-notes.update', $inspection), [
            'overall_notes' => 'Apartment clean, fair condition, partially furnished',
        ])->assertOk()->assertJsonFragment(['overall_notes' => 'Apartment clean, fair condition, partially furnished']);

        $this->assertSame('Apartment clean, fair condition, partially furnished', $inspection->fresh()->overall_notes);
    }

    public function test_overall_notes_can_be_cleared(): void
    {
        $inspection = $this->makeInspection();
        $inspection->update(['overall_notes' => 'Something']);

        $this->postJson(route('corex.rental-inspections.overall-notes.update', $inspection), ['overall_notes' => null])
            ->assertOk();

        $this->assertNull($inspection->fresh()->overall_notes);
    }

    public function test_tab_payload_exposes_the_agencys_condition_states(): void
    {
        $response = $this->getJson(route('corex.properties.rental-inspection-tab.data', $this->property));

        $response->assertOk();
        $keys = collect($response->json('condition_states'))->pluck('key')->all();
        $this->assertContains('n_a', $keys);
        $this->assertContains('good', $keys);
    }
}
