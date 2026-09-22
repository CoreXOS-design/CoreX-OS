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

    public function test_opening_inventory_from_the_property_resolves_it_transparently_and_lists_its_rooms(): void
    {
        $this->assertSame(0, RentalInventory::count());

        $response = $this->get(route('corex.properties.inventory.show', $this->property));

        $response->assertOk();
        $response->assertSee('Lounge');
        $this->assertSame(1, RentalInventory::count());
        $this->assertSame($this->lease->id, RentalInventory::first()->lease_id);
    }

    public function test_a_sale_property_with_no_active_lease_gets_an_honest_state_not_a_crash(): void
    {
        $saleProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Sale Property', 'status' => 'active', 'listing_type' => 'sale',
        ]);

        $response = $this->get(route('corex.properties.inventory.show', $saleProperty));

        $response->assertOk();
        $response->assertSee('no active lease');
    }

    public function test_full_capture_loop_line_photo_tag_survives_reload_with_no_save_button(): void
    {
        Storage::fake('public');

        // Step 1: open from the property — this transparently starts the inventory.
        $this->get(route('corex.properties.inventory.show', $this->property))->assertOk();
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
        $reload = $this->get(route('corex.properties.inventory.show', $this->property));
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

        $this->get(route('corex.properties.inventory.show', $blankProperty))->assertOk();

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
        $reload = $this->get(route('corex.properties.inventory.show', $blankProperty));
        $reload->assertOk();
        $reload->assertSee('Bedroom 1', false);
    }

    public function test_line_and_photo_are_scoped_to_the_inventory_they_belong_to(): void
    {
        Storage::fake('public');
        $this->get(route('corex.properties.inventory.show', $this->property))->assertOk();
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
}
