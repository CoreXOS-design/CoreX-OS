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
use App\Models\RentalCommandCentreUserPreference;
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

    /**
     * Matches RentalFaultReportController::index()'s own "open_no_work_order"
     * exception tile's status set exactly (§39): resolved/cancelled/declined
     * are the only closed statuses.
     */
    public function test_open_faults_tile_excludes_resolved_cancelled_and_declined(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);

        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_REPORTED);
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_RESOLVED);
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_CANCELLED);
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_DECLINED);

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

    /**
     * Conductor browser-verification fix, 2026-10-04 — the bug: tile showed
     * 1 (count of PROPERTIES with ≥1 open work order) while the property's
     * own row column and the Rental Work Orders list both showed 2 (one
     * Reported + one Ordered). The tile must equal the SUM of the per-row
     * column across every scoped property, never the count of properties.
     */
    public function test_open_work_orders_tile_equals_sum_of_per_row_column_not_property_count(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $propertyOne = $this->makeRentalProperty($agency, $branch, $agent);
        $propertyTwo = $this->makeRentalProperty($agency, $branch, $agent);

        $this->makeWorkOrder($agency, $branch, $propertyOne, RentalWorkOrder::STATUS_REPORTED, now());
        $this->makeWorkOrder($agency, $branch, $propertyOne, RentalWorkOrder::STATUS_ORDERED, now());
        $this->makeWorkOrder($agency, $branch, $propertyTwo, RentalWorkOrder::STATUS_IN_PROGRESS, now());

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $tiles = $this->service->tileCounts($agent, 'all');
        $rows = $this->service->derivedPropertyQuery($agent, 'all')->get(['id', 'open_work_orders_count']);

        self::assertSame(3, $tiles['open_work_orders']);
        self::assertSame(3, (int) $rows->sum('open_work_orders_count'));
        // The property-count would have been 2 — proving this is genuinely
        // a different (and now correct) number, not a coincidence.
        self::assertNotSame(2, $tiles['open_work_orders']);
    }

    public function test_open_faults_tile_equals_sum_of_per_row_column_not_property_count(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $propertyOne = $this->makeRentalProperty($agency, $branch, $agent);
        $propertyTwo = $this->makeRentalProperty($agency, $branch, $agent);

        $this->makeFaultReport($agency, $branch, $propertyOne, RentalFaultReport::STATUS_AWAITING_APPROVAL);
        $this->makeFaultReport($agency, $branch, $propertyOne, RentalFaultReport::STATUS_AWAITING_APPROVAL);
        $this->makeFaultReport($agency, $branch, $propertyTwo, RentalFaultReport::STATUS_APPROVED);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $tiles = $this->service->tileCounts($agent, 'all');
        $rows = $this->service->derivedPropertyQuery($agent, 'all')->get(['id', 'open_faults_count']);

        self::assertSame(3, $tiles['open_faults']);
        self::assertSame(3, (int) $rows->sum('open_faults_count'));
        self::assertNotSame(2, $tiles['open_faults']);
    }

    /**
     * AT-444 follow-up (2026-10-05) — leases.notice_date now exists, so
     * this tile is wired for real instead of the old hardcoded 0.
     */
    public function test_notice_given_tile_is_zero_when_no_lease_has_notice(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeActiveLease($agency, $branch, $property);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        self::assertSame(0, $this->service->tileCounts($agent, 'all')['notice_given']);
    }

    public function test_notice_given_tile_counts_active_leases_with_active_notice_and_matches_the_table_filter(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $withNotice = $this->makeRentalProperty($agency, $branch, $agent);
        $withoutNotice = $this->makeRentalProperty($agency, $branch, $agent);

        $leaseWithNotice = $this->makeActiveLease($agency, $branch, $withNotice, [
            'notice_date' => now()->addDays(20)->toDateString(),
            'notice_given_by' => Lease::NOTICE_BY_TENANT,
        ]);
        $this->makeActiveLease($agency, $branch, $withoutNotice);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        // Mirrors Lease::hasActiveNotice()'s own definition exactly.
        self::assertTrue($leaseWithNotice->fresh()->hasActiveNotice());

        $tiles = $this->service->tileCounts($agent, 'all');
        $filtered = $this->service->tableQuery($agent, 'all', ['tile' => 'notice_given'])->get();

        self::assertSame(1, $tiles['notice_given']);
        self::assertSame($tiles['notice_given'], $filtered->count());
        self::assertSame($withNotice->id, $filtered->first()->id);
    }

    /**
     * AT-444 follow-up (2026-10-05) — "Renewals in progress" is the ONE
     * definition Lease::hasPendingRenewalDraft() mirrors; this proves the
     * Command Centre's SQL-level count agrees with that model method, not
     * just with itself.
     */
    public function test_renewals_in_progress_tile_matches_lease_has_pending_renewal_draft_and_the_table_filter(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $renewing = $this->makeRentalProperty($agency, $branch, $agent);
        $notRenewing = $this->makeRentalProperty($agency, $branch, $agent);

        $activeBeingRenewed = $this->makeActiveLease($agency, $branch, $renewing);
        Lease::create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'property_id' => $renewing->id,
            'previous_lease_id' => $activeBeingRenewed->id,
            'status' => Lease::STATUS_DRAFT,
            'rental_amount' => 9500,
            'start_date' => now()->addMonth()->toDateString(),
            'source' => 'manual',
        ]);
        $activeNotRenewing = $this->makeActiveLease($agency, $branch, $notRenewing);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        self::assertTrue($activeBeingRenewed->fresh()->hasPendingRenewalDraft());
        self::assertFalse($activeNotRenewing->fresh()->hasPendingRenewalDraft());

        $tiles = $this->service->tileCounts($agent, 'all');
        $filtered = $this->service->tableQuery($agent, 'all', ['tile' => 'renewals_in_progress'])->get();

        self::assertSame(1, $tiles['renewals_in_progress']);
        self::assertSame($tiles['renewals_in_progress'], $filtered->count());
        self::assertSame($renewing->id, $filtered->first()->id);
    }

    public function test_review_renewal_queue_item_links_to_the_renew_dialog(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $lease = $this->makeActiveLease($agency, $branch, $property, ['end_date' => now()->addDays(10)->toDateString()]);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $item = $this->service->queueItems($agent, 'all')->first(fn ($i) => $i['type'] === 'review_renewal');

        self::assertNotNull($item);
        self::assertSame($lease->id, $item['route_params']['lease']);
        self::assertSame('renew', $item['route_params']['action']);
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

    /**
     * AT-444 follow-up 3 (2026-10-05) — once
     * rentals:prepare-renewal-drafts has drafted a lease
     * (Lease::hasPendingRenewalDraft()), this row's own type/label changes
     * so the agent sees it's ready rather than still "needs review".
     */
    public function test_queue_shows_renewal_draft_ready_once_a_draft_exists(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $lease = $this->makeActiveLease($agency, $branch, $property, [
            'end_date' => now()->addDays(10)->toDateString(), 'created_by_user_id' => $agent->id,
        ]);
        Lease::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'previous_lease_id' => $lease->id, 'status' => Lease::STATUS_DRAFT, 'rental_amount' => 9500,
            'start_date' => now()->addDays(11)->toDateString(), 'source' => 'manual',
        ]);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        // Also has an outstanding start_inspection row (no completed
        // in-inspection) — a lease can need more than one action at once
        // (the queue's own "never collapsed per property" design), so this
        // filters by type, not just by lease.
        $item = $this->service->queueItems($agent, 'all')->first(fn ($i) => $i['type'] === 'renewal_draft_ready' && $i['lease']?->id === $lease->id);

        self::assertNotNull($item);
        self::assertSame('Renewal draft ready', $item['label']);
        self::assertStringContainsString('R9,500.00', $item['detail']);
    }

    /**
     * No e-sign source, no agency template configured at all — the command
     * would never draft this one, so the row names the gap instead.
     */
    public function test_queue_shows_missing_info_when_the_lease_cannot_be_auto_drafted(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $lease = $this->makeActiveLease($agency, $branch, $property, [
            'end_date' => now()->addDays(10)->toDateString(), 'created_by_user_id' => $agent->id,
        ]);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $item = $this->service->queueItems($agent, 'all')->first(fn ($i) => $i['type'] === 'review_renewal' && $i['lease']?->id === $lease->id);

        self::assertNotNull($item);
        self::assertStringContainsString('Missing:', $item['detail']);
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

    /**
     * Reuses RentalWorkOrder::scopeOverdue() exactly — status IN
     * [ordered,in_progress] AND updated_at past the threshold. A merely
     * "reported" (never ordered) work order, however old, is NOT overdue
     * in this codebase's own vocabulary — asserted explicitly here because
     * the pre-fix version wrongly used 'reported_at' and included
     * 'reported' status, disagreeing with the real list screen.
     */
    public function test_queue_work_order_overdue_rule_matches_the_real_overdue_scope(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        // Default overdue_reminder_days = 3 (RentalWorkOrderSetting::DEFAULT_OVERDUE_REMINDER_DAYS).
        $overdueOrdered = $this->makeWorkOrder($agency, $branch, $property, RentalWorkOrder::STATUS_ORDERED, now()->subDays(10));
        $this->ageUpdatedAt($overdueOrdered, now()->subDays(10));

        $notYetOverdueOrdered = $this->makeWorkOrder($agency, $branch, $property, RentalWorkOrder::STATUS_ORDERED, now());
        $this->ageUpdatedAt($notYetOverdueOrdered, now()->subDay());

        $staleButOnlyReported = $this->makeWorkOrder($agency, $branch, $property, RentalWorkOrder::STATUS_REPORTED, now());
        $this->ageUpdatedAt($staleButOnlyReported, now()->subDays(30));

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $items = $this->service->queueItems($agent, 'all');
        $overdue = $items->filter(fn ($i) => $i['type'] === 'work_order_overdue');

        self::assertCount(1, $overdue);
        self::assertSame($overdueOrdered->id, $overdue->first()['route_params']['rentalWorkOrder']);
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

    /**
     * "occupied" is a genuine property-level tile — its count and its
     * table-filter row count are the SAME number by definition (unlike
     * open_faults/open_work_orders, now deliberately a total-vs-property-
     * count split — see the dedicated sum tests above).
     */
    public function test_tile_click_filters_the_table_to_the_same_set_the_tile_counted(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $occupied = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeActiveLease($agency, $branch, $occupied);
        $this->makeRentalProperty($agency, $branch, $agent); // vacant

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $tiles = $this->service->tileCounts($agent, 'all');
        $filtered = $this->service->tableQuery($agent, 'all', ['tile' => 'occupied'])->get();

        self::assertSame($tiles['occupied'], $filtered->count());
        self::assertSame($occupied->id, $filtered->first()->id);
    }

    /**
     * The "open_faults"/"open_work_orders" TILE clicks still filter the
     * table to properties with ≥1 open item (a property-level filter) even
     * though the tile NUMBER itself is now a total count, not a property
     * count — two genuinely different, both-correct numbers.
     */
    public function test_open_faults_tile_click_still_filters_to_properties_with_any_open_fault(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_REPORTED);
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_AWAITING_APPROVAL);
        $this->makeRentalProperty($agency, $branch, $agent); // no faults

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $tiles = $this->service->tileCounts($agent, 'all');
        $filtered = $this->service->tableQuery($agent, 'all', ['tile' => 'open_faults'])->get();

        self::assertSame(2, $tiles['open_faults']);
        self::assertCount(1, $filtered);
        self::assertSame($property->id, $filtered->first()->id);
    }

    /**
     * Conductor browser-verification fix — two identical "Fault awaiting
     * owner approval" rows on the same property were indistinguishable.
     * Each queue row must name ITS OWN record.
     */
    public function test_queue_rows_name_their_own_record(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $faultOne = $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_AWAITING_APPROVAL, 'Leaking roof');
        $faultTwo = $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_AWAITING_APPROVAL, 'Broken gate motor');

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $items = $this->service->queueItems($agent, 'all')->filter(fn ($i) => $i['type'] === 'fault_awaiting_approval');
        self::assertCount(2, $items);

        $details = $items->pluck('detail')->all();
        self::assertContains('Leaking roof', $details);
        self::assertContains('Broken gate motor', $details);
        self::assertNotSame($details[0] ?? null, $details[1] ?? null);

        // Each row's button opens THAT specific fault, not the other one.
        $byFault = $items->keyBy(fn ($i) => $i['route_params']['rentalFaultReport']);
        self::assertSame('Leaking roof', $byFault[$faultOne->id]['detail']);
        self::assertSame('Broken gate motor', $byFault[$faultTwo->id]['detail']);
    }

    public function test_queue_lease_based_rows_name_the_tenant(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeActiveLease($agency, $branch, $property, ['end_date' => now()->addDays(10)->toDateString()]);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $item = $this->service->queueItems($agent, 'all')->first(fn ($i) => $i['type'] === 'review_renewal');
        self::assertNotNull($item);
        self::assertStringContainsString('Tenant', $item['detail']);
        self::assertStringNotContainsString('Lease ends', $item['detail']); // old redundant date text is gone
    }

    public function test_default_sort_is_lease_end_ascending_with_vacant_last(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $soon = $this->makeRentalProperty($agency, $branch, $agent);
        $vacant = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeActiveLease($agency, $branch, $soon, ['end_date' => now()->addDays(5)->toDateString()]);
        // $vacant has no lease at all — active_end_date is NULL.

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        // No explicit sort param — exercises the service's own default.
        $query = $this->service->tableQuery($agent, 'all', []);
        $this->service->applySort($query, null, null);
        $ids = $query->get()->pluck('id')->values()->all();

        self::assertSame([$soon->id, $vacant->id], $ids);
    }

    /**
     * Carbon 3 changed diffInDays()'s default from an absolute int to a
     * SIGNED FLOAT — every age_days using the old bare `diffInDays()` call
     * silently started returning negative decimals (e.g. -11.06) for any
     * past-dated reference, which the blade's `age_days > 0` display guard
     * then hid entirely. Locks in: every "how old" value is a clean,
     * non-negative whole number of days (rule A's future-deadline value is
     * the one deliberate exception — negative-by-design so it stays hidden
     * and only drives the internal sort).
     */
    public function test_queue_age_days_are_non_negative_whole_numbers_except_future_deadlines(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);

        $this->makeActiveLease($agency, $branch, $property, ['end_date' => now()->subDays(11)->toDateString()]);
        $fault = $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_AWAITING_APPROVAL);
        // Exactly midnight 11 days ago on every fixture — the service's
        // $today is also pinned to startOfDay(), so this is a clean 11.0
        // day diff with no time-of-day flooring ambiguity to account for.
        \Illuminate\Support\Facades\DB::table('rental_fault_reports')->where('id', $fault->id)->update(['reported_at' => now()->startOfDay()->subDays(11)]);
        $wo = $this->makeWorkOrder($agency, $branch, $property, RentalWorkOrder::STATUS_ORDERED, now());
        $this->ageUpdatedAt($wo, now()->startOfDay()->subDays(11));

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $items = $this->service->queueItems($agent, 'all');

        $recordOutcome = $items->first(fn ($i) => $i['type'] === 'record_outcome');
        $faultItem = $items->first(fn ($i) => $i['type'] === 'fault_awaiting_approval');
        $woItem = $items->first(fn ($i) => $i['type'] === 'work_order_overdue');

        foreach ([$recordOutcome, $faultItem, $woItem] as $item) {
            self::assertIsInt($item['age_days']);
            self::assertGreaterThanOrEqual(0, $item['age_days']);
            self::assertSame(11, $item['age_days']);
        }
    }

    public function test_search_matches_landlord_via_contact_property_pivot(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeRentalProperty($agency, $branch, $agent); // no landlord link

        $landlord = Contact::create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'first_name' => 'Ulrich',
            'last_name' => 'Landlordtestsurname',
            'email' => 'landlord-' . uniqid() . '@example.test',
        ]);
        \App\Models\ContactProperty::create([
            'contact_id' => $landlord->id,
            'property_id' => $property->id,
            'role' => 'landlord',
        ]);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $results = $this->service->tableQuery($agent, 'all', ['q' => 'Landlordtestsurname'])->get();
        self::assertCount(1, $results);
        self::assertSame($property->id, $results->first()->id);
    }

    /**
     * Layout fix round 2 — the "Open" column merges the two separate
     * open_faults/open_work_orders sort keys into one 'open_total' key
     * (sorted by the combined count) since the table now shows one
     * compact "2 F · 2 WO" column instead of two separate ones.
     */
    public function test_open_total_sort_orders_by_combined_faults_and_work_orders(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $low = $this->makeRentalProperty($agency, $branch, $agent);
        $high = $this->makeRentalProperty($agency, $branch, $agent);

        // low: 1 open fault, 0 work orders = 1 total.
        $this->makeFaultReport($agency, $branch, $low, RentalFaultReport::STATUS_REPORTED);
        // high: 1 open fault + 2 open work orders = 3 total.
        $this->makeFaultReport($agency, $branch, $high, RentalFaultReport::STATUS_REPORTED);
        $this->makeWorkOrder($agency, $branch, $high, RentalWorkOrder::STATUS_REPORTED, now());
        $this->makeWorkOrder($agency, $branch, $high, RentalWorkOrder::STATUS_ORDERED, now());

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $query = $this->service->tableQuery($agent, 'all', []);
        $this->service->applySort($query, 'open_total', 'desc');
        $ids = $query->get()->pluck('id')->values()->all();

        self::assertSame([$high->id, $low->id], $ids);
    }

    /**
     * The row column feeding the merged "Open" column's link targets
     * (corex.rental-fault-reports.index / .rental-work-orders.index, both
     * filtered by property_id) must count the exact same "open" set the
     * tiles use — otherwise the table cell and the list it links to would
     * disagree on what's open.
     */
    public function test_open_column_row_values_match_the_shared_open_definition(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_AWAITING_APPROVAL);
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_RESOLVED);
        $this->makeWorkOrder($agency, $branch, $property, RentalWorkOrder::STATUS_ORDERED, now());

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $row = $this->service->tableQuery($agent, 'all', [])->where('id', $property->id)
            ->first(['id', 'open_faults_count', 'open_work_orders_count']);

        self::assertSame(1, (int) $row->open_faults_count);
        self::assertSame(1, (int) $row->open_work_orders_count);

        $directFaults = RentalFaultReport::where('property_id', $property->id)
            ->whereNotIn('status', RentalCommandCentreService::FAULT_OPEN_STATUSES_EXCLUDED)->count();
        $directWO = RentalWorkOrder::where('property_id', $property->id)
            ->whereNotIn('status', RentalCommandCentreService::WORK_ORDER_OPEN_STATUSES_EXCLUDED)->count();
        self::assertSame($directFaults, (int) $row->open_faults_count);
        self::assertSame($directWO, (int) $row->open_work_orders_count);
    }

    /**
     * Conductor browser-verification fix round 2 — a lease whose property
     * has since been archived (soft-deleted) must still appear in the
     * queue, labelled "Unknown property", with its action button still
     * opening the LEASE so the agent can fix the record — never silently
     * dropped from the queue.
     */
    public function test_queue_row_with_no_linked_property_still_shows_and_links_to_the_lease(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $lease = $this->makeActiveLease($agency, $branch, $property, ['end_date' => now()->addDays(5)->toDateString()]);

        $property->delete(); // soft delete — Lease::property() then resolves to null.

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $item = $this->service->queueItems($agent, 'all')->first(fn ($i) => $i['type'] === 'review_renewal' && $i['lease']->id === $lease->id);

        self::assertNotNull($item, 'the row must not be silently dropped when its property is gone');
        self::assertNull($item['property']);
        self::assertSame('corex.leases.show', $item['route']);
        self::assertSame($lease->id, $item['route_params']['lease']);
    }

    public function test_rental_command_centre_user_preference_persists_queue_collapsed_state(): void
    {
        [, , $agent] = $this->makeAgencyBranchAgent();

        self::assertFalse(RentalCommandCentreUserPreference::stateFor($agent->id)['queue_collapsed']);

        RentalCommandCentreUserPreference::setFor($agent->id, 'queue_collapsed', true);

        self::assertTrue(RentalCommandCentreUserPreference::stateFor($agent->id)['queue_collapsed']);

        // A second user's preference is independent.
        $otherAgent = User::factory()->create(['role' => 'agent']);
        self::assertFalse(RentalCommandCentreUserPreference::stateFor($otherAgent->id)['queue_collapsed']);
    }

    // ───────────────── 2026-10-05 fix round (B1) — queue sort/group-by/filter ─────────────────

    public function test_queue_filter_by_property_narrows_to_that_propertys_items_only(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $propertyA = $this->makeRentalProperty($agency, $branch, $agent);
        $propertyB = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeFaultReport($agency, $branch, $propertyA, RentalFaultReport::STATUS_AWAITING_APPROVAL);
        $this->makeFaultReport($agency, $branch, $propertyB, RentalFaultReport::STATUS_AWAITING_APPROVAL);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $items = $this->service->queueItems($agent, 'all', $propertyA->id);

        self::assertCount(1, $items);
        self::assertSame($propertyA->id, $items->first()['property']->id);
    }

    /**
     * The new property filter is a narrowing of the SAME already-scoped
     * query, never a second, looser one — requesting another agent's
     * property id while scoped to 'own' must return nothing, not that
     * agent's item.
     */
    public function test_queue_property_filter_cannot_escape_own_scope(): void
    {
        [$agency, $branch, $agentOne] = $this->makeAgencyBranchAgent();
        $agentTwo = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $propertyTwo = $this->makeRentalProperty($agency, $branch, $agentTwo);
        $this->makeFaultReport($agency, $branch, $propertyTwo, RentalFaultReport::STATUS_AWAITING_APPROVAL);

        $this->grantScope($agentOne, 'rental_command_centre', 'own', $agency->id);
        $this->actingAs($agentOne);

        $items = $this->service->queueItems($agentOne, 'own', $propertyTwo->id);

        self::assertCount(0, $items);
    }

    public function test_queue_date_range_filters_against_each_rules_own_date_column(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $recentFault = $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_AWAITING_APPROVAL);
        RentalFaultReport::where('id', $recentFault->id)->update(['reported_at' => now()->subDays(5)]);
        $oldFault = $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_AWAITING_APPROVAL);
        RentalFaultReport::where('id', $oldFault->id)->update(['reported_at' => now()->subDays(40)]);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $items = $this->service->queueItems($agent, 'all', null, now()->subDays(10)->toDateString(), null);
        $faultIds = $items->filter(fn ($i) => $i['type'] === 'fault_awaiting_approval')->pluck('route_params.rentalFaultReport');

        self::assertTrue($faultIds->contains($recentFault->id));
        self::assertFalse($faultIds->contains($oldFault->id));
    }

    public function test_queue_sort_by_date_orders_soonest_item_date_first_nulls_last(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $soonFault = $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_AWAITING_APPROVAL);
        RentalFaultReport::where('id', $soonFault->id)->update(['reported_at' => now()->subDays(2)]);
        $laterFault = $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_AWAITING_APPROVAL);
        RentalFaultReport::where('id', $laterFault->id)->update(['reported_at' => now()->subDays(20)]);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $items = $this->service->queueItems($agent, 'all', null, null, null, 'date')
            ->filter(fn ($i) => $i['type'] === 'fault_awaiting_approval')
            ->values();

        self::assertSame($laterFault->id, $items[0]['route_params']['rentalFaultReport']);
        self::assertSame($soonFault->id, $items[1]['route_params']['rentalFaultReport']);
    }

    public function test_queue_sort_by_property_orders_alphabetically_by_address(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $propertyZ = $this->makeRentalProperty($agency, $branch, $agent, ['title' => 'Zebra Lane 1']);
        $propertyA = $this->makeRentalProperty($agency, $branch, $agent, ['title' => 'Acacia Lane 1']);
        $this->makeFaultReport($agency, $branch, $propertyZ, RentalFaultReport::STATUS_AWAITING_APPROVAL);
        $this->makeFaultReport($agency, $branch, $propertyA, RentalFaultReport::STATUS_AWAITING_APPROVAL);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $items = $this->service->queueItems($agent, 'all', null, null, null, 'property')->values();

        self::assertSame($propertyA->id, $items[0]['property']->id);
        self::assertSame($propertyZ->id, $items[1]['property']->id);
    }

    public function test_group_queue_items_by_property_nests_that_propertys_items_under_it(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_AWAITING_APPROVAL, 'Leaking roof');
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_AWAITING_APPROVAL, 'Broken gate motor');

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $items = $this->service->queueItems($agent, 'all')
            ->filter(fn ($i) => $i['type'] === 'fault_awaiting_approval');
        $groups = $this->service->groupQueueItems($items, 'property');

        self::assertCount(1, $groups);
        self::assertSame($property->buildDisplayAddress(), $groups->first()['heading']);
        self::assertCount(2, $groups->first()['items']);
        self::assertEqualsCanonicalizing(
            ['Leaking roof', 'Broken gate motor'],
            $groups->first()['items']->pluck('detail')->all()
        );
    }

    public function test_group_queue_items_by_date_buckets_overdue_separately_from_future(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        // Rule B (record_outcome) fires for a lease whose end_date is already in
        // the past — a genuine "overdue" item_date.
        $this->makeActiveLease($agency, $branch, $property, ['end_date' => now()->subDays(5)->toDateString()]);
        $futureProperty = $this->makeRentalProperty($agency, $branch, $agent);
        // Rule A (review_renewal) fires for a lease expiring inside the window —
        // a genuine future item_date.
        $this->makeActiveLease($agency, $branch, $futureProperty, ['end_date' => now()->addDays(10)->toDateString()]);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $items = $this->service->queueItems($agent, 'all');
        $groups = $this->service->groupQueueItems($items, 'date');

        self::assertSame('Overdue', $groups->first()['heading']);
        self::assertTrue($groups->first()['items']->contains(fn ($i) => $i['type'] === 'record_outcome'));
        self::assertTrue($groups->last()['items']->contains(fn ($i) => $i['type'] === 'review_renewal'));
    }

    public function test_group_queue_items_none_returns_the_existing_flat_list_unchanged(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_AWAITING_APPROVAL);

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $items = $this->service->queueItems($agent, 'all');

        self::assertSame($items->all(), $this->service->groupQueueItems($items, 'none')->all());
    }

    public function test_queue_group_by_and_sort_choice_is_remembered_per_user(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $this->get(route('corex.rentals.command-centre.index', ['queue_group_by' => 'property', 'queue_sort' => 'date']))
            ->assertOk();

        self::assertSame('property', RentalCommandCentreUserPreference::stateFor($agent->id)['queue_group_by']);
        self::assertSame('date', RentalCommandCentreUserPreference::stateFor($agent->id)['queue_sort']);

        // A later visit with NO explicit choice in the query string reuses
        // the remembered one — proven by rendering successfully with the
        // grouped-by-property code path active (queueGroups, not queue).
        $this->get(route('corex.rentals.command-centre.index'))->assertOk();
        self::assertSame('property', RentalCommandCentreUserPreference::stateFor($agent->id)['queue_group_by']);
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

    private function makeFaultReport(Agency $agency, Branch $branch, Property $property, string $status, ?string $title = null): RentalFaultReport
    {
        return RentalFaultReport::create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'property_id' => $property->id,
            'status' => $status,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON,
            'title' => $title ?? 'Fault ' . uniqid(),
            'description' => 'Test fault description',
            'reported_at' => now(),
        ]);
    }

    /** Bypasses Eloquent's auto-touch so a work order can simulate age for scopeOverdue(). */
    private function ageUpdatedAt(RentalWorkOrder $workOrder, $when): void
    {
        \Illuminate\Support\Facades\DB::table('rental_work_orders')->where('id', $workOrder->id)->update(['updated_at' => $when]);
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
