<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Jobs\RebuildDealMoneyLinesJob;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\DealSettlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DealSettlementObserver::saved() used to run an ALL-DEALS money-line rebuild synchronously on every
 * settlement save — the identical O(all deals) + Artisan-bootstrap 502 risk fixed in DealObserver.
 * The fix scopes the rebuild to the settlement's OWN deal and queues it (RebuildDealMoneyLinesJob).
 *
 * PERFORMANCE change, not a MATH change. These tests pin: a settlement save dispatches the scoped job
 * for that settlement's deal, end-to-end it rebuilds that deal's lines (from the settlement values),
 * and it leaves OTHER deals' money-lines untouched (the all-deals path would have rebuilt them too).
 */
final class DealSettlementRecalcScopeQueueTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Agency,1:Branch} */
    private function agency(): array
    {
        $agency = Agency::create(['name' => 'Settle Co', 'slug' => 'settle-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return [$agency, $branch];
    }

    private function agent(Agency $agency, Branch $branch): User
    {
        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
    }

    /** Deal + one listing agent (deal_user), via raw inserts so no observer fires during setup. */
    private function makeDealWithAgent(Agency $agency, Branch $branch, User $agent): int
    {
        $dealId = DB::table('deals')->insertGetId([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'period' => '2026-06', 'deal_date' => '2026-06-01',
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
            'listing_our_share_percent' => 100, 'selling_our_share_percent' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deal_user')->insert([
            'deal_id' => $dealId, 'user_id' => $agent->id, 'side' => 'listing',
            'agent_split_percent' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $dealId;
    }

    public function test_saving_a_settlement_dispatches_the_scoped_job(): void
    {
        Queue::fake();
        [$agency, $branch] = $this->agency();
        $agent = $this->agent($agency, $branch);
        $dealId = $this->makeDealWithAgent($agency, $branch, $agent);

        DealSettlement::create([
            'agency_id' => $agency->id, 'deal_id' => $dealId, 'user_id' => $agent->id,
            'side' => 'listing', 'share_percent' => 100,
        ]);

        Queue::assertPushed(RebuildDealMoneyLinesJob::class, fn ($job) => $job->dealId === $dealId);
    }

    public function test_settlement_save_rebuilds_only_that_deal_end_to_end(): void
    {
        // Test env QUEUE_CONNECTION=sync → the dispatched job runs inline (whole chain exercised).
        [$agency, $branch] = $this->agency();
        $agentA = $this->agent($agency, $branch);
        $agentB = $this->agent($agency, $branch);

        $dealA = $this->makeDealWithAgent($agency, $branch, $agentA);
        $dealB = $this->makeDealWithAgent($agency, $branch, $agentB); // never saved → no rebuild expected

        DealSettlement::create([
            'agency_id' => $agency->id, 'deal_id' => $dealA, 'user_id' => $agentA->id,
            'side' => 'listing', 'share_percent' => 100,
        ]);

        // Deal A rebuilt from the settlement (source = 'settlement').
        $this->assertDatabaseHas('deal_money_lines', [
            'deal_id' => $dealA, 'user_id' => $agentA->id, 'side' => 'listing',
            'source' => 'settlement', 'deleted_at' => null,
        ]);

        // Deal B untouched — the scoped rebuild of A's settlement did NOT recompute other deals.
        $this->assertSame(
            0,
            DB::table('deal_money_lines')->where('deal_id', $dealB)->whereNull('deleted_at')->count(),
            'a settlement save on deal A must not rebuild deal B'
        );
    }
}
