<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Services\Admin\AgentDeletionService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Locks in the "move deals to another agent" path of agent deletion — built
 * to merge duplicate agent accounts without orphaning deals or commission.
 * Spec: .ai/specs/agent-delete-reassignment.md (Deals section).
 *
 * Deal rows are inserted via DB::table to bypass the Deal global scopes
 * (AgencyScope + DealBranchScope), mirroring how the service itself works.
 */
class AgentDeleteDealReassignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    /** @return array{0: Agency, 1: User, 2: User} agency, source, target */
    private function makeAgencyAndAgents(): array
    {
        $agency = Agency::create(['name' => 'Merge Co', 'slug' => 'merge-co']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        $source = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $target = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        return [$agency, $source, $target];
    }

    private function makeDeal(Agency $agency): int
    {
        return DB::table('deals')->insertGetId([
            'agency_id'        => $agency->id,
            'period'           => '2026-06',
            'deal_date'        => '2026-06-01',
            'property_value'   => 1000000,
            'total_commission' => 57500,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function test_move_transfers_ownership_and_money_to_target(): void
    {
        [$agency, $source, $target] = $this->makeAgencyAndAgents();
        $dealId = $this->makeDeal($agency);

        DB::table('deals')->where('id', $dealId)->update(['managed_by_user_id' => $source->id]);
        DB::table('deal_user')->insert([
            'deal_id' => $dealId, 'user_id' => $source->id, 'side' => 'listing',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deal_settlements')->insert([
            'deal_id' => $dealId, 'user_id' => $source->id, 'side' => 'listing',
            'agency_id' => $agency->id, 'share_percent' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deal_money_lines')->insert([
            'deal_id' => $dealId, 'user_id' => $source->id, 'period' => '2026-06',
            'agency_id' => $agency->id, 'agent_net_ex_vat' => 25000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(AgentDeletionService::class)->reassignDeals($source, $target, $source->id);

        $this->assertDatabaseHas('deals', ['id' => $dealId, 'managed_by_user_id' => $target->id]);
        $this->assertDatabaseHas('deal_user', ['deal_id' => $dealId, 'user_id' => $target->id, 'side' => 'listing']);
        $this->assertDatabaseMissing('deal_user', ['deal_id' => $dealId, 'user_id' => $source->id]);
        $this->assertDatabaseHas('deal_settlements', ['deal_id' => $dealId, 'user_id' => $target->id]);
        $this->assertDatabaseHas('deal_money_lines', ['deal_id' => $dealId, 'user_id' => $target->id]);
    }

    public function test_move_dedups_a_slot_the_target_already_holds(): void
    {
        [$agency, $source, $target] = $this->makeAgencyAndAgents();
        $dealId = $this->makeDeal($agency);

        // Both duplicate accounts are listing agents on the same deal.
        DB::table('deal_user')->insert([
            ['deal_id' => $dealId, 'user_id' => $source->id, 'side' => 'listing', 'created_at' => now(), 'updated_at' => now()],
            ['deal_id' => $dealId, 'user_id' => $target->id, 'side' => 'listing', 'created_at' => now(), 'updated_at' => now()],
        ]);

        app(AgentDeletionService::class)->reassignDeals($source, $target, $source->id);

        // Source's duplicate is dropped; target keeps exactly one row for the slot.
        $this->assertDatabaseMissing('deal_user', ['deal_id' => $dealId, 'user_id' => $source->id]);
        $this->assertSame(1, DB::table('deal_user')
            ->where('deal_id', $dealId)->where('user_id', $target->id)->where('side', 'listing')->count());
    }

    public function test_preview_counts_distinct_deals_and_flags_has_deals(): void
    {
        [$agency, $source, $target] = $this->makeAgencyAndAgents();

        $asAgent  = $this->makeDeal($agency);
        $asManager = $this->makeDeal($agency);

        DB::table('deal_user')->insert([
            'deal_id' => $asAgent, 'user_id' => $source->id, 'side' => 'selling',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deals')->where('id', $asManager)->update(['managed_by_user_id' => $source->id]);

        $counts = app(AgentDeletionService::class)->preview($source);

        $this->assertSame(2, $counts['deals']);
        $this->assertTrue($counts['has_deals']);

        // The target (no deals) reports none.
        $this->assertSame(0, app(AgentDeletionService::class)->preview($target)['deals']);
        $this->assertFalse(app(AgentDeletionService::class)->preview($target)['has_deals']);
    }
}
