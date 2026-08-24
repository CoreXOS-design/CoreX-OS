<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\ProspectingListing;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-380 — Market Intelligence canvassing-pool visibility scope
 * (market_intelligence.view, own | branch | all), Role-Manager-configurable.
 *
 * Before this, MarketIntelligenceController::work() had NO agent/branch
 * restriction at all — every role saw the entire agency's canvassing pool.
 * role_permissions is unseeded in the test DB, so PermissionService falls
 * back to role-name defaults: agent -> own, branch_manager -> branch,
 * admin/super_admin -> all — exactly what exercises
 * ProspectingListing::scopeVisibleTo() here.
 */
final class ProspectingListingVisibilityScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 2026-08-20 — 'own' no longer means "captured_by_user_id === me". Live
     * data proved that field records who ran the bulk import (99.2% of
     * 39,556 rows on agency 1 are captured_by ONE account, not the working
     * agent), so filtering on it collapsed 'own' to zero results for every
     * agent/office_admin the instant import volume swamped the tiny slice
     * of real per-agent captures. 'own' now resolves through the same real,
     * populated branch_id signal 'branch' already uses — an agent sees
     * their branch's canvassing pool regardless of who/what captured each
     * row, and is still excluded from another branch's pool.
     */
    public function test_agent_sees_their_branchs_pool_regardless_of_who_captured_it(): void
    {
        [$agencyId, $b1, $b2] = $this->seedAgency(twoBranches: true);
        $agentA = $this->makeUser($agencyId, $b1, 'agent');
        $agentB = $this->makeUser($agencyId, $b1, 'agent');
        $importer = $this->makeUser($agencyId, $b1, 'admin');

        // Mirrors live: virtually every real row is captured_by the bulk
        // importer, not the agent it's actually relevant to.
        $this->makeListing($agencyId, $b1, $importer->id, 'P24-BulkImported');
        $this->makeListing($agencyId, $b1, $agentB->id, 'P24-B');
        $this->makeListing($agencyId, $b2, $importer->id, 'P24-OtherBranch');

        $this->assertSame('own', PermissionService::marketIntelligenceScope($agentA));

        $refs = $this->visibleRefs($agentA);
        // The regression this fixes: a listing captured by someone else
        // entirely, but in the agent's own branch, must be visible.
        $this->assertContains('P24-BulkImported', $refs, 'own scope must not collapse to empty just because a different account captured the row');
        $this->assertContains('P24-B', $refs);
        // Still correctly scoped — another branch stays invisible.
        $this->assertNotContains('P24-OtherBranch', $refs);
    }

    /**
     * The exact live incident, reproduced: an agent with ZERO
     * personally-captured listings (the real, common case — only 2 of ~14
     * live agents ever personally captured anything) must still see their
     * branch's pool, not an empty list.
     */
    public function test_agent_with_zero_personal_captures_still_sees_their_branch_pool(): void
    {
        [$agencyId, $b1] = $this->seedAgency();
        $agent = $this->makeUser($agencyId, $b1, 'agent');
        $someoneElse = $this->makeUser($agencyId, $b1, 'admin');

        // $agent has captured NOTHING — every listing belongs to someone else.
        $this->makeListing($agencyId, $b1, $someoneElse->id, 'P24-Never-Captured-By-Agent');

        $refs = $this->visibleRefs($agent);
        $this->assertNotEmpty($refs, 'an agent who never personally captured anything must not see an empty canvass pool');
        $this->assertContains('P24-Never-Captured-By-Agent', $refs);
    }

    public function test_admin_scope_sees_whole_agency(): void
    {
        [$agencyId, $b1] = $this->seedAgency();
        $admin  = $this->makeUser($agencyId, $b1, 'super_admin');
        $agentB = $this->makeUser($agencyId, $b1, 'agent');

        $this->makeListing($agencyId, $b1, $admin->id, 'P24-Admin');
        $this->makeListing($agencyId, $b1, $agentB->id, 'P24-Other');

        $this->assertSame('all', PermissionService::marketIntelligenceScope($admin));

        $refs = $this->visibleRefs($admin);
        $this->assertContains('P24-Admin', $refs);
        $this->assertContains('P24-Other', $refs);
    }

    public function test_branch_manager_sees_branch_not_other_branch(): void
    {
        [$agencyId, $b1, $b2] = $this->seedAgency(twoBranches: true);
        $bm     = $this->makeUser($agencyId, $b1, 'branch_manager');
        $agentB = $this->makeUser($agencyId, $b2, 'agent');

        $this->makeListing($agencyId, $b1, $bm->id, 'P24-Branch1');
        $this->makeListing($agencyId, $b2, $agentB->id, 'P24-Branch2');

        $this->assertSame('branch', PermissionService::marketIntelligenceScope($bm));

        $refs = $this->visibleRefs($bm);
        $this->assertContains('P24-Branch1', $refs);
        $this->assertNotContains('P24-Branch2', $refs);
    }

    /**
     * A branchless all-branches admin (branches.view_all, no single branch_id)
     * selecting "branch" scope must see everything, not collapse to "own" —
     * same carve-out as CalendarEvent::scopeVisibleTo().
     */
    public function test_branchless_view_all_user_on_branch_scope_sees_everything(): void
    {
        [$agencyId, $b1, $b2] = $this->seedAgency(twoBranches: true);

        DB::table('role_permissions')->insert([
            ['role' => 'admin', 'permission_key' => 'market_intelligence.view', 'scope' => 'branch', 'agency_id' => $agencyId, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'admin', 'permission_key' => 'branches.view_all', 'scope' => null, 'agency_id' => $agencyId, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $admin   = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => null, 'role' => 'admin']);
        $agentB1 = $this->makeUser($agencyId, $b1, 'agent');
        $agentB2 = $this->makeUser($agencyId, $b2, 'agent');

        $this->makeListing($agencyId, $b1, $agentB1->id, 'P24-B1');
        $this->makeListing($agencyId, $b2, $agentB2->id, 'P24-B2');

        $this->assertSame('branch', PermissionService::marketIntelligenceScope($admin));

        $refs = $this->visibleRefs($admin);
        $this->assertContains('P24-B1', $refs);
        $this->assertContains('P24-B2', $refs);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int,2:int} [agencyId, branch1Id, branch2Id] */
    private function seedAgency(bool $twoBranches = false): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $b1 = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Branch 1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $b2 = $twoBranches ? (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Branch 2',
            'created_at' => now(), 'updated_at' => now(),
        ]) : $b1;

        return [$agencyId, $b1, $b2];
    }

    private function makeUser(int $agencyId, int $branchId, string $role): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => $role,
        ]);
    }

    private function makeListing(int $agencyId, int $branchId, int $capturedByUserId, string $portalRef): void
    {
        ProspectingListing::withoutGlobalScopes()->create([
            'agency_id' => $agencyId,
            'branch_id' => $branchId,
            'captured_by_user_id' => $capturedByUserId,
            'portal_source' => 'p24',
            'portal_ref' => $portalRef,
            'suburb' => 'Uvongo',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /** @return string[] */
    private function visibleRefs(User $user): array
    {
        $scope = PermissionService::marketIntelligenceScope($user);

        return ProspectingListing::withoutGlobalScopes()
            ->where('agency_id', $user->agency_id)
            ->visibleTo($user, $scope)
            ->pluck('portal_ref')->all();
    }
}
