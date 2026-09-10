<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Deal;
use App\Models\User;
use App\Services\Finance\FinanceComputeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DR2 Financial Audit F2 (AT-408) — Deal::agents() was belongsToMany(User::class)
 * with no withTrashed(). Once an agent's account was deactivated, every read of
 * $deal->agents for their historical deals silently dropped them — the deal_user
 * pivot row (and the real commission it represents) was untouched, it just
 * disappeared from this one relationship. Two live consumers were confirmed
 * affected against real QA1 deals 131/132:
 *
 *  - Manifestation A (LIVE, currently wrong): FinanceComputeService::
 *    dealAgentIncomeByAgentExVat(), which feeds RollupService's per-agent/
 *    branch/company aggregation into finance_computed_values (Branch/Company
 *    Performance, TV leaderboard). Real deal 131's departed agent (user 41)
 *    lost R13,043.48; deal 132's (user 33) lost R36,750.00 — the missing share
 *    silently re-labelled as company profit (dealRetainedTotal = full income
 *    minus agent income).
 *  - Manifestation B (real but dormant): Deal::allocations()/allocateSide()/
 *    branchCommission() — currently unreachable by any registered route
 *    (AgentCommissionController has none), but real in the data and will
 *    activate the moment anything calls it.
 *
 * Reassuring counter-finding (verified separately, not retested here):
 * DealMoneyLineRebuilder::rebuildSingleDeal() — the function that actually
 * builds deal_money_lines, which real payslip printing reads — queries
 * DB::table('deal_user') raw, not $deal->agents. This bug never reached an
 * actual payslip.
 */
final class DealAgentsIncludesTrashedTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Trashed Agent Co', 'slug' => 'ta-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Mirrors real deal 131: one active agent (listing, 100%), one departed agent (selling, 100%). */
    private function makeDealWithOneDepartedAgent(): array
    {
        $activeAgent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent']);
        $departedAgent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent']);

        $deal = Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(200000, 299999), 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'accepted_status' => 'R', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 100_000,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
            'listing_external' => false, 'selling_external' => false,
        ]);

        DB::table('deal_user')->insert([
            ['deal_id' => $deal->id, 'user_id' => $activeAgent->id, 'side' => 'listing', 'agent_split_percent' => 100, 'agent_cut_percent' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['deal_id' => $deal->id, 'user_id' => $departedAgent->id, 'side' => 'selling', 'agent_split_percent' => 100, 'agent_cut_percent' => 100, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $departedAgent->delete(); // soft — the exact real-world trigger

        return [$deal->fresh(), $activeAgent, $departedAgent];
    }

    public function test_deal_agents_includes_a_soft_deleted_agent(): void
    {
        [$deal, $activeAgent, $departedAgent] = $this->makeDealWithOneDepartedAgent();

        $ids = $deal->agents()->pluck('users.id')->all();

        $this->assertContains($activeAgent->id, $ids);
        $this->assertContains($departedAgent->id, $ids, 'a departed agent\'s real deal_user row must not silently vanish from this relationship');
    }

    public function test_listing_and_selling_agents_inherit_the_fix(): void
    {
        [$deal, $activeAgent, $departedAgent] = $this->makeDealWithOneDepartedAgent();

        $this->assertSame([$activeAgent->id], $deal->listingAgents()->pluck('users.id')->all());
        $this->assertSame([$departedAgent->id], $deal->sellingAgents()->pluck('users.id')->all());
    }

    public function test_manifestation_a_agent_income_by_agent_includes_the_departed_agents_share(): void
    {
        [$deal, $activeAgent, $departedAgent] = $this->makeDealWithOneDepartedAgent();

        $byAgent = FinanceComputeService::dealAgentIncomeByAgentExVat($deal);

        // Both sides are 50/50 of a R100,000 Incl-VAT total → R43,478.26 ex-VAT
        // pool per side, 100% split, 100% cut each — both agents earn the full
        // pool of their own side.
        $this->assertArrayHasKey($activeAgent->id, $byAgent);
        $this->assertArrayHasKey($departedAgent->id, $byAgent, 'the departed agent\'s income must not silently disappear — it was previously re-labelled as company profit');
        $this->assertEqualsWithDelta($byAgent[$activeAgent->id], $byAgent[$departedAgent->id], 0.01, 'both sides are an identical 50/50 split — both agents should earn the same amount');
    }

    public function test_manifestation_b_allocations_includes_the_departed_agents_share(): void
    {
        [$deal, $activeAgent, $departedAgent] = $this->makeDealWithOneDepartedAgent();

        $allocations = $deal->allocations();

        $this->assertArrayHasKey($activeAgent->id, $allocations);
        $this->assertArrayHasKey($departedAgent->id, $allocations, 'Deal::allocations() is dormant today (no registered route calls it) but must not silently drop a real share the moment it is reactivated');
        $this->assertGreaterThan(0, $allocations[$departedAgent->id]);
    }

    public function test_branch_commission_counts_the_departed_agents_branch_share(): void
    {
        [$deal, $activeAgent, $departedAgent] = $this->makeDealWithOneDepartedAgent();

        $commission = $deal->branchCommission($this->branchId);
        $allocations = $deal->allocations();
        $expected = ($allocations[$activeAgent->id] ?? 0) + ($allocations[$departedAgent->id] ?? 0);

        $this->assertEqualsWithDelta($expected, $commission, 0.01);
    }

    /**
     * Real deal 131's exact shape: listing 100% to one agent, selling 100% to
     * a since-departed agent, R100,000 total commission. The audit's own
     * recorded diff for this deal was R13,043.48.
     */
    public function test_worked_example_matching_real_deal_131_shape(): void
    {
        [$deal, $activeAgent, $departedAgent] = $this->makeDealWithOneDepartedAgent();

        $byAgent = FinanceComputeService::dealAgentIncomeByAgentExVat($deal);

        $this->assertEqualsWithDelta(43478.26, $byAgent[$departedAgent->id], 0.01);
    }
}
