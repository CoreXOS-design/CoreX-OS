<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Jobs\RebuildDealMoneyLinesJob;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Deal;
use App\Models\User;
use App\Services\DealMoneyLineRebuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AT-364 follow-up (perf) — DealObserver::saved() used to run an ALL-DEALS money-line rebuild
 * synchronously on every deal save (O(all deals) + a full Artisan bootstrap), which 502'd cold
 * requests. The fix scopes the rebuild to the saved deal and moves it to a queued job.
 *
 * This is a PERFORMANCE change, not a MATH change. These tests pin exactly that:
 *   - the scoped rebuild produces BYTE-IDENTICAL money-lines for a deal vs the old all-deals path,
 *   - a scoped rebuild leaves OTHER deals' money-lines untouched,
 *   - saving a deal dispatches the scoped RebuildDealMoneyLinesJob for that deal,
 *   - the job actually rebuilds the deal's money-lines (handle), and end-to-end a save still
 *     materialises them (the suite's sync queue runs the job inline).
 */
final class DealMoneyLineRecalcScopeQueueTest extends TestCase
{
    use RefreshDatabase;

    /** The computed money-line columns (everything the rebuild derives — excludes id/timestamps). */
    private const MONEY_COLS = [
        'user_id', 'side', 'period', 'branch_id',
        'side_pool_ex_vat', 'allocation_percent', 'pool_share_ex_vat',
        'agent_cut_percent', 'agent_gross_ex_vat', 'company_gross_ex_vat',
        'paye_method', 'paye_value', 'paye_amount',
        'deductions', 'agent_net_ex_vat', 'source',
    ];

    /** @return array{0:Agency,1:Branch} */
    private function agency(): array
    {
        $agency = Agency::create(['name' => 'Recalc Co', 'slug' => 'recalc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return [$agency, $branch];
    }

    private function agent(Agency $agency, Branch $branch): User
    {
        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
    }

    /**
     * Build a deal + its deal_user agent rows via raw inserts (no Eloquent events, so the observer
     * does NOT fire during setup — we drive the rebuild explicitly).
     *
     * @param array<int,array{0:int,1:string,2:float}> $agents [userId, side, agent_split_percent]
     */
    private function makeDealWithAgents(Agency $agency, Branch $branch, array $agents): int
    {
        $dealId = DB::table('deals')->insertGetId([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'period' => '2026-06', 'deal_date' => '2026-06-01',
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
            'listing_our_share_percent' => 100, 'selling_our_share_percent' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($agents as [$userId, $side, $split]) {
            DB::table('deal_user')->insert([
                'deal_id' => $dealId, 'user_id' => $userId, 'side' => $side,
                'agent_split_percent' => $split, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $dealId;
    }

    /** The LIVE (non-trashed) money-lines for a deal, as comparable arrays of the derived columns. */
    private function liveLines(int $dealId): array
    {
        return DB::table('deal_money_lines')
            ->where('deal_id', $dealId)->whereNull('deleted_at')
            ->orderBy('user_id')->orderBy('side')
            ->get(self::MONEY_COLS)
            ->map(fn ($r) => (array) $r)->all();
    }

    private function liveLineIds(int $dealId): array
    {
        return DB::table('deal_money_lines')
            ->where('deal_id', $dealId)->whereNull('deleted_at')
            ->orderBy('id')->pluck('id')->all();
    }

    public function test_scoped_rebuild_is_byte_identical_and_leaves_other_deals_untouched(): void
    {
        [$agency, $branch] = $this->agency();
        $a1 = $this->agent($agency, $branch);
        $a2 = $this->agent($agency, $branch);
        $b1 = $this->agent($agency, $branch);

        $dealA = $this->makeDealWithAgents($agency, $branch, [[$a1->id, 'listing', 100], [$a2->id, 'selling', 100]]);
        $dealB = $this->makeDealWithAgents($agency, $branch, [[$b1->id, 'listing', 100]]);

        // OLD behaviour: the observer's unfiltered all-deals rebuild.
        DealMoneyLineRebuilder::rebuild(null, null);
        $viaAll   = $this->liveLines($dealA);
        $bIdsAfterAll = $this->liveLineIds($dealB);
        $this->assertNotEmpty($viaAll, 'precondition: the deal produced money-lines');

        // NEW behaviour: rebuild ONLY deal A.
        DealMoneyLineRebuilder::rebuildDealId($dealA);
        $viaScoped = $this->liveLines($dealA);

        // 1) byte-identical derived money-lines for the saved deal
        $this->assertSame($viaAll, $viaScoped, 'scoped rebuild must produce identical money-lines for the deal');

        // 2) the scoped rebuild of A must not have touched deal B's live projection at all
        $this->assertSame($bIdsAfterAll, $this->liveLineIds($dealB), 'scoping deal A must leave deal B untouched');
    }

    public function test_saving_a_deal_dispatches_the_scoped_job(): void
    {
        Queue::fake();
        [$agency, $branch] = $this->agency();
        $dealId = $this->makeDealWithAgents($agency, $branch, [[$this->agent($agency, $branch)->id, 'listing', 100]]);

        // A real Eloquent save fires the observer (update path — no DealCreated chain).
        Deal::findOrFail($dealId)->touch();

        Queue::assertPushed(RebuildDealMoneyLinesJob::class, fn ($job) => $job->dealId === $dealId);
    }

    public function test_job_rebuilds_money_lines_for_the_deal(): void
    {
        [$agency, $branch] = $this->agency();
        $agent = $this->agent($agency, $branch);
        $dealId = $this->makeDealWithAgents($agency, $branch, [[$agent->id, 'listing', 100]]);

        $this->assertDatabaseMissing('deal_money_lines', ['deal_id' => $dealId, 'deleted_at' => null]);

        (new RebuildDealMoneyLinesJob($dealId))->handle();

        $this->assertDatabaseHas('deal_money_lines', [
            'deal_id' => $dealId, 'user_id' => $agent->id, 'side' => 'listing', 'deleted_at' => null,
        ]);
    }

    public function test_deal_save_materialises_money_lines_end_to_end_via_queue(): void
    {
        // Test env QUEUE_CONNECTION=sync → the dispatched job runs inline, exercising the whole
        // save → observer → job → rebuild chain.
        [$agency, $branch] = $this->agency();
        $agent = $this->agent($agency, $branch);
        $dealId = $this->makeDealWithAgents($agency, $branch, [[$agent->id, 'listing', 100]]);

        Deal::findOrFail($dealId)->touch();

        $this->assertDatabaseHas('deal_money_lines', [
            'deal_id' => $dealId, 'user_id' => $agent->id, 'side' => 'listing', 'deleted_at' => null,
        ]);
    }
}
