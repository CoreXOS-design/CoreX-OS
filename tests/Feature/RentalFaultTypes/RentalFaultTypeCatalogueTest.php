<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultTypes;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalFaultType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * .ai/specs/rentals-faults-work-orders.md §2/§8.1/§13(3) — Slice 1: the
 * agency-configurable fault catalogue. Proves: seeding is idempotent and
 * agency-scoped, the safety line + power-tripping/DB-board content rules
 * survive seeding, full CRUD (create/edit/archive/restore) works and is
 * agency-isolated, and the property valve/DB-board fields save correctly.
 */
final class RentalFaultTypeCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'RFT Agency', 'slug' => 'rft-agency-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Test Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    public function test_seeding_creates_the_default_catalogue_for_a_fresh_agency(): void
    {
        RentalFaultType::seedDefaultsFor($this->agency->id);

        $this->assertSame(9, RentalFaultType::count());
        $this->assertTrue(RentalFaultType::where('name', 'Burst pipe / water leak')->where('urgency', 'emergency')->exists());
        $this->assertTrue(RentalFaultType::where('name', 'Power tripping / no power')->exists());
    }

    public function test_seeding_is_idempotent_and_never_overwrites_an_agency_edit(): void
    {
        RentalFaultType::seedDefaultsFor($this->agency->id);
        $burstPipe = RentalFaultType::where('name', 'Burst pipe / water leak')->firstOrFail();
        $burstPipe->update(['first_aid_steps' => 'Agency-customised wording.']);

        // Re-run the seeder (simulating a redeploy) — must not clobber the edit.
        RentalFaultType::seedDefaultsFor($this->agency->id);

        $this->assertSame(9, RentalFaultType::count());
        $this->assertSame('Agency-customised wording.', $burstPipe->fresh()->first_aid_steps);
    }

    public function test_every_seeded_default_opens_with_the_fixed_safety_line(): void
    {
        RentalFaultType::seedDefaultsFor($this->agency->id);

        foreach (RentalFaultType::all() as $faultType) {
            $this->assertStringStartsWith(RentalFaultType::SAFETY_LINE, $faultType->first_aid_steps);
        }
    }

    public function test_electrical_and_geyser_defaults_never_instruct_past_switching_a_breaker(): void
    {
        RentalFaultType::seedDefaultsFor($this->agency->id);

        $electrical = RentalFaultType::where('name', 'Power tripping / no power')->firstOrFail();
        $geyser = RentalFaultType::where('name', 'Geyser')->firstOrFail();

        // The underlying rule (§13(4)/§2.1): never instruct past switching a
        // breaker. "switching only" was this suite's own original phrasing
        // check — updated 2026-09-29 (Johan's QA1 fold-in) once Power
        // tripping's wording changed to describe the actual breaker
        // procedure directly rather than using that literal phrase; Geyser's
        // wording is unchanged and still uses it.
        $this->assertStringContainsString('circuit breakers', strtolower($electrical->first_aid_steps));
        $this->assertStringContainsString('switching only', strtolower($geyser->first_aid_steps));

        foreach ([$electrical, $geyser] as $faultType) {
            $this->assertStringNotContainsString('open the board', strtolower($faultType->first_aid_steps));
            $this->assertStringNotContainsString('remove the cover', strtolower($faultType->first_aid_steps));
        }
    }

    public function test_two_agencies_get_fully_independent_catalogues(): void
    {
        $otherAgency = Agency::create(['name' => 'RFT Other Agency', 'slug' => 'rft-other-' . uniqid()]);
        RentalFaultType::seedDefaultsFor($this->agency->id);
        RentalFaultType::seedDefaultsFor($otherAgency->id);

        $this->assertSame(9, RentalFaultType::where('agency_id', $this->agency->id)->count());
        $this->assertSame(9, RentalFaultType::where('agency_id', $otherAgency->id)->count());

        // Editing agency A's copy never touches agency B's.
        $mine = RentalFaultType::where('agency_id', $this->agency->id)->where('name', 'Geyser')->firstOrFail();
        $mine->update(['first_aid_steps' => 'Agency A only.']);
        $theirs = RentalFaultType::where('agency_id', $otherAgency->id)->where('name', 'Geyser')->firstOrFail();
        $this->assertNotSame('Agency A only.', $theirs->first_aid_steps);
    }

    public function test_agent_can_add_a_custom_fault_type(): void
    {
        $response = $this->actingAs($this->admin)->post(route('corex.rental-fault-types.store'), [
            'name' => 'Fence damage',
            'category' => 'Structural',
            'urgency' => 'routine',
            'first_aid_steps' => 'Photograph the damage.',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('corex.rental-fault-types.index'));
        $this->assertTrue(RentalFaultType::where('name', 'Fence damage')->where('agency_id', $this->agency->id)->exists());
    }

    public function test_agent_can_edit_a_fault_type(): void
    {
        RentalFaultType::seedDefaultsFor($this->agency->id);
        $faultType = RentalFaultType::where('name', 'Lock / keys')->firstOrFail();

        $this->actingAs($this->admin)->put(route('corex.rental-fault-types.update', $faultType), [
            'name' => 'Lock / keys',
            'category' => 'Security',
            'urgency' => 'urgent',
            'first_aid_steps' => 'Updated steps.',
            'is_active' => '1',
        ])->assertRedirect(route('corex.rental-fault-types.index'));

        $this->assertSame('urgent', $faultType->fresh()->urgency);
    }

    public function test_archive_is_soft_delete_and_removes_it_from_the_active_list_only(): void
    {
        RentalFaultType::seedDefaultsFor($this->agency->id);
        $faultType = RentalFaultType::where('name', 'Roof leak')->firstOrFail();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-types.archive', $faultType))
            ->assertRedirect();

        $this->assertSoftDeleted('rental_fault_types', ['id' => $faultType->id]);
        $this->assertFalse($faultType->fresh()->is_active);
        // Never hard-deleted — non-negotiable #1.
        $this->assertDatabaseHas('rental_fault_types', ['id' => $faultType->id]);
    }

    public function test_archived_fault_type_can_be_restored(): void
    {
        RentalFaultType::seedDefaultsFor($this->agency->id);
        $faultType = RentalFaultType::where('name', 'Appliance')->firstOrFail();
        $faultType->archive();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-types.restore', $faultType->id))
            ->assertRedirect();

        $this->assertNull($faultType->fresh()->deleted_at);
        $this->assertTrue($faultType->fresh()->is_active);
    }

    public function test_a_video_link_document_can_be_attached_without_a_file_upload(): void
    {
        $faultType = RentalFaultType::create([
            'agency_id' => $this->agency->id, 'name' => 'Test fault', 'urgency' => 'routine',
            'sort_order' => 1, 'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->post(route('corex.rental-fault-types.documents.store', $faultType), [
            'video_url' => 'https://youtube.com/watch?v=example',
            'caption' => 'How to find the valve',
        ])->assertRedirect();

        $this->assertDatabaseHas('rental_fault_type_documents', [
            'rental_fault_type_id' => $faultType->id,
            'document_type' => 'video_link',
            'external_url' => 'https://youtube.com/watch?v=example',
        ]);
    }

    public function test_an_image_document_is_stored_and_downloadable_reference_persists(): void
    {
        Storage::fake('public');
        $faultType = RentalFaultType::create([
            'agency_id' => $this->agency->id, 'name' => 'Test fault 2', 'urgency' => 'routine',
            'sort_order' => 1, 'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->post(route('corex.rental-fault-types.documents.store', $faultType), [
            'image' => UploadedFile::fake()->image('valve.jpg'),
        ])->assertRedirect();

        $doc = $faultType->documents()->first();
        $this->assertSame('image', $doc->document_type);
        Storage::disk('public')->assertExists($doc->storage_path);
    }

    // ── Property fields, §3 ──────────────────────────────────────────────

    public function test_property_water_valve_and_db_board_location_can_be_saved(): void
    {
        $this->property->update(['listing_type' => 'rental', 'listing_type_pending' => false]);

        $this->actingAs($this->admin)->put(route('corex.properties.rental-details.update', $this->property), [
            'rental_main_water_valve_location' => 'Outside, left of the front door',
            'rental_db_board_location' => 'Garage, back wall',
        ])->assertRedirect();

        $this->property->refresh();
        $this->assertSame('Outside, left of the front door', $this->property->rental_main_water_valve_location);
        $this->assertSame('Garage, back wall', $this->property->rental_db_board_location);
    }

    public function test_property_water_valve_photo_uploads_and_saves_a_url(): void
    {
        Storage::fake('public');
        $this->property->update(['listing_type' => 'rental', 'listing_type_pending' => false]);

        $this->actingAs($this->admin)->put(route('corex.properties.rental-details.update', $this->property), [
            'rental_main_water_valve_location' => 'Outside',
            'rental_main_water_valve_photo' => UploadedFile::fake()->image('valve.jpg'),
        ])->assertRedirect();

        $this->property->refresh();
        $this->assertNotNull($this->property->rental_main_water_valve_photo_path);
    }
}
