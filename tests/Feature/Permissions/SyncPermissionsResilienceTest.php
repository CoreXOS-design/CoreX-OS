<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Johan, 2026-09-07: "the platform wide bug - needs attention and get fixed.
 * hate silent fails." SyncPermissions::seedRoleDefaults()/mergeRoleDefaults()
 * used to do
 *
 *   try { $roles = Role::all(...) } catch (\Throwable $e) { $roles = <synthetic template roles> }
 *
 * On a genuinely EMPTY `roles` table (the real state until Role Manager or a
 * seeder populates it — nothing seeds it automatically) `Role::all()` returns
 * an empty Collection, it does NOT throw. The catch never fired, the
 * synthetic fallback never engaged, the per-role loop never ran, and the
 * command printed "0 new row(s) inserted" and exited 0 — a fresh environment
 * came up looking fine while every permission in CoreX was silently missing,
 * for every module, not just rental_applications.
 *
 * "Demonstrating the defect before demonstrating the fix" — test 1 reproduces
 * the OLD code's exact mechanism (not a paraphrase of it) against a real,
 * genuinely empty `roles` table and shows it does not throw. Tests 2–4 prove
 * the fixed command's actual behaviour on that same real, empty table plus
 * the harder failure case and the idempotent/normal-state case.
 */
final class SyncPermissionsResilienceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Faithful reproduction of the exact pre-fix snippet from
     * SyncPermissions::mergeRoleDefaults() — not a description of the bug,
     * the actual code as it existed before this fix, isolated here so the
     * defect can be demonstrated even though the real file no longer
     * contains it.
     */
    /**
     * $this->artisan()'s PendingCommand wrapper proved unreliable for this
     * command in this suite — chaining expectsOutputToContain() after
     * assertExitCode() (or even calling assertExitCode() alone ahead of a
     * DB assertion) intermittently returned a real exit code while the
     * command's actual database writes did not take effect, verified by
     * isolating the exact same scenario with Artisan::call() instead, which
     * consistently produced the correct real writes. Using the plain
     * facade call throughout this file for that reason.
     *
     * @return array{0: int, 1: string} [exit code, full console output]
     */
    private function runSync(array $options): array
    {
        $exitCode = Artisan::call('corex:sync-permissions', $options);

        return [$exitCode, Artisan::output()];
    }

    private function oldBuggyRoleResolution(array $roleDefaults)
    {
        try {
            $roles = Role::all(['name', 'is_owner', 'agency_id']);
        } catch (\Throwable $e) {
            $roles = collect(array_map(
                fn ($n) => (object) ['name' => $n, 'is_owner' => false, 'agency_id' => null],
                array_keys($roleDefaults)
            ));
        }

        return $roles;
    }

    public function test_the_old_buggy_pattern_silently_returns_empty_on_a_genuinely_empty_roles_table(): void
    {
        $this->assertSame(0, Role::count(), 'Precondition: roles table must be genuinely empty — RefreshDatabase gives schema, not seeded data.');

        $roleDefaults = config('corex-permissions.role_defaults');
        $result = $this->oldBuggyRoleResolution($roleDefaults);

        // THIS is the defect: Role::all() on an empty table does not throw,
        // so the catch's synthetic-fallback never engages. $result is an
        // empty Collection — not a caught exception, not a warning, not a
        // recognisable failure signal of any kind. A caller that then loops
        // "foreach ($result as $role)" runs zero iterations and reports
        // success with 0 grants created — exactly what shipped.
        $this->assertTrue($result->isEmpty(), 'The old pattern returns an EMPTY collection, not a thrown exception, on a genuinely empty roles table — this is the root cause.');
    }

    public function test_new_code_recovers_via_the_fallback_on_a_genuinely_empty_roles_table_and_grants_correctly(): void
    {
        $this->assertSame(0, Role::count());
        $this->assertSame(0, RolePermission::count());

        [$exitCode, $output] = $this->runSync(['--merge-defaults' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('returned NO rows', $output);
        $this->assertStringContainsString('falling back to synthetic template roles', $output);

        // The fallback recovered and produced REAL grants, not a silent no-op.
        $this->assertGreaterThan(0, RolePermission::count(), 'The fixed command must actually grant permissions when it falls back to synthetic template roles.');
        $this->assertTrue(RolePermission::where('role', 'admin')->where('permission_key', 'rental_applications.view')->exists());
        $this->assertTrue(RolePermission::where('role', 'agent')->where('permission_key', 'rental_applications.view')->exists());
        $this->assertTrue(RolePermission::where('role', 'branch_manager')->where('permission_key', 'rental_applications.view')->exists());
    }

    public function test_new_code_fails_loudly_and_exits_non_zero_when_even_the_fallback_has_nothing(): void
    {
        $this->assertSame(0, Role::count());

        // The one case resolveRolesOrFail() genuinely cannot recover from:
        // no real Role rows AND role_defaults itself is empty.
        Config::set('corex-permissions.role_defaults', []);

        [$exitCode, $output] = $this->runSync(['--merge-defaults' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('FAILED', $output);
        $this->assertSame(0, RolePermission::count(), 'A failed run must not have written anything.');
    }

    public function test_a_normal_populated_run_is_unaffected_and_remains_idempotent(): void
    {
        foreach (['super_admin', 'admin', 'branch_manager', 'agent', 'viewer', 'office_admin'] as $name) {
            Role::forceCreate(['name' => $name, 'label' => ucfirst($name), 'agency_id' => null, 'is_owner' => $name === 'super_admin']);
        }

        [$firstExitCode] = $this->runSync(['--merge-defaults' => true]);
        $this->assertSame(0, $firstExitCode);
        $firstCount = RolePermission::count();
        $this->assertGreaterThan(0, $firstCount);

        [$secondExitCode] = $this->runSync(['--merge-defaults' => true]);
        $this->assertSame(0, $secondExitCode);
        $this->assertSame($firstCount, RolePermission::count(), 'A second run against an already-synced, normal state must insert nothing new — idempotent.');
    }
}
