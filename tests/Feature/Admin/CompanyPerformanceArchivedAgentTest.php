<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Services\Admin\CompanyPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-192 (c) — Archived agents' HISTORICAL commission must still count toward
 * their HOME branch's TEAM column for the period they earned it.
 *
 * Regression guard for the silent R248k drop: when the consolidated
 * "Elize Southbroom" (branch_manager, branch 3) login was archived
 * (is_active=0) the period rollup's raw `where('is_active', 1)` agent gate
 * dropped her entirely, taking R248,160.53 of real Southbroom take-home out of
 * the branch TEAM column. The fix keeps every active split-counting agent PLUS
 * any now-inactive split-counting agent with a non-declined deal dated in the
 * period — and labels them archived in the grid.
 *
 * Rows are inserted via DB::table to bypass the Deal global scopes, mirroring
 * how the service itself reads (raw DB::table, no Eloquent scopes).
 */
class CompanyPerformanceArchivedAgentTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Coastal Co', 'slug' => 'coastal-co']);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Southbroom', 'code' => 'SO']);
    }

    private function makeAgent(bool $active, int $cfbs = 1): User
    {
        return User::factory()->create([
            'agency_id'                => $this->agency->id,
            'branch_id'                => $this->branch->id,
            'role'                     => 'branch_manager',
            'is_active'                => $active,
            'counts_for_branch_split'  => $cfbs,
        ]);
    }

    /** Seed one non-declined, non-external listing deal in the period for $agent. */
    private function seedDeal(User $agent, string $period = '2026-06', string $accepted = 'A'): int
    {
        $dealId = DB::table('deals')->insertGetId([
            'agency_id'                 => $this->agency->id,
            'branch_id'                 => $this->branch->id,
            'period'                    => $period,
            'deal_date'                 => $period . '-10',
            'accepted_status'           => $accepted,
            'property_value'            => 1000000,
            'total_commission'          => 57500,   // R50 000 ex-VAT
            'listing_split_percent'     => 100,
            'selling_split_percent'     => 0,
            'listing_external'          => 0,
            'listing_our_share_percent' => 100,
            'created_at'                => now(),
            'updated_at'                => now(),
        ]);

        DB::table('deal_user')->insert([
            'deal_id'             => $dealId,
            'user_id'             => $agent->id,
            'side'                => 'listing',
            'agent_split_percent' => 100,
            'agent_cut_percent'   => 100,   // agent keeps the whole side pool
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        return $dealId;
    }

    public function test_archived_agent_with_period_production_counts_and_is_labelled(): void
    {
        $active   = $this->makeAgent(active: true);
        $archived = $this->makeAgent(active: false);   // the retired Elize-Southbroom shape

        $this->seedDeal($active);
        $this->seedDeal($archived);

        $rollup = app(CompanyPerformanceService::class)->getPeriodRollup('2026-06');

        $rows = collect($rollup['rows']);
        $activeRow   = $rows->firstWhere('user_id', $active->id);
        $archivedRow = $rows->firstWhere('user_id', $archived->id);

        // Both render; the archived one is flagged so the grid can badge it.
        $this->assertNotNull($activeRow, 'Active agent must render.');
        $this->assertNotNull($archivedRow, 'Archived agent with period production MUST render.');
        $this->assertFalse((bool) $activeRow['is_archived']);
        $this->assertTrue((bool) $archivedRow['is_archived']);

        // The archived agent's take-home flows into the branch TEAM column.
        $branch = collect($rollup['branches'])->firstWhere('branch_id', $this->branch->id);
        $this->assertNotNull($branch);
        $this->assertEqualsWithDelta(
            (float) $activeRow['actuals']['agent_income'],
            (float) $archivedRow['actuals']['agent_income'],
            0.01,
            'Same deal shape → archived agent earns the same income as the active one.'
        );
        $this->assertGreaterThan(0, (float) $archivedRow['actuals']['agent_income']);
        $this->assertEqualsWithDelta(
            (float) $activeRow['actuals']['agent_income'] + (float) $archivedRow['actuals']['agent_income'],
            (float) $branch['actuals']['team_agent_income'],
            0.01,
            'Branch TEAM column must include BOTH agents — archived production is not silently dropped.'
        );
    }

    public function test_archived_agent_without_period_production_stays_out(): void
    {
        $archivedNoDeals = $this->makeAgent(active: false);
        $archivedDeclinedOnly = $this->makeAgent(active: false);
        $this->seedDeal($archivedDeclinedOnly, accepted: 'D'); // declined → does not count

        $rollup = app(CompanyPerformanceService::class)->getPeriodRollup('2026-06');
        $ids = collect($rollup['rows'])->pluck('user_id')->all();

        $this->assertNotContains($archivedNoDeals->id, $ids, 'Archived agent with no production must not clutter the grid.');
        $this->assertNotContains($archivedDeclinedOnly->id, $ids, 'A declined deal is not production.');
    }

    public function test_active_agent_without_split_flag_still_excluded(): void
    {
        // counts_for_branch_split=0 remains a hard gate for everyone (unchanged).
        $noSplit = $this->makeAgent(active: true, cfbs: 0);
        $this->seedDeal($noSplit);

        $rollup = app(CompanyPerformanceService::class)->getPeriodRollup('2026-06');
        $ids = collect($rollup['rows'])->pluck('user_id')->all();

        $this->assertNotContains($noSplit->id, $ids);
    }
}
