<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Deal;
use App\Models\User;
use App\Services\Admin\CompanyPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DR2 Financial Audit F5 (AT-411) — "what period does a deal belong to" had
 * at least two independent, disagreeing conventions on the same Branch
 * Performance screen: Deal::statusSummaryForBranch() bucketed by
 * deals.deal_date; Deal::marketAveragesForBranch() (already correct) and
 * RollupService (the audited Finance Engine, AT-408/F3) both bucket by
 * deals.period. CompanyPerformanceService's own fallback-path queries (found
 * while working AT-410/F4) had the same deal_date bug in 8 places across
 * both the company- and branch-level rollups.
 *
 * Canonical rule adopted (matches the majority, already-audited convention):
 * deals.period is the single source of truth for period membership.
 *
 * Reproduced on real QA1 deal 105 (agency 1, branch 3, agent Kym Pollard):
 * period='2026-04', deal_date='2026-03-18', Granted, R33,925 commission.
 * Before this fix it counted toward March's branch totals; it belongs in
 * April's.
 */
final class PeriodBucketingUsesDealsPeriodTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Period Co', 'slug' => 'pd-' . uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Mirrors real deal 105's exact shape: period and deal_date fall in different months. */
    private function makeMismatchedDeal(): array
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => false, 'counts_for_branch_split' => 1]);
        $dealId = DB::table('deals')->insertGetId([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => random_int(500000, 599999),
            'period' => '2026-04', 'deal_date' => '2026-03-18', // the real deal 105 mismatch
            'accepted_status' => 'G', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'listing_split_percent' => 100, 'selling_split_percent' => 0,
            'listing_external' => 0, 'listing_our_share_percent' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deal_user')->insert([
            'deal_id' => $dealId, 'user_id' => $agent->id, 'side' => 'listing',
            'agent_split_percent' => 100, 'agent_cut_percent' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$dealId, $agent];
    }

    public function test_status_summary_for_branch_attributes_a_mismatched_deal_to_its_period_not_its_deal_date(): void
    {
        [$dealId, $agent] = $this->makeMismatchedDeal();

        $march = Deal::statusSummaryForBranch($this->branchId, '2026-03');
        $april = Deal::statusSummaryForBranch($this->branchId, '2026-04');

        $this->assertSame(0, $march['granted_period'], 'the deal\'s deal_date falls in March, but its period is April — it must not count toward March');
        $this->assertSame(1, $april['granted_period'], 'it belongs in April, where its period says it does');
    }

    public function test_company_performance_team_participant_grid_uses_period_not_deal_date(): void
    {
        // The agent is created with is_active=false — this query includes an
        // inactive agent ONLY via the period-participant check (AT-192), so
        // it's the precise lens needed here: if that check used deal_date,
        // the agent would appear via a March deal that isn't really theirs
        // this period; if it correctly uses period, they appear in April only.
        [$dealId, $agent] = $this->makeMismatchedDeal();

        $rollupMarch = app(CompanyPerformanceService::class)->getPeriodRollup('2026-03', $this->agencyId);
        $rollupApril = app(CompanyPerformanceService::class)->getPeriodRollup('2026-04', $this->agencyId);

        $marchIds = collect($rollupMarch['rows'])->pluck('user_id')->all();
        $aprilIds = collect($rollupApril['rows'])->pluck('user_id')->all();

        $this->assertNotContains($agent->id, $marchIds, 'the participant grid must follow deals.period, not deal_date');
        $this->assertContains($agent->id, $aprilIds);
    }
}
