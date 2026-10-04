<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-439 Part 3 — the shared rental list standard (.ai/specs/rentals-rebuild.md
 * §1.1) applied to Rental Inspections: Total tile, per-page, print-list,
 * export (archived toggle already existed pre-Part 3). Also covers item 4
 * — RentalInspectionController::create() pre-selecting from a
 * property_id/lease_id query param. Search/sort/filter/pagination/scoping
 * are already covered by RentalInspectionListScreenTest.
 */
final class RentalInspectionListStandardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'RI List Standard Agency', 'slug' => 'rils-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
    }

    private function property(User $agent, string $title = 'Property'): Property
    {
        return Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $agent->id, 'branch_id' => $this->branch->id,
            'title' => $title, 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function activeLease(Property $property, User $creator): Lease
    {
        return Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now(), 'created_by_user_id' => $creator->id,
        ]);
    }

    private function inspection(Lease $lease, User $creator, array $attrs = []): RentalInspection
    {
        return RentalInspection::create(array_merge([
            'agency_id' => $this->agency->id,
            'lease_id' => $lease->id,
            'type' => RentalInspection::TYPE_IN,
            'created_by_user_id' => $creator->id,
        ], $attrs));
    }

    public function test_total_tile_count_equals_scoped_row_count(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $leaseA = $this->activeLease($this->property($admin, 'A'), $admin);
        $leaseB = $this->activeLease($this->property($admin, 'B'), $admin);
        $this->inspection($leaseA, $admin, ['status' => RentalInspection::STATUS_DRAFT]);
        $this->inspection($leaseB, $admin, ['status' => RentalInspection::STATUS_COMPLETED]);

        $response = $this->actingAs($admin)->get(route('corex.rental-inspections.index'));

        $response->assertOk();
        $this->assertSame(2, $response->viewData('tileCounts')['total']);
        $this->assertSame(2, $response->viewData('inspections')->total());
    }

    public function test_per_page_option_controls_page_size(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        for ($i = 0; $i < 15; $i++) {
            $this->inspection($this->activeLease($this->property($admin, "P{$i}"), $admin), $admin);
        }

        $response = $this->actingAs($admin)->get(route('corex.rental-inspections.index', ['per_page' => 10]));
        $this->assertCount(10, $response->viewData('inspections'));
    }

    public function test_print_list_shows_only_scoped_filtered_rows_with_active_filters_in_header(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $matching = $this->inspection($this->activeLease($this->property($admin, 'Match'), $admin), $admin, ['status' => RentalInspection::STATUS_COMPLETED]);
        $this->inspection($this->activeLease($this->property($admin, 'NoMatch'), $admin), $admin, ['status' => RentalInspection::STATUS_DRAFT]);

        $response = $this->actingAs($admin)->get(route('corex.rental-inspections.print-list', ['status' => 'completed']));

        $response->assertOk();
        $this->assertSame([$matching->id], $response->viewData('inspections')->pluck('id')->all());
        $this->assertSame('Completed', $response->viewData('printFilters')['Status']);
    }

    public function test_export_respects_scope_and_filters(): void
    {
        $agentOne = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $agentTwo = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->inspection($this->activeLease($this->property($agentOne, 'Mine'), $agentOne), $agentOne);
        $this->inspection($this->activeLease($this->property($agentTwo, 'Not mine'), $agentTwo), $agentTwo);

        $csv = $this->actingAs($agentOne)->get(route('corex.rental-inspections.export', ['format' => 'csv']));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('Mine', $body);
        $this->assertStringNotContainsString('Not mine', $body);
    }

    // ── Item 4 — create() pre-select from property_id/lease_id ─────────

    public function test_create_preselects_property_from_lease_id_param(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $property = $this->property($admin, 'Pre-select target');
        $lease = $this->activeLease($property, $admin);

        $response = $this->actingAs($admin)->get(route('corex.rental-inspections.create', ['lease_id' => $lease->id]));

        $response->assertOk();
        $this->assertSame($property->id, $response->viewData('selectedPropertyId'));
    }

    public function test_create_preselects_property_from_property_id_param(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $property = $this->property($admin, 'Pre-select via property_id');
        $this->activeLease($property, $admin);

        $response = $this->actingAs($admin)->get(route('corex.rental-inspections.create', ['property_id' => $property->id]));

        $response->assertOk();
        $this->assertSame($property->id, $response->viewData('selectedPropertyId'));
    }

    public function test_create_ignores_a_lease_id_outside_the_users_scope_instead_of_erroring(): void
    {
        $agentOne = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $agentTwo = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $otherLease = $this->activeLease($this->property($agentTwo, 'Not agent ones'), $agentTwo);

        $response = $this->actingAs($agentOne)->get(route('corex.rental-inspections.create', ['lease_id' => $otherLease->id]));

        $response->assertOk();
        $this->assertNull($response->viewData('selectedPropertyId'));
    }

    // ── Item 2 — context bar on the show page ───────────────────────────

    public function test_show_page_includes_the_context_bar_with_correct_inspections_count(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $lease = $this->activeLease($this->property($admin, 'Context bar property'), $admin);
        $first = $this->inspection($lease, $admin, ['type' => RentalInspection::TYPE_IN]);
        $this->inspection($lease, $admin, ['type' => RentalInspection::TYPE_AD_HOC]);

        $this->actingAs($admin)->get(route('corex.rental-inspections.show', $first))
            ->assertOk()
            ->assertSee('Inspections (2)', false);
    }

    public function test_create_ignores_a_lease_id_from_another_agency_instead_of_erroring(): void
    {
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $otherAgency->id]);
        \Illuminate\Support\Facades\Auth::logout();
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $otherProperty = Property::forceCreate([
            'agency_id' => $otherAgency->id, 'agent_id' => $otherAdmin->id, 'branch_id' => $otherBranch->id,
            'title' => 'Other agency property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $otherLease = Lease::create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'property_id' => $otherProperty->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 5000, 'start_date' => now(), 'created_by_user_id' => $otherAdmin->id,
        ]);

        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('corex.rental-inspections.create', ['lease_id' => $otherLease->id]));

        $response->assertOk();
        $this->assertNull($response->viewData('selectedPropertyId'));
    }
}
