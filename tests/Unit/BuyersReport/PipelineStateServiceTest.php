<?php

declare(strict_types=1);

namespace Tests\Unit\BuyersReport;

use App\Services\BuyersReport\BuyersReportScope;
use App\Services\BuyersReport\PipelineStateService;
use App\Services\Performance\Period;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the pipeline-state spine Johan asked for (2026-08-20): the report's
 * snapshot must equal the pipeline board's own count, at every drill level,
 * including the real bug this test suite caught during build -- an inner
 * join to `users` silently dropping buyers with no assigned agent (15 of 64
 * "warm" buyers on qa1 real data). snapshotByAgent() must never undercount
 * snapshot()'s own total.
 */
final class PipelineStateServiceTest extends TestCase
{
    private const AGENCY_ID = 9301;

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

    public function test_snapshot_by_agent_never_drops_buyers_with_no_assigned_agent(): void
    {
        $agent = 3001;
        $this->seedUser($agent, 'Agent One');
        $this->seedBuyer(1, $agent, 'warm');
        $this->seedBuyer(2, $agent, 'warm');
        $this->seedBuyer(3, null, 'warm'); // unassigned -- must still count

        $scope = new BuyersReportScope(self::AGENCY_ID, BuyersReportScope::LEVEL_AGENCY);
        $service = app(PipelineStateService::class);

        $snapshot = $service->snapshot($scope);
        $this->assertSame(3, $snapshot['states']['warm']);

        $byAgent = $service->snapshotByAgent($scope);
        $sum = array_sum(array_map(fn ($a) => $a['states']['warm'], $byAgent));
        $this->assertSame(3, $sum, 'Per-agent breakdown must sum to the snapshot total -- an unassigned buyer must not be silently dropped.');

        $unassigned = collect($byAgent)->firstWhere('user_id', PipelineStateService::AGENT_UNASSIGNED);
        $this->assertNotNull($unassigned, 'Unassigned buyers must appear under the AGENT_UNASSIGNED sentinel, not vanish.');
        $this->assertSame(1, $unassigned['states']['warm']);
    }

    public function test_agent_summary_and_buyer_list_reconcile_for_every_state(): void
    {
        $agentA = 3002;
        $this->seedUser($agentA, 'Agent A');
        $this->seedBuyer(4, $agentA, 'new');
        $this->seedBuyer(5, $agentA, 'new');
        $this->seedBuyer(6, $agentA, 'cold');
        $this->seedBuyer(7, null, 'cold');
        $this->seedBuyer(8, $agentA, null); // no_state

        $scope = new BuyersReportScope(self::AGENCY_ID, BuyersReportScope::LEVEL_AGENCY);
        $service = app(PipelineStateService::class);

        // Iterate as [state, expected] pairs, not a keyed array -- a literal
        // `null =>` array key silently casts to '' in PHP, not null.
        foreach ([['new', 2], ['cold', 2], [null, 1]] as [$state, $expected]) {
            $summary = $service->agentSummaryForState($scope, $state);
            $this->assertSame($expected, $summary['count'], "state=$state summary total");
            $sumRows = array_sum(array_column($summary['rows'], 'count'));
            $this->assertSame($expected, $sumRows, "state=$state per-agent rows must sum to the total");

            foreach ($summary['rows'] as $row) {
                $list = $service->buyersForAgentInState($row['agent_id'], $state);
                $this->assertSame($row['count'], $list['count'], "agent {$row['agent_id']} state=$state buyer-list count must equal its summary row");
            }
        }
    }

    public function test_movement_counts_entered_and_left_within_period_only(): void
    {
        $agent = 3003;
        $this->seedUser($agent, 'Mover Agent');
        $buyer = $this->seedBuyer(9, $agent, 'warm');

        $now = Carbon::now();
        DB::table('buyer_state_transitions')->insert([
            ['contact_id' => $buyer, 'agency_id' => self::AGENCY_ID, 'from_state' => 'new', 'to_state' => 'warm', 'occurred_at' => $now->copy()->subDays(2)],
            ['contact_id' => $buyer, 'agency_id' => self::AGENCY_ID, 'from_state' => 'warm', 'to_state' => 'cold', 'occurred_at' => $now->copy()->subDays(40)], // outside period
        ]);

        $scope = new BuyersReportScope(self::AGENCY_ID, BuyersReportScope::LEVEL_AGENCY);
        $period = new Period($now->copy()->subDays(7)->startOfDay()->toImmutable(), $now->copy()->endOfDay()->toImmutable(), 'This period', 'custom');

        $movement = app(PipelineStateService::class)->movement($scope, $period);
        $this->assertSame(1, $movement['warm']['entered'], 'The in-period new->warm transition must count.');
        $this->assertSame(0, $movement['cold']['entered'], 'The out-of-period warm->cold transition must NOT count.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedUser(int $id, string $name): void
    {
        DB::table('users')->insert(['id' => $id, 'agency_id' => self::AGENCY_ID, 'name' => $name, 'is_active' => 1, 'role' => 'agent']);
    }

    private function seedBuyer(int $id, ?int $agentId, ?string $state): int
    {
        DB::table('contacts')->insert([
            'id' => $id, 'agency_id' => self::AGENCY_ID, 'agent_id' => $agentId,
            'is_buyer' => 1, 'buyer_state' => $state, 'first_name' => "Buyer $id", 'last_name' => '',
        ]);

        return $id;
    }

    private function dropSchema(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Schema::dropIfExists('buyer_state_transitions');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('users');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function buildSchema(): void
    {
        $this->dropSchema();

        Schema::create('users', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name')->nullable();
            $table->string('role', 40)->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('contacts', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->boolean('is_buyer')->default(0);
            $table->string('buyer_state', 20)->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('buyer_state_transitions', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('from_state', 20)->nullable();
            $table->string('to_state', 20)->nullable();
            $table->string('reason', 30)->nullable();
            $table->timestamp('occurred_at');
        });
    }
}
