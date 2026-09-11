<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Models\Deal;
use App\Models\User;
use App\Services\Agent\AgentPerformanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DR2 Financial Audit F6 (AT-412) — My Agent Dashboard's getMonthlySnapshot()
 * applied the DEAL SIDE's split (deals.listing/selling_split_percent) but
 * never the AGENT's own share of that side (deal_user.agent_split_percent).
 * Two agents co-listing/co-selling the same side each saw the FULL side's
 * property value and commission credited to them individually — a real,
 * provable double-count on the agent's own "Sales Value" figure (shown
 * directly as a rand amount on screen), not just an architectural risk as
 * the audit's initial pass characterised it.
 *
 * Reproduced on real QA1 deal 124 (agency 1, period 2026-05): Kym Pollard
 * is the SOLE listing agent (agent_split_percent=100) but shares the
 * selling side 50/50 with Dru De Bruyn. Before this fix, Kym's dashboard
 * credited her the full R1,275,000 property value and R75,000 commission
 * from this one deal — the entire deal, when she only wholly owns half of
 * it (listing) and shares the other half (selling) with Dru. After the fix:
 * R956,250 / R56,250 — her true combined share.
 *
 * deals_count (DISTINCT deal_id) was never affected — a co-listed deal was
 * always counted once, correctly, regardless of split.
 *
 * The "Avg commission %" ratio itself (the only figure this service's
 * result is used for beyond raw display) is unaffected by this bug in the
 * ordinary case, since the missing factor is the same in both the
 * numerator (commission) and denominator (sales value) for any single
 * side — not retested here, verified by hand in the finding's report.
 *
 * No historical correction applies — this is a read-time query, not a
 * stored value; fixing the code corrects every past and future view
 * immediately.
 */
final class AgentDashboardCoAgentSplitTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Co-Agent Co', 'slug' => 'ca-' . uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_dashboard_credits_only_the_agents_own_share_of_a_co_listed_side(): void
    {
        // Mirrors real deal 124's exact shape.
        $kym = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent']);
        $dru = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent']);

        $deal = Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(100000, 199999), 'period' => '2026-05', 'deal_date' => '2026-05-02',
            'accepted_status' => 'R', 'commission_status' => 'Not Paid',
            'property_value' => 1_275_000, 'total_commission' => 75_000,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
            'listing_external' => 0, 'selling_external' => 0,
        ]);

        DB::table('deal_user')->insert([
            ['deal_id' => $deal->id, 'user_id' => $kym->id, 'side' => 'listing', 'agent_split_percent' => 100, 'agent_cut_percent' => 70, 'created_at' => now(), 'updated_at' => now()],
            ['deal_id' => $deal->id, 'user_id' => $kym->id, 'side' => 'selling', 'agent_split_percent' => 50, 'agent_cut_percent' => 70, 'created_at' => now(), 'updated_at' => now()],
            ['deal_id' => $deal->id, 'user_id' => $dru->id, 'side' => 'selling', 'agent_split_percent' => 50, 'agent_cut_percent' => 50, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $snapshot = app(AgentPerformanceService::class)->getMonthlySnapshot($kym, Carbon::createFromFormat('Y-m', '2026-05'));

        $this->assertSame(1, $snapshot['actuals']['deals_count']);
        $this->assertEqualsWithDelta(956250.00, $snapshot['actuals']['sales_value'], 0.01, 'listing (100% hers) + selling (her 50% half) — not the full R1,275,000');
    }

    public function test_the_other_co_agent_sees_only_their_own_half_too(): void
    {
        $kym = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent']);
        $dru = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent']);

        $deal = Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(100000, 199999), 'period' => '2026-05', 'deal_date' => '2026-05-02',
            'accepted_status' => 'R', 'commission_status' => 'Not Paid',
            'property_value' => 1_275_000, 'total_commission' => 75_000,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
            'listing_external' => 0, 'selling_external' => 0,
        ]);

        DB::table('deal_user')->insert([
            ['deal_id' => $deal->id, 'user_id' => $kym->id, 'side' => 'listing', 'agent_split_percent' => 100, 'agent_cut_percent' => 70, 'created_at' => now(), 'updated_at' => now()],
            ['deal_id' => $deal->id, 'user_id' => $kym->id, 'side' => 'selling', 'agent_split_percent' => 50, 'agent_cut_percent' => 70, 'created_at' => now(), 'updated_at' => now()],
            ['deal_id' => $deal->id, 'user_id' => $dru->id, 'side' => 'selling', 'agent_split_percent' => 50, 'agent_cut_percent' => 50, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $snapshot = app(AgentPerformanceService::class)->getMonthlySnapshot($dru, Carbon::createFromFormat('Y-m', '2026-05'));

        // Dru only ever shares the selling side — must show HER half, not the
        // whole side (which would double the deal's true value across the two).
        $this->assertEqualsWithDelta(318750.00, $snapshot['actuals']['sales_value'], 0.01);
    }
}
