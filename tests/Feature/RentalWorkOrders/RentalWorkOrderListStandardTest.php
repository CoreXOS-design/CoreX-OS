<?php

declare(strict_types=1);

namespace Tests\Feature\RentalWorkOrders;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalWorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-439 Part 3 — the shared rental list standard (.ai/specs/rentals-rebuild.md
 * §1.1) applied to Rental Work Orders: Total tile, per-page, show-archived
 * (new — the list previously had no way to browse an archived work
 * order), print-list, export. Search/sort/filter/pagination/scoping are
 * already covered by RentalWorkOrderListScreenTest.
 */
final class RentalWorkOrderListStandardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'RWO List Standard Agency', 'slug' => 'rwols-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
    }

    private function property(User $agent, string $title = 'Property'): Property
    {
        return Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $agent->id, 'branch_id' => $this->branch->id,
            'title' => $title, 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function workOrder(Property $property, User $creator, array $attrs = []): RentalWorkOrder
    {
        return RentalWorkOrder::create(array_merge([
            'agency_id' => $this->agency->id,
            'branch_id' => $property->branch_id,
            'property_id' => $property->id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED,
            'reported_by_user_id' => $creator->id,
            'title' => 'Geyser burst',
            'description' => 'Water everywhere.',
            'status' => RentalWorkOrder::STATUS_REPORTED,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED,
            'reported_at' => now(),
            'created_by_user_id' => $creator->id,
        ], $attrs));
    }

    public function test_total_tile_count_equals_scoped_row_count(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->workOrder($this->property($admin, 'A'), $admin, ['status' => RentalWorkOrder::STATUS_REPORTED]);
        $this->workOrder($this->property($admin, 'B'), $admin, ['status' => RentalWorkOrder::STATUS_COMPLETED]);

        $response = $this->actingAs($admin)->get(route('corex.rental-work-orders.index'));

        $response->assertOk();
        $this->assertSame(2, $response->viewData('tileCounts')['total']);
        $this->assertSame(2, $response->viewData('workOrders')->total());
    }

    public function test_per_page_option_controls_page_size(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        for ($i = 0; $i < 15; $i++) {
            $this->workOrder($this->property($admin, "P{$i}"), $admin);
        }

        $response = $this->actingAs($admin)->get(route('corex.rental-work-orders.index', ['per_page' => 10]));
        $this->assertCount(10, $response->viewData('workOrders'));
    }

    public function test_show_archived_lists_only_archived_work_orders_and_restore_brings_it_back(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $live = $this->workOrder($this->property($admin, 'Live'), $admin);
        $archived = $this->workOrder($this->property($admin, 'Archived'), $admin);
        $archived->delete();

        $response = $this->actingAs($admin)->get(route('corex.rental-work-orders.index', ['archived' => 1]));
        $this->assertSame([$archived->id], $response->viewData('workOrders')->pluck('id')->all());

        $response = $this->actingAs($admin)->get(route('corex.rental-work-orders.index'));
        $this->assertSame([$live->id], $response->viewData('workOrders')->pluck('id')->all());

        $this->actingAs($admin)->post(route('corex.rental-work-orders.restore', $archived->id))
            ->assertRedirect();
        $this->assertNull($archived->fresh()->deleted_at);
    }

    public function test_print_list_shows_only_scoped_filtered_rows_with_active_filters_in_header(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $matching = $this->workOrder($this->property($admin, 'Match'), $admin, ['status' => RentalWorkOrder::STATUS_COMPLETED]);
        $this->workOrder($this->property($admin, 'NoMatch'), $admin, ['status' => RentalWorkOrder::STATUS_REPORTED]);

        $response = $this->actingAs($admin)->get(route('corex.rental-work-orders.print-list', ['status' => 'completed']));

        $response->assertOk();
        $this->assertSame([$matching->id], $response->viewData('workOrders')->pluck('id')->all());
        $this->assertSame('Completed', $response->viewData('printFilters')['Status']);
    }

    public function test_export_respects_scope_and_filters(): void
    {
        $agentOne = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $agentTwo = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->workOrder($this->property($agentOne, 'Mine'), $agentOne);
        $this->workOrder($this->property($agentTwo, 'Not mine'), $agentTwo);

        $csv = $this->actingAs($agentOne)->get(route('corex.rental-work-orders.export', ['format' => 'csv']));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('Mine', $body);
        $this->assertStringNotContainsString('Not mine', $body);
    }

    // ── Item 2 — context bar on the show page ───────────────────────────

    public function test_show_page_includes_the_context_bar_with_correct_work_orders_count(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $property = $this->property($admin, 'Context bar property');
        $first = $this->workOrder($property, $admin);
        $this->workOrder($property, $admin);

        $this->actingAs($admin)->get(route('corex.rental-work-orders.show', $first))
            ->assertOk()
            ->assertSee('Work orders (2)', false);
    }

    // ── Item 3 — the list screen's own property-filter picker ──────────

    /**
     * Only properties that actually have a work order visible to this
     * user, never every rental property — the unscoped picker
     * (searchProperties(), AT-442 fix #2) stays on the create screen,
     * unaffected by this separately-named endpoint.
     */
    public function test_search_filter_properties_only_returns_properties_with_a_visible_work_order(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $withOrder = $this->property($admin, 'Has a work order');
        $this->workOrder($withOrder, $admin);
        $withoutOrder = $this->property($admin, 'No work order at all');

        $response = $this->actingAs($admin)->getJson(route('corex.rental-work-orders.search-filter-properties', ['q' => 'order']));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($withOrder->id, $ids);
        $this->assertNotContains($withoutOrder->id, $ids);
    }

    public function test_search_filter_properties_never_returns_another_agencys_property(): void
    {
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $otherAgency->id]);
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $otherProperty = Property::forceCreate([
            'agency_id' => $otherAgency->id, 'agent_id' => $otherAdmin->id, 'branch_id' => $otherBranch->id,
            'title' => 'Other agency ordered property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        RentalWorkOrder::create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'property_id' => $otherProperty->id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $otherAdmin->id,
            'title' => 'Geyser', 'description' => 'desc', 'status' => RentalWorkOrder::STATUS_REPORTED,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
            'created_by_user_id' => $otherAdmin->id,
        ]);

        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        $response = $this->actingAs($admin)->getJson(route('corex.rental-work-orders.search-filter-properties', ['q' => 'ordered']));

        $response->assertOk();
        $this->assertSame([], $response->json());
    }

    /** The CREATE form's own picker must keep offering every rental property, completely unaffected by item 3. */
    public function test_create_forms_own_property_picker_still_offers_properties_with_no_work_order(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $neverOrdered = $this->property($admin, 'Never had a work order yet');

        $response = $this->actingAs($admin)->getJson(route('corex.rental-work-orders.search-properties', ['q' => 'Never had']));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($neverOrdered->id, $ids);
    }
}
