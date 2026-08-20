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
