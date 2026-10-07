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
use App\Models\RentalInventoryLineDisposition;
use App\Models\RentalInventoryPhoto;
use App\Models\RentalInventorySetting;
use App\Models\RentalInventorySignature;
use App\Models\User;
use App\Services\Rentals\RentalInventoryComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * .ai/specs/rental-inventory.md §14 — Johan's approved move-in-vs-now
 * mockup: quantities on both sides of a line, a disposition vocabulary
 * distinct from condition, "unchanged" lines collapsing to one grey line,
 * an "Only differences" filter, and showing (never hiding) that the
 * current side has no photo yet.
 */
final class RentalInventoryComparisonRebuildTest extends TestCase
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

        $this->agency = Agency::create(['name' => 'Comparison Rebuild Agency', 'slug' => 'comparison-rebuild-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Comparison Rebuild Property', 'status' => 'active', 'listing_type' => 'rental',
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

    private function completeInventory(): void
    {
        RentalInventorySignature::capture($this->inventory, RentalInventorySignature::PARTY_AGENT, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_signature_path' => '/fake/agent-signature.png',
            'recorded_by_user_id' => $this->agent->id,
        ]);
        $this->inventory->markCompleted();
    }

    public function test_baseline_disposition_key_defaults_to_present(): void
    {
        $this->assertSame('present', RentalInventorySetting::baselineDispositionKeyFor($this->agency->id));
    }

    public function test_a_present_finding_matching_move_in_quantity_is_unchanged(): void
    {
        $line = RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 4, 'description' => 'Dining chairs', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->completeInventory();
        RentalInventoryLineDisposition::record($line, 'present', ['recorded_by_user_id' => $this->agent->id]);

        $rows = (new RentalInventoryComparisonService())->compare($this->inventory->fresh());
        $this->assertTrue($rows[0]['unchanged']);
    }

    public function test_a_present_finding_with_a_mismatched_quantity_is_not_unchanged(): void
    {
        $line = RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 4, 'description' => 'Dining chairs', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->completeInventory();
        // Contradictory data (disposition says "present" but the count
        // doesn't match) is exactly the kind of thing that needs a human's
        // eyes, never silently smoothed over as "no change."
        RentalInventoryLineDisposition::record($line, 'present', ['quantity_found' => 3, 'recorded_by_user_id' => $this->agent->id]);

        $rows = (new RentalInventoryComparisonService())->compare($this->inventory->fresh());
        $this->assertFalse($rows[0]['unchanged']);
    }

    public function test_a_damaged_finding_is_never_unchanged_even_with_a_matching_quantity(): void
    {
        $line = RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Samsung TV 55"', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->completeInventory();
        RentalInventoryLineDisposition::record($line, 'damaged', [
            'quantity_found' => 1, 'notes' => 'Cracked screen', 'recorded_by_user_id' => $this->agent->id,
        ]);

        $rows = (new RentalInventoryComparisonService())->compare($this->inventory->fresh());
        $this->assertFalse($rows[0]['unchanged'], 'All present but damaged is still a real finding, never "no change."');
    }

    public function test_an_outstanding_line_is_not_unchanged(): void
    {
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Samsung TV 55"', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->completeInventory();

        $rows = (new RentalInventoryComparisonService())->compare($this->inventory->fresh());
        $this->assertTrue($rows[0]['outstanding']);
        $this->assertFalse($rows[0]['unchanged'], '"Not yet checked" is a different state from "confirmed unchanged."');
    }

    public function test_uploading_a_move_out_photo_tags_the_line_immediately_and_is_kept_apart_from_move_in_photos(): void
    {
        Storage::fake('public');
        $line = RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Samsung TV 55"', 'created_by_user_id' => $this->agent->id,
        ]);
        // A move-in photo already exists on this line (from capture).
        $moveInPhoto = RentalInventoryPhoto::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'storage_path' => '/storage/move-in.jpg',
            'file_size_bytes' => 111, 'uploaded_by_user_id' => $this->agent->id,
        ]);
        $line->photos()->attach($moveInPhoto->id, ['agency_id' => $this->agency->id]);

        // Move-out findings (and photos) can only be recorded once the inventory is COMPLETED.
        $this->completeInventory();

        $response = $this->postJson(route('corex.rental-inventories.lines.move-out-photos.store', [$this->inventory, $line]), [
            'photos' => [UploadedFile::fake()->image('found.jpg', 800, 600)],
            'client_idempotency_keys' => [(string) \Illuminate\Support\Str::uuid()],
        ])->assertStatus(201);

        $moveOutPhotoId = $response->json('photos.0.id');
        $this->assertNotSame($moveInPhoto->id, $moveOutPhotoId);
        $this->assertSame(RentalInventoryPhoto::SIDE_MOVE_OUT, RentalInventoryPhoto::find($moveOutPhotoId)->side);
        $this->assertSame(RentalInventoryPhoto::SIDE_MOVE_IN, $moveInPhoto->fresh()->side);

        $fresh = $line->fresh();
        $this->assertCount(1, $fresh->moveInPhotos);
        $this->assertCount(1, $fresh->moveOutPhotos);
        $this->assertSame($moveInPhoto->id, $fresh->moveInPhotos->first()->id);
        $this->assertSame($moveOutPhotoId, $fresh->moveOutPhotos->first()->id);
    }

    public function test_move_out_photo_upload_404s_for_a_line_on_a_different_inventory(): void
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

        $this->postJson(route('corex.rental-inventories.lines.move-out-photos.store', [$this->inventory, $foreignLine]), [
            'photos' => [UploadedFile::fake()->image('x.jpg')],
        ])->assertStatus(404);
    }

    public function test_comparison_page_shows_no_photo_taken_when_the_current_side_has_none(): void
    {
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Samsung TV 55"', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->completeInventory();

        $response = $this->get(route('corex.rental-inventories.comparison', $this->inventory));

        $response->assertOk();
        $response->assertSee('No photo taken yet.');
    }

    public function test_comparison_page_collapses_unchanged_lines_and_keeps_full_detail_for_a_real_difference(): void
    {
        $fine = RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 4, 'description' => 'Dining chairs', 'created_by_user_id' => $this->agent->id,
        ]);
        $missing = RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Gold padlock with key', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->completeInventory();
        RentalInventoryLineDisposition::record($fine, 'present', ['recorded_by_user_id' => $this->agent->id]);
        RentalInventoryLineDisposition::record($missing, 'missing', ['quantity_found' => 0, 'notes' => 'Not found anywhere on move-out', 'recorded_by_user_id' => $this->agent->id]);

        $response = $this->get(route('corex.rental-inventories.comparison', $this->inventory));

        $response->assertOk();
        $response->assertSee('no change');
        $response->assertSee('4 at move-in, 4 today', false);
        $response->assertSee('1 at move-in, 0 today', false);
        $response->assertSee('Missing');
    }

    public function test_comparison_page_renders_the_only_differences_toggle(): void
    {
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Samsung TV 55"', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->completeInventory();

        $response = $this->get(route('corex.rental-inventories.comparison', $this->inventory));

        $response->assertOk();
        $response->assertSee('Only differences');
    }

    public function test_settings_page_saves_baseline_disposition_key(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->actingAs($admin);

        $this->post(route('corex.settings.rental-inventory.update'), [
            'disposition_presets' => [
                ['key' => 'present', 'label' => 'All there', 'requires_notes' => '0'],
                ['key' => 'missing', 'label' => 'Missing', 'requires_notes' => '1'],
            ],
            'baseline_disposition_key' => 'present',
        ])->assertRedirect(route('corex.settings.rental-inventory.edit'));

        $this->assertSame('present', RentalInventorySetting::baselineDispositionKeyFor($this->agency->id));
    }

    public function test_settings_baseline_key_falls_back_to_default_if_it_no_longer_exists_in_the_saved_presets(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->actingAs($admin);

        // A baseline key that was just removed from the preset list in the
        // SAME save must never persist as an orphaned reference.
        $this->post(route('corex.settings.rental-inventory.update'), [
            'disposition_presets' => [
                ['key' => 'missing', 'label' => 'Missing', 'requires_notes' => '1'],
            ],
            'baseline_disposition_key' => 'present',
        ])->assertRedirect();

        $setting = RentalInventorySetting::where('agency_id', $this->agency->id)->first();
        $this->assertNull($setting->baseline_disposition_key);
        // Resolves sensibly anyway: 'missing' requires notes, so the
        // resolver falls through to "first preset needing no reason" —
        // here there is none, so it falls back to the first preset itself.
        $this->assertSame('missing', RentalInventorySetting::baselineDispositionKeyFor($this->agency->id));
    }
}
