<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInventory;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventoryPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * .ai/specs/rental-inventory.md §0b — the property-embedded, room-based
 * capture surface rebuilt 2026-09-22. Proves the whole loop Johan asked to
 * see: open inventory from a property with its rooms already listed, add a
 * line item to a room, upload a photo to that room, tag the line to that
 * photo, and find it all still there on reload — with no Save button
 * anywhere in this flow.
 */
final class RentalInventoryCaptureTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private PropertyRoom $lounge;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Inventory Capture Agency', 'slug' => 'inv-capture-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Inventory Capture Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12000, 'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->lounge = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'lounge', 'label' => 'Lounge', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    /**
     * Audit M4 — the capture screen's GET is read-only and no longer creates
     * the inventory; the agent starts it with an explicit POST (idempotent —
     * it resumes an existing current inventory). Returns the GET response.
     */
    private function openInventory(\App\Models\Property $property): \Illuminate\Testing\TestResponse
    {
        $this->post(route('corex.properties.inventory.start', $property));

        return $this->get(route('corex.properties.inventory.show', $property));
    }

    public function test_opening_inventory_from_the_property_resolves_it_transparently_and_lists_its_rooms(): void
    {
        $this->assertSame(0, RentalInventory::count());

        $response = $this->openInventory($this->property);

        $response->assertOk();
        $response->assertSee('Lounge');
        $this->assertSame(1, RentalInventory::count());
        $this->assertSame($this->lease->id, RentalInventory::first()->lease_id);
    }

    /**
     * .ai/specs/rental-inventory.md §0a/§15 — Johan's standing ruling (2026-09-22,
     * restated 2026-09-28): "inventory was specifically specced not only for
     * rentals. sales will also need it... it should be on properties." A sale
     * property has no Lease to attach an inventory to, so it now gets a
     * PROPERTY-LEVEL inventory (lease_id null) instead of the old dead-end
     * "no active lease" state — full capture (rooms/lines/photos) works
     * identically to the rental path, which the rest of this test class
     * already proves for a leased property.
     */
    public function test_a_sale_property_with_no_active_lease_gets_a_property_level_inventory(): void
    {
        $saleProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Sale Property', 'status' => 'active', 'listing_type' => 'sale',
        ]);

        $response = $this->openInventory($saleProperty);

        $response->assertOk();
        $response->assertDontSee('no active lease');

        $inventory = RentalInventory::where('property_id', $saleProperty->id)->first();
        $this->assertNotNull($inventory, 'a sale property must resolve/start its own inventory, not return none');
        $this->assertNull($inventory->lease_id);
    }

    /** Reopening the sale property's inventory resumes the SAME property-level record, never a second one. */
    public function test_reopening_a_sale_propertys_inventory_resumes_the_same_record(): void
    {
        $saleProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Sale Property', 'status' => 'active', 'listing_type' => 'sale',
        ]);

        $this->openInventory($saleProperty)->assertOk();
        $this->openInventory($saleProperty)->assertOk();

        $this->assertSame(1, RentalInventory::where('property_id', $saleProperty->id)->count());
    }

    /**
     * Johan: "sales will also need it... full inventory capture." Proves the
     * sale property's property-level inventory captures a real line item and
     * a room-tagged photo exactly like the rental (leased) path already does
     * in test_full_capture_loop_line_photo_tag_survives_reload_with_no_save_button
     * — same room source, same autosave endpoints, no lease anywhere.
     */
    public function test_sale_property_captures_a_line_and_photo_same_as_a_rental(): void
    {
        Storage::fake('public');

        $saleProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Sale Property', 'status' => 'active', 'listing_type' => 'sale',
        ]);
        $kitchen = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $saleProperty->id,
            'type' => 'kitchen', 'label' => 'Kitchen', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->openInventory($saleProperty)->assertOk();
        $inventory = RentalInventory::where('property_id', $saleProperty->id)->firstOrFail();
        $this->assertNull($inventory->lease_id);

        $lineResponse = $this->postJson(route('corex.rental-inventories.lines.store', $inventory), [
            'property_room_id' => $kitchen->id,
            'quantity' => 1,
            'description' => 'Defy silver dishwasher',
        ])->assertStatus(201);
        $lineId = $lineResponse->json('id');
        $this->assertSame($kitchen->id, RentalInventoryLine::find($lineId)->property_room_id);

        $photoResponse = $this->postJson(route('corex.rental-inventories.photos.store', $inventory), [
            'property_room_id' => $kitchen->id,
            'photos' => [UploadedFile::fake()->image('kitchen.jpg', 800, 600)],
            'client_idempotency_keys' => [(string) \Illuminate\Support\Str::uuid()],
        ])->assertStatus(201);

        $this->assertSame(1, RentalInventoryPhoto::where('rental_inventory_id', $inventory->id)->count());
    }

    /**
     * Move-in-vs-now comparison is explicitly a rental-tenancy concept (§8) —
     * a sale property's property-level inventory (no lease, no move-out) must
     * refuse it rather than render a comparison against a tenancy that never
     * existed.
     */
    public function test_completed_sale_property_inventory_refuses_the_move_out_comparison(): void
    {
        $saleProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Sale Property', 'status' => 'active', 'listing_type' => 'sale',
        ]);
        $room = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $saleProperty->id,
            'type' => 'kitchen', 'label' => 'Kitchen', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $this->agent->id,
        ]);
        $inventory = \App\Models\RentalInventory::startForProperty($saleProperty, $this->agent);
        \App\Models\RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $inventory->id,
            'property_room_id' => $room->id, 'room_label' => $room->label,
            'quantity' => 1, 'description' => 'Built-in oven', 'created_by_user_id' => $this->agent->id,
        ]);
        \App\Models\RentalInventorySignature::capture($inventory, 'agent', 'signed', [
            'party_signature_path' => 'signatures/fake.png',
            'recorded_by_user_id' => $this->agent->id,
        ]);
        $inventory->markCompleted();

        $this->get(route('corex.rental-inventories.comparison', $inventory))->assertStatus(400);
    }

    public function test_full_capture_loop_line_photo_tag_survives_reload_with_no_save_button(): void
    {
        Storage::fake('public');

        // Step 1: open from the property — this transparently starts the inventory.
        $this->openInventory($this->property)->assertOk();
        $inventory = RentalInventory::firstOrFail();

        // Step 2: add a line item to the room — autosave endpoint, no separate "create" step.
        $lineResponse = $this->postJson(route('corex.rental-inventories.lines.store', $inventory), [
            'property_room_id' => $this->lounge->id,
            'quantity' => 1,
            'description' => 'Samsung TV 55"',
        ])->assertStatus(201);
        $lineId = $lineResponse->json('id');

        $this->assertSame($this->lounge->id, RentalInventoryLine::find($lineId)->property_room_id);
        $this->assertSame('Lounge', RentalInventoryLine::find($lineId)->room_label);

        // Step 3: upload a photo tagged to the same room.
        $photoResponse = $this->postJson(route('corex.rental-inventories.photos.store', $inventory), [
            'property_room_id' => $this->lounge->id,
            'photos' => [UploadedFile::fake()->image('lounge-tv.jpg', 800, 600)],
            'client_idempotency_keys' => [(string) \Illuminate\Support\Str::uuid()],
        ])->assertStatus(201);
        $photoId = $photoResponse->json('photos.0.id');

        $this->assertSame(1, RentalInventoryPhoto::where('rental_inventory_id', $inventory->id)->count());
        $this->assertSame($this->lounge->id, RentalInventoryPhoto::find($photoId)->property_room_id);

        // Step 4: tag the line to the photo — Johan's own example, the TV's serial number.
        $this->postJson(route('corex.rental-inventories.lines.photos.attach', [$inventory, $lineId, $photoId]))
            ->assertOk();

        $line = RentalInventoryLine::with('photos')->find($lineId);
        $this->assertCount(1, $line->photos);
        $this->assertSame($photoId, $line->photos->first()->id);

        // Step 5: reload the capture page — everything persisted, nothing was ever "saved" explicitly.
        $reload = $this->openInventory($this->property);
        $reload->assertOk();
        // The line lives in the Alpine data blob (json_encode'd, not literal
        // server-rendered HTML), so assert on the description without its
        // trailing quote mark — json_encode's HEX_QUOT flag re-encodes that
        // character, and the substring alone is sufficient proof it persisted.
        $reload->assertSee('Samsung TV 55', false);
        $this->assertSame(1, RentalInventory::count(), 'Reload must resume the existing inventory, not start a second one.');

        // Untag proves the pivot is a genuine toggle, not a one-way tag.
        $this->deleteJson(route('corex.rental-inventories.lines.photos.detach', [$inventory, $lineId, $photoId]))
            ->assertOk();
        $this->assertCount(0, RentalInventoryLine::find($lineId)->photos);
    }

    /**
     * .ai/specs/rental-inventory.md §4b — Johan: "we specced inventory being
     * blank then you can create the spaces same as with inspections."
     * Proves the whole point of reusing the inspections write path rather
     * than building a second space model: a space created from the
     * inventory screen is the exact same PropertyRoom row the Inspection
     * Items section's own "Space (new room)" control would have created —
     * so it is immediately visible on the inspection side too, not a
     * lookalike record in a parallel table.
     */
    public function test_a_space_created_from_a_blank_property_is_the_same_property_room_inspections_would_see(): void
    {
        // A genuinely blank property — no rooms at all, the real state of
        // every new property, which is exactly the state Johan was blocked
        // on tonight.
        $blankProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Blank Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $blankProperty->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now()->subMonth(),
            'created_by_user_id' => $this->agent->id,
        ]);
        $this->assertSame(0, PropertyRoom::where('property_id', $blankProperty->id)->count());

        $this->openInventory($blankProperty)->assertOk();

        // Same write path the Inspection Items section's own control calls
        // (RentalInspectionRecordingController::storeItem, kind=space) —
        // proving reuse by calling the identical route, not a new one.
        $response = $this->postJson(route('corex.properties.rental-inspection-items.store', $blankProperty), [
            'kind' => 'space',
            'label' => 'Bedroom 1',
            'space_type' => 'Bedroom',
        ])->assertOk();

        $roomId = $response->json('items.0.room.id');
        $this->assertNotNull($roomId, 'storeItem(kind=space) must return the new room on every created item.');

        $room = PropertyRoom::find($roomId);
        $this->assertNotNull($room, 'The space must be a real PropertyRoom row.');
        $this->assertSame($blankProperty->id, $room->property_id);
        $this->assertSame('Bedroom 1', $room->label);
        $this->assertFalse((bool) $room->is_retired);

        // The inventory capture page's own room list uses the exact same
        // query PropertyRoom::where('property_id')->where('is_retired',
        // false) — this IS the query the inspections tab data endpoint uses
        // too, so a room visible here is, by construction, visible there.
        $reload = $this->openInventory($blankProperty);
        $reload->assertOk();
        $reload->assertSee('Bedroom 1', false);
    }

    public function test_line_and_photo_are_scoped_to_the_inventory_they_belong_to(): void
    {
        Storage::fake('public');
        $this->openInventory($this->property)->assertOk();
        $inventory = RentalInventory::firstOrFail();

        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $otherInventory = RentalInventory::create([
            'agency_id' => $otherAgency->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'created_by_user_id' => $this->agent->id,
        ]);
        $foreignLine = RentalInventoryLine::create([
            'agency_id' => $otherAgency->id, 'rental_inventory_id' => $otherInventory->id,
            'room_label' => 'Lounge', 'quantity' => 1, 'description' => 'Foreign item',
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.rental-inventories.lines.photos.attach', [$inventory, $foreignLine->id, 1]))
            ->assertStatus(404);
    }

    /**
     * .ai/specs/rental-inventory.md §0b.3 — the spreadsheet-grid capture
     * save path (Johan, 2026-09-25/26). commitDraftRow() only ever reaches
     * the server with a non-empty description; a blank row must never
     * become a request the server has to reject in the first place, but if
     * a caller ever does send one, the server is still the backstop.
     */
    public function test_a_line_with_no_description_is_refused_not_saved(): void
    {
        $this->openInventory($this->property)->assertOk();
        $inventory = RentalInventory::firstOrFail();

        $this->postJson(route('corex.rental-inventories.lines.store', $inventory), [
            'property_room_id' => $this->lounge->id,
            'quantity' => 1,
            'description' => '',
        ])->assertStatus(422);

        $this->assertSame(0, RentalInventoryLine::count());
    }

    /**
     * .ai/specs/rental-inventory.md §0b.3 — the grid's own edit-in-place
     * path: an agent arrows back into an already-saved line, fixes a typo,
     * arrows away. commitExistingLineIfDirty() PUTs to this same endpoint.
     */
    public function test_updating_an_existing_line_persists_the_edit(): void
    {
        $this->openInventory($this->property)->assertOk();
        $inventory = RentalInventory::firstOrFail();

        $line = RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Whte wooden headboard',
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->putJson(route('corex.rental-inventories.lines.update', [$inventory, $line]), [
            'property_room_id' => $this->lounge->id,
            'quantity' => 2,
            'description' => 'White wooden headboard',
        ])->assertOk()
          ->assertJsonPath('quantity', 2)
          ->assertJsonPath('description', 'White wooden headboard');

        $this->assertSame(2, $line->fresh()->quantity);
        $this->assertSame('White wooden headboard', $line->fresh()->description);
    }

    /**
     * .ai/specs/rental-inventory.md §7 — the standalone list/create screen is
     * kept as the CRUD floor, no longer the day-to-day way in (§0b), but must
     * still work for a sale property: RentalInventoryController::create() no
     * longer filters to leased properties, and store() now branches to
     * RentalInventory::startForProperty() when the chosen property has none.
     */
    public function test_the_standalone_picker_offers_and_starts_a_property_level_inventory_for_a_sale_property(): void
    {
        $saleProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Sale Property', 'status' => 'active', 'listing_type' => 'sale',
        ]);

        $this->get(route('corex.rental-inventories.create'))
            ->assertOk()
            ->assertSee('Sale Property');

        $this->post(route('corex.rental-inventories.store'), ['property_id' => $saleProperty->id])
            ->assertRedirect();

        $inventory = RentalInventory::where('property_id', $saleProperty->id)->firstOrFail();
        $this->assertNull($inventory->lease_id);
    }

    /**
     * Johan, 2026-09-28, on 15726: "why ask the agent to retype something we
     * know" — a blank property's Inventory tab now offers a one-click way to
     * seed real PropertyRoom rows from the listing's own advertising Spaces
     * (spaces_json), reusing the EXACT SAME endpoint Inspections' own "Build
     * from advertising details" button already calls
     * (RentalInspectionFormSeeder::seedFromAdvertising()) — not a second
     * beds/baths/garages-based seeder. Proves the button is offered when
     * blank, the seed endpoint creates rooms from real advertising units
     * (including a Flatlet, which no beds/baths/garages column would ever
     * expose), and those rooms are immediately visible back on the capture
     * screen — editable afterwards, never auto-created without the click.
     */
    public function test_a_blank_property_offers_create_spaces_from_listing_and_seeds_real_rooms(): void
    {
        $blankProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Blank Property With Advertising Spaces', 'status' => 'active', 'listing_type' => 'sale',
            'spaces_json' => [
                'spaces' => [
                    ['type' => 'Bedroom', 'count' => 2, 'units' => [
                        ['label' => 'Bedroom 1', 'features' => []],
                        ['label' => 'Bedroom 2', 'features' => []],
                    ], 'featuresAll' => [], 'descriptionAll' => ''],
                    ['type' => 'Flatlet', 'count' => 1, 'units' => [
                        ['label' => 'Flatlet 1', 'features' => []],
                    ], 'featuresAll' => [], 'descriptionAll' => ''],
                ],
                'features' => [],
            ],
        ]);

        $response = $this->openInventory($blankProperty);
        $response->assertOk();
        $response->assertSee('Create spaces from listing', false);
        $response->assertDontSee('Inventory and Inspections share the same room list');

        $seedResponse = $this->postJson(
            route('corex.properties.rental-inspection-items.seed-from-advertising', $blankProperty)
        )->assertOk();

        $roomLabels = collect($seedResponse->json('rooms'))->pluck('label');
        $this->assertTrue($roomLabels->contains('Bedroom 1'));
        $this->assertTrue($roomLabels->contains('Bedroom 2'));
        $this->assertTrue($roomLabels->contains('Flatlet 1'));

        $reload = $this->openInventory($blankProperty);
        $reload->assertOk();
        $reload->assertSee('Bedroom 1', false);
        $reload->assertSee('Flatlet 1', false);
    }
}
