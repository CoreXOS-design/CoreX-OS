<?php

declare(strict_types=1);

namespace Tests\Unit\Performance;

use App\Services\Performance\BuyerActivityService;
use App\Services\Performance\Period;
use App\Services\Performance\PerformanceScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
 * DB approach: hand-built minimal schema, same ERROR-1419 workaround used
 * throughout this session.
 */
final class BuyerActivityServiceAppointmentsTest extends TestCase
{
    private const AGENCY_ID = 9201;

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

    public function test_rollup_counts_appointments_linked_only_via_the_tick_list(): void
    {
        $agentId = 701;
        DB::table('users')->insert(['id' => $agentId, 'agency_id' => self::AGENCY_ID, 'branch_id' => 1, 'name' => 'Agent', 'is_active' => 1]);

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
        DB::table('users')->insert(['id' => $agentId, 'agency_id' => self::AGENCY_ID, 'branch_id' => 1, 'name' => 'Agent', 'is_active' => 1]);

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
            'title' => 'Test viewing', 'event_date' => $eventDate,
        ]);
    }

    private function seedEventLink(int $eventId, int $contactId): void
    {
        DB::table('calendar_event_links')->insert([
            'calendar_event_id' => $eventId, 'linkable_type' => \App\Models\Contact::class,
            'linkable_id' => $contactId, 'role' => 'buyer_contact',
        ]);
    }

    private function dropSchema(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Schema::dropIfExists('calendar_event_links');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('communication_links');
        Schema::dropIfExists('communications');
        Schema::dropIfExists('buyer_lost_records');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('users');
        Schema::dropIfExists('branches');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function buildSchema(): void
    {
        $this->dropSchema();

        Schema::create('branches', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->string('name')->nullable();
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
            $table->boolean('is_buyer')->default(0);
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('buyer_state', 20)->nullable();
            $table->timestamp('buyer_pipeline_entered_at')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('calendar_events', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('category', 40)->nullable();
            $table->string('title')->nullable();
            $table->timestamp('event_date')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('calendar_event_links', function ($table) {
            $table->id();
            $table->unsignedBigInteger('calendar_event_id');
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->string('role', 40)->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

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

        Schema::create('buyer_lost_records', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('agent_owner_user_id_at_loss')->nullable();
            $table->string('reason_label')->nullable();
            $table->string('reason_code')->nullable();
            $table->decimal('preapproval_amount_at_loss', 15, 2)->nullable();
            $table->string('buyer_state_at_loss', 20)->nullable();
            $table->unsignedInteger('days_in_pipeline_at_loss')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
        });
    }
}
