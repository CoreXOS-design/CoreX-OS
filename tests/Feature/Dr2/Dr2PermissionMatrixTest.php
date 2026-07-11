<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DR2 permission doctrine (Johan): deal CREATION/setup is ADMIN + BRANCH MANAGER
 * only. AGENTS do NOT create deals — they get READ access to their deals plus
 * feedback (log/remarks) and pipeline step updates. This proves the matrix is
 * REAL (not cosmetic): the capture routes gate on `create_deals` (admin + BM),
 * the register on `view_deals` (all three), so an agent is blocked from capture
 * but can reach the register.
 */
final class Dr2PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Perm Co', 'slug' => 'perm-' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function seedRole(string $role, array $permKeys): void
    {
        Role::create(['name' => $role, 'label' => ucfirst($role), 'agency_id' => $this->agencyId]);
        foreach ($permKeys as $key) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agencyId]);
        }
        Role::clearCache();
        PermissionService::clearCache();
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => $role, 'is_active' => true,
        ]);
    }

    public function test_agent_cannot_reach_dr2_capture_but_can_reach_the_register(): void
    {
        // Agent: read + feedback, NO create.
        $this->seedRole('agent', ['access_deal_register', 'view_deals']);
        $agent = $this->user('agent');

        $this->withoutVite();
        // Capture is admin+BM only — agent is blocked, HARD (403), not just hidden.
        $this->actingAs($agent)->get(route('deals-dr2.create'))->assertForbidden();
        $this->actingAs($agent)->post(route('deals-dr2.store'), [])->assertForbidden();
        // The register is readable by the agent.
        $this->actingAs($agent)->get(route('deals-dr2.index'))->assertOk();
    }

    public function test_branch_manager_can_reach_dr2_capture(): void
    {
        // Real BM default (config/corex-permissions.php): access + action keys.
        $this->seedRole('branch_manager', ['access_deal_register', 'view_deals', 'create_deals', 'deals.view', 'deals.create', 'deals.edit']);
        $bm = $this->user('branch_manager');

        $this->withoutVite();
        $this->actingAs($bm)->get(route('deals-dr2.create'))->assertOk();
    }
}
