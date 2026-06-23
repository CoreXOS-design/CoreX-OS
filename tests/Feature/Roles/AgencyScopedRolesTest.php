<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Models\Agency;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoleProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Roles & permissions are agency-scoped (.ai/specs/roles-permissions.md).
 *
 * The bug this guards: role_permissions used to be keyed by role NAME only, so
 * one agency editing its "admin" rewrote every agency's "admin". These tests
 * prove each agency's grants are isolated and resolve per the user's agency.
 */
final class AgencyScopedRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();
        Role::clearCache();
    }

    public function test_editing_one_agencys_permissions_does_not_touch_another(): void
    {
        [$a, $b] = [$this->seedAgency('admin', ['deals.export', 'contacts.export', 'listings.delete']),
                    $this->seedAgency('admin', ['deals.export', 'contacts.export', 'listings.delete'])];

        PermissionService::clearCache();
        $this->assertCount(3, PermissionService::getPermissionsForRole('admin', $a));
        $this->assertCount(3, PermissionService::getPermissionsForRole('admin', $b));

        // Simulate a Role Manager edit on agency A only: drop one grant.
        RolePermission::where('role', 'admin')->where('agency_id', $a)
            ->where('permission_key', 'listings.delete')->forceDelete();
        PermissionService::clearCache();

        $this->assertCount(2, PermissionService::getPermissionsForRole('admin', $a), 'Agency A should lose one grant');
        $this->assertCount(3, PermissionService::getPermissionsForRole('admin', $b), 'Agency B must be UNTOUCHED');
    }

    public function test_user_has_permission_resolves_against_their_own_agency(): void
    {
        $a = $this->seedAgency('admin', ['deals.export']);
        $b = $this->seedAgency('admin', []); // same role name, no grants

        $userA = User::factory()->create(['agency_id' => $a, 'role' => 'admin']);
        $userB = User::factory()->create(['agency_id' => $b, 'role' => 'admin']);

        PermissionService::clearCache();
        $this->assertTrue(PermissionService::userHasPermission($userA, 'deals.export'));
        $this->assertFalse(PermissionService::userHasPermission($userB, 'deals.export'),
            'Agency B admin must NOT inherit Agency A admin grants');
    }

    public function test_role_names_are_unique_per_agency_not_globally(): void
    {
        $a = $this->makeAgency();
        $b = $this->makeAgency();

        // Both agencies may own a role with the same name.
        Role::create(['name' => 'rentals_manager', 'label' => 'Rentals Manager', 'agency_id' => $a]);
        Role::create(['name' => 'rentals_manager', 'label' => 'Rentals Manager', 'agency_id' => $b]);

        $this->assertSame(1, Role::where('name', 'rentals_manager')->where('agency_id', $a)->count());
        $this->assertSame(1, Role::where('name', 'rentals_manager')->where('agency_id', $b)->count());

        // A custom role in A is invisible to B's role set.
        Role::create(['name' => 'intern', 'label' => 'Intern', 'agency_id' => $a]);
        $this->assertContains('intern', Role::roleNames($a));
        $this->assertNotContains('intern', Role::roleNames($b));
    }

    public function test_creating_an_agency_auto_provisions_roles_from_templates(): void
    {
        // Seed global templates (agency_id NULL) the way a fresh install would.
        $this->seedTemplateRole('admin', ['deals.export', 'contacts.export']);
        $this->seedTemplateRole('agent', ['deals.export']);

        // Eloquent create fires the Agency::created hook → provisioning.
        $agency = Agency::create(['name' => 'Provisioned ' . Str::random(5), 'slug' => 'prov-' . Str::random(6)]);

        $this->assertSame(2, Role::where('agency_id', $agency->id)->count(), 'admin + agent cloned');
        PermissionService::clearCache();
        $this->assertCount(2, PermissionService::getPermissionsForRole('admin', $agency->id));
        $this->assertCount(1, PermissionService::getPermissionsForRole('agent', $agency->id));
    }

    public function test_provisioning_is_idempotent_and_non_destructive(): void
    {
        $this->seedTemplateRole('admin', ['deals.export', 'contacts.export']);
        $agency = Agency::create(['name' => 'Idem ' . Str::random(5), 'slug' => 'idem-' . Str::random(6)]);

        // Agency customised: removed a grant. Re-provision must restore the
        // missing template grant WITHOUT wiping anything the agency still has.
        RolePermission::where('role', 'admin')->where('agency_id', $agency->id)
            ->where('permission_key', 'contacts.export')->forceDelete();

        RoleProvisioningService::provisionForAgency($agency);
        PermissionService::clearCache();

        $keys = PermissionService::getPermissionsForRole('admin', $agency->id);
        $this->assertCount(2, $keys, 'Re-provision restores the missing template grant, no duplicates');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function makeAgency(): int
    {
        return (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Create an agency + an agency-scoped role with the given grants. Returns agency id. */
    private function seedAgency(string $role, array $permKeys): int
    {
        $agencyId = $this->makeAgency();

        Role::create(['name' => $role, 'label' => ucfirst($role), 'agency_id' => $agencyId]);

        foreach ($permKeys as $key) {
            RolePermission::create([
                'role' => $role, 'permission_key' => $key, 'agency_id' => $agencyId,
            ]);
        }

        return $agencyId;
    }

    private function seedTemplateRole(string $role, array $permKeys): void
    {
        Role::create(['name' => $role, 'label' => ucfirst($role), 'agency_id' => null]);

        foreach ($permKeys as $key) {
            RolePermission::create([
                'role' => $role, 'permission_key' => $key, 'agency_id' => null,
            ]);
        }
        Role::clearCache();
    }
}
