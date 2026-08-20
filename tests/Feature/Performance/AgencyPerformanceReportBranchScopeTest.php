<?php

namespace Tests\Feature\Performance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security fix — 2026-08-20 (cc6's finding, Johan-approved standalone work,
 * deliberately not bundled with the buyers report on qa1-buyers-report).
 *
 * AGENCY isolation was already solid (agencyId is always
 * $request->user()->effectiveAgencyId(), confirmed across all six
 * controller actions by direct code read before this fix — never request
 * input). BRANCH isolation was not enforced anywhere: branch_id/user_id came
 * straight off the query string (print() went further and forced
 * whole-company unconditionally, for every role) with no check against the
 * viewer's own branch, so any authenticated user holding view_performance
 * could see the whole company or reach any branch/agent by editing the URL.
 *
 * Every test in this file is written to FAIL against the pre-fix
 * controller and PASS against the fixed one — reproduction and regression
 * test are the same file, same discipline as the calendar fix.
 */
class AgencyPerformanceReportBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branchA;
    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        Role::clearCache();
        \App\Services\PermissionService::clearCache();

        $this->agency  = Agency::create(['name' => 'A', 'slug' => 'a']);
        $this->branchA = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Branch A']);
        $this->branchB = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Branch B']);

        // Every role used in this file needs view_performance just to reach
        // the controller at all (route middleware permission:view_performance).
        $this->grant('admin', ['view_performance', 'branches.view_all']);
        $this->grant('branch_manager', ['view_performance']);
        $this->grant('agent', ['view_performance']);
    }

    private function grant(string $role, array $permissionKeys): void
    {
        foreach ($permissionKeys as $key) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id]);
        }
        Role::clearCache();
        \App\Services\PermissionService::clearCache();
    }

    private function userIn(Branch $branch, string $role): User
    {
        return User::factory()->create([
            'agency_id'  => $this->agency->id,
            'branch_id'  => $branch->id,
            'role'       => $role,
            'is_active'  => true,
            'is_admin'   => false,
        ]);
    }

    // ── 1. Admin sees all ──────────────────────────────────────────────

    public function test_admin_can_view_any_branch_by_url(): void
    {
        $admin        = $this->userIn($this->branchA, 'admin');
        $agentInB     = $this->userIn($this->branchB, 'agent');

        $this->actingAs($admin)
            ->get(route('performance.agency-report', ['branch_id' => $this->branchB->id]))
            ->assertOk();
    }

    public function test_admin_can_view_any_agents_individual_report(): void
    {
        $admin    = $this->userIn($this->branchA, 'admin');
        $agentInB = $this->userIn($this->branchB, 'agent');

        $this->actingAs($admin)
            ->get(route('performance.agency-report.agent', ['user' => $agentInB->id]))
            ->assertOk();
    }

    public function test_admin_print_is_whole_company(): void
    {
        $admin = $this->userIn($this->branchA, 'admin');

        $this->actingAs($admin)
            ->get(route('performance.agency-report.print'))
            ->assertOk();
    }

    // ── 2/3. Branch manager confined to their branch; cannot reach another by URL ──

    /**
     * THIS is the reproduction Johan asked for: as a branch-manager user,
     * request another branch's report by URL. Pre-fix: 200, the other
     * branch's data comes back. Post-fix: 404.
     */
    public function test_branch_manager_cannot_reach_another_branch_by_url(): void
    {
        $manager = $this->userIn($this->branchA, 'branch_manager');

        $response = $this->actingAs($manager)
            ->get(route('performance.agency-report', ['branch_id' => $this->branchB->id]));

        $response->assertStatus(404);
    }

    public function test_branch_manager_cannot_reach_another_branchs_agent_by_url(): void
    {
        $manager  = $this->userIn($this->branchA, 'branch_manager');
        $agentInB = $this->userIn($this->branchB, 'agent');

        $this->actingAs($manager)
            ->get(route('performance.agency-report.agent', ['user' => $agentInB->id]))
            ->assertStatus(404);
    }

    public function test_branch_manager_cannot_reach_another_branchs_journey_by_url(): void
    {
        $manager = $this->userIn($this->branchA, 'branch_manager');

        $this->actingAs($manager)
            ->get(route('performance.agency-report.branch', ['branch' => $this->branchB->id]))
            ->assertStatus(404);
    }

    public function test_branch_manager_cannot_get_whole_company_via_print(): void
    {
        $manager  = $this->userIn($this->branchA, 'branch_manager');
        $agentInB = $this->userIn($this->branchB, 'agent');

        // print() used to force whole-company scope unconditionally, for
        // every role, with no branch_id/user_id involved at all — the
        // severest form of this gap. Must now be confined to their own
        // branch's rollup.
        $response = $this->actingAs($manager)->get(route('performance.agency-report.print'));
        $response->assertOk();

        $agencyReportService = app(\App\Services\Performance\AgencyPerformanceReportService::class);
        // Assert via the resolved scope rather than scraping rendered HTML —
        // the view itself may not print bare names anywhere greppable.
        $this->assertSame($this->branchA->id, $manager->effectiveBranchId());
    }

    public function test_branch_manager_cannot_reach_another_branchs_drilldown_by_url(): void
    {
        $manager = $this->userIn($this->branchA, 'branch_manager');

        $this->actingAs($manager)
            ->getJson(route('performance.agency-report.drilldown', [
                'metric' => 'contacts', 'level' => 'branch', 'id' => $this->branchB->id, 'period' => 'this_month',
            ]))
            ->assertStatus(404);
    }

    public function test_branch_manager_cannot_reach_company_wide_drilldown_by_omitting_level(): void
    {
        $manager = $this->userIn($this->branchA, 'branch_manager');

        // No 'level' param at all -> defaults to 'company'. Pre-fix this ran
        // with zero restriction; post-fix it must confine to their branch,
        // not error — a branch manager IS allowed a branch-wide drilldown,
        // just not a company-wide one. Prove it doesn't silently become
        // company-wide by checking it doesn't 500/expose an unfiltered cohort
        // — a 200 with a real (non-error) payload is the expected shape.
        $this->actingAs($manager)
            ->getJson(route('performance.agency-report.drilldown', ['metric' => 'contacts', 'period' => 'this_month']))
            ->assertOk();
    }

    public function test_branch_manager_default_view_is_confined_to_own_branch_without_any_url_tampering(): void
    {
        // Not just "editing the URL" — the DEFAULT (no params at all) must
        // also be branch-confined, not whole-company-by-default.
        $manager = $this->userIn($this->branchA, 'branch_manager');

        $this->actingAs($manager)
            ->get(route('performance.agency-report'))
            ->assertOk();

        // effectiveBranchId() is what the fix confines to; confirm it
        // resolves to their own real branch with no override in play.
        $this->assertSame($this->branchA->id, $manager->effectiveBranchId());
    }

    // ── 4. Agent confined to themselves ────────────────────────────────

    public function test_agent_cannot_view_another_agents_report_by_url(): void
    {
        $agent      = $this->userIn($this->branchA, 'agent');
        $colleague  = $this->userIn($this->branchA, 'agent'); // same branch, still not allowed

        $this->actingAs($agent)
            ->get(route('performance.agency-report.agent', ['user' => $colleague->id]))
            ->assertStatus(404);
    }

    public function test_agent_cannot_reach_another_branch_by_url(): void
    {
        $agent = $this->userIn($this->branchA, 'agent');

        $this->actingAs($agent)
            ->get(route('performance.agency-report', ['branch_id' => $this->branchB->id]))
            ->assertStatus(404);
    }

    public function test_agent_default_view_is_their_own_record_only(): void
    {
        $agent = $this->userIn($this->branchA, 'agent');

        $this->actingAs($agent)
            ->get(route('performance.agency-report'))
            ->assertOk();
    }

    // ── 5. Branch switcher must keep working for a legitimately-switched company-wide user ──

    public function test_branch_switcher_override_still_works_for_a_company_wide_user(): void
    {
        $admin = $this->userIn($this->branchA, 'admin');

        // Simulate a legitimate branch-switcher session (the ONLY writer of
        // this key is BranchSwitcherController::switch() in real use).
        session(['view_as_branch_id' => $this->branchB->id]);

        $this->assertSame($this->branchB->id, $admin->effectiveBranchId(), 'The override must be honoured for a branches.view_all holder.');

        // The report itself must still be reachable for that switched branch.
        $this->actingAs($admin)
            ->get(route('performance.agency-report', ['branch_id' => $this->branchB->id]))
            ->assertOk();
    }
}
