<?php

declare(strict_types=1);

namespace Tests\Feature\RoleManager;

use App\Http\Controllers\CoreX\RoleManagerController;
use App\Http\Middleware\CheckPermission;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Role Manager — functional correctness: saving permissions persists AND is
 * enforced, role assignment works, blocking blocks, and every guard guards.
 *
 * NOTE ON SEEDING — this is the crux of the whole file. PermissionService falls
 * back to "allow all" when role_permissions is empty, and the test DB IS empty:
 * the schema snapshot is schema-only, so the data-seeding migrations
 * (seed_existing_roles, sync-permissions) do NOT replay under RefreshDatabase.
 * Without seeding, every "blocked" assertion here would pass for the wrong reason
 * (allow-all lets everything through, so a false negative reads as a pass).
 *
 * So setUp() seeds the permission system for real — the six global role templates
 * + their grants from config/corex-permissions.php — mirroring what production has
 * after `corex:sync-permissions`. Agency::create then provisions each agency's own
 * copies via RoleProvisioningService (Agency.php booted hook). The
 * seeded_state_is_real test asserts the fallback is NOT active, so the suite can
 * never silently degrade to allow-all.
 */
final class RoleManagerFunctionalTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    private RoleManagerController $rm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rm = app(RoleManagerController::class);
        $this->seedPermissionSystem();
    }

    /**
     * Seed the global role templates + their permission grants, the way
     * production is after its seed migrations + `corex:sync-permissions`. The
     * schema snapshot carries structure only, so this must be done explicitly or
     * enforcement falls back to allow-all and the blocking tests become theatre.
     */
    private function seedPermissionSystem(): void
    {
        $now = now();
        $roles = [
            ['name' => 'super_admin',    'label' => 'System Owner',   'is_owner' => 1, 'can_be_deleted' => 0, 'sort_order' => 1],
            ['name' => 'admin',          'label' => 'Administrator',  'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 2],
            ['name' => 'branch_manager', 'label' => 'Branch Manager', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 3],
            ['name' => 'agent',          'label' => 'Agent',          'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 4],
            ['name' => 'viewer',         'label' => 'Viewer',         'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 5],
            ['name' => 'office_admin',   'label' => 'Office Staff',   'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 6],
        ];
        foreach ($roles as &$r) {
            $r['agency_id']  = null;
            $r['created_at'] = $now;
            $r['updated_at'] = $now;
        }
        DB::table('roles')->insert($roles);

        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);

        Role::clearCache();
        PermissionService::clearCache();
    }

    public function test_seeded_state_is_real_not_allow_all(): void
    {
        // If this fails, every "blocked" assertion in this file is meaningless.
        $this->assertTrue(
            RolePermission::exists(),
            'the permission system must be seeded, or PermissionService falls back to allow-all'
        );
        $this->assertGreaterThanOrEqual(
            6,
            Role::withoutGlobalScopes()->whereNull('agency_id')->count(),
            'the six global role templates must exist'
        );
    }

    /** @return array{0: Agency, 1: User, 2: User} agency, admin, agent */
    private function provisionedAgency(): array
    {
        $agency = Agency::create(['name' => 'Coastal ' . uniqid(), 'slug' => 'coastal-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        $mk = fn (string $role) => User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => $role, 'is_active' => true,
        ]);

        // Agency::create fires RoleProvisioningService (Agency.php booted hook),
        // which copies the global templates seeded in setUp into this agency's own
        // grants. Without those grants the blocking tests would silently fall back
        // to allow-all — so assert they landed.
        $this->assertGreaterThan(
            0,
            RolePermission::where('agency_id', $agency->id)->count(),
            'the new agency must be provisioned its own permission grants from the seeded templates'
        );

        return [$agency, $mk('admin'), $mk('agent')];
    }

    /** True if the user passes the real CheckPermission middleware for $permission. */
    private function passesGate(User $user, string $permission): bool
    {
        Auth::login($user);
        PermissionService::clearCache();
        try {
            (new CheckPermission())->handle(
                Request::create('/x', 'GET'),
                fn ($r) => response('ok'),
                $permission
            );
            return true;
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 403) {
                return false;
            }
            throw $e;
        }
    }

    public function test_blocking_reflects_who_holds_the_permission(): void
    {
        [, $admin, $agent] = $this->provisionedAgency();

        $this->assertTrue($this->passesGate($admin, 'access_role_manager'), 'admin may open Role Manager');
        $this->assertFalse($this->passesGate($agent, 'access_role_manager'), 'agent is blocked from Role Manager');
        $this->assertFalse($this->passesGate($agent, 'edit_permissions'), 'agent is blocked from editing permissions');
        $this->assertFalse($this->passesGate($agent, 'manage_users'), 'agent is blocked from user management');
        $this->assertTrue($this->passesGate($agent, 'access_properties'), 'agent keeps a permission it does hold');
    }

    public function test_saving_permissions_persists_and_is_enforced_immediately(): void
    {
        [$agency, $admin, $agent] = $this->provisionedAgency();

        $this->assertFalse($this->passesGate($agent, 'manage_users'), 'agent starts without manage_users');

        $agentPerms = RolePermission::where('agency_id', $agency->id)->where('role', 'agent')
            ->pluck('permission_key')->all();

        // Grant.
        Auth::login($admin);
        $this->rm->savePermissions(Request::create('/x', 'POST', [
            'role'        => 'agent',
            'permissions' => array_fill_keys(array_merge($agentPerms, ['manage_users']), '1'),
        ]));
        $this->assertTrue($this->passesGate($agent, 'manage_users'), 'granting takes effect with no cache staleness');

        // Revoke.
        Auth::login($admin);
        $this->rm->savePermissions(Request::create('/x', 'POST', [
            'role'        => 'agent',
            'permissions' => array_fill_keys(array_diff($agentPerms, ['access_properties']), '1'),
        ]));
        $this->assertFalse($this->passesGate($agent, 'access_properties'), 'revoking takes effect immediately');
    }

    public function test_invalid_permission_keys_are_filtered_out(): void
    {
        [$agency, $admin] = $this->provisionedAgency();

        Auth::login($admin);
        $this->rm->savePermissions(Request::create('/x', 'POST', [
            'role'        => 'agent',
            'permissions' => ['not_a_real_permission_key' => '1', 'access_properties' => '1'],
        ]));

        $this->assertFalse(
            RolePermission::where('agency_id', $agency->id)->where('role', 'agent')
                ->where('permission_key', 'not_a_real_permission_key')->exists(),
            'a permission key that does not exist in the catalogue must never be stored'
        );
    }

    public function test_edit_all_implies_view_all_server_side(): void
    {
        [$agency, $admin] = $this->provisionedAgency();

        Auth::login($admin);
        $this->rm->savePermissions(Request::create('/x', 'POST', [
            'role'        => 'agent',
            'permissions' => ['branches.edit_all' => '1'],   // view_all deliberately NOT sent
        ]));

        $this->assertTrue(
            RolePermission::where('agency_id', $agency->id)->where('role', 'agent')
                ->where('permission_key', 'branches.view_all')->exists(),
            'branches.edit_all must imply branches.view_all so no edit-without-view leak is possible'
        );
    }

    public function test_updating_a_users_role_works(): void
    {
        [, $admin, $agent] = $this->provisionedAgency();

        Auth::login($admin);
        $this->rm->updateUserRole(Request::create('/x', 'POST', [
            'user_id' => $agent->id, 'role' => 'branch_manager',
        ]));

        $this->assertSame('branch_manager', $agent->fresh()->role);
    }

    public function test_a_non_owner_cannot_assign_the_owner_role(): void
    {
        [, $admin, $agent] = $this->provisionedAgency();
        $owner = Role::withoutGlobalScopes()->where('is_owner', true)->firstOrFail();

        Auth::login($admin);
        try {
            $this->rm->updateUserRole(Request::create('/x', 'POST', [
                'user_id' => $agent->id, 'role' => $owner->name,
            ]));
            $this->fail('a non-owner assigning the owner role should 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame('agent', $agent->fresh()->role, 'the role must be unchanged after the blocked attempt');
    }

    public function test_save_permissions_rejects_a_role_outside_this_agency(): void
    {
        [, $admin] = $this->provisionedAgency();
        $owner = Role::withoutGlobalScopes()->where('is_owner', true)->firstOrFail();

        Auth::login($admin);
        $this->expectException(ValidationException::class);
        $this->rm->savePermissions(Request::create('/x', 'POST', [
            'role' => $owner->name, 'permissions' => [],
        ]));
    }

    public function test_cannot_edit_another_agencys_role(): void
    {
        [, $admin] = $this->provisionedAgency();

        $other = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'other-' . uniqid()]);
        $foreign = Role::create([
            'name' => 'foreign_' . uniqid(), 'label' => 'Foreign',
            'agency_id' => $other->id, 'can_be_deleted' => true,
        ]);

        Auth::login($admin);   // admin belongs to the first agency, not $other
        try {
            $this->rm->updateRole(Request::create('/x', 'PUT', ['label' => 'Hijacked']), $foreign);
            $this->fail('editing another agency\'s role should 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame('Foreign', $foreign->fresh()->label, 'the foreign role must be untouched');
    }

    public function test_cannot_delete_an_owner_role(): void
    {
        [$agency, $admin] = $this->provisionedAgency();

        $localOwner = Role::create([
            'name' => 'local_owner_' . uniqid(), 'label' => 'LO',
            'agency_id' => $agency->id, 'is_owner' => true, 'can_be_deleted' => false,
        ]);

        Auth::login($admin);
        try {
            $this->rm->destroyRole(Request::create('/x', 'DELETE'), $localOwner);
            $this->fail('deleting an owner role should 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_store_role_seeds_agent_defaults(): void
    {
        [$agency, $admin] = $this->provisionedAgency();
        $agentCount = RolePermission::where('agency_id', $agency->id)->where('role', 'agent')->count();

        Auth::login($admin);
        $this->rm->storeRole(Request::create('/x', 'POST', ['label' => 'Senior Agent']));

        $new = Role::withoutGlobalScopes()->where('agency_id', $agency->id)->where('label', 'Senior Agent')->firstOrFail();
        $this->assertSame(
            $agentCount,
            RolePermission::where('agency_id', $agency->id)->where('role', $new->name)->count(),
            'a new role must start with the agent permission set as its baseline'
        );
    }

    public function test_copy_permissions_overwrites_target_with_source(): void
    {
        [$agency, $admin] = $this->provisionedAgency();
        $adminCount = RolePermission::where('agency_id', $agency->id)->where('role', 'admin')->count();

        Auth::login($admin);
        $this->rm->copyPermissions(Request::create('/x', 'POST', [
            'source_role' => 'admin', 'target_roles' => ['agent'],
        ]));

        $this->assertSame(
            $adminCount,
            RolePermission::where('agency_id', $agency->id)->where('role', 'agent')->count(),
            'the target role must end up with exactly the source role\'s permission set'
        );
    }

    public function test_destroy_role_reassigns_active_users_then_soft_deletes(): void
    {
        [$agency, $admin, $agent] = $this->provisionedAgency();

        Auth::login($admin);
        $this->rm->storeRole(Request::create('/x', 'POST', ['label' => 'Temp Role']));
        $temp = Role::withoutGlobalScopes()->where('agency_id', $agency->id)->where('label', 'Temp Role')->firstOrFail();

        $agent->role = $temp->name;
        $agent->save();

        Auth::login($admin);
        $this->rm->destroyRole(Request::create('/x', 'DELETE', ['reassign_to' => 'agent']), $temp);

        $this->assertSame('agent', $agent->fresh()->role, 'active users must be reassigned, not orphaned');
        $this->assertTrue(
            Role::withoutGlobalScopes()->where('id', $temp->id)->whereNotNull('deleted_at')->exists(),
            'the role must be soft-deleted (Non-negotiable #1), not hard-deleted'
        );
    }
}
