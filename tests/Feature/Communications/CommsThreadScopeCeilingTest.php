<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * AT-118 REVERSAL (Johan 2026-07-15): "bm do not see threads by default. admin and
 * users sees it. bm goes to request access."
 *
 * The code ceiling in PermissionService::getDataScope() clamps a `communications`
 * scope of 'branch' down to 'own' — regardless of the stored role_permissions row —
 * while leaving every OTHER module's branch scope (deals/calendar/tasks/outreach)
 * untouched. Admins/owners keep 'all' via the break-glass.
 */
final class CommsThreadScopeCeilingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'HFC ' . uniqid(), 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Port Shepstone']);
        Cache::flush();
    }

    private function branchManager(): User
    {
        return User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager',
        ]);
    }

    /** Seed the agency's stored scopes exactly as a live agency would carry them. */
    private function seedBranchScopes(): void
    {
        RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'communications.view', 'scope' => 'branch', 'agency_id' => $this->agency->id]);
        RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'deals.view', 'scope' => 'branch', 'agency_id' => $this->agency->id]);
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();
    }

    public function test_bm_communications_branch_scope_is_clamped_to_own_but_deals_stays_branch(): void
    {
        $this->seedBranchScopes();
        $bm = $this->branchManager();

        // The stored row says 'branch' for both — the ceiling overrides ONLY communications.
        $this->assertSame('own', PermissionService::getDataScope($bm, 'communications'),
            'BM must NOT see the branch — comms threads clamp to own (they request access for more).');
        $this->assertSame('branch', PermissionService::getDataScope($bm, 'deals'),
            'The ceiling is comms-only — deals/operational branch oversight is unchanged.');
    }

    public function test_all_scope_for_communications_is_not_clamped(): void
    {
        // An admin/authoriser carries communications.view scope='all' — the ceiling
        // only touches 'branch', so 'all' passes through untouched (admins see threads).
        RolePermission::create(['role' => 'admin', 'permission_key' => 'communications.view', 'scope' => 'all', 'agency_id' => $this->agency->id]);
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();

        $admin = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
        ]);

        $this->assertSame('all', PermissionService::getDataScope($admin, 'communications'),
            'A stored comms scope of all is never clamped — admins keep full thread visibility.');
    }
}
