<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-192 (b) — a DR1 deal may never be stored without a branch.
 *
 * A NULL-home-branch admin (the consolidated multi-branch-manager Elize shape)
 * captures the branch from a dropdown; nothing auto-derives it. Before the fix
 * `branch_id` was `nullable`, so a missed selection silently stored a
 * null-branch deal whose commission split lines (which inherit deals.branch_id)
 * became unattributed. The fix REQUIRES an explicit branch server-side for any
 * non-branch-scope capturer. Normal branch agents are auto-stamped and never
 * hit the gate.
 */
class DealCaptureBranchRequiredTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function payload(int $listingAgentId, int $sellingAgentId, array $overrides = []): array
    {
        return array_merge([
            'period'                => '2026-06',
            'deal_date'             => '2026-06-10',
            'property_value'        => 1000000,
            'total_commission'      => 57500,
            'listing_split_percent' => 50,
            'selling_split_percent' => 50,
            'listing_agents'        => [(string) $listingAgentId],
            'selling_agents'        => [(string) $sellingAgentId],
        ], $overrides);
    }

    /** @return array{0:User,1:User} two agents in $branch */
    private function twoAgents(Agency $agency, Branch $branch): array
    {
        return [
            User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']),
            User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']),
        ];
    }

    public function test_null_home_admin_cannot_store_deal_without_a_branch(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-b']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Southbroom']);
        $admin = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => null, 'role' => 'admin', 'is_active' => true,
        ]);
        [$l, $s] = $this->twoAgents($agency, $branch);

        $before = DB::table('deals')->count();

        $this->actingAs($admin)
            ->post(route('admin.deals.store'), $this->payload($l->id, $s->id)) // no branch_id
            ->assertSessionHasErrors('branch_id');

        $this->assertSame($before, DB::table('deals')->count(), 'No null-branch deal may be created.');
    }

    public function test_null_home_admin_can_store_when_branch_is_chosen(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-b2']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Ballito']);
        $admin = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => null, 'role' => 'admin', 'is_active' => true,
        ]);
        [$l, $s] = $this->twoAgents($agency, $branch);

        $this->actingAs($admin)
            ->post(route('admin.deals.store'), $this->payload($l->id, $s->id, ['branch_id' => $branch->id]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('deals', [
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'period' => '2026-06',
        ]);
    }

    public function test_branch_agent_is_unaffected_and_auto_stamped(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-b3']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);
        $bm = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'branch_manager', 'is_active' => true,
        ]);
        [$l, $s] = $this->twoAgents($agency, $branch);

        // Branch-scope capturer submits NO branch_id — it must be auto-stamped
        // from their home branch, and the deal saves cleanly (gate not reached).
        $this->actingAs($bm)
            ->post(route('admin.deals.store'), $this->payload($l->id, $s->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('deals', [
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'period' => '2026-06',
        ]);
    }
}
