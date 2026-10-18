<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * QA1 scope-fix (2026-10-07, audit defect 1) — the Performance & ROI report used to
 * take branch_id / user_id from the query string with only an agency check, so any
 * role that could open it (agents included) saw company, branch and any other
 * agent's figures by editing the URL. These tests drive the REAL HTTP routes as
 * each role and prove, for the screen, the comparison period, every drill-down
 * page, the drill-down JSON and the print pages:
 *
 *   agent          -> own figures only
 *   branch manager -> own branch only
 *   admin          -> the whole agency, never another agency
 *
 * and that a URL asking for something outside that ceiling returns only what the
 * viewer is entitled to (screens) or 403 (a specific other agent / branch page).
 *
 * Roles here resolve through PermissionService's test posture (no grants table
 * seeded => role-shaped defaults: admin=all, branch_manager=branch, agent=own),
 * the same defaults scope_defaults ships for performance_report.view.
 */
final class PerformanceReportScopeTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $otherAgency;
    private Branch $b1;
    private Branch $b2;
    private User $admin;
    private User $bm;        // branch manager of B1
    private User $a1;        // agent, B1
    private User $a2;        // agent, B1 (colleague)
    private User $b2agent;   // agent, B2 (other branch, same agency)
    private User $foreign;   // agent, other agency

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency      = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $this->otherAgency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->b1 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->b2 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $foreignBranch = Branch::create(['agency_id' => $this->otherAgency->id, 'name' => 'Sea Point']);

        $mk = fn (string $name, string $role, Agency $a, Branch $b) => User::factory()->create([
            'name' => $name, 'role' => $role, 'agency_id' => $a->id, 'branch_id' => $b->id,
        ]);
        $this->admin   = $mk('Pam Principal', 'admin', $this->agency, $this->b1);
        $this->bm      = $mk('Bea Manager', 'branch_manager', $this->agency, $this->b1);
        $this->a1      = $mk('Alice Ramsgate', 'agent', $this->agency, $this->b1);
        $this->a2      = $mk('Colin Ramsgate', 'agent', $this->agency, $this->b1);
        $this->b2agent = $mk('Maggie Margate', 'agent', $this->agency, $this->b2);
        $this->foreign = $mk('Frank Foreign', 'agent', $this->otherAgency, $foreignBranch);

        // One contact each, created this month, so every drill-down has a recognisable row.
        $this->contactFor($this->a1, 'ZZalice');
        $this->contactFor($this->a2, 'ZZcolin');
        $this->contactFor($this->b2agent, 'ZZmaggie');
        $this->contactFor($this->foreign, 'ZZfrank');
    }

    protected function tearDown(): void
    {
        \App\Services\PermissionService::clearCache(); // never leak the 'grants exist' posture into another test
        parent::tearDown();
    }

    private function contactFor(User $agent, string $firstName): void
    {
        (new Contact())->forceFill([
            'agency_id' => $agent->agency_id, 'branch_id' => $agent->branch_id,
            'first_name' => $firstName, 'last_name' => 'Lead',
            'created_by_user_id' => $agent->id, 'agent_id' => $agent->id,
        ])->save();
    }

    private function names(): array
    {
        return ['Alice Ramsgate', 'Colin Ramsgate', 'Maggie Margate', 'Frank Foreign'];
    }

    /**
     * The app layout carries a user-switcher list unrelated to this report, so a page-wide
     * assertDontSee would trip on it. The index embeds its agent rows inside the
     * agencyReport({...}) component config; every other report page renders them in its own
     * body. Compare names inside that region only.
     */
    private function reportRegion(string $html): string
    {
        $start = strpos($html, 'agencyReport({');
        if ($start !== false) {
            $end = strpos($html, 'drilldownBase:', $start);

            return substr($html, $start, ($end !== false ? $end : strlen($html)) - $start);
        }
        $main = strpos($html, '<main');

        return $main !== false ? substr($html, $main) : $html;
    }

    private function assertSeesOnly(\Illuminate\Testing\TestResponse $res, array $visible): void
    {
        $res->assertOk();
        $region = $this->reportRegion($res->getContent());
        foreach ($this->names() as $name) {
            $this->assertSame(
                in_array($name, $visible, true),
                str_contains($region, $name),
                $name . (in_array($name, $visible, true) ? ' should be' : ' must NOT be') . ' in the report',
            );
        }
    }

    // ─────────────────────────── agent: own only ───────────────────────────

    public function test_agent_sees_only_their_own_figures_even_when_the_url_asks_for_more(): void
    {
        $this->actingAs($this->a1);

        $this->assertSeesOnly($this->get(route('performance.agency-report')), ['Alice Ramsgate']);

        // Company, branch and other-agent / other-agency requests by URL: still only Alice.
        $this->assertSeesOnly($this->get(route('performance.agency-report', ['user_id' => $this->a2->id])), ['Alice Ramsgate']);
        $this->assertSeesOnly($this->get(route('performance.agency-report', ['user_id' => $this->b2agent->id])), ['Alice Ramsgate']);
        $this->assertSeesOnly($this->get(route('performance.agency-report', ['user_id' => $this->foreign->id])), ['Alice Ramsgate']);
        $this->assertSeesOnly($this->get(route('performance.agency-report', ['branch_id' => $this->b2->id])), ['Alice Ramsgate']);
        // ...and through the comparison period too.
        $this->assertSeesOnly($this->get(route('performance.agency-report', ['compare' => 'previous', 'user_id' => $this->a2->id])), ['Alice Ramsgate']);
    }

    public function test_agent_cannot_open_another_agents_page_or_any_branch_page_but_can_open_their_own(): void
    {
        $this->actingAs($this->a1);

        $this->get(route('performance.agency-report.agent', ['user' => $this->a1->id]))->assertOk();
        $this->get(route('performance.agency-report.agent', ['user' => $this->a2->id]))->assertForbidden();      // colleague, same branch
        $this->get(route('performance.agency-report.agent', ['user' => $this->b2agent->id]))->assertForbidden(); // other branch
        $this->get(route('performance.agency-report.agent', ['user' => $this->foreign->id]))->assertNotFound();  // other agency

        $this->get(route('performance.agency-report.agent.print', ['user' => $this->a1->id]))->assertOk();
        $this->get(route('performance.agency-report.agent.print', ['user' => $this->a2->id]))->assertForbidden();

        $this->get(route('performance.agency-report.branch', ['branch' => $this->b1->id]))->assertForbidden();   // even their own branch
        $this->get(route('performance.agency-report.branch', ['branch' => $this->b2->id]))->assertForbidden();
        $this->get(route('performance.agency-report.branch', ['branch' => 'unassigned']))->assertForbidden();
    }

    public function test_agent_print_contains_only_their_own_figures(): void
    {
        $this->actingAs($this->a1);

        $this->assertSeesOnly($this->get(route('performance.agency-report.print')), ['Alice Ramsgate']);
        $this->assertSeesOnly($this->get(route('performance.agency-report.print', ['user_id' => $this->a2->id])), ['Alice Ramsgate']);
    }

    public function test_agent_drilldown_json_is_clamped_to_their_own_rows_and_refuses_other_agents_and_branches(): void
    {
        $this->actingAs($this->a1);
        $url = fn (array $q) => route('performance.agency-report.drilldown', $q + ['metric' => 'contacts', 'period' => 'this_month']);

        // Company-level drill-down by an agent: only their own contact.
        $company = $this->getJson($url(['level' => 'company']))->assertOk();
        $this->assertSame(1, $company->json('total'));
        $this->assertSame('ZZalice Lead', $company->json('rows.0.name'));

        // Their own agent-level drill-down works.
        $this->assertSame(1, $this->getJson($url(['level' => 'agent', 'id' => $this->a1->id]))->assertOk()->json('total'));

        // A specific other agent / any branch: 403, never the rows.
        $this->getJson($url(['level' => 'agent', 'id' => $this->a2->id]))->assertForbidden();
        $this->getJson($url(['level' => 'agent', 'id' => $this->b2agent->id]))->assertForbidden();
        $this->getJson($url(['level' => 'branch', 'id' => $this->b1->id]))->assertForbidden();
        $this->getJson($url(['level' => 'branch', 'id' => $this->b2->id]))->assertForbidden();
        $this->getJson($url(['level' => 'agent', 'id' => $this->foreign->id]))->assertNotFound();
    }

    // ───────────────────────── branch manager: own branch ─────────────────────────

    public function test_branch_manager_sees_only_their_branch_even_when_the_url_asks_for_more(): void
    {
        $this->actingAs($this->bm);

        $own = ['Alice Ramsgate', 'Colin Ramsgate'];
        $this->assertSeesOnly($this->get(route('performance.agency-report')), $own);
        $this->assertSeesOnly($this->get(route('performance.agency-report', ['branch_id' => $this->b2->id])), $own);
        $this->assertSeesOnly($this->get(route('performance.agency-report', ['user_id' => $this->b2agent->id])), $own);
        $this->assertSeesOnly($this->get(route('performance.agency-report', ['user_id' => $this->foreign->id])), $own);
        $this->assertSeesOnly($this->get(route('performance.agency-report', ['compare' => 'previous', 'branch_id' => $this->b2->id])), $own);

        // Narrowing to ONE agent inside their own branch is allowed.
        $this->assertSeesOnly($this->get(route('performance.agency-report', ['user_id' => $this->a2->id])), ['Colin Ramsgate']);

        $this->assertSeesOnly($this->get(route('performance.agency-report.print')), $own);
    }

    public function test_branch_manager_pages_and_drilldown_stop_at_their_branch(): void
    {
        $this->actingAs($this->bm);

        $this->get(route('performance.agency-report.branch', ['branch' => $this->b1->id]))->assertOk();
        $branchPage = $this->get(route('performance.agency-report.branch', ['branch' => $this->b1->id]));
        $this->assertStringNotContainsString('Maggie Margate', $this->reportRegion($branchPage->getContent()));
        $this->get(route('performance.agency-report.branch', ['branch' => $this->b2->id]))->assertForbidden();
        $this->get(route('performance.agency-report.branch', ['branch' => 'unassigned']))->assertForbidden();

        $this->get(route('performance.agency-report.agent', ['user' => $this->a2->id]))->assertOk();
        $this->get(route('performance.agency-report.agent', ['user' => $this->b2agent->id]))->assertForbidden();
        $this->get(route('performance.agency-report.agent.print', ['user' => $this->b2agent->id]))->assertForbidden();

        $url = fn (array $q) => route('performance.agency-report.drilldown', $q + ['metric' => 'contacts', 'period' => 'this_month']);
        $this->assertSame(2, $this->getJson($url(['level' => 'company']))->assertOk()->json('total')); // Alice + Colin, not Maggie
        $this->getJson($url(['level' => 'branch', 'id' => $this->b2->id]))->assertForbidden();
        $this->getJson($url(['level' => 'agent', 'id' => $this->b2agent->id]))->assertForbidden();
        $this->assertSame(1, $this->getJson($url(['level' => 'agent', 'id' => $this->a2->id]))->assertOk()->json('total'));
    }

    // ───────────────────────────── admin: the agency ─────────────────────────────

    public function test_admin_sees_the_whole_agency_never_another_agency_and_can_narrow(): void
    {
        $this->actingAs($this->admin);

        $all = ['Alice Ramsgate', 'Colin Ramsgate', 'Maggie Margate'];
        $this->assertSeesOnly($this->get(route('performance.agency-report')), $all);
        $this->assertSeesOnly($this->get(route('performance.agency-report', ['branch_id' => $this->b2->id])), ['Maggie Margate']);
        $this->assertSeesOnly($this->get(route('performance.agency-report', ['user_id' => $this->a2->id])), ['Colin Ramsgate']);
        // A foreign agency's ids are dropped, not obeyed.
        $this->assertSeesOnly($this->get(route('performance.agency-report', ['user_id' => $this->foreign->id])), $all);
        $this->assertSeesOnly($this->get(route('performance.agency-report.print')), $all);

        $this->get(route('performance.agency-report.branch', ['branch' => $this->b2->id]))->assertOk();
        $this->get(route('performance.agency-report.agent', ['user' => $this->b2agent->id]))->assertOk();
        $this->assertSame(3, $this->getJson(route('performance.agency-report.drilldown', ['metric' => 'contacts', 'level' => 'company', 'period' => 'this_month']))->assertOk()->json('total'));
    }

    // ─────────── the legacy Admin / BM performance pages (same gap, by id) ───────────

    public function test_agent_cannot_open_the_company_branch_or_other_agent_performance_pages_by_url(): void
    {
        $this->actingAs($this->a1);

        $this->get(route('admin.performance'))->assertForbidden();
        $this->get(route('admin.branch.performance', ['branchId' => $this->b1->id]))->assertForbidden();
        $this->get(route('admin.branch.performance', ['branchId' => $this->b2->id]))->assertForbidden();
        $this->get(route('admin.agent.performance', ['userId' => $this->a2->id]))->assertForbidden();
        $this->get(route('bm.agent.performance', ['userId' => $this->a2->id]))->assertForbidden();
        $this->get(route('bm.performance'))->assertForbidden();
    }

    public function test_branch_manager_cannot_open_the_company_page_or_another_branch_by_url(): void
    {
        $this->actingAs($this->bm);

        $this->get(route('admin.performance'))->assertForbidden();
        $this->get(route('admin.branch.performance', ['branchId' => $this->b2->id]))->assertForbidden();
        $this->get(route('admin.agent.performance', ['userId' => $this->b2agent->id]))->assertForbidden();
        $this->assertNotSame(403, $this->get(route('bm.performance'))->status());
        $this->assertNotSame(403, $this->get(route('bm.agent.performance', ['userId' => $this->a2->id]))->status());
        $this->assertNotSame(403, $this->get(route('admin.branch.performance', ['branchId' => $this->b1->id]))->status());
    }

    public function test_admin_is_not_blocked_from_the_company_branch_and_agent_performance_pages(): void
    {
        $this->actingAs($this->admin);

        $this->assertNotSame(403, $this->get(route('admin.performance'))->status());
        $this->assertNotSame(403, $this->get(route('admin.branch.performance', ['branchId' => $this->b2->id]))->status());
        $this->assertNotSame(403, $this->get(route('admin.agent.performance', ['userId' => $this->b2agent->id]))->status());
    }

    public function test_a_role_with_no_performance_report_scope_row_fails_closed_to_own(): void
    {
        // A grants table that exists but holds NO performance_report.view row for the role
        // (the live position of a role that predates this key) must read as 'own', not 'all'.
        DB::table('role_permissions')->insert([
            'role' => 'agent', 'permission_key' => 'view_performance', 'agency_id' => $this->agency->id, 'scope' => null,
        ]);
        \App\Services\PermissionService::clearCache();

        $this->actingAs($this->a1);
        $this->assertSeesOnly($this->get(route('performance.agency-report', ['user_id' => $this->a2->id])), ['Alice Ramsgate']);
    }

    private function grantAgent(string $performanceScope, string $buyersScope): void
    {
        foreach ([
            ['view_performance', null],
            ['performance_report.view', $performanceScope],
            ['buyers_report.view', $buyersScope],
        ] as [$key, $scope]) {
            DB::table('role_permissions')->insert([
                'role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id, 'scope' => $scope,
            ]);
        }
        \App\Services\PermissionService::clearCache();
    }

    public function test_the_ceiling_comes_from_the_performance_report_key_not_the_buyers_report_key(): void
    {
        // Role Manager says: Buyers Report = all, Performance report = branch.  ROI follows its OWN key.
        $this->grantAgent('branch', 'all');
        $this->actingAs($this->a1);
        $this->assertSeesOnly($this->get(route('performance.agency-report')), ['Alice Ramsgate', 'Colin Ramsgate']);
        $this->get(route('performance.agency-report.agent', ['user' => $this->b2agent->id]))->assertForbidden();
    }

    public function test_a_role_manager_grant_of_all_widens_the_report_for_that_role(): void
    {
        // Role Manager says: Buyers Report = own, Performance report = all.
        $this->grantAgent('all', 'own');
        $this->actingAs($this->a1);
        $this->assertSeesOnly($this->get(route('performance.agency-report')), ['Alice Ramsgate', 'Colin Ramsgate', 'Maggie Margate']);
        $this->get(route('performance.agency-report.agent', ['user' => $this->b2agent->id]))->assertOk();
    }
}
