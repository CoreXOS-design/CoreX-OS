<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalInspection;
use App\Models\RentalWorkOrder;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Rentals\RentalCommandCentreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-441 — .ai/specs/rental-command-centre.md. Covers: tile counts per
 * scope level (own/branch/all), agency isolation, each needs-action rule,
 * and search/sort/filter on the full table.
 */
final class RentalCommandCentreServiceTest extends TestCase
{
    use RefreshDatabase;

    private RentalCommandCentreService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RentalCommandCentreService::class);
    }

    public function test_tile_counts_never_leak_across_agencies(): void
    {
        [$agencyA, $branchA, $agentA] = $this->makeAgencyBranchAgent();
        [$agencyB, $branchB, $agentB] = $this->makeAgencyBranchAgent();

        $this->makeRentalProperty($agencyA, $branchA, $agentA);
        $this->makeRentalProperty($agencyA, $branchA, $agentA);
        $this->makeRentalProperty($agencyB, $branchB, $agentB);

        $this->grantAllScope($agentA, 'rental_command_centre', $agencyA->id);
        $this->grantAllScope($agentB, 'rental_command_centre', $agencyB->id);

        $this->actingAs($agentA);
        $tilesA = $this->service->tileCounts($agentA, 'all');
        self::assertSame(2, $tilesA['all']);

        $this->actingAs($agentB);
        $tilesB = $this->service->tileCounts($agentB, 'all');
        self::assertSame(1, $tilesB['all']);
    }

    public function test_own_scope_only_counts_the_agents_own_properties(): void
    {
        [$agency, $branch, $agentOne] = $this->makeAgencyBranchAgent();
        $agentTwo = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $this->makeRentalProperty($agency, $branch, $agentOne);
        $this->makeRentalProperty($agency, $branch, $agentTwo);

        $this->grantScope($agentOne, 'rental_command_centre', 'own', $agency->id);
        $this->actingAs($agentOne);

        $resolved = $this->service->resolveScope($agentOne, null);
        self::assertSame('own', $resolved);

        $tiles = $this->service->tileCounts($agentOne, $resolved);
        self::assertSame(1, $tiles['all']);
    }

    public function test_branch_scope_counts_the_whole_branch(): void
    {
        [$agency, $branch, $agentOne] = $this->makeAgencyBranchAgent();
        $agentTwo = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $otherBranch = Branch::create(['agency_id' => $agency->id, 'name' => 'Other Branch']);
        $agentThree = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $otherBranch->id, 'role' => 'agent']);

        $this->makeRentalProperty($agency, $branch, $agentOne);
        $this->makeRentalProperty($agency, $branch, $agentTwo);
        $this->makeRentalProperty($agency, $otherBranch, $agentThree);

        $this->grantScope($agentOne, 'rental_command_centre', 'branch', $agency->id);
        $this->actingAs($agentOne);

        $tiles = $this->service->tileCounts($agentOne, $this->service->resolveScope($agentOne, null));
        self::assertSame(2, $tiles['all']);
    }

    public function test_requested_scope_wider_than_ceiling_is_clamped(): void
    {
        [$agency, $branch, $agentOne] = $this->makeAgencyBranchAgent();
        $agentTwo = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $this->makeRentalProperty($agency, $branch, $agentOne);
        $this->makeRentalProperty($agency, $branch, $agentTwo);

        $this->grantScope($agentOne, 'rental_command_centre', 'own', $agency->id);
        $this->actingAs($agentOne);

        // A hand-edited ?scope=all must NOT widen past the ceiling.
        $resolved = $this->service->resolveScope($agentOne, 'all');
        self::assertSame('own', $resolved);
        self::assertSame(1, $this->service->tileCounts($agentOne, $resolved)['all']);
    }

    public function test_occupied_and_unoccupied_tiles(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $occupied = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeRentalProperty($agency, $branch, $agent); // vacant

        $this->makeActiveLease($agency, $branch, $occupied);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $tiles = $this->service->tileCounts($agent, 'all');
        self::assertSame(1, $tiles['occupied']);
        self::assertSame(1, $tiles['unoccupied']);
    }

    public function test_open_faults_tile_excludes_resolved_and_cancelled(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);

        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_REPORTED);
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_RESOLVED);
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_CANCELLED);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $tiles = $this->service->tileCounts($agent, 'all');
        self::assertSame(1, $tiles['open_faults']);
    }

    public function test_open_work_orders_tile_excludes_completed_and_cancelled(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);

        $this->makeWorkOrder($agency, $branch, $property, RentalWorkOrder::STATUS_REPORTED, now());
        $this->makeWorkOrder($agency, $branch, $property, RentalWorkOrder::STATUS_COMPLETED, now());

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $tiles = $this->service->tileCounts($agent, 'all');
        self::assertSame(1, $tiles['open_work_orders']);
    }

    public function test_notice_given_tile_is_zero_no_field_exists_yet(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeActiveLease($agency, $branch, $property);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        self::assertSame(0, $this->service->tileCounts($agent, 'all')['notice_given']);
    }

    public function test_queue_review_renewal_rule_fires_for_lease_expiring_in_window(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeActiveLease($agency, $branch, $property, ['end_date' => now()->addDays(10)->toDateString()]);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $items = $this->service->queueItems($agent, 'all');
        self::assertTrue($items->contains(fn ($i) => $i['type'] === 'review_renewal' && $i['property']->id === $property->id));
    }

    public function test_queue_record_outcome_rule_fires_for_active_lease_past_end_date(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeActiveLease($agency, $branch, $property, ['end_date' => now()->subDays(5)->toDateString()]);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $items = $this->service->queueItems($agent, 'all');
        self::assertTrue($items->contains(fn ($i) => $i['type'] === 'record_outcome' && $i['property']->id === $property->id));
    }

    public function test_queue_fault_awaiting_approval_rule(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $fault = $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_AWAITING_APPROVAL);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $items = $this->service->queueItems($agent, 'all');
        self::assertTrue($items->contains(fn ($i) => $i['type'] === 'fault_awaiting_approval' && $i['property']->id === $property->id));
    }

    public function test_queue_work_order_overdue_rule_uses_agency_setting(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        // Default overdue_reminder_days = 3 (RentalWorkOrderSetting::DEFAULT_OVERDUE_REMINDER_DAYS).
        $this->makeWorkOrder($agency, $branch, $property, RentalWorkOrder::STATUS_REPORTED, now()->subDays(10));
        $this->makeWorkOrder($agency, $branch, $property, RentalWorkOrder::STATUS_REPORTED, now()->subDay());

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $items = $this->service->queueItems($agent, 'all');
        $overdue = $items->filter(fn ($i) => $i['type'] === 'work_order_overdue');
        self::assertCount(1, $overdue);
    }

    public function test_queue_start_inspection_rule_for_active_lease_with_no_completed_in_inspection(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $lease = $this->makeActiveLease($agency, $branch, $property);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $items = $this->service->queueItems($agent, 'all');
        self::assertTrue($items->contains(fn ($i) => $i['type'] === 'start_inspection' && $i['lease']->id === $lease->id));

        RentalInspection::create([
            'agency_id' => $agency->id,
            'lease_id' => $lease->id,
            'property_id' => $property->id,
            'type' => RentalInspection::TYPE_IN,
            'status' => RentalInspection::STATUS_COMPLETED,
            'completed_at' => now(),
            'created_by_user_id' => $agent->id,
        ]);

        $itemsAfter = $this->service->queueItems($agent, 'all');
        self::assertFalse($itemsAfter->contains(fn ($i) => $i['type'] === 'start_inspection' && ($i['lease']->id ?? null) === $lease->id));
    }

    public function test_search_matches_address_and_erf_number(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $match = $this->makeRentalProperty($agency, $branch, $agent, ['title' => 'Unique Villa 1234', 'erf_number' => 'ERF-9999']);
        $this->makeRentalProperty($agency, $branch, $agent, ['title' => 'Different Place']);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $results = $this->service->tableQuery($agent, 'all', ['q' => 'ERF-9999'])->get();
        self::assertCount(1, $results);
        self::assertSame($match->id, $results->first()->id);
    }

    public function test_status_filter_narrows_the_table(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $this->makeRentalProperty($agency, $branch, $agent, ['status' => 'draft']);
        $withdrawn = $this->makeRentalProperty($agency, $branch, $agent, ['status' => 'withdrawn']);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $results = $this->service->tableQuery($agent, 'all', ['status' => 'withdrawn'])->get();
        self::assertCount(1, $results);
        self::assertSame($withdrawn->id, $results->first()->id);
    }

    public function test_sort_by_lease_end_ascending(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $soon = $this->makeRentalProperty($agency, $branch, $agent);
        $later = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeActiveLease($agency, $branch, $soon, ['end_date' => now()->addDays(5)->toDateString()]);
        $this->makeActiveLease($agency, $branch, $later, ['end_date' => now()->addDays(50)->toDateString()]);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $query = $this->service->tableQuery($agent, 'all', []);
        $this->service->applySort($query, 'lease_end', 'asc');
        $ids = $query->get()->pluck('id')->values()->all();

        self::assertSame($soon->id, $ids[0]);
    }

    public function test_tile_click_filters_the_table_to_the_same_set_the_tile_counted(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_REPORTED);
        $this->makeRentalProperty($agency, $branch, $agent); // no faults

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $tiles = $this->service->tileCounts($agent, 'all');
        $filtered = $this->service->tableQuery($agent, 'all', ['tile' => 'open_faults'])->get();

        self::assertSame($tiles['open_faults'], $filtered->count());
        self::assertSame($property->id, $filtered->first()->id);
    }

    // ───────────────────────────── fixtures ─────────────────────────────

    /** @return array{0: Agency, 1: Branch, 2: User} */
    private function makeAgencyBranchAgent(): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch ' . uniqid()]);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        return [$agency, $branch, $agent];
    }

    private function makeRentalProperty(Agency $agency, Branch $branch, User $agent, array $overrides = []): Property
    {
        return Property::create(array_merge([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'agent_id' => $agent->id,
            'title' => 'Rental property ' . uniqid(),
            'status' => 'active',
            'listing_type' => 'rental',
        ], $overrides));
    }

    private function makeActiveLease(Agency $agency, Branch $branch, Property $property, array $overrides = []): Lease
    {
        $lease = Lease::create(array_merge([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE,
            'rental_amount' => 9000,
            'start_date' => now()->subMonth()->toDateString(),
            'source' => 'manual',
        ], $overrides));

        $contact = Contact::create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'first_name' => 'Tenant',
            'last_name' => uniqid(),
            'email' => 'tenant-' . uniqid() . '@example.test',
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);

        return $lease;
    }

    private function makeFaultReport(Agency $agency, Branch $branch, Property $property, string $status): RentalFaultReport
    {
        return RentalFaultReport::create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'property_id' => $property->id,
            'status' => $status,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON,
            'title' => 'Fault ' . uniqid(),
            'description' => 'Test fault description',
            'reported_at' => now(),
        ]);
    }

    private function makeWorkOrder(Agency $agency, Branch $branch, Property $property, string $status, $reportedAt): RentalWorkOrder
    {
        return RentalWorkOrder::create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'property_id' => $property->id,
            'status' => $status,
            'title' => 'Work order ' . uniqid(),
            'description' => 'Test work order description',
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED,
            'reported_at' => $reportedAt,
        ]);
    }

    private function grantAllScope(User $user, string $module, int $agencyId): void
    {
        $this->grantScope($user, $module, 'all', $agencyId);
    }

    private function grantScope(User $user, string $module, string $scope, int $agencyId): void
    {
        RolePermission::create([
            'role' => $user->role,
            'permission_key' => $module . '.view',
            'scope' => $scope,
            'agency_id' => $agencyId,
        ]);
    }
}
