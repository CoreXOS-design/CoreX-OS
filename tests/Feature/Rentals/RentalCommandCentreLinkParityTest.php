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
 * Rentals cross-cut (8 Oct 2026) — clicking a command-centre number must show exactly that many rows:
 *  - the Open faults / Open work orders tiles open the fault / work-order list itself (open ones only, limited to the
 *    properties the tile counted — its own/branch/all choice);
 *  - the per-row "N F" / "N WO" links open that property's open faults / work orders, counted under the viewer's own
 *    fault / work-order scope;
 *  - the lists have an "Open only" filter that uses the command centre's own definition of open;
 *  - every needs-action row opens a page that exists.
 */
final class RentalCommandCentreLinkParityTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $colleague;
    private Property $mine;
    private Property $theirs;
    private Property $notRental;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Link Parity ' . uniqid(), 'slug' => 'lp-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->colleague = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        $this->mine = $this->property($this->agent);
        $this->theirs = $this->property($this->colleague);
        $this->notRental = $this->property($this->agent, ['listing_type' => 'sale']);

        // my property: 2 open faults (one reported by me, one by the colleague), 1 resolved, 1 declined; 1 open + 1 completed work order
        $this->fault($this->mine, RentalFaultReport::STATUS_REPORTED, $this->agent);
        $this->fault($this->mine, RentalFaultReport::STATUS_AWAITING_APPROVAL, $this->colleague);
        $this->fault($this->mine, RentalFaultReport::STATUS_RESOLVED, $this->agent);
        $this->fault($this->mine, RentalFaultReport::STATUS_DECLINED, $this->agent);
        $this->workOrder($this->mine, RentalWorkOrder::STATUS_ORDERED, $this->agent);
        $this->workOrder($this->mine, RentalWorkOrder::STATUS_COMPLETED, $this->agent);
        // the colleague's property: 1 open fault, 2 open work orders
        $this->fault($this->theirs, RentalFaultReport::STATUS_REPORTED, $this->colleague);
        $this->workOrder($this->theirs, RentalWorkOrder::STATUS_REPORTED, $this->colleague);
        $this->workOrder($this->theirs, RentalWorkOrder::STATUS_IN_PROGRESS, $this->agent);
        // a property that is not a rental listing: never part of the command centre's numbers
        $this->fault($this->notRental, RentalFaultReport::STATUS_REPORTED, $this->agent);
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function property(User $agent, array $over = []): Property
    {
        return Property::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $agent->id,
            'title' => 'Prop ' . uniqid(), 'status' => 'active', 'listing_type' => 'rental',
        ], $over));
    }

    private function fault(Property $p, string $status, User $by): RentalFaultReport
    {
        return RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $p->id, 'status' => $status,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON,
            'title' => 'Fault ' . uniqid(), 'description' => 'd', 'reported_at' => now(), 'created_by_user_id' => $by->id,
        ]);
    }

    private function workOrder(Property $p, string $status, User $by): RentalWorkOrder
    {
        return RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $p->id, 'status' => $status,
            'title' => 'WO ' . uniqid(), 'description' => 'd', 'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED,
            'reported_at' => now(), 'created_by_user_id' => $by->id,
        ]);
    }

    private function grant(User $user, array $scopes): void
    {
        foreach ($scopes as $module => $scope) {
            foreach (['view'] as $action) {
                RolePermission::updateOrCreate(
                    ['role' => $user->role, 'permission_key' => "{$module}.{$action}", 'agency_id' => $this->agency->id],
                    ['scope' => $scope]
                );
            }
        }
        \App\Services\PermissionService::clearCache();
    }

    private function service(): RentalCommandCentreService
    {
        return app(RentalCommandCentreService::class);
    }

    private function total(string $view, string $url): int
    {
        return $this->actingAs($this->agent)->get($url)->assertOk()->viewData($view)->total();
    }

    // ── tiles ──────────────────────────────────────────────────────────

    /** @dataProvider scopes */
    public function test_the_open_faults_and_work_orders_tiles_open_a_list_with_exactly_the_number_they_show(string $scope): void
    {
        $this->grant($this->agent, ['rental_command_centre' => 'all', 'rental_fault_reports' => 'all', 'rental_work_orders' => 'all']);
        $tiles = $this->service()->tileCounts($this->agent, $scope);

        $page = $this->actingAs($this->agent)->get(route('corex.rentals.command-centre.index', ['scope' => $scope]))->assertOk()->getContent();
        $faultHref = route('corex.rental-fault-reports.index', ['open' => 1, 'cc_scope' => $scope]);
        $woHref = route('corex.rental-work-orders.index', ['open' => 1, 'cc_scope' => $scope]);
        $this->assertStringContainsString(e($faultHref), $page);
        $this->assertStringContainsString(e($woHref), $page);

        $this->assertSame($tiles['open_faults']['records'], $this->total('faultReports', $faultHref), "open faults tile at scope={$scope}");
        $this->assertSame($tiles['open_work_orders']['records'], $this->total('workOrders', $woHref), "open work orders tile at scope={$scope}");
    }

    public static function scopes(): array
    {
        return [['own'], ['branch'], ['all']];
    }

    public function test_the_tile_numbers_themselves_are_what_the_data_says(): void
    {
        $this->grant($this->agent, ['rental_command_centre' => 'all', 'rental_fault_reports' => 'all', 'rental_work_orders' => 'all']);

        $own = $this->service()->tileCounts($this->agent, 'own');
        $this->assertSame(2, $own['open_faults']['records'], 'my rental property only; the sale listing and the colleague are not mine');
        $this->assertSame(1, $own['open_work_orders']['records']);

        $all = $this->service()->tileCounts($this->agent, 'all');
        $this->assertSame(3, $all['open_faults']['records'], 'the sale listing is not a rental, so it is never counted');
        $this->assertSame(3, $all['open_work_orders']['records']);
    }

    public function test_a_viewer_with_a_narrower_fault_scope_is_counted_and_listed_under_that_scope(): void
    {
        // can see the whole rental book, but only the faults / work orders they raised themselves
        $this->grant($this->agent, ['rental_command_centre' => 'all', 'rental_fault_reports' => 'own', 'rental_work_orders' => 'own']);

        $tiles = $this->service()->tileCounts($this->agent, 'all');
        $this->assertSame(1, $tiles['open_faults']['records'], 'mine: 1 reported by me on my property (the colleague\'s two are not mine to see)');
        $this->assertSame(2, $tiles['open_work_orders']['records']);

        $this->assertSame(1, $this->total('faultReports', route('corex.rental-fault-reports.index', ['open' => 1, 'cc_scope' => 'all'])));
        $this->assertSame(2, $this->total('workOrders', route('corex.rental-work-orders.index', ['open' => 1, 'cc_scope' => 'all'])));

        // the per-row counts on the properties table follow the same scope
        $rows = $this->service()->tableQuery($this->agent, 'all', [])->get(['id', 'open_faults_count', 'open_work_orders_count'])->keyBy('id');
        $this->assertSame(1, (int) $rows[$this->mine->id]->open_faults_count);
        $this->assertSame(0, (int) $rows[$this->theirs->id]->open_faults_count);
    }

    public function test_a_viewer_with_no_fault_access_sees_zero_not_a_number_they_cannot_open(): void
    {
        $this->grant($this->agent, ['rental_command_centre' => 'all']);

        $tiles = $this->service()->tileCounts($this->agent, 'all');
        $this->assertSame(0, $tiles['open_faults']['records']);
        $this->assertSame(0, $tiles['open_work_orders']['records']);
    }

    // ── per-row links ───────────────────────────────────────────────────

    /** @dataProvider faultScopes */
    public function test_each_rows_fault_and_work_order_link_lands_on_exactly_its_count(string $faultScope): void
    {
        $this->grant($this->agent, ['rental_command_centre' => 'all', 'rental_fault_reports' => $faultScope, 'rental_work_orders' => $faultScope]);

        $page = $this->actingAs($this->agent)->get(route('corex.rentals.command-centre.index', ['scope' => 'all']))->assertOk()->getContent();
        $rows = $this->service()->tableQuery($this->agent, 'all', [])->get(['id', 'open_faults_count', 'open_work_orders_count']);
        $this->assertGreaterThan(0, $rows->count());

        foreach ($rows as $row) {
            if ((int) $row->open_faults_count > 0) {
                $href = route('corex.rental-fault-reports.index', ['property_id' => $row->id, 'open' => 1]);
                $this->assertStringContainsString(e($href), $page, 'the F link carries open=1');
            }
            if ((int) $row->open_work_orders_count > 0) {
                $this->assertStringContainsString(e(route('corex.rental-work-orders.index', ['property_id' => $row->id, 'open' => 1])), $page);
            }
            $this->assertSame((int) $row->open_faults_count, $this->total('faultReports', route('corex.rental-fault-reports.index', ['property_id' => $row->id, 'open' => 1])), "faults on property {$row->id} at fault scope={$faultScope}");
            $this->assertSame((int) $row->open_work_orders_count, $this->total('workOrders', route('corex.rental-work-orders.index', ['property_id' => $row->id, 'open' => 1])), "work orders on property {$row->id} at scope={$faultScope}");
        }
    }

    public static function faultScopes(): array
    {
        return [['own'], ['all']];
    }

    // ── the lists' "Open only" filter ───────────────────────────────────────

    public function test_open_only_uses_the_command_centres_own_definition_of_open(): void
    {
        $this->grant($this->agent, ['rental_command_centre' => 'all', 'rental_fault_reports' => 'all', 'rental_work_orders' => 'all']);

        $allFaults = $this->total('faultReports', route('corex.rental-fault-reports.index'));
        $openFaults = $this->total('faultReports', route('corex.rental-fault-reports.index', ['open' => 1]));
        $this->assertSame(6, $allFaults);
        $this->assertSame(4, $openFaults, 'resolved and declined drop out');

        $this->assertSame(4, $this->total('workOrders', route('corex.rental-work-orders.index')));
        $this->assertSame(3, $this->total('workOrders', route('corex.rental-work-orders.index', ['open' => 1])), 'completed drops out');

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.index', ['open' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="fault-filter-open"', $html);
        $this->assertStringContainsString('data-qa="wo-filter-open"', $this->actingAs($this->agent)->get(route('corex.rental-work-orders.index', ['open' => 1]))->getContent());
    }

    public function test_a_viewer_cannot_widen_the_command_centre_limit_past_their_own_ceiling(): void
    {
        $this->grant($this->agent, ['rental_command_centre' => 'own', 'rental_fault_reports' => 'all', 'rental_work_orders' => 'all']);

        // a hand-edited cc_scope=all is clamped to the command centre's own ceiling, never wider
        $this->assertSame(2, $this->total('faultReports', route('corex.rental-fault-reports.index', ['open' => 1, 'cc_scope' => 'all'])));
    }

    // ── queue links ─────────────────────────────────────────────────────

    public function test_every_needs_action_row_opens_a_page_that_exists(): void
    {
        $this->grant($this->agent, [
            'rental_command_centre' => 'all', 'rental_fault_reports' => 'all', 'rental_work_orders' => 'all',
            'leases' => 'all', 'rental_inspections' => 'all',
        ]);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->mine->id, 'status' => Lease::STATUS_ACTIVE,
            'rental_amount' => 9000, 'start_date' => now()->subMonths(2)->toDateString(), 'end_date' => now()->addDays(20)->toDateString(), 'source' => 'manual',
            'created_by_user_id' => $this->agent->id,
        ]);
        $contact = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tess', 'last_name' => 'Tenant', 'email' => uniqid() . '@example.test']);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);

        $this->actingAs($this->agent);
        $items = $this->service()->queueItems($this->agent, 'all');
        $this->assertGreaterThan(2, $items->count());

        // this agent may not start an inspection: that row stays (it still needs action) but shows no button that could only answer 403
        $inspectionRow = $items->first(fn ($i) => $i['route'] === 'corex.rental-inspections.create');
        $this->assertNotNull($inspectionRow);
        $this->assertFalse($this->service()->canOpenRoute($this->agent, 'corex.rental-inspections.create'));
        $html = $this->actingAs($this->agent)->get(route('corex.rentals.command-centre.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString(e(route('corex.rental-inspections.create', $inspectionRow['route_params'])), $html);

        $opened = 0;
        foreach ($items as $item) {
            if (! $this->service()->canOpenRoute($this->agent, $item['route'])) {
                continue;
            }
            $opened++;
            $url = route($item['route'], $item['route_params']);
            $status = $this->actingAs($this->agent)->get($url)->getStatusCode();
            $this->assertSame(200, $status, "queue row '{$item['type']}' -> {$item['route']} answered {$status}");
        }
        $this->assertGreaterThan(2, $opened);

        // once the role may create inspections the button is there and opens
        $this->grant($this->agent, ['rental_inspections' => 'all']);
        RolePermission::updateOrCreate(['role' => $this->agent->role, 'permission_key' => 'rental_inspections.create', 'agency_id' => $this->agency->id], ['scope' => 'all']);
        \App\Services\PermissionService::clearCache();
        $this->assertTrue($this->service()->canOpenRoute($this->agent->fresh(), 'corex.rental-inspections.create'));
        $this->actingAs($this->agent)->get(route('corex.rental-inspections.create', $inspectionRow['route_params']))->assertOk();
    }

    // ── "Inspections due": the tile and the queue are one definition ─────────────

    private function lease(Property $p, array $over = []): Lease
    {
        $lease = Lease::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $p->id, 'status' => Lease::STATUS_ACTIVE,
            'rental_amount' => 9000, 'start_date' => now()->subMonths(3)->toDateString(), 'end_date' => now()->addYear()->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ], $over));
        $contact = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tess', 'last_name' => 'T' . uniqid(), 'email' => uniqid() . '@example.test']);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);

        return $lease;
    }

    private function inspection(Lease $lease, string $type, string $status): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'property_id' => $lease->property_id,
            'type' => $type, 'status' => $status, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private const INSPECTION_ROW_TYPES = ['start_inspection', 'start_out_inspection', 'interim_inspection_due', 'open_inspection'];

    /** @dataProvider scopes */
    public function test_the_inspections_due_tile_counts_exactly_the_properties_the_queue_lists(string $scope): void
    {
        $this->grant($this->agent, ['rental_command_centre' => 'all', 'rental_inspections' => 'all', 'leases' => 'all']);

        // 1. an open (draft) inspection on a lease that has already had its move-in done — in the tile, and now in the queue
        $openOnly = $this->property($this->agent);
        $l1 = $this->lease($openOnly);
        $this->inspection($l1, RentalInspection::TYPE_IN, RentalInspection::STATUS_COMPLETED);
        $this->inspection($l1, RentalInspection::TYPE_OUT, RentalInspection::STATUS_AWAITING_SIGNATURE);
        // 2. no move-in ever done on an active lease: due (start) — counted once even with two rows
        $dueOnly = $this->property($this->agent);
        $this->lease($dueOnly);
        // 3. both open AND due: still ONE property
        $both = $this->property($this->agent);
        $this->inspection($this->lease($both), RentalInspection::TYPE_IN, RentalInspection::STATUS_IN_PROGRESS);
        // 4. healthy: move-in completed, nothing open
        $healthy = $this->property($this->agent);
        $this->inspection($this->lease($healthy), RentalInspection::TYPE_IN, RentalInspection::STATUS_COMPLETED);
        // 5. cancelled inspection only, move-in done: neither open nor due
        $cancelled = $this->property($this->agent);
        $l5 = $this->lease($cancelled);
        $this->inspection($l5, RentalInspection::TYPE_IN, RentalInspection::STATUS_COMPLETED);
        $this->inspection($l5, RentalInspection::TYPE_OUT, RentalInspection::STATUS_CANCELLED);
        // 6. a colleague's property with an open inspection (counts under all/branch, not under own)
        $theirsOpen = $this->property($this->colleague);
        $l6 = $this->lease($theirsOpen);
        $this->inspection($l6, RentalInspection::TYPE_IN, RentalInspection::STATUS_COMPLETED);
        $this->inspection($l6, RentalInspection::TYPE_OUT, RentalInspection::STATUS_DRAFT);
        // 7. not a rental listing at all: in neither the tile nor the queue
        $sale = $this->property($this->agent, ['listing_type' => 'sale']);
        $this->inspection($this->lease($sale), RentalInspection::TYPE_IN, RentalInspection::STATUS_DRAFT);

        $this->actingAs($this->agent);
        $tile = $this->service()->tileCounts($this->agent, $scope)['inspections_due'];
        $listed = $this->service()->tableQuery($this->agent, $scope, ['tile' => 'inspections_due'])->count();
        $queued = $this->service()->queueItems($this->agent, $scope)
            ->filter(fn ($i) => in_array($i['type'], self::INSPECTION_ROW_TYPES, true))
            ->map(fn ($i) => $i['property']?->id)->filter()->unique()->count();

        $this->assertSame($tile, $listed, "tile vs its opened list at scope={$scope}");
        $this->assertSame($tile, $queued, "tile vs the properties the queue lists at scope={$scope}");
        $this->assertGreaterThan(0, $tile);
    }

    public function test_every_property_the_tile_counts_has_a_queue_row_and_the_open_rows_open_that_inspection(): void
    {
        $this->grant($this->agent, ['rental_command_centre' => 'all', 'rental_inspections' => 'all']);
        $open = $this->property($this->agent);
        $l = $this->lease($open);
        $this->inspection($l, RentalInspection::TYPE_IN, RentalInspection::STATUS_COMPLETED);
        $insp = $this->inspection($l, RentalInspection::TYPE_OUT, RentalInspection::STATUS_IN_PROGRESS);

        $this->actingAs($this->agent);
        $row = $this->service()->queueItems($this->agent, 'all')->first(fn ($i) => $i['type'] === 'open_inspection');
        $this->assertNotNull($row);
        $this->assertSame($open->id, $row['property']->id);
        $this->assertSame('corex.rental-inspections.show', $row['route']);
        $this->assertSame(['rentalInspection' => $insp->id], $row['route_params']);
        $this->assertSame('Finish inspection', $row['label']);
        $this->actingAs($this->agent)->get(route($row['route'], $row['route_params']))->assertOk();

        $this->assertSame([], $this->service()->queueItems($this->agent, 'all')->filter(fn ($i) => $i['type'] === 'open_inspection' && $i['property']->id !== $open->id)->all());

        $insp->update(['status' => RentalInspection::STATUS_COMPLETED]);
        $this->assertNull($this->service()->queueItems($this->agent, 'all')->first(fn ($i) => $i['type'] === 'open_inspection'), 'finished = off the list');
    }

    // ── "Record outcome": only where it is the next step ──────────────────────────

    public function test_record_outcome_lists_only_leases_where_recording_an_outcome_is_the_next_step(): void
    {
        $this->grant($this->agent, ['rental_command_centre' => 'all', 'rental_inspections' => 'all', 'leases' => 'all']);
        $ended = fn (array $over = []) => $this->lease($this->property($this->agent), array_merge(['start_date' => now()->subYear()->toDateString(), 'end_date' => now()->subDays(5)->toDateString()], $over));
        $withMoveIn = function (Lease $l) {
            $this->inspection($l, RentalInspection::TYPE_IN, RentalInspection::STATUS_COMPLETED);
            $this->inspection($l, RentalInspection::TYPE_OUT, RentalInspection::STATUS_COMPLETED);

            return $l;
        };

        $plain = $withMoveIn($ended());                                                                  // ended, nothing on file → LISTED
        $notice = $withMoveIn($ended(['notice_date' => now()->subDays(40)->toDateString(), 'notice_given_by' => Lease::NOTICE_BY_TENANT]));
        $monthly = $withMoveIn($ended(['is_month_to_month' => true]));
        $renewed = $withMoveIn($ended());
        $renewed->forceFill(['renewed_lease_id' => $plain->id])->save();
        $hasDraft = $withMoveIn($ended());
        $this->lease($hasDraft->property, ['status' => Lease::STATUS_DRAFT, 'previous_lease_id' => $hasDraft->id, 'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addYear()->toDateString()]);
        $future = $withMoveIn($this->lease($this->property($this->agent)));                              // not ended yet
        $draftLease = $this->lease($this->property($this->agent), ['status' => Lease::STATUS_DRAFT, 'end_date' => now()->subDays(5)->toDateString()]);
        $expired = $this->lease($this->property($this->agent), ['status' => Lease::STATUS_EXPIRED, 'end_date' => now()->subDays(5)->toDateString()]);

        $this->actingAs($this->agent);
        $rows = $this->service()->queueItems($this->agent, 'all')->filter(fn ($i) => $i['type'] === 'record_outcome');
        $ids = $rows->map(fn ($i) => $i['lease']->id)->all();

        $this->assertSame([$plain->id], $ids, 'only the ended lease with nothing on file');
        foreach ([$notice, $monthly, $renewed, $hasDraft, $future, $draftLease, $expired] as $lease) {
            $this->assertNotContains($lease->id, $ids, "lease {$lease->id} is not awaiting an outcome");
        }

        // and for every row the Lease Hub's own next step IS "Record outcome"
        $hub = app(\App\Services\Rentals\LeaseHubService::class);
        foreach ($rows as $row) {
            $this->assertSame('Record outcome', $hub->nextStep($row['lease']->fresh(), $this->agent)['label']);
            $this->assertSame(['lease' => $row['lease']->id, 'action' => 'outcomes'], $row['route_params'], 'opens the hub with the outcomes menu');
        }
    }
}
