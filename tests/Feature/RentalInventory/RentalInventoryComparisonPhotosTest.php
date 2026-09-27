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
use App\Models\RentalInventorySignature;
use App\Models\User;
use App\Services\Rentals\RentalInventoryComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-inventory.md §11.8 investigation, 2026-09-27 — the
 * move-out comparison page rendered zero photos anywhere despite
 * RentalInventoryLine::photos() already holding every move-in photo an
 * agent tagged during capture. The data was real and correctly linked; it
 * just had no consumer. Proves the comparison service now surfaces it, and
 * the page itself renders it, without touching the capture/tagging path
 * that already worked.
 */
final class RentalInventoryComparisonPhotosTest extends TestCase
{
    use RefreshDatabase;

    public function test_compare_includes_the_lines_tagged_move_in_photos(): void
    {
        \Illuminate\Support\Facades\Auth::logout();
        $agency = Agency::create(['name' => 'Comparison Photos Agency', 'slug' => 'cmp-photos-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $this->actingAs($agent);

        $property = Property::forceCreate([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'title' => 'Comparison Photos Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $lease = Lease::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 11000, 'start_date' => now()->subMonths(3),
            'created_by_user_id' => $agent->id,
        ]);
        $lounge = PropertyRoom::create([
            'agency_id' => $agency->id, 'property_id' => $property->id,
            'type' => 'lounge', 'label' => 'Lounge', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $agent->id,
        ]);

        $inventory = RentalInventory::start($property, $lease, $agent);

        $photographed = RentalInventoryLine::create([
            'agency_id' => $agency->id, 'rental_inventory_id' => $inventory->id,
            'property_room_id' => $lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Samsung TV 55"',
            'created_by_user_id' => $agent->id,
        ]);
        $unphotographed = RentalInventoryLine::create([
            'agency_id' => $agency->id, 'rental_inventory_id' => $inventory->id,
            'property_room_id' => $lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Wooden TV table',
            'created_by_user_id' => $agent->id,
        ]);

        $photo = RentalInventoryPhoto::create([
            'agency_id' => $agency->id, 'rental_inventory_id' => $inventory->id,
            'property_room_id' => $lounge->id, 'storage_path' => '/storage/properties/1/tv.jpg',
            'file_size_bytes' => 12345, 'uploaded_by_user_id' => $agent->id,
        ]);
        $photographed->photos()->attach($photo->id, ['agency_id' => $agency->id]);

        $rows = (new RentalInventoryComparisonService())->compare($inventory);
        $byLineId = collect($rows)->keyBy('line_id');

        $this->assertSame(['/storage/properties/1/tv.jpg'], collect($byLineId[$photographed->id]['photos'])->pluck('storage_path')->all());
        $this->assertSame([], $byLineId[$unphotographed->id]['photos'], 'A line nobody photographed must render zero thumbnails, never an error.');
    }

    public function test_the_comparison_page_renders_the_move_in_photo(): void
    {
        \Illuminate\Support\Facades\Auth::logout();
        $agency = Agency::create(['name' => 'Comparison Photos Page Agency', 'slug' => 'cmp-photos-page-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $this->actingAs($agent);

        $property = Property::forceCreate([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'title' => 'Comparison Photos Page Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $lease = Lease::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 11000, 'start_date' => now()->subMonths(3),
            'created_by_user_id' => $agent->id,
        ]);
        $lounge = PropertyRoom::create([
            'agency_id' => $agency->id, 'property_id' => $property->id,
            'type' => 'lounge', 'label' => 'Lounge', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $agent->id,
        ]);

        $inventory = RentalInventory::start($property, $lease, $agent);

        $line = RentalInventoryLine::create([
            'agency_id' => $agency->id, 'rental_inventory_id' => $inventory->id,
            'property_room_id' => $lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Samsung TV 55"',
            'created_by_user_id' => $agent->id,
        ]);
        $photo = RentalInventoryPhoto::create([
            'agency_id' => $agency->id, 'rental_inventory_id' => $inventory->id,
            'property_room_id' => $lounge->id, 'storage_path' => '/storage/properties/1/tv.jpg',
            'file_size_bytes' => 12345, 'uploaded_by_user_id' => $agent->id,
        ]);
        $line->photos()->attach($photo->id, ['agency_id' => $agency->id]);

        // Satisfy the §12 completion gate (one line, one room, no unvisited
        // rooms) then the pre-existing signature gate (no lease tenants and
        // no resolvable landlord, so the agent's own signature is enough).
        RentalInventorySignature::capture($inventory, RentalInventorySignature::PARTY_AGENT, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_signature_path' => '/fake/agent-signature.png',
            'recorded_by_user_id' => $agent->id,
        ]);
        $inventory->markCompleted();

        $response = $this->get(route('corex.rental-inventories.comparison', $inventory));

        $response->assertOk();
        $response->assertSee('/storage/properties/1/tv.jpg', false);
    }
}
