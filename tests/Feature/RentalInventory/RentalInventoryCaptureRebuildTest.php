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
use App\Models\RentalInventorySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * .ai/specs/rental-inventory.md §13 — Johan's approved capture-screen
 * mockup: room chips with status dots, an always-open entry row, per-line
 * condition chips, immediate per-line photo upload, and "Copy from last
 * inventory." Covers the new server-side behaviour this pass adds; the
 * room-chip/status-dot UI itself is proven by a real-HTTP render (see
 * RentalInventoryCaptureTest's own reload assertions) plus a real
 * compiled-JS parse check run by hand before pushing — a PHPUnit test
 * cannot click a chip, only prove the server contract behind it.
 */
final class RentalInventoryCaptureRebuildTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private RentalInventory $inventory;
    private PropertyRoom $lounge;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Capture Rebuild Agency', 'slug' => 'capture-rebuild-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Capture Rebuild Property', 'status' => 'active', 'listing_type' => 'rental',
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
        $this->inventory = RentalInventory::start($this->property, $this->lease, $this->agent);
    }

    public function test_condition_states_for_defaults_when_agency_has_not_customized(): void
    {
        $states = RentalInventorySetting::conditionStatesFor($this->agency->id);

        $this->assertSame(RentalInventorySetting::DEFAULT_CONDITION_STATES, $states);
        $this->assertSame(['new', 'good', 'fair', 'damaged'], collect($states)->pluck('key')->all());
    }

    public function test_condition_key_can_be_set_on_a_new_line(): void
    {
        $response = $this->postJson(route('corex.rental-inventories.lines.store', $this->inventory), [
            'property_room_id' => $this->lounge->id,
            'quantity' => 1,
            'description' => 'Samsung TV 55"',
            'condition_key' => 'good',
        ])->assertStatus(201);

        $this->assertSame('good', RentalInventoryLine::find($response->json('id'))->condition_key);
    }

    public function test_condition_key_can_be_updated_on_an_existing_line(): void
    {
        $line = RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Samsung TV 55"',
            'created_by_user_id' => $this->agent->id,
        ]);
        $this->assertNull($line->condition_key);

        $this->putJson(route('corex.rental-inventories.lines.update', [$this->inventory, $line]), [
            'property_room_id' => $this->lounge->id,
            'quantity' => 1,
            'description' => 'Samsung TV 55"',
            'condition_key' => 'damaged',
        ])->assertOk()->assertJsonPath('condition_key', 'damaged');

        $this->assertSame('damaged', $line->fresh()->condition_key);
    }

    /** The lazy-but-valid shortcut (BUILD_STANDARD §2) must still work: a line saves fine with no condition picked at all. */
    public function test_a_line_saves_without_a_condition_key(): void
    {
        $this->postJson(route('corex.rental-inventories.lines.store', $this->inventory), [
            'property_room_id' => $this->lounge->id,
            'quantity' => 1,
            'description' => 'White wooden headboard',
        ])->assertStatus(201);

        $this->assertNull(RentalInventoryLine::first()->condition_key);
    }

    public function test_uploading_a_photo_to_a_line_tags_it_in_the_same_request(): void
    {
        Storage::fake('public');
        $line = RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Samsung TV 55"',
            'created_by_user_id' => $this->agent->id,
        ]);

        $response = $this->postJson(route('corex.rental-inventories.photos.store', $this->inventory), [
            'property_room_id' => $this->lounge->id,
            'rental_inventory_line_id' => $line->id,
            'photos' => [UploadedFile::fake()->image('tv.jpg', 800, 600)],
            'client_idempotency_keys' => [(string) \Illuminate\Support\Str::uuid()],
        ])->assertStatus(201);

        $photoId = $response->json('photos.0.id');
        $this->assertSame([$line->id], $response->json('photos.0.lines'));
        $this->assertCount(1, $line->fresh()->photos, 'Upload with rental_inventory_line_id must tag in the SAME request — no separate attach call.');
        $this->assertSame($photoId, $line->fresh()->photos->first()->id);
    }

    public function test_uploading_a_photo_to_a_line_404s_for_a_line_on_a_different_inventory(): void
    {
        Storage::fake('public');
        $otherInventory = RentalInventory::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'created_by_user_id' => $this->agent->id,
        ]);
        $foreignLine = RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $otherInventory->id,
            'room_label' => 'Lounge', 'quantity' => 1, 'description' => 'Foreign item',
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.rental-inventories.photos.store', $this->inventory), [
            'rental_inventory_line_id' => $foreignLine->id,
            'photos' => [UploadedFile::fake()->image('x.jpg')],
        ])->assertStatus(404);
    }

    public function test_copy_from_last_inventory_404s_when_no_prior_inventory_exists(): void
    {
        $response = $this->postJson(route('corex.rental-inventories.copy-from-last', $this->inventory));

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'This property has no earlier inventory to copy from.');
    }

    public function test_copy_from_last_inventory_copies_lines_from_the_prior_tenancys_inventory(): void
    {
        // The prior tenancy's own inventory — RentalInventory::start()
        // refuses a second inventory per LEASE, so this needs its own lease.
        $priorLease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_CANCELLED, 'rental_amount' => 11000, 'start_date' => now()->subYear(),
            'created_by_user_id' => $this->agent->id,
        ]);
        $priorInventory = RentalInventory::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $priorLease->id,
            'created_by_user_id' => $this->agent->id, 'status' => RentalInventory::STATUS_COMPLETED,
        ]);
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $priorInventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Samsung TV 55"', 'condition_key' => 'good',
            'created_by_user_id' => $this->agent->id,
        ]);
        $retiredPriorLine = RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $priorInventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Added in error', 'created_by_user_id' => $this->agent->id,
        ]);
        $retiredPriorLine->retire();

        $response = $this->postJson(route('corex.rental-inventories.copy-from-last', $this->inventory))
            ->assertStatus(201);

        $copied = $this->inventory->fresh()->lines;
        $this->assertCount(1, $copied, 'Only the ACTIVE (non-retired) prior line copies across.');
        $this->assertSame('Samsung TV 55"', $copied->first()->description);
        $this->assertSame('good', $copied->first()->condition_key);
        $this->assertSame($this->lounge->id, $copied->first()->property_room_id);
        $this->assertSame($this->agency->id, $copied->first()->agency_id);
        $this->assertSame($this->inventory->id, $copied->first()->rental_inventory_id);

        // The original inventory/lease's own lines are untouched — this is a
        // COPY, never a move.
        $this->assertCount(2, $priorInventory->fresh()->allLines);
    }

    public function test_copy_from_last_inventory_is_additive_never_overwriting_existing_lines(): void
    {
        $priorLease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_CANCELLED, 'rental_amount' => 11000, 'start_date' => now()->subYear(),
            'created_by_user_id' => $this->agent->id,
        ]);
        $priorInventory = RentalInventory::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $priorLease->id,
            'created_by_user_id' => $this->agent->id,
        ]);
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $priorInventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Samsung TV 55"', 'created_by_user_id' => $this->agent->id,
        ]);

        // This inventory already has its own line before copying.
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Already typed by the agent', 'created_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.rental-inventories.copy-from-last', $this->inventory))->assertStatus(201);

        $descriptions = $this->inventory->fresh()->lines->pluck('description')->sort()->values()->all();
        $this->assertSame(['Already typed by the agent', 'Samsung TV 55"'], $descriptions);
    }

    public function test_capture_page_offers_copy_from_last_inventory_only_when_a_prior_inventory_exists(): void
    {
        // NOT "Copy from last inventory" — that exact phrase also sits in
        // this page's own JS documentation comment (line ~475), rendered
        // unconditionally inside the <script> block regardless of whether
        // the button itself shows. The surrounding sentence is only ever
        // server-rendered inside the @if($hasPriorInventory) block itself,
        // so it actually proves the control's real visibility.
        $withoutPrior = $this->get(route('corex.properties.inventory.show', $this->property));
        $withoutPrior->assertOk();
        $withoutPrior->assertDontSee('This property has an earlier inventory on record.');

        $priorLease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_CANCELLED, 'rental_amount' => 11000, 'start_date' => now()->subYear(),
        ]);
        RentalInventory::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $priorLease->id,
            'created_by_user_id' => $this->agent->id,
        ]);

        $withPrior = $this->get(route('corex.properties.inventory.show', $this->property));
        $withPrior->assertOk();
        $withPrior->assertSee('This property has an earlier inventory on record.');
    }

    public function test_capture_page_renders_the_agencys_condition_chip_labels(): void
    {
        $response = $this->get(route('corex.properties.inventory.show', $this->property));

        $response->assertOk();
        foreach (RentalInventorySetting::DEFAULT_CONDITION_STATES as $state) {
            $response->assertSee($state['label']);
        }
    }
}
