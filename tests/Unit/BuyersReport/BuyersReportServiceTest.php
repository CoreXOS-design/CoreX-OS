<?php

declare(strict_types=1);

namespace Tests\Unit\BuyersReport;

use App\Services\BuyersReport\BuyersReportScope;
use App\Services\BuyersReport\BuyersReportService;
use App\Services\Performance\BuyerActivityService;
use App\Services\Performance\HierarchyResolver;
use App\Services\Performance\Period;
use App\Services\Performance\Providers\BuyersWonProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves BuyersReportService::needsAttention() does the one thing Johan
 * explicitly called out as an approved extra: a buyer an agent deliberately
 * PARKED (manual_override) must never be mixed into the "needs attention"
 * worry list, and buyers_won must reach the rollup — both against the REAL
 * services (BuyerActivityService, HierarchyResolver, BuyersWonProvider),
 * not stand-ins.
 *
 * Also proves the scope boundary: a buyer belonging to an agent OUTSIDE the
 * resolved cohort never appears, regardless of state — the report must never
 * be the leak this whole task exists to prevent.
 *
 * DB approach: hand-built minimal schema, same ERROR-1419 workaround used
 * all night on this box.
 */
final class BuyersReportServiceTest extends TestCase
{
    private const AGENCY_ID = 9101;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
        parent::tearDown();
    }

    public function test_manually_parked_buyer_is_separated_from_genuine_neglect_and_out_of_scope_buyer_never_leaks(): void
    {
        $inScopeAgent    = 701; // this agent IS in the resolved cohort (their own branch)
        $outOfScopeAgent = 702; // a DIFFERENT branch's agent — must never appear

        $this->seedUser($inScopeAgent, self::AGENCY_ID, 501, 'Agent In-Scope');
        $this->seedUser($outOfScopeAgent, self::AGENCY_ID, 502, 'Agent Out-Of-Scope');

        $now = Carbon::now();

        // Genuinely neglected — cold, no manual placement, 40 days in state.
        $neglected = $this->seedBuyer(1, self::AGENCY_ID, $inScopeAgent, 501, 'cold', 'Neglected Buyer');
        $this->seedTransition($neglected, 'cold', 'auto_recompute', $now->copy()->subDays(40));

        // Manually parked — cold, but an agent DELIBERATELY moved them there 5 days ago.
        $parked = $this->seedBuyer(2, self::AGENCY_ID, $inScopeAgent, 501, 'cold', 'Parked Buyer');
        $this->seedTransition($parked, 'cold', 'manual_override', $now->copy()->subDays(5));

        // Out of scope — same agency, but a DIFFERENT agent/branch. Must never leak in.
        $outOfScope = $this->seedBuyer(3, self::AGENCY_ID, $outOfScopeAgent, 502, 'cold', 'Should Not Appear');
        $this->seedTransition($outOfScope, 'cold', 'auto_recompute', $now->copy()->subDays(90));

        $scope = new BuyersReportScope(self::AGENCY_ID, BuyersReportScope::LEVEL_BRANCH, branchId: 501);
        $service = app(BuyersReportService::class);

        $result = $service->needsAttention($scope);

        $attentionIds = array_column($result['attention'], 'contact_id');
        $parkedIds    = array_column($result['parked'], 'contact_id');

        $this->assertContains($neglected, $attentionIds, 'A genuinely neglected buyer must appear in the worry list.');
        $this->assertNotContains($parked, $attentionIds, 'A manually-parked buyer must NOT be in the worry list.');
        $this->assertContains($parked, $parkedIds, 'A manually-parked buyer must appear in the separate parked list instead.');
        $this->assertNotContains($outOfScope, $attentionIds, 'An out-of-scope buyer must never leak into the worry list.');
        $this->assertNotContains($outOfScope, $parkedIds, 'An out-of-scope buyer must never leak into the parked list either.');
    }

    public function test_buyers_won_reaches_the_rollup(): void
    {
        $agentId = 801;
        $this->seedUser($agentId, self::AGENCY_ID, 601, 'Winning Agent');

        $won = $this->seedBuyer(4, self::AGENCY_ID, $agentId, 601, 'won', 'Won Buyer');
        $this->seedTransition($won, 'won', 'property_linked', Carbon::now()->subDays(2));

        $scope  = new BuyersReportScope(self::AGENCY_ID, BuyersReportScope::LEVEL_BRANCH, branchId: 601);
        $period = new Period(Carbon::now()->subDays(30)->startOfDay()->toImmutable(), Carbon::now()->endOfDay()->toImmutable(), 'This period', 'custom');

        $service = app(BuyersReportService::class);
        $result  = $service->build($scope, $period);

        $this->assertSame(1, $result['company']['buyers_won']);
        $agentRow = collect($result['agents'])->firstWhere('user_id', $agentId);
        $this->assertSame(1, $agentRow['metrics']['buyers_won']);
    }

    /**
     * Johan (2026-08-20, live review): "You have a value that is 0 across the
     * board?" — a confident R0 next to real losses is worse than a blank, and
     * most "lost buyers" turn out to be the system's own timeout housekeeping
     * (reason_code=no_activity), not a human decision. Proves both facts are
     * now surfaced: lost_value_captured is false when nothing was ever
     * captured, and the auto/real split is counted correctly even when a mix
     * of both exists.
     */
    public function test_lost_value_captured_and_auto_real_split_are_honest(): void
    {
        $agentId = 1001;
        $this->seedUser($agentId, self::AGENCY_ID, 701, 'Losing Agent');

        $now = Carbon::now();

        $auto1 = $this->seedBuyer(20, self::AGENCY_ID, $agentId, 701, 'lost', 'Auto Lost One');
        $auto2 = $this->seedBuyer(21, self::AGENCY_ID, $agentId, 701, 'lost', 'Auto Lost Two');
        $real  = $this->seedBuyer(22, self::AGENCY_ID, $agentId, 701, 'lost', 'Real Lost With Value');

        DB::table('buyer_lost_records')->insert([
            ['contact_id' => $auto1, 'agency_id' => self::AGENCY_ID, 'agent_owner_user_id_at_loss' => $agentId, 'reason_code' => 'no_activity', 'reason_label' => 'No activity (auto-transitioned)', 'preapproval_amount_at_loss' => null, 'recorded_at' => $now, 'recovered_at' => null],
            ['contact_id' => $auto2, 'agency_id' => self::AGENCY_ID, 'agent_owner_user_id_at_loss' => $agentId, 'reason_code' => 'no_activity', 'reason_label' => 'No activity (auto-transitioned)', 'preapproval_amount_at_loss' => null, 'recorded_at' => $now, 'recovered_at' => null],
            ['contact_id' => $real, 'agency_id' => self::AGENCY_ID, 'agent_owner_user_id_at_loss' => $agentId, 'reason_code' => 'chose_another', 'reason_label' => 'Buyer chose another property', 'preapproval_amount_at_loss' => 1500000, 'recorded_at' => $now, 'recovered_at' => null],
        ]);

        $scope  = new BuyersReportScope(self::AGENCY_ID, BuyersReportScope::LEVEL_BRANCH, branchId: 701);
        $period = new Period(Carbon::now()->subDays(1)->startOfDay()->toImmutable(), Carbon::now()->addDay()->endOfDay()->toImmutable(), 'This period', 'custom');

        $result = app(BuyersReportService::class)->build($scope, $period);

        $this->assertTrue($result['company']['lost_value_captured'], 'One row DID capture a value -- must read true, not "not captured".');
        $this->assertSame(2, $result['company']['lost_auto'], 'Both no_activity rows must count as auto.');
        $this->assertSame(1, $result['company']['lost_real'], 'The chose_another row must count as real, not auto.');
    }

    public function test_lost_value_captured_is_false_when_nothing_was_ever_captured(): void
    {
        $agentId = 1002;
        $this->seedUser($agentId, self::AGENCY_ID, 702, 'All Auto Agent');
        $now = Carbon::now();
        $auto = $this->seedBuyer(23, self::AGENCY_ID, $agentId, 702, 'lost', 'Only Auto Lost');
        DB::table('buyer_lost_records')->insert([
            'contact_id' => $auto, 'agency_id' => self::AGENCY_ID, 'agent_owner_user_id_at_loss' => $agentId,
            'reason_code' => 'no_activity', 'reason_label' => 'No activity (auto-transitioned)',
            'preapproval_amount_at_loss' => null, 'recorded_at' => $now, 'recovered_at' => null,
        ]);

        $scope  = new BuyersReportScope(self::AGENCY_ID, BuyersReportScope::LEVEL_BRANCH, branchId: 702);
        $period = new Period(Carbon::now()->subDays(1)->startOfDay()->toImmutable(), Carbon::now()->addDay()->endOfDay()->toImmutable(), 'This period', 'custom');

        $result = app(BuyersReportService::class)->build($scope, $period);

        $this->assertFalse($result['company']['lost_value_captured'], 'Nothing was ever captured -- must read false, so the tile says "Not captured" rather than R0.');
        $this->assertSame(1, $result['company']['lost_auto']);
        $this->assertSame(0, $result['company']['lost_real']);
    }

    /**
     * Johan (2026-08-20, live review): "Theres no ways to say - buyer / leads
     * - and I take it all tenants excluded here?" Answer from real data:
     * NOT excluded — on live, ~28% of the is_buyer=1 cohort is labelled
     * "Lessee" (a rental-side type, not a buyer). Proves the type filter
     * genuinely narrows the buyers-held count by contact_types.name.
     */
    public function test_type_filter_narrows_buyers_held_by_contact_type_label(): void
    {
        $agentId = 1101;
        $this->seedUser($agentId, self::AGENCY_ID, 801, 'Mixed Book Agent');

        DB::table('contact_types')->insert([
            ['id' => 1, 'name' => 'Buyer'],
            ['id' => 2, 'name' => 'Lead'],
            ['id' => 3, 'name' => 'Lessee'],
        ]);

        $realBuyer = $this->seedBuyer(30, self::AGENCY_ID, $agentId, 801, 'warm', 'Real Buyer');
        DB::table('contacts')->where('id', $realBuyer)->update(['contact_type_id' => 1]);

        $lead = $this->seedBuyer(31, self::AGENCY_ID, $agentId, 801, 'new', 'Pure Lead');
        DB::table('contacts')->where('id', $lead)->update(['contact_type_id' => 2]);

        $lessee = $this->seedBuyer(32, self::AGENCY_ID, $agentId, 801, 'warm', 'Actually A Tenant');
        DB::table('contacts')->where('id', $lessee)->update(['contact_type_id' => 3]);

        $scope  = new BuyersReportScope(self::AGENCY_ID, BuyersReportScope::LEVEL_BRANCH, branchId: 801);
        $period = new Period(Carbon::now()->subDays(1)->startOfDay()->toImmutable(), Carbon::now()->addDay()->endOfDay()->toImmutable(), 'This period', 'custom');

        $service = app(BuyersReportService::class);

        $all = $service->build($scope, $period);
        $this->assertSame(3, $all['company']['buyers'], 'No filter -- all three, unchanged.');

        $buyersOnly = $service->build($scope, $period, 'buyer');
        $this->assertSame(1, $buyersOnly['company']['buyers'], 'type=buyer must exclude the Lead and the Lessee.');

        $leadsOnly = $service->build($scope, $period, 'lead');
        $this->assertSame(1, $leadsOnly['company']['buyers'], 'type=lead must exclude the pure Buyer and the Lessee.');

        $tenantsOnly = $service->build($scope, $period, 'tenant');
        $this->assertSame(1, $tenantsOnly['company']['buyers'], 'type=tenant must catch the Lessee -- the exact contamination Johan asked about.');
    }

    public function test_viewing_with_no_feedback_is_flagged_via_both_link_paths_and_fed_viewing_is_not(): void
    {
        $agentId = 901;
        $this->seedUser($agentId, self::AGENCY_ID, 701, 'Viewing Agent');

        $directBuyer   = $this->seedBuyer(10, self::AGENCY_ID, $agentId, 701, 'warm', 'Direct Link Buyer');
        $tickListBuyer = $this->seedBuyer(11, self::AGENCY_ID, $agentId, 701, 'warm', 'Tick List Buyer');
        $fedBuyer      = $this->seedBuyer(12, self::AGENCY_ID, $agentId, 701, 'warm', 'Fed Buyer');

        $past = Carbon::now()->subDays(3);

        // Direct link (calendar_events.contact_id) — no feedback. Must appear.
        $evt1 = $this->seedViewing($agentId, $past, contactId: $directBuyer);

        // Tick-list link (calendar_event_links, role=buyer_contact) — no feedback. Must appear.
        $evt2 = $this->seedViewing($agentId, $past, contactId: null);
        $this->seedEventLink($evt2, $tickListBuyer);

        // Direct link, but feedback WAS captured. Must NOT appear.
        $evt3 = $this->seedViewing($agentId, $past, contactId: $fedBuyer);
        DB::table('calendar_event_feedback')->insert([
            'calendar_event_id' => $evt3, 'contact_id' => $fedBuyer,
            'feedback_kind' => 'viewing', 'captured_at' => $past,
        ]);

        $scope   = new BuyersReportScope(self::AGENCY_ID, BuyersReportScope::LEVEL_BRANCH, branchId: 701);
        $service = app(BuyersReportService::class);
        $result  = $service->needsAttention($scope);

        $flagged = array_column($result['no_feedback'], 'contact_id');

        $this->assertContains($directBuyer, $flagged, 'Direct contact_id link with no feedback must be flagged.');
        $this->assertContains($tickListBuyer, $flagged, 'Tick-list (calendar_event_links) link with no feedback must be flagged.');
        $this->assertNotContains($fedBuyer, $flagged, 'A viewing with feedback captured must not be flagged.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedViewing(int $agentId, Carbon $eventDate, ?int $contactId): int
    {
        return (int) DB::table('calendar_events')->insertGetId([
            'user_id' => $agentId, 'contact_id' => $contactId, 'category' => 'viewing',
            'title' => 'Test viewing', 'event_date' => $eventDate, 'status' => 'completed',
        ]);
    }

    private function seedEventLink(int $eventId, int $contactId): void
    {
        DB::table('calendar_event_links')->insert([
            'calendar_event_id' => $eventId, 'linkable_type' => \App\Models\Contact::class,
            'linkable_id' => $contactId, 'role' => 'buyer_contact',
        ]);
    }

    private function seedUser(int $id, int $agencyId, int $branchId, string $name): void
    {
        DB::table('users')->insert([
            'id' => $id, 'agency_id' => $agencyId, 'branch_id' => $branchId,
            'name' => $name, 'role' => 'agent', 'is_active' => 1,
        ]);
    }

    private function seedBuyer(int $id, int $agencyId, int $agentId, int $branchId, string $state, string $name): int
    {
        DB::table('contacts')->insert([
            'id' => $id, 'agency_id' => $agencyId, 'agent_id' => $agentId, 'branch_id' => $branchId,
            'is_buyer' => 1, 'buyer_state' => $state, 'first_name' => $name, 'last_name' => '',
            'last_activity_at' => null, 'last_contacted_at' => null,
        ]);
        return $id;
    }

    private function seedTransition(int $contactId, string $toState, string $reason, Carbon $occurredAt): void
    {
        DB::table('buyer_state_transitions')->insert([
            'agency_id' => self::AGENCY_ID, 'contact_id' => $contactId,
            'to_state' => $toState, 'reason' => $reason, 'occurred_at' => $occurredAt,
        ]);
    }

    private function dropSchema(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Schema::dropIfExists('communication_links');
        Schema::dropIfExists('communications');
        Schema::dropIfExists('calendar_event_feedback');
        Schema::dropIfExists('contact_types');
        Schema::dropIfExists('calendar_event_links');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('buyer_lost_records');
        Schema::dropIfExists('buyer_state_transitions');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('users');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('agencies');
        Schema::dropIfExists('roles');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function buildSchema(): void
    {
        $this->dropSchema();

        Schema::create('agencies', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('split_branches_enabled')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('branches', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->string('name')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('roles', function ($table) {
            $table->id();
            $table->string('name', 60);
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->boolean('is_owner')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('users', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name')->nullable();
            $table->string('role', 40)->nullable();
            $table->boolean('is_active')->default(1);
            $table->boolean('show_in_performance_reports')->default(1);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('contacts', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('contact_type_id')->nullable();
            $table->boolean('is_buyer')->default(0);
            $table->string('buyer_state', 20)->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('buyer_pipeline_entered_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('contact_types', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('buyer_state_transitions', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('from_state', 20)->nullable();
            $table->string('to_state', 20);
            $table->string('reason', 30)->nullable();
            $table->unsignedBigInteger('triggered_by_user_id')->nullable();
            $table->timestamp('occurred_at');
        });

        Schema::create('buyer_lost_records', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('reason_code', 40)->nullable();
            $table->string('reason_label')->nullable();
            $table->decimal('preapproval_amount_at_loss', 12, 2)->nullable();
            $table->string('buyer_state_at_loss', 20)->nullable();
            $table->unsignedInteger('days_in_pipeline_at_loss')->nullable();
            $table->unsignedInteger('days_since_last_activity_at_loss')->nullable();
            $table->unsignedBigInteger('agent_owner_user_id_at_loss')->nullable();
            $table->unsignedBigInteger('branch_id_at_loss')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamp('recovered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('calendar_events', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('category', 80)->nullable();
            $table->string('title')->nullable();
            $table->string('status', 20)->default('pending');
            $table->dateTime('event_date');
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('calendar_event_links', function ($table) {
            $table->id();
            $table->unsignedBigInteger('calendar_event_id');
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->string('role')->default('attendee');
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('calendar_event_feedback', function ($table) {
            $table->id();
            $table->unsignedBigInteger('calendar_event_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('feedback_kind', 40)->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        // BuyerActivityService::metricsByUser() always queries these two, even
        // when a test only cares about a different metric -- must exist so the
        // schema is self-sufficient regardless of test run order.
        Schema::create('communications', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('channel', 20)->nullable();
            $table->timestamp('occurred_at')->nullable();
        });

        Schema::create('communication_links', function ($table) {
            $table->id();
            $table->unsignedBigInteger('communication_id');
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->timestamp('deleted_at')->nullable();
        });
    }
}
