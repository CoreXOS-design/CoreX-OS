<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Models\Agency;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Permissions\RoleDefaultsResolver;
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

        // Roles come from the templates; GRANTS come from config role_defaults (the template's made-up
        // 'deals.export' / 'contacts.export' grants are NOT cloned).
        $this->assertEqualsCanonicalizing($this->configKeysFor('admin'), $this->grantedKeys('admin', $agency->id));
        $this->assertEqualsCanonicalizing($this->configKeysFor('agent'), $this->grantedKeys('agent', $agency->id));
        $this->assertNotContains('deals.export', $this->grantedKeys('admin', $agency->id));
    }

    public function test_provisioning_is_idempotent_and_non_destructive(): void
    {
        $this->seedTemplateRole('admin', []);
        $agency = Agency::create(['name' => 'Idem ' . Str::random(5), 'slug' => 'idem-' . Str::random(6)]);
        $expected = $this->configKeysFor('admin');

        // Agency customised: removed a grant. Re-provision must restore the
        // missing default WITHOUT wiping anything the agency still has.
        $dropped = $expected[0];
        RolePermission::where('role', 'admin')->where('agency_id', $agency->id)
            ->where('permission_key', $dropped)->forceDelete();
        $this->assertNotContains($dropped, $this->grantedKeys('admin', $agency->id));

        RoleProvisioningService::provisionForAgency($agency);

        $keys = $this->grantedKeys('admin', $agency->id);
        $this->assertContains($dropped, $keys, 'Re-provision restores the missing default grant');
        $this->assertCount(count($expected), $keys, 'no duplicates');
    }

    public function test_a_new_agency_starts_at_bare_minimum_even_if_the_template_grants_are_over_broad(): void
    {
        // Johan, 2026-10-12: "each role should get bare minimum ... until an admin gives permissions
        // they should stay like that." The global template's grants had drifted far past the config
        // (office_admin ~312 vs 6); cloning them gave every new agency that access.
        $this->seedTemplateRole('office_admin', ['access_role_manager', 'edit_permissions', 'impersonate_users', 'run_payroll']);
        $this->seedTemplateRole('agent', ['compliance.manage', 'finance.view', 'roles.view', 'contacts.delete']);
        $this->seedTemplateRole('assistant', ['access_role_manager']);

        $agency = Agency::create(['name' => 'Minimal ' . Str::random(5), 'slug' => 'min-' . Str::random(6)]);

        $officeAdmin = $this->grantedKeys('office_admin', $agency->id);
        $this->assertEqualsCanonicalizing($this->configKeysFor('office_admin'), $officeAdmin);
        $this->assertNotContains('access_role_manager', $officeAdmin, 'an office admin must not start with Role Manager');
        $this->assertNotContains('run_payroll', $officeAdmin);

        $agent = $this->grantedKeys('agent', $agency->id);
        $this->assertEqualsCanonicalizing($this->configKeysFor('agent'), $agent);
        foreach (['compliance.manage', 'finance.view', 'roles.view', 'contacts.delete'] as $k) {
            $this->assertNotContains($k, $agent, "an agent must not start with {$k}");
        }

        $this->assertSame([], $this->grantedKeys('assistant', $agency->id), 'the assistant role stays at zero grants');
    }

    public function test_a_new_agencys_admin_gets_every_permission_except_the_platform_owner_only_ones(): void
    {
        $this->seedTemplateRole('admin', []); // an empty template must not shrink what a new admin gets

        $agency = Agency::create(['name' => 'AdminAll ' . Str::random(5), 'slug' => 'adm-' . Str::random(6)]);
        $granted = $this->grantedKeys('admin', $agency->id);

        $allKeys = array_column(config('corex-permissions.permissions'), 'key');
        $ownerOnly = config('corex-permissions.role_defaults.admin.exclude');

        $this->assertNotEmpty($ownerOnly);
        $this->assertEqualsCanonicalizing(array_values(array_diff($allKeys, $ownerOnly)), $granted);
        $this->assertContains('access_role_manager', $granted, 'the agency admin can run Role Manager to widen other roles');
        foreach ($ownerOnly as $k) {
            $this->assertNotContains($k, $granted, "{$k} is platform-owner-only");
        }
    }

    public function test_a_role_with_no_config_defaults_gets_no_grants_and_view_scopes_follow_the_role(): void
    {
        $this->seedTemplateRole('admin', []);
        $this->seedTemplateRole('agent', []);
        $this->seedTemplateRole('rentals_manager', ['deals.export']); // custom template role, not in role_defaults

        $agency = Agency::create(['name' => 'Scope ' . Str::random(5), 'slug' => 'scp-' . Str::random(6)]);

        $this->assertTrue(Role::where('agency_id', $agency->id)->where('name', 'rentals_manager')->exists());
        $this->assertSame([], $this->grantedKeys('rentals_manager', $agency->id));

        // .view keys carry a data scope from scope_defaults (admin = all, agent = own), shared modules = all.
        $shared = config('corex-permissions.shared_scope_modules');
        foreach (['admin' => 'all', 'agent' => 'own'] as $role => $scope) {
            $viewKey = collect($this->configKeysFor($role))
                ->first(fn ($k) => str_ends_with($k, '.view') && ! in_array(explode('.', $k)[0], $shared, true));
            $this->assertNotNull($viewKey, "{$role} has at least one non-shared .view default");
            $this->assertSame($scope, RolePermission::where('role', $role)->where('agency_id', $agency->id)
                ->where('permission_key', $viewKey)->value('scope'), "{$role} {$viewKey} scope");
        }
    }

    public function test_provisioning_survives_duplicate_global_templates(): void
    {
        // Regression for the 2026-08-12 500 on POST /corex/settings/agencies:
        // roles_name_agency_unique(name, agency_id) does not stop two global
        // template rows sharing name='admin', agency_id=NULL — MySQL never
        // treats two NULLs as equal in a unique index. When that happens,
        // provisioning must still succeed with exactly ONE cloned role/grant
        // per agency, not attempt a second INSERT and 1062.
        $this->seedTemplateRole('admin', ['deals.export']);
        $this->seedTemplateRole('admin', ['deals.export', 'contacts.export']); // duplicate template, same name

        $agency = Agency::create(['name' => 'Dupe ' . Str::random(5), 'slug' => 'dupe-' . Str::random(6)]);

        $this->assertSame(1, Role::where('agency_id', $agency->id)->where('name', 'admin')->count(),
            'Exactly one admin role cloned despite two global templates');
        $this->assertCount(count($this->configKeysFor('admin')), $this->grantedKeys('admin', $agency->id),
            'one set of default grants, not doubled by the duplicate template');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** The config's bare-minimum key set for a role (what a new agency must start with). */
    private function configKeysFor(string $role): array
    {
        $cfg = config('corex-permissions');

        return array_values(array_unique(
            RoleDefaultsResolver::keysForDef($cfg['role_defaults'][$role], array_column($cfg['permissions'], 'key'))
        ));
    }

    /** Live grant keys a role holds in an agency. */
    private function grantedKeys(string $role, int $agencyId): array
    {
        return RolePermission::where('role', $role)->where('agency_id', $agencyId)->pluck('permission_key')->all();
    }

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
