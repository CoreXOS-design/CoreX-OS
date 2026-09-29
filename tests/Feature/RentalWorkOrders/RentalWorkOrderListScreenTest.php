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
 * Stage 4 list-screen verification for .ai/specs/rental-work-orders.md
 * §3/§6 — same BUILD_STANDARD §1a-§1d floor as the fault-reports and
 * inspections lists: search, sort, filter, pagination, empty state,
 * OWN/BRANCH/AGENCY scoping enforced at the query layer.
 */
final class RentalWorkOrderListScreenTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branchA;
    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'RWO List Agency', 'slug' => 'rwo-list-' . uniqid()]);
        $this->branchA = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->branchB = Branch::forceCreate(['name' => 'Margate', 'agency_id' => $this->agency->id]);
    }

    private function property(Branch $branch, string $address, User $agent): Property
    {
        return Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'title' => $address, 'status' => 'active', 'listing_type' => 'rental',
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

    public function test_search_matches_property_address_and_title(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $this->workOrder($this->property($this->branchA, '19 Windsor Avenue', $admin), $admin, ['title' => 'Roof leak']);
        $this->workOrder($this->property($this->branchA, 'Somewhere else', $admin), $admin, ['title' => 'Fence broken']);

        $this->actingAs($admin)->get(route('corex.rental-work-orders.index', ['q' => 'Windsor']))
            ->assertOk()->assertSee('Windsor Avenue');
        $this->actingAs($admin)->get(route('corex.rental-work-orders.index', ['q' => 'Roof']))
            ->assertOk()->assertSee('Windsor Avenue');
    }

    public function test_default_sort_is_reported_at_most_recent_first(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $older = $this->workOrder($this->property($this->branchA, 'Older', $admin), $admin, ['reported_at' => now()->subDays(10)]);
        $newer = $this->workOrder($this->property($this->branchA, 'Newer', $admin), $admin, ['reported_at' => now()->subDay()]);

        $response = $this->actingAs($admin)->get(route('corex.rental-work-orders.index'));

        $response->assertOk();
        $ids = $response->viewData('workOrders')->pluck('id')->all();
        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_status_and_paid_by_filters_narrow_the_list(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $completed = $this->workOrder($this->property($this->branchA, 'A', $admin), $admin, [
            'status' => RentalWorkOrder::STATUS_COMPLETED, 'paid_by' => RentalWorkOrder::PAID_BY_OWNER,
        ]);
        $this->workOrder($this->property($this->branchA, 'B', $admin), $admin, ['status' => RentalWorkOrder::STATUS_REPORTED]);

        $response = $this->actingAs($admin)->get(route('corex.rental-work-orders.index', ['status' => 'completed']));
        $this->assertSame([$completed->id], $response->viewData('workOrders')->pluck('id')->all());

        $response = $this->actingAs($admin)->get(route('corex.rental-work-orders.index', ['paid_by' => 'owner']));
        $this->assertSame([$completed->id], $response->viewData('workOrders')->pluck('id')->all());
    }

    public function test_overdue_filter_finds_only_stale_ordered_or_in_progress_work_orders(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $stale = $this->workOrder($this->property($this->branchA, 'Stale', $admin), $admin, ['status' => RentalWorkOrder::STATUS_ORDERED]);
        $stale->timestamps = false;
        $stale->updated_at = now()->subDays(5);
        $stale->saveQuietly();

        $this->workOrder($this->property($this->branchA, 'Fresh', $admin), $admin, ['status' => RentalWorkOrder::STATUS_ORDERED]);
        $this->workOrder($this->property($this->branchA, 'Completed and stale', $admin), $admin, ['status' => RentalWorkOrder::STATUS_COMPLETED]);

        $response = $this->actingAs($admin)->get(route('corex.rental-work-orders.index', ['overdue' => 1]));
        $this->assertSame([$stale->id], $response->viewData('workOrders')->pluck('id')->all());
    }

    public function test_paginates_at_twenty_five(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        for ($i = 0; $i < 30; $i++) {
            $this->workOrder($this->property($this->branchA, "Property {$i}", $admin), $admin);
        }

        $response = $this->actingAs($admin)->get(route('corex.rental-work-orders.index'));
        $this->assertCount(25, $response->viewData('workOrders'));
        $this->assertSame(30, $response->viewData('workOrders')->total());
    }

    public function test_empty_state_distinguishes_none_yet_from_none_matching_filter(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);

        $this->actingAs($admin)->get(route('corex.rental-work-orders.index'))
            ->assertOk()->assertSee('No work orders yet on this agency');

        $this->workOrder($this->property($this->branchA, 'Only one', $admin), $admin, ['status' => RentalWorkOrder::STATUS_COMPLETED]);

        $this->actingAs($admin)->get(route('corex.rental-work-orders.index', ['status' => 'cancelled']))
            ->assertOk()->assertSee('No work orders match this search or filter');
    }

    public function test_agent_sees_only_own_created_work_orders(): void
    {
        $agentOne = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent']);
        $agentTwo = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent']);
        $own = $this->workOrder($this->property($this->branchA, 'Mine', $agentOne), $agentOne);
        $this->workOrder($this->property($this->branchA, 'Not mine', $agentTwo), $agentTwo);

        $response = $this->actingAs($agentOne)->get(route('corex.rental-work-orders.index'));
        $this->assertSame([$own->id], $response->viewData('workOrders')->pluck('id')->all());
    }

    public function test_branch_manager_sees_branch_but_not_other_branch(): void
    {
        $manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'branch_manager']);
        $agentSameBranch = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent']);
        $agentOtherBranch = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchB->id, 'role' => 'agent']);

        $inBranch = $this->workOrder($this->property($this->branchA, 'Branch A property', $agentSameBranch), $agentSameBranch);
        $this->workOrder($this->property($this->branchB, 'Branch B property', $agentOtherBranch), $agentOtherBranch);

        $response = $this->actingAs($manager)->get(route('corex.rental-work-orders.index'));
        $this->assertSame([$inBranch->id], $response->viewData('workOrders')->pluck('id')->all());
    }

    public function test_show_renders_the_work_order(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $workOrder = $this->workOrder($this->property($this->branchA, 'Detail property', $admin), $admin, ['title' => 'Cracked tile']);

        $this->actingAs($admin)->get(route('corex.rental-work-orders.show', $workOrder))
            ->assertOk()->assertSee('Cracked tile');
    }

    public function test_a_work_order_with_an_update_cannot_be_deleted_only_cancelled(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $workOrder = $this->workOrder($this->property($this->branchA, 'Has evidence', $admin), $admin);
        $workOrder->addNote('Called the plumber.', $admin);

        $this->actingAs($admin)->delete(route('corex.rental-work-orders.destroy', $workOrder))->assertSessionHasErrors();
        $this->assertNull($workOrder->fresh()->deleted_at);
    }

    public function test_a_work_order_with_no_evidence_can_be_archived_and_restored(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $workOrder = $this->workOrder($this->property($this->branchA, 'No evidence yet', $admin), $admin);

        $this->actingAs($admin)->delete(route('corex.rental-work-orders.destroy', $workOrder))
            ->assertRedirect(route('corex.rental-work-orders.index'));
        $this->assertNotNull($workOrder->fresh()->deleted_at);

        $this->actingAs($admin)->post(route('corex.rental-work-orders.restore', $workOrder->id))
            ->assertRedirect(route('corex.rental-work-orders.show', $workOrder));
        $this->assertNull($workOrder->fresh()->deleted_at);
    }

    public function test_cross_agency_work_order_is_not_reachable_by_id(): void
    {
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $otherAgency->id]);
        \Illuminate\Support\Facades\Auth::logout();
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $otherProperty = Property::forceCreate([
            'agency_id' => $otherAgency->id, 'agent_id' => $otherAdmin->id, 'branch_id' => $otherBranch->id,
            'title' => 'Other agency property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $otherWorkOrder = RentalWorkOrder::create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'property_id' => $otherProperty->id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $otherAdmin->id,
            'title' => 'Other agency work order', 'description' => 'x', 'status' => RentalWorkOrder::STATUS_REPORTED,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $otherAdmin->id,
        ]);

        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);

        $this->actingAs($admin)->get(route('corex.rental-work-orders.show', $otherWorkOrder))->assertNotFound();
    }
}
