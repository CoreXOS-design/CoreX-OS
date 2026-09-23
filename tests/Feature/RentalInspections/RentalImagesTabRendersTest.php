<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-inspections.md §4/§14 — Stage 3 UI, slices 2 (item
 * management) and 3 (observation recording). Proves the property show page
 * actually renders with the new Blade/Alpine additions, not just that
 * php -l is silent (which can't catch broken @if/@endif pairing, a stray
 * leftover bracket from an edit, or an undefined Blade variable).
 */
final class RentalImagesTabRendersTest extends TestCase
{
    use RefreshDatabase;

    public function test_rental_images_tab_renders_with_no_items_yet(): void
    {
        $agency = Agency::create(['name' => 'RI Tab Render Agency', 'slug' => 'ri-tab-render-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $property = Property::forceCreate([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'title' => 'Tab Render Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        // Johan's ruling, 2026-09-23 — the chain: In is always the first
        // link, so it's the only type offered from nothing. "Start
        // Out-Inspection" as an independent trigger (the old segregated
        // two-section shape this test used to lock in) no longer exists —
        // Out is only ever reachable via "Next inspection" from an
        // existing predecessor now (RentalInspection::startNext()).
        $this->actingAs($agent)
            ->get(route('corex.properties.show', $property->id))
            ->assertOk()
            ->assertSee('Inspection Items')
            ->assertSee('Start In-Inspection')
            ->assertDontSee('Start Out-Inspection');
    }

    public function test_rental_images_tab_renders_with_items_and_an_active_lease(): void
    {
        $agency = Agency::create(['name' => 'RI Tab Render Agency', 'slug' => 'ri-tab-render-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $property = Property::forceCreate([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'title' => 'Tab Render Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        Lease::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now(), 'created_by_user_id' => $agent->id,
        ]);
        RentalInspectionItem::create([
            'agency_id' => $agency->id, 'property_id' => $property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Main Bedroom', 'created_by_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('corex.properties.show', $property->id))
            ->assertOk()
            ->assertSee('Inspection Items')
            ->assertSee('Record…');
    }

    /**
     * §0.4 — the discrepancy fixture below needs a genuinely SECOND agent;
     * both observations used the same $agent until 2026-09-22 (see
     * RentalInspectionDiscrepancy::sameAuthor() — a same-author correction
     * is no longer treated as a conflict, so the fixture must represent a
     * real second person to still exercise the "Resolve" markup this test
     * asserts on).
     */
    public function test_rental_images_tab_renders_lifecycle_and_discrepancy_markup_for_an_inspection_under_way(): void
    {
        $agency = Agency::create(['name' => 'RI Tab Render Agency', 'slug' => 'ri-tab-render-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $secondAgent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $property = Property::forceCreate([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'title' => 'Tab Render Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $lease = Lease::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now(), 'created_by_user_id' => $agent->id,
        ]);
        $item = RentalInspectionItem::create([
            'agency_id' => $agency->id, 'property_id' => $property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Main Bedroom', 'created_by_user_id' => $agent->id,
        ]);
        $inspection = RentalInspection::create([
            'agency_id' => $agency->id, 'lease_id' => $lease->id, 'type' => RentalInspection::TYPE_IN,
            'created_by_user_id' => $agent->id,
        ]);
        RentalInspectionObservation::record([
            'agency_id' => $agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $agent->id, 'condition' => 'good', 'source' => 'in_inspection',
        ]);
        RentalInspectionObservation::record([
            'agency_id' => $agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $secondAgent->id, 'condition' => 'damaged', 'notes' => 'Cracked window.', 'source' => 'in_inspection',
        ]);

        $this->actingAs($agent)
            ->get(route('corex.properties.show', $property->id))
            ->assertOk()
            ->assertSee('Complete')
            ->assertSee('Resolve')
            ->assertSee('Ready to sign');
    }
}
