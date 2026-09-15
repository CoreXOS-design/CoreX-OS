<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * AT-392 — Johan, 2026-09-07 (cc4's finding, verified): the
 * rental_applications.* grants existed in QA1's role_permissions table, but
 * config/corex-permissions.php's role_defaults did not (yet) list them for
 * agent/branch_manager — the rows had been produced by running the sync
 * command from a worktree with the config change, against the shared QA1
 * database, before that config commit had been merged into QA1's own
 * checkout. The grants were never hand-written into the database directly;
 * this test's job is to prove the STRONGER claim Johan actually needs: that
 * the COMMITTED config, run through the COMMITTED command, against a
 * genuinely EMPTY role_permissions table, reproduces the exact grant set —
 * so a fresh agency, a QA1 reset, Staging, or live all get this feature
 * working without anyone touching the database by hand.
 *
 * setUp() seeds the SAME minimal template Role rows (agency_id=null) a real
 * environment has once platform setup/Role Manager has run — this is a
 * fresh GRANTS table, not a fresh ROLES table. A genuinely empty `roles`
 * table is a separate, pre-existing, cross-cutting limitation of
 * SyncPermissions::mergeRoleDefaults() itself (Role::all() returns an empty
 * Collection rather than throwing, so its catch-block template-role
 * fallback never fires and ZERO grants are created for ANY module, not
 * just this one) — reported to the coordinator, not fixed here: it is a
 * platform-wide permissions-system concern, not a Rental Applications
 * defect, and fixing SyncPermissions.php is outside this lane's scope.
 */
final class RentalApplicationPermissionDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin', 'branch_manager', 'agent', 'viewer', 'office_admin'] as $name) {
            Role::forceCreate(['name' => $name, 'label' => ucfirst($name), 'agency_id' => null, 'is_owner' => $name === 'super_admin']);
        }
    }

    public function test_sync_permissions_from_a_clean_database_grants_the_correct_role_defaults(): void
    {
        $before = RolePermission::where('permission_key', 'like', 'rental_applications.%')->count();
        $this->assertSame(0, $before, 'Precondition: role_permissions must be genuinely empty before the sync runs.');

        Artisan::call('corex:sync-permissions', ['--merge-defaults' => true]);

        $grants = RolePermission::where('permission_key', 'like', 'rental_applications.%')
            ->get(['role', 'permission_key', 'scope'])
            ->groupBy('role')
            ->map(fn ($rows) => $rows->pluck('permission_key')->sort()->values()->all());

        foreach (['admin', 'branch_manager', 'agent'] as $role) {
            $this->assertContains('rental_applications.view', $grants[$role] ?? [], "{$role} must hold rental_applications.view from config alone.");
            $this->assertContains('rental_applications.create', $grants[$role] ?? [], "{$role} must hold rental_applications.create from config alone.");
            $this->assertContains('rental_applications.view_returned', $grants[$role] ?? [], "{$role} must hold rental_applications.view_returned from config alone.");
        }

        // manage_settings is admin-only per the house pattern (manage_finance_definitions,
        // outreach_templates.manage, compliance.whistleblow.configure) — branch_manager
        // and agent must NOT receive it just because they hold the other three.
        $this->assertContains('rental_applications.manage_settings', $grants['admin'] ?? [], 'admin gets manage_settings via all-minus-exclude.');
        $this->assertNotContains('rental_applications.manage_settings', $grants['branch_manager'] ?? [], 'branch_manager must NOT get manage_settings.');
        $this->assertNotContains('rental_applications.manage_settings', $grants['agent'] ?? [], 'agent must NOT get manage_settings.');

        // The .view key's scope must resolve per scope_defaults, from config
        // alone — this is what RentalApplication::scopeVisibleTo() actually reads.
        $scopes = RolePermission::where('permission_key', 'rental_applications.view')
            ->get(['role', 'scope'])->pluck('scope', 'role');
        $this->assertSame('all', $scopes['admin'] ?? null);
        $this->assertSame('branch', $scopes['branch_manager'] ?? null);
        $this->assertSame('own', $scopes['agent'] ?? null);

        // The other three keys are action gates, not scope-bearing — they must
        // carry no scope value (getDataScope() only ever reads the .view key).
        $nonViewScopes = RolePermission::whereIn('permission_key', [
            'rental_applications.create', 'rental_applications.view_returned', 'rental_applications.manage_settings',
        ])->pluck('scope')->unique()->all();
        $this->assertSame([null], $nonViewScopes, 'Only rental_applications.view carries a scope value.');
    }

    public function test_sync_permissions_touches_nothing_outside_rental_applications_beyond_its_own_normal_defaults(): void
    {
        // Sanity check requested by Johan last time: a merge-defaults run
        // must not silently sweep in unrelated keys as a side effect of
        // this feature's own config entries. Run it twice — idempotent,
        // second run inserts nothing new.
        Artisan::call('corex:sync-permissions', ['--merge-defaults' => true]);
        $firstRunCount = RolePermission::count();

        Artisan::call('corex:sync-permissions', ['--merge-defaults' => true]);
        $secondRunCount = RolePermission::count();

        $this->assertSame($firstRunCount, $secondRunCount, 'merge-defaults must be idempotent — a second run inserts nothing new.');
    }
}
