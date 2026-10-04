<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\RentalInspection;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderQuote;
use App\Models\RentalWorkOrderSetting;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Rentals\RentalCommandCentreService;
use App\Services\Rentals\RentalReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-443 — .ai/specs/rentals-reports.md. Covers: status-tick defaults and
 * narrowing, period boundaries, own/branch/all scoping, agency isolation,
 * totals == sum of rows, group-by, and the no-N+1 query-count budget.
 */
final class RentalReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private RentalReportService $service;
    private RentalCommandCentreService $cc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RentalReportService::class);
        $this->cc = app(RentalCommandCentreService::class);
    }

    // ───────────────────────── Fault reports ─────────────────────────

    public function test_fault_reports_default_buckets_include_every_non_terminal_and_terminal_status_individually_available(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);

        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_REPORTED, $agent);
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_RESOLVED, $agent);
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_CANCELLED, $agent);

        $this->grantAllScope($agent, 'rental_reports', $agency->id);
        $this->grantAllScope($agent, 'rental_fault_reports', $agency->id);
        $this->actingAs($agent);

        // No buckets ticked = sensible default = every status included.
        $result = $this->service->faultReports($agent, ['period' => 'any']);
        self::assertSame(3, $result['count']);

        // Narrowing to just "resolved" excludes the other two.
        $narrowed = $this->service->faultReports($agent, ['period' => 'any', 'buckets' => ['resolved']]);
        self::assertSame(1, $narrowed['count']);
        self::assertSame('Resolved', $narrowed['rows']->first()['status']);
    }

    public function test_fault_reports_period_boundary_is_inclusive_on_both_ends(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);

        $inside = $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_REPORTED, $agent, now()->subDays(15));
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_REPORTED, $agent, now()->subDays(45));

        $this->grantAllScope($agent, 'rental_reports', $agency->id);
        $this->grantAllScope($agent, 'rental_fault_reports', $agency->id);
        $this->actingAs($agent);

        $result = $this->service->faultReports($agent, ['period' => 'last_30']);
        self::assertSame(1, $result['count']);
        self::assertSame($inside->id, $result['rows']->first()['_model']->id);
    }

    public function test_fault_reports_urgency_column_reads_from_the_linked_fault_type(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $faultType = RentalFaultType::create(['agency_id' => $agency->id, 'name' => 'Burst pipe', 'urgency' => 'emergency']);

        $fault = $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_REPORTED, $agent);
        $fault->update(['rental_fault_type_id' => $faultType->id]);

        $this->grantAllScope($agent, 'rental_reports', $agency->id);
        $this->grantAllScope($agent, 'rental_fault_reports', $agency->id);
        $this->actingAs($agent);

        $result = $this->service->faultReports($agent, ['period' => 'any']);
        self::assertSame('Emergency', $result['rows']->first()['urgency']);
    }

    public function test_fault_reports_never_leak_across_agencies(): void
    {
        [$agencyA, $branchA, $agentA] = $this->makeAgencyBranchAgent();
        [$agencyB, $branchB, $agentB] = $this->makeAgencyBranchAgent();
        $propA = $this->makeRentalProperty($agencyA, $branchA, $agentA);
        $propB = $this->makeRentalProperty($agencyB, $branchB, $agentB);

        $this->makeFaultReport($agencyA, $branchA, $propA, RentalFaultReport::STATUS_REPORTED, $agentA);
        $this->makeFaultReport($agencyB, $branchB, $propB, RentalFaultReport::STATUS_REPORTED, $agentB);

        $this->grantAllScope($agentA, 'rental_reports', $agencyA->id);
        $this->grantAllScope($agentA, 'rental_fault_reports', $agencyA->id);
        $this->actingAs($agentA);

        $result = $this->service->faultReports($agentA, ['period' => 'any']);
        self::assertSame(1, $result['count']);
    }

    public function test_fault_reports_own_scope_only_sees_own_created_rows(): void
    {
        [$agency, $branch, $agentOne] = $this->makeAgencyBranchAgent();
        $agentTwo = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $property = $this->makeRentalProperty($agency, $branch, $agentOne);

        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_REPORTED, $agentOne);
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_REPORTED, $agentTwo);

        $this->grantScope($agentOne, 'rental_reports', 'own', $agency->id);
        $this->grantScope($agentOne, 'rental_fault_reports', 'own', $agency->id);
        $this->actingAs($agentOne);

        $result = $this->service->faultReports($agentOne, ['period' => 'any']);
        self::assertSame(1, $result['count']);
    }

    public function test_fault_reports_report_scope_cannot_widen_past_the_entity_ceiling(): void
    {
        [$agency, $branch, $agentOne] = $this->makeAgencyBranchAgent();
        $agentTwo = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $property = $this->makeRentalProperty($agency, $branch, $agentOne);

        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_REPORTED, $agentOne);
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_REPORTED, $agentTwo);

        // rental_reports ceiling is 'all' but the owning entity's own ceiling is 'own' —
        // the report must still only show the agent's own rows (§9: narrow, never widen).
        $this->grantAllScope($agentOne, 'rental_reports', $agency->id);
        $this->grantScope($agentOne, 'rental_fault_reports', 'own', $agency->id);
        $this->actingAs($agentOne);

        $result = $this->service->faultReports($agentOne, ['period' => 'any', 'scope' => 'all']);
        self::assertSame(1, $result['count']);
    }

    public function test_fault_reports_totals_row_equals_sum_of_visible_rows(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);

        $f1 = $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_RESOLVED, $agent, now()->subDays(10));
        $f1->update(['resolved_at' => now()->subDays(8)]);
        $f2 = $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_RESOLVED, $agent, now()->subDays(20));
        $f2->update(['resolved_at' => now()->subDays(15)]);

        $this->grantAllScope($agent, 'rental_reports', $agency->id);
        $this->grantAllScope($agent, 'rental_fault_reports', $agency->id);
        $this->actingAs($agent);

        $result = $this->service->faultReports($agent, ['period' => 'any']);
        $expected = $result['rows']->sum('days_open');
        self::assertSame($expected, $result['rows']->sum(fn ($r) => (float) ($r['days_open'] ?? 0)));
        self::assertSame(2, $result['count']);
    }

    public function test_fault_reports_group_by_property_groups_rows_correctly(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $propertyOne = $this->makeRentalProperty($agency, $branch, $agent, ['title' => 'Property One']);
        $propertyTwo = $this->makeRentalProperty($agency, $branch, $agent, ['title' => 'Property Two']);

        $this->makeFaultReport($agency, $branch, $propertyOne, RentalFaultReport::STATUS_REPORTED, $agent);
        $this->makeFaultReport($agency, $branch, $propertyOne, RentalFaultReport::STATUS_REPORTED, $agent);
        $this->makeFaultReport($agency, $branch, $propertyTwo, RentalFaultReport::STATUS_REPORTED, $agent);

        $this->grantAllScope($agent, 'rental_reports', $agency->id);
        $this->grantAllScope($agent, 'rental_fault_reports', $agency->id);
        $this->actingAs($agent);

        $result = $this->service->faultReports($agent, ['period' => 'any', 'group_by' => 'property']);
        self::assertCount(2, $result['groups']);
        self::assertSame(2, array_values($result['groups'])[array_search($propertyOne->buildDisplayAddress(), array_keys($result['groups']), true)] ?? null);
    }

    public function test_fault_reports_query_count_stays_flat_as_rows_grow(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        for ($i = 0; $i < 25; $i++) {
            $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_REPORTED, $agent);
        }

        $this->grantAllScope($agent, 'rental_reports', $agency->id);
        $this->grantAllScope($agent, 'rental_fault_reports', $agency->id);
        $this->actingAs($agent);

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });
        $result = $this->service->faultReports($agent, ['period' => 'any']);
        self::assertSame(25, $result['count']);
        // A small, ROW-COUNT-INDEPENDENT constant (eager loads + one scope/permission
        // check), never one query per row — 25 rows must cost the same as 5 would.
        self::assertLessThanOrEqual(12, $queries, 'Fault reports report must not N+1 per row — got ' . $queries . ' queries for 25 rows.');
    }

    // ───────────────────────── Work orders ─────────────────────────

    public function test_work_orders_overdue_bucket_uses_the_agency_overdue_setting(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        RentalWorkOrderSetting::create(['agency_id' => $agency->id, 'overdue_reminder_days' => 5]);

        // RentalWorkOrder::scopeOverdue() reads updated_at (last time anything
        // changed on the order), not reported_at — timestamps() force-sets
        // updated_at to "now" on create, so it must be backdated explicitly here.
        $overdue = $this->makeWorkOrder($agency, $branch, $property, RentalWorkOrder::STATUS_ORDERED, now()->subDays(10), $agent);
        $overdue->timestamps = false;
        $overdue->forceFill(['updated_at' => now()->subDays(10)])->save();

        $recent = $this->makeWorkOrder($agency, $branch, $property, RentalWorkOrder::STATUS_ORDERED, now()->subDays(1), $agent);
        $recent->timestamps = false;
        $recent->forceFill(['updated_at' => now()->subDays(1)])->save();

        $this->grantAllScope($agent, 'rental_reports', $agency->id);
        $this->grantAllScope($agent, 'rental_work_orders', $agency->id);
        $this->actingAs($agent);

        $result = $this->service->workOrders($agent, ['period' => 'any', 'buckets' => ['overdue']]);
        self::assertSame(1, $result['count']);
        self::assertSame($overdue->id, $result['rows']->first()['_model']->id);
    }

    public function test_work_orders_quoted_amount_prefers_the_selected_quote_over_cost_amount(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $workOrder = $this->makeWorkOrder($agency, $branch, $property, RentalWorkOrder::STATUS_ORDERED, now(), $agent);
        $workOrder->update(['cost_amount' => 999]);

        $supplier = AgencyServiceProvider::create(['agency_id' => $agency->id, 'name' => 'Acme Plumbing']);
        RentalWorkOrderQuote::create([
            'agency_id' => $agency->id, 'rental_work_order_id' => $workOrder->id,
            'agency_service_provider_id' => $supplier->id, 'amount' => 1500, 'quote_date' => now()->toDateString(),
            'is_selected' => true,
        ]);

        $this->grantAllScope($agent, 'rental_reports', $agency->id);
        $this->grantAllScope($agent, 'rental_work_orders', $agency->id);
        $this->actingAs($agent);

        $result = $this->service->workOrders($agent, ['period' => 'any']);
        self::assertSame(1500.0, $result['rows']->first()['quoted_amount']);
    }

    public function test_work_orders_never_leak_across_agencies(): void
    {
        [$agencyA, $branchA, $agentA] = $this->makeAgencyBranchAgent();
        [$agencyB, $branchB, $agentB] = $this->makeAgencyBranchAgent();
        $propA = $this->makeRentalProperty($agencyA, $branchA, $agentA);
        $propB = $this->makeRentalProperty($agencyB, $branchB, $agentB);

        $this->makeWorkOrder($agencyA, $branchA, $propA, RentalWorkOrder::STATUS_REPORTED, now(), $agentA);
        $this->makeWorkOrder($agencyB, $branchB, $propB, RentalWorkOrder::STATUS_REPORTED, now(), $agentB);

        $this->grantAllScope($agentA, 'rental_reports', $agencyA->id);
        $this->grantAllScope($agentA, 'rental_work_orders', $agencyA->id);
        $this->actingAs($agentA);

        $result = $this->service->workOrders($agentA, ['period' => 'any']);
        self::assertSame(1, $result['count']);
    }

    public function test_work_orders_done_by_filter_distinguishes_supplier_from_own_team(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);

        $supplierOrder = $this->makeWorkOrder($agency, $branch, $property, RentalWorkOrder::STATUS_ORDERED, now(), $agent);
        $supplier = AgencyServiceProvider::create(['agency_id' => $agency->id, 'name' => 'Acme Electrical']);
        $supplierOrder->update(['agency_service_provider_id' => $supplier->id]);

        $ownTeamOrder = $this->makeWorkOrder($agency, $branch, $property, RentalWorkOrder::STATUS_ORDERED, now(), $agent);
        RentalJobCard::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'rental_work_order_id' => $ownTeamOrder->id,
            'property_id' => $property->id, 'title' => 'Fix the gate', 'status' => RentalJobCard::STATUS_SCHEDULED,
            'created_by_user_id' => $agent->id,
        ]);

        $this->grantAllScope($agent, 'rental_reports', $agency->id);
        $this->grantAllScope($agent, 'rental_work_orders', $agency->id);
        $this->actingAs($agent);

        $supplierResult = $this->service->workOrders($agent, ['period' => 'any', 'done_by' => 'supplier']);
        self::assertSame(1, $supplierResult['count']);
        self::assertSame($supplierOrder->id, $supplierResult['rows']->first()['_model']->id);

        $ownTeamResult = $this->service->workOrders($agent, ['period' => 'any', 'done_by' => 'own_team']);
        self::assertSame(1, $ownTeamResult['count']);
        self::assertSame($ownTeamOrder->id, $ownTeamResult['rows']->first()['_model']->id);
    }

    // ───────────────────────── Job cards (AT-442, landed mid-build) ─────────────────────────

    public function test_job_cards_default_buckets_include_everything_and_narrow_on_tick(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);

        $this->makeJobCard($agency, $branch, $property, RentalJobCard::STATUS_SCHEDULED, $agent);
        $this->makeJobCard($agency, $branch, $property, RentalJobCard::STATUS_COMPLETED, $agent);
        $this->makeJobCard($agency, $branch, $property, RentalJobCard::STATUS_CANCELLED, $agent);

        $this->grantAllScope($agent, 'rental_reports', $agency->id);
        $this->grantAllScope($agent, 'rental_job_cards', $agency->id);
        $this->actingAs($agent);

        $result = $this->service->jobCards($agent, ['period' => 'any']);
        self::assertSame(3, $result['count']);

        $narrowed = $this->service->jobCards($agent, ['period' => 'any', 'buckets' => ['completed']]);
        self::assertSame(1, $narrowed['count']);
        self::assertSame('Completed', $narrowed['rows']->first()['status']);
    }

    public function test_job_cards_aggregates_labour_hours_and_parts_from_its_own_lines(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $card = $this->makeJobCard($agency, $branch, $property, RentalJobCard::STATUS_COMPLETED, $agent);

        RentalJobCardLine::create([
            'agency_id' => $agency->id, 'rental_job_card_id' => $card->id, 'type' => 'labour',
            'description' => 'Plumbing repair', 'quantity' => 2.5, 'created_by_user_id' => $agent->id,
        ]);
        RentalJobCardLine::create([
            'agency_id' => $agency->id, 'rental_job_card_id' => $card->id, 'type' => 'part',
            'description' => 'PVC elbow joint', 'quantity' => 3, 'created_by_user_id' => $agent->id,
        ]);

        $this->grantAllScope($agent, 'rental_reports', $agency->id);
        $this->grantAllScope($agent, 'rental_job_cards', $agency->id);
        $this->actingAs($agent);

        $result = $this->service->jobCards($agent, ['period' => 'any']);
        $row = $result['rows']->first();
        self::assertSame(2.5, $row['labour_hours']);
        self::assertStringContainsString('PVC elbow joint', $row['parts_used']);
    }

    public function test_job_cards_total_cost_is_null_not_zero_when_unpriced(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeJobCard($agency, $branch, $property, RentalJobCard::STATUS_SCHEDULED, $agent);

        $this->grantAllScope($agent, 'rental_reports', $agency->id);
        $this->grantAllScope($agent, 'rental_job_cards', $agency->id);
        $this->actingAs($agent);

        $result = $this->service->jobCards($agent, ['period' => 'any']);
        self::assertNull($result['rows']->first()['total_cost']);
    }

    public function test_job_cards_never_leak_across_agencies(): void
    {
        [$agencyA, $branchA, $agentA] = $this->makeAgencyBranchAgent();
        [$agencyB, $branchB, $agentB] = $this->makeAgencyBranchAgent();
        $propA = $this->makeRentalProperty($agencyA, $branchA, $agentA);
        $propB = $this->makeRentalProperty($agencyB, $branchB, $agentB);

        $this->makeJobCard($agencyA, $branchA, $propA, RentalJobCard::STATUS_SCHEDULED, $agentA);
        $this->makeJobCard($agencyB, $branchB, $propB, RentalJobCard::STATUS_SCHEDULED, $agentB);

        $this->grantAllScope($agentA, 'rental_reports', $agencyA->id);
        $this->grantAllScope($agentA, 'rental_job_cards', $agencyA->id);
        $this->actingAs($agentA);

        $result = $this->service->jobCards($agentA, ['period' => 'any']);
        self::assertSame(1, $result['count']);
    }

    // ───────────────────────── Lease status (delegates to RentalCommandCentreService) ─────────────────────────

    public function test_lease_status_report_matches_command_centre_tile_counts(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $occupied = $this->makeRentalProperty($agency, $branch, $agent);
        $this->makeActiveLease($agency, $branch, $occupied);
        $this->makeRentalProperty($agency, $branch, $agent); // unoccupied

        $this->grantAllScope($agent, 'rental_command_centre', $agency->id);
        $this->actingAs($agent);

        $ccTiles = $this->cc->tileCounts($agent, 'all');
        $report = $this->service->leaseStatus($agent, ['scope' => 'all'], $this->cc);

        self::assertSame($ccTiles['all'], $report['count']);
        self::assertSame($ccTiles['occupied'], $report['rows']->where('status_bucket', 'Occupied')->count());
    }

    // ───────────────────────── Lease expiries ─────────────────────────

    public function test_lease_expiries_only_includes_leases_inside_the_forward_window(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $propertyIn = $this->makeRentalProperty($agency, $branch, $agent);
        $propertyOut = $this->makeRentalProperty($agency, $branch, $agent);

        $this->makeActiveLease($agency, $branch, $propertyIn, ['end_date' => now()->addDays(20)->toDateString()]);
        $this->makeActiveLease($agency, $branch, $propertyOut, ['end_date' => now()->addDays(90)->toDateString()]);

        $this->grantAllScope($agent, 'rental_reports', $agency->id);
        $this->grantAllScope($agent, 'leases', $agency->id);
        $this->actingAs($agent);

        $result = $this->service->leaseExpiries($agent, ['period' => 'next_30']);
        self::assertSame(1, $result['count']);
    }

    // ───────────────────────── Inspections ─────────────────────────

    public function test_inspections_overdue_derivation_is_scheduled_date_in_the_past_and_still_open(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $lease = $this->makeActiveLease($agency, $branch, $property);

        $overdue = RentalInspection::create([
            'agency_id' => $agency->id, 'lease_id' => $lease->id, 'property_id' => $property->id,
            'type' => RentalInspection::TYPE_IN, 'status' => RentalInspection::STATUS_DRAFT,
            'scheduled_for' => now()->subDays(5)->toDateString(), 'created_by_user_id' => $agent->id,
        ]);
        RentalInspection::create([
            'agency_id' => $agency->id, 'lease_id' => $lease->id, 'property_id' => $property->id,
            'type' => RentalInspection::TYPE_IN, 'status' => RentalInspection::STATUS_DRAFT,
            'scheduled_for' => now()->addDays(5)->toDateString(), 'created_by_user_id' => $agent->id,
        ]);

        $this->grantAllScope($agent, 'rental_reports', $agency->id);
        $this->grantAllScope($agent, 'rental_inspections', $agency->id);
        $this->actingAs($agent);

        $result = $this->service->inspections($agent, ['period' => 'any', 'buckets' => ['overdue']]);
        self::assertSame(1, $result['count']);
        self::assertSame($overdue->id, $result['rows']->first()['_model']->id);
        self::assertSame('Overdue', $result['rows']->first()['status']);
    }

    // ───────────────────────── Property history ─────────────────────────

    public function test_property_history_groups_events_by_lease_and_between_tenancies(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyBranchAgent();
        $property = $this->makeRentalProperty($agency, $branch, $agent);
        $lease = $this->makeActiveLease($agency, $branch, $property, ['status' => Lease::STATUS_EXPIRED, 'end_date' => now()->subDays(60)->toDateString()]);

        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_RESOLVED, $agent)->update(['lease_id' => $lease->id]);
        // A vacancy-period fault — no lease on record.
        $this->makeFaultReport($agency, $branch, $property, RentalFaultReport::STATUS_REPORTED, $agent);

        $this->actingAs($agent);
        $history = $this->service->propertyHistory($agent, $property);

        self::assertCount(2, $history['timeline']);
        self::assertStringContainsString('Lease #' . $lease->id, $history['timeline'][0]['group']);
        self::assertSame('Between tenancies (no lease on record)', $history['timeline'][1]['group']);
        self::assertCount(1, $history['timeline'][0]['events']);
        self::assertCount(1, $history['timeline'][1]['events']);
    }

    // ───────────────────────── Fixtures ─────────────────────────

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

    private function makeFaultReport(Agency $agency, Branch $branch, Property $property, string $status, User $createdBy, $reportedAt = null): RentalFaultReport
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
            'reported_at' => $reportedAt ?? now(),
            'created_by_user_id' => $createdBy->id,
        ]);
    }

    private function makeWorkOrder(Agency $agency, Branch $branch, Property $property, string $status, $reportedAt, User $createdBy): RentalWorkOrder
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
            'created_by_user_id' => $createdBy->id,
        ]);
    }

    private function makeJobCard(Agency $agency, Branch $branch, Property $property, string $status, User $createdBy): RentalJobCard
    {
        // rental_job_cards.rental_work_order_id is a required, unique 1:1 FK — a job card
        // is always the "own team" side of a real work order, never created standalone.
        $workOrder = $this->makeWorkOrder($agency, $branch, $property, RentalWorkOrder::STATUS_ORDERED, now(), $createdBy);

        return RentalJobCard::create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'rental_work_order_id' => $workOrder->id,
            'property_id' => $property->id,
            'title' => 'Job card ' . uniqid(),
            'status' => $status,
            'assigned_user_id' => $createdBy->id,
            'scheduled_at' => now(),
            'created_by_user_id' => $createdBy->id,
        ]);
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

    private function grantAllScope(User $user, string $module, int $agencyId): void
    {
        $this->grantScope($user, $module, 'all', $agencyId);
    }
}
