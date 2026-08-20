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

    // ── Helpers ──────────────────────────────────────────────────────────

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
            $table->timestamp('buyer_pipeline_entered_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
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
    }
}
