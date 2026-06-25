<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ad Manager (Tools) — data-scope enforcement.
 *
 * The legacy boolean `ad_manager.all_agents` was replaced by the `ad_manager.view`
 * data-scope key (None / Own / Branch / All). This test locks the three live
 * scopes against the Tools → Ad Manager listing:
 *   - Own    → only the user's own listings.
 *   - Branch → own + same-branch agents' listings; never another branch.
 *   - All    → every agent's listing in the agency.
 *
 * Properties only appear when "live somewhere" (P24 / PP / website), so each
 * fixture listing is marked P24-active.
 */
final class AdManagerScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_own_scope_sees_only_own_listings(): void
    {
        [$agency, $branchA] = $this->agencyWithBranch();
        $agent = $this->agencyUser($agency, $branchA, 'agent');
        $other = $this->agencyUser($agency, $branchA, 'agent');

        $this->property($agency, $branchA, $agent, 'ZZZ-Own-House');
        $this->property($agency, $branchA, $other, 'ZZZ-Other-House');

        $this->actingAs($agent)
            ->get(route('tools.ad-manager'))
            ->assertOk()
            ->assertSee('ZZZ-Own-House')
            ->assertDontSee('ZZZ-Other-House');
    }

    public function test_branch_scope_sees_branch_listings_not_other_branch(): void
    {
        [$agency, $branchA] = $this->agencyWithBranch();
        $branchB = $this->branch($agency, 'Branch B');

        $manager      = $this->agencyUser($agency, $branchA, 'branch_manager');
        $sameBranch   = $this->agencyUser($agency, $branchA, 'agent');
        $otherBranch  = $this->agencyUser($agency, $branchB, 'agent');

        $this->property($agency, $branchA, $sameBranch, 'ZZZ-SameBranch-House');
        $this->property($agency, $branchB, $otherBranch, 'ZZZ-OtherBranch-House');

        $this->actingAs($manager)
            ->get(route('tools.ad-manager'))
            ->assertOk()
            ->assertSee('ZZZ-SameBranch-House')
            ->assertDontSee('ZZZ-OtherBranch-House');
    }

    public function test_all_scope_sees_every_agency_listing(): void
    {
        [$agency, $branchA] = $this->agencyWithBranch();
        $branchB = $this->branch($agency, 'Branch B');

        $admin       = $this->agencyUser($agency, $branchA, 'admin');
        $agentA      = $this->agencyUser($agency, $branchA, 'agent');
        $agentB      = $this->agencyUser($agency, $branchB, 'agent');

        $this->property($agency, $branchA, $agentA, 'ZZZ-BranchA-House');
        $this->property($agency, $branchB, $agentB, 'ZZZ-BranchB-House');

        $this->actingAs($admin)
            ->get(route('tools.ad-manager'))
            ->assertOk()
            ->assertSee('ZZZ-BranchA-House')
            ->assertSee('ZZZ-BranchB-House');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} [agencyId, defaultBranchId] */
    private function agencyWithBranch(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = $this->branch($agencyId, 'Default');

        return [$agencyId, $branchId];
    }

    private function branch(int $agencyId, string $name): int
    {
        return (int) DB::table('branches')->insertGetId([
            'agency_id'  => $agencyId,
            'name'       => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function agencyUser(int $agencyId, int $branchId, string $role): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $branchId,
            'role'      => $role,
        ]);
    }

    private function property(int $agencyId, int $branchId, User $agent, string $title): Property
    {
        return Property::create([
            'agency_id'               => $agencyId,
            'branch_id'               => $branchId,
            'agent_id'                => $agent->id,
            'title'                   => $title,
            'status'                  => 'active',
            'listing_type'            => 'sale',
            'property_type'           => 'house',
            // "Live somewhere" so it appears in the Ad Manager listing.
            'p24_ref'                 => 'P24-' . Str::random(6),
            'p24_syndication_status'  => 'active',
        ]);
    }
}
