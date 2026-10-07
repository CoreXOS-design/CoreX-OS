<?php

declare(strict_types=1);

namespace Tests\Unit\Performance;

use App\Services\Performance\BuyerActivityService;
use App\Services\Performance\Period;
use App\Services\Performance\PerformanceScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Johan (2026-08-20, live review): the Buyers Report's Appointments tile
 * showed 0 against 87 real calendar events for the period. Root cause:
 * BuyerActivityService only ever counted calendar_events.contact_id — the
 * single-FK link that only ever captures the FIRST ticked buyer on a
 * viewing. Every buyer past the first is linked ONLY via
 * calendar_event_links (role=buyer_contact), the multi-buyer tick-list
 * shipped the night before. This proves both metricsByUser() (feeds the
 * report tile) and appointmentsByContact() (feeds the agent-detail
 * breakdown) now count the tick-list path too, deduped against the direct
 * path so a doubly-linked event is never double-counted.
 *
 * DB approach: RefreshDatabase against the REAL tables, with real rows (no raw DDL — the old
 * hand-built schema dropped users/contacts/branches in the lane's persistent test schema).
 */
final class BuyerActivityServiceAppointmentsTest extends TestCase
{
    use RefreshDatabase;

    private const AGENCY_ID = 9201;

    protected function setUp(): void
    {
        parent::setUp();
        // Real tables, rolled back by RefreshDatabase. The rows below use made-up
        // agency/branch/user ids whose parent rows are irrelevant to what is proved
        // here, so foreign-key checks are off for this connection (restored in tearDown).
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
    }

    protected function tearDown(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    public function test_rollup_counts_appointments_linked_only_via_the_tick_list(): void
    {
        $agentId = 701;
        $this->seedAgent($agentId);

        $directBuyer   = $this->seedBuyer(1, $agentId, 'Direct Buyer');
        $tickListBuyer = $this->seedBuyer(2, $agentId, 'Tick List Buyer');

        $now = Carbon::now();

        // Direct link (calendar_events.contact_id) -- the old code already counted this.
        $evt1 = $this->seedViewing($agentId, $now, contactId: $directBuyer);

        // Tick-list-only link (calendar_event_links, role=buyer_contact) -- the
        // exact case that was silently dropped before this fix.
        $evt2 = $this->seedViewing($agentId, $now, contactId: null);
        $this->seedEventLink($evt2, $tickListBuyer);

        $period = new Period(
            $now->copy()->startOfMonth()->toImmutable(),
            $now->copy()->endOfMonth()->toImmutable(),
            'This month',
            'this_month',
        );
        $scope = new PerformanceScope(self::AGENCY_ID, null, null);

        $rollup = app(BuyerActivityService::class)->rollup($scope, $period);

        $agentRow = collect($rollup['agents'])->firstWhere('user_id', $agentId);
        $this->assertSame(2, $agentRow['metrics']['appointments'], 'Both the direct-link and tick-list-only viewings must count.');
        $this->assertSame(2, $rollup['company']['appointments']);
    }

    public function test_agent_detail_dedupes_an_event_linked_both_ways(): void
    {
        $agentId = 801;
        $this->seedAgent($agentId);

        $buyer = $this->seedBuyer(3, $agentId, 'Double Linked Buyer');
        $now = Carbon::now();

        // Linked BOTH via contact_id AND via calendar_event_links for the same buyer.
        $evt = $this->seedViewing($agentId, $now, contactId: $buyer);
        $this->seedEventLink($evt, $buyer);

        $period = new Period(
            $now->copy()->startOfMonth()->toImmutable(),
            $now->copy()->endOfMonth()->toImmutable(),
            'This month',
            'this_month',
        );

        $detail = app(BuyerActivityService::class)->agentDetail(self::AGENCY_ID, $agentId, $period);

        $row = collect($detail['buyers'])->firstWhere('contact_id', $buyer);
        $this->assertSame(1, $row['appointments'], 'A doubly-linked event must count once, not twice.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedAgent(int $id): void
    {
        DB::table('users')->insert([
            'id' => $id, 'agency_id' => self::AGENCY_ID, 'branch_id' => 1, 'name' => 'Agent',
            'email' => "agent{$id}@example.test", 'password' => 'x', 'is_active' => 1,
        ]);
    }

    private function seedBuyer(int $id, int $agentId, string $name): int
    {
        DB::table('contacts')->insert([
            'id' => $id, 'agency_id' => self::AGENCY_ID, 'agent_id' => $agentId,
            'branch_id' => 1, 'is_buyer' => 1, 'first_name' => $name, 'last_name' => '',
            'buyer_state' => 'warm',
        ]);

        return $id;
    }

    private function seedViewing(int $agentId, Carbon $eventDate, ?int $contactId): int
    {
        return (int) DB::table('calendar_events')->insertGetId([
            'user_id' => $agentId, 'contact_id' => $contactId, 'category' => 'viewing',
            'event_type' => 'manual', 'title' => 'Test viewing', 'event_date' => $eventDate,
        ]);
    }

    private function seedEventLink(int $eventId, int $contactId): void
    {
        DB::table('calendar_event_links')->insert([
            'calendar_event_id' => $eventId, 'linkable_type' => \App\Models\Contact::class,
            'linkable_id' => $contactId, 'role' => 'buyer_contact',
        ]);
    }
}
