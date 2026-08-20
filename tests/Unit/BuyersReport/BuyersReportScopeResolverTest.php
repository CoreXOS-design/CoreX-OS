<?php

declare(strict_types=1);

namespace Tests\Unit\BuyersReport;

use App\Models\User;
use App\Services\BuyersReport\BuyersReportScope;
use App\Services\BuyersReport\BuyersReportScopeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * THE non-negotiable test for the buyers report (Johan, 2026-08-20): prove a
 * branch manager cannot see another branch by URL — the exact gap found in
 * AgencyPerformanceReportController (branch_id/user_id taken from the request
 * query string with no check against the viewer's own scope). This report is
 * a product feature for every CoreX tenant, so this isn't optional hardening,
 * it's the one thing that must never regress.
 *
 * Also proves the resolver's ceiling comes from the REAL enforcement
 * mechanism (PermissionService::getDataScope('contacts'), the same one
 * ContactScope reads) rather than a bespoke rule invented for this report —
 * and proves cross-AGENCY isolation independently of the branch check, since
 * that's the more serious failure mode (AT-381).
 *
 * DB approach: hand-built minimal schema (agencies, branches, users, roles,
 * role_permissions), same technique used all night for the RefreshDatabase /
 * ERROR-1419 trigger-privilege gotcha on this box — bypasses artisan migrate
 * entirely, exercises the REAL PermissionService::getDataScope() and the
 * REAL resolver, not a stand-in.
 */
final class BuyersReportScopeResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
        parent::tearDown();
    }

    public function test_branch_manager_requesting_another_branch_by_url_is_ignored_and_own_branch_is_used(): void
    {
        $agencyId  = 9001;
        $ownBranch = 501;
        $otherBranch = 502;
        $this->seedAgency($agencyId, splitBranches: true);
        $this->seedBranch($ownBranch, $agencyId);
        $this->seedBranch($otherBranch, $agencyId);
        $this->grantContactsScope('branch_manager', $agencyId, 'all'); // 'all' + split_branches=1 -> effective 'branch'

        $bm = $this->makeUser(701, $agencyId, $ownBranch, 'branch_manager');

        $resolver = new BuyersReportScopeResolver();
        // Attacker-shaped request: level=agency (wider than allowed) AND an
        // explicit branch_id belonging to a DIFFERENT branch in the SAME agency.
        $scope = $resolver->resolve($bm, requestedLevel: 'agency', requestedBranchId: $otherBranch, requestedUserId: null);

        $this->assertSame(BuyersReportScope::LEVEL_BRANCH, $scope->level, 'Requested level must be clamped down to the real ceiling.');
        $this->assertSame($ownBranch, $scope->branchId, 'The branch used must be the VIEWER\'S OWN branch...');
        $this->assertNotSame($otherBranch, $scope->branchId, '...and NEVER the one supplied in the request.');
    }

    /**
     * THE non-negotiable test for the second pass (Johan, 2026-08-20): a
     * branch manager cannot reach another branch's drill-down or agent page
     * by URL. cc4 found precisely this gap in AgencyPerformanceReportController's
     * agent()/drilldown()/print routes -- they check agency membership only,
     * never branch membership. canViewAgent()/canViewBranch() exist so the
     * buyers report's agent/branch pages and drill-down never do the same.
     */
    public function test_branch_manager_cannot_view_an_agent_page_outside_their_own_branch(): void
    {
        $agencyId  = 9006;
        $ownBranch = 801;
        $otherBranch = 802;
        $this->seedAgency($agencyId, splitBranches: true);
        $this->seedBranch($ownBranch, $agencyId);
        $this->seedBranch($otherBranch, $agencyId);
        $this->grantContactsScope('branch_manager', $agencyId, 'all');

        DB::table('users')->insert(['id' => 501, 'agency_id' => $agencyId, 'branch_id' => $ownBranch]);
        DB::table('users')->insert(['id' => 502, 'agency_id' => $agencyId, 'branch_id' => $otherBranch]);

        $bm = $this->makeUser(701, $agencyId, $ownBranch, 'branch_manager');
        $resolver = new BuyersReportScopeResolver();

        $this->assertTrue($resolver->canViewAgent($bm, 501), 'Own-branch agent must be viewable.');
        $this->assertFalse($resolver->canViewAgent($bm, 502), 'Different-branch agent, same agency, must NOT be viewable.');
    }

    public function test_branch_manager_cannot_view_another_branchs_page(): void
    {
        $agencyId  = 9007;
        $ownBranch = 901;
        $otherBranch = 902;
        $this->seedAgency($agencyId, splitBranches: true);
        $this->seedBranch($ownBranch, $agencyId);
        $this->seedBranch($otherBranch, $agencyId);
        $this->grantContactsScope('branch_manager', $agencyId, 'all');

        $bm = $this->makeUser(702, $agencyId, $ownBranch, 'branch_manager');
        $resolver = new BuyersReportScopeResolver();

        $this->assertTrue($resolver->canViewBranch($bm, $ownBranch));
        $this->assertFalse($resolver->canViewBranch($bm, $otherBranch));
    }

    public function test_own_ceiling_agent_cannot_view_any_agent_page_but_their_own_or_any_branch_page(): void
    {
        $agencyId = 9008;
        $branch   = 1001;
        $this->seedAgency($agencyId, splitBranches: false);
        $this->seedBranch($branch, $agencyId);
        $this->grantContactsScope('agent', $agencyId, 'own');

        DB::table('users')->insert(['id' => 601, 'agency_id' => $agencyId, 'branch_id' => $branch]);
        DB::table('users')->insert(['id' => 602, 'agency_id' => $agencyId, 'branch_id' => $branch]);

        $agent = $this->makeUser(601, $agencyId, $branch, 'agent');
        $resolver = new BuyersReportScopeResolver();

        $this->assertTrue($resolver->canViewAgent($agent, 601), 'An agent can view their own page.');
        $this->assertFalse($resolver->canViewAgent($agent, 602), 'An agent cannot view a colleague\'s page, even same branch.');
        $this->assertFalse($resolver->canViewBranch($agent, $branch), 'An own-ceiling agent gets no branch-wide page at all.');
    }

    public function test_agency_ceiling_admin_can_view_any_agent_or_branch_in_their_own_agency_but_not_another(): void
    {
        $myAgency = 9009;
        $otherAgency = 9010;
        $this->seedAgency($myAgency, splitBranches: false);
        $this->seedAgency($otherAgency, splitBranches: false);
        $this->seedBranch(1101, $myAgency);
        $this->seedBranch(1102, $otherAgency);
        $this->grantContactsScope('admin', $myAgency, 'all');

        DB::table('users')->insert(['id' => 1201, 'agency_id' => $myAgency, 'branch_id' => 1101]);
        DB::table('users')->insert(['id' => 1202, 'agency_id' => $otherAgency, 'branch_id' => 1102]);

        $admin = $this->makeUser(1301, $myAgency, null, 'admin');
        $resolver = new BuyersReportScopeResolver();

        $this->assertTrue($resolver->canViewAgent($admin, 1201), 'Any agent in the admin\'s own agency.');
        $this->assertFalse($resolver->canViewAgent($admin, 1202), 'Never an agent in a DIFFERENT agency.');
        $this->assertTrue($resolver->canViewBranch($admin, 1101));
        $this->assertFalse($resolver->canViewBranch($admin, 1102), 'Never a branch in a DIFFERENT agency.');
    }

    public function test_agent_requesting_agency_level_is_clamped_to_own(): void
    {
        $agencyId = 9002;
        $this->seedAgency($agencyId, splitBranches: false);
        $this->grantContactsScope('agent', $agencyId, 'own');

        $agent = $this->makeUser(801, $agencyId, 601, 'agent');

        $resolver = new BuyersReportScopeResolver();
        $scope = $resolver->resolve($agent, requestedLevel: 'agency', requestedBranchId: null, requestedUserId: 999);

        $this->assertSame(BuyersReportScope::LEVEL_OWN, $scope->level);
        $this->assertSame(801, $scope->userId);
        $this->assertNull($scope->branchId);
    }

    public function test_agency_level_viewer_cannot_reach_a_branch_or_user_in_a_different_agency(): void
    {
        $myAgency = 9003;
        $otherAgency = 9004;
        $this->seedAgency($myAgency, splitBranches: false);
        $this->seedAgency($otherAgency, splitBranches: false);
        $this->seedBranch(701, $otherAgency); // belongs to the OTHER agency
        $this->grantContactsScope('admin', $myAgency, 'all');

        $admin = $this->makeUser(901, $myAgency, null, 'admin');

        $resolver = new BuyersReportScopeResolver();
        $scope = $resolver->resolve($admin, requestedLevel: 'agency', requestedBranchId: 701, requestedUserId: null);

        $this->assertSame($myAgency, $scope->agencyId, 'Cross-AGENCY isolation: agencyId is always the VIEWER\'S own, never request-derived at all.');
        $this->assertNull($scope->branchId, 'A branch belonging to a DIFFERENT agency must never resolve, even for an agency-ceiling viewer.');
    }

    /**
     * Johan (2026-08-20, post-verification) — proves the resolver clamps
     * under a RESTRICTIVE config, not just that it faithfully reads agency
     * 1's currently-broad one. Ties the clamped scope through to the actual
     * controller title text (not just the raw scope object) so a report
     * this permissive by DEFAULT still tightens correctly the moment any
     * agency's Role Manager is set narrower — this ships to every CoreX
     * client, not just agency 1.
     */
    public function test_own_ceiling_agent_requesting_company_scope_is_clamped_to_own_and_title_says_you(): void
    {
        $agencyId = 9011;
        $this->seedAgency($agencyId, splitBranches: false);
        $this->grantContactsScope('agent', $agencyId, 'own');

        $agent = $this->makeUser(1401, $agencyId, 601, 'agent');

        $resolver = new BuyersReportScopeResolver();
        $scope = $resolver->resolve($agent, requestedLevel: 'agency', requestedBranchId: null, requestedUserId: 999);

        $this->assertSame(BuyersReportScope::LEVEL_OWN, $scope->level, 'A restrictive config must clamp the report exactly as it clamps the pipeline board.');
        $this->assertSame(1401, $scope->userId);

        $title = $this->callDrilldownTitle('buyers', $scope, 9);
        $this->assertStringContainsString('— You ·', $title, 'The title must reflect the CLAMPED scope, never the requested one — an own-ceiling viewer must never see "Company" even having asked for it.');
    }

    public function test_branch_ceiling_agent_requesting_company_scope_or_another_branchs_id_is_clamped_to_own_branch(): void
    {
        $agencyId    = 9012;
        $ownBranch   = 1501;
        $otherBranch = 1502;
        $this->seedAgency($agencyId, splitBranches: true);
        $this->seedBranch($ownBranch, $agencyId);
        $this->seedBranch($otherBranch, $agencyId);
        $this->grantContactsScope('branch_manager', $agencyId, 'all'); // split=1 -> effective 'branch'

        $bm = $this->makeUser(1601, $agencyId, $ownBranch, 'branch_manager');
        $resolver = new BuyersReportScopeResolver();

        // Requesting company-wide -> clamped to their own branch, not agency.
        $scopeCompany = $resolver->resolve($bm, requestedLevel: 'agency');
        $this->assertSame(BuyersReportScope::LEVEL_BRANCH, $scopeCompany->level);
        $this->assertSame($ownBranch, $scopeCompany->branchId);

        // Requesting a DIFFERENT branch's id explicitly -> refused, own branch used.
        $scopeOther = $resolver->resolve($bm, requestedLevel: 'agency', requestedBranchId: $otherBranch);
        $this->assertSame($ownBranch, $scopeOther->branchId, 'A branch-ceiling viewer must never reach a DIFFERENT branch, even by supplying its id directly.');
        $this->assertNotSame($otherBranch, $scopeOther->branchId);

        $title = $this->callDrilldownTitle('buyers', $scopeOther, 4);
        $this->assertStringContainsString('Branch ' . $ownBranch, $title, 'The title must name the VIEWER\'S OWN branch, never the one requested.');
    }

    public function test_admin_default_with_no_request_params_is_agency_wide(): void
    {
        $agencyId = 9005;
        $this->seedAgency($agencyId, splitBranches: false);
        $this->grantContactsScope('admin', $agencyId, 'all');

        $admin = $this->makeUser(902, $agencyId, null, 'admin');

        $resolver = new BuyersReportScopeResolver();
        $scope = $resolver->resolve($admin);

        $this->assertSame(BuyersReportScope::LEVEL_AGENCY, $scope->level);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Invokes the real private BuyersReportController::drilldownTitle() via
     * reflection — proves the CLAMPED scope drives the visible title text,
     * not just an internal object the view might render differently.
     */
    private function callDrilldownTitle(string $metric, BuyersReportScope $scope, int $total): string
    {
        $controller = new \App\Http\Controllers\BuyersReport\BuyersReportController();
        $period = new \App\Services\Performance\Period(
            \Carbon\CarbonImmutable::parse('2026-08-01'),
            \Carbon\CarbonImmutable::parse('2026-08-31'),
            'This month',
            'this_month',
        );
        $method = new \ReflectionMethod($controller, 'drilldownTitle');
        $method->setAccessible(true);

        return $method->invoke($controller, $metric, $scope, $period, $total, null);
    }

    private function makeUser(int $id, int $agencyId, ?int $branchId, string $role): User
    {
        $user = new User();
        $user->id = $id;
        $user->agency_id = $agencyId;
        $user->branch_id = $branchId;
        $user->role = $role;
        $user->is_assistant = false;
        return $user;
    }

    private function seedAgency(int $id, bool $splitBranches): void
    {
        DB::table('agencies')->insert([
            'id' => $id, 'name' => 'Test Agency ' . $id,
            'split_branches_enabled' => $splitBranches,
        ]);
    }

    private function seedBranch(int $id, int $agencyId): void
    {
        DB::table('branches')->insert(['id' => $id, 'agency_id' => $agencyId, 'name' => 'Branch ' . $id]);
    }

    private function grantContactsScope(string $role, int $agencyId, string $scope): void
    {
        DB::table('roles')->insert(['name' => $role, 'agency_id' => $agencyId, 'is_owner' => false, 'sort_order' => 1]);
        DB::table('role_permissions')->insert([
            'role' => $role, 'permission_key' => 'contacts.view', 'agency_id' => $agencyId, 'scope' => $scope,
        ]);
    }

    private function dropSchema(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('agencies');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function buildSchema(): void
    {
        $this->dropSchema();

        Schema::create('agencies', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('split_branches_enabled')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('branches', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->string('name')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('users', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('role', 40)->nullable();
        });

        Schema::create('roles', function ($table) {
            $table->id();
            $table->string('name', 60);
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->boolean('is_owner')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('role_permissions', function ($table) {
            $table->id();
            $table->string('role', 60);
            $table->string('permission_key', 100);
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->string('scope', 20)->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }
}
