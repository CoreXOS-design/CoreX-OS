<?php

namespace Tests\Feature\Permissions;

use App\Models\RolePermission;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Covers the permission-drift reconciler (corex:reconcile-role-grants): it
 * soft-deletes closed-include over-grants, keeps the legit config set, clears
 * the permission cache, is fully reversible via its snapshot, and REFUSES
 * non-closed roles (owner '*' / all-minus admin).
 */
class ReconcileRoleGrantsTest extends TestCase
{
    use RefreshDatabase;

    /** Legit agent keys (present in config agent.include) vs. drift keys (absent). */
    private const LEGIT = ['view_deals', 'deals.view'];
    private const OVER  = ['settle_deals', 'create_deals', 'manage_targets'];

    private function seedAgentGrants(): void
    {
        $rows = [];
        foreach (array_merge(self::LEGIT, self::OVER) as $key) {
            $rows[] = ['role' => 'agent', 'permission_key' => $key, 'agency_id' => null, 'scope' => null];
        }
        RolePermission::insert($rows);
        PermissionService::clearCache();
    }

    public function test_dry_run_reports_but_changes_nothing(): void
    {
        $this->seedAgentGrants();

        $this->artisan('corex:reconcile-role-grants', ['--roles' => 'agent'])
            ->assertExitCode(0);

        // Every seeded row still live — dry-run must not touch the DB.
        foreach (array_merge(self::LEGIT, self::OVER) as $key) {
            $this->assertTrue(
                RolePermission::where('role', 'agent')->where('permission_key', $key)->exists(),
                "Dry-run should have left {$key} untouched"
            );
        }
    }

    public function test_apply_removes_only_over_grants_and_clears_cache(): void
    {
        $this->seedAgentGrants();

        $snapshot = storage_path('app/permission-reconcile/test-apply.json');
        @unlink($snapshot);

        $this->artisan('corex:reconcile-role-grants', [
            '--roles'    => 'agent',
            '--apply'    => true,
            '--snapshot' => $snapshot,
        ])->assertExitCode(0);

        // Over-grants are soft-deleted (gone from live, present with trashed).
        foreach (self::OVER as $key) {
            $this->assertFalse(
                RolePermission::where('role', 'agent')->where('permission_key', $key)->exists(),
                "{$key} should be soft-deleted"
            );
            $this->assertTrue(
                RolePermission::withTrashed()->where('role', 'agent')->where('permission_key', $key)->whereNotNull('deleted_at')->exists(),
                "{$key} should still exist as a trashed row (recoverable — no hard delete)"
            );
        }

        // Legit keys survive.
        foreach (self::LEGIT as $key) {
            $this->assertTrue(
                RolePermission::where('role', 'agent')->where('permission_key', $key)->exists(),
                "{$key} is in config include and must be kept"
            );
        }

        // Snapshot manifest written for rollback.
        $this->assertTrue(File::exists($snapshot), 'Apply must write a rollback snapshot');
        $snap = json_decode(File::get($snapshot), true);
        $this->assertSame(count(self::OVER), $snap['count']);

        // PermissionService now resolves the cleaned set (cache was cleared by the command).
        $resolved = PermissionService::getPermissionsForRole('agent', null);
        $this->assertContains('view_deals', $resolved);
        $this->assertNotContains('settle_deals', $resolved);
    }

    public function test_rollback_restores_exactly_what_apply_removed(): void
    {
        $this->seedAgentGrants();

        $snapshot = storage_path('app/permission-reconcile/test-rollback.json');
        @unlink($snapshot);

        $this->artisan('corex:reconcile-role-grants', [
            '--roles' => 'agent', '--apply' => true, '--snapshot' => $snapshot,
        ])->assertExitCode(0);

        // Sanity: over-grants gone.
        $this->assertFalse(RolePermission::where('permission_key', 'settle_deals')->exists());

        // One-command rollback.
        $this->artisan('corex:reconcile-role-grants', ['--rollback' => $snapshot])
            ->assertExitCode(0);

        // All over-grants restored to live.
        foreach (self::OVER as $key) {
            $this->assertTrue(
                RolePermission::where('role', 'agent')->where('permission_key', $key)->exists(),
                "{$key} should be restored after rollback"
            );
        }

        PermissionService::clearCache();
        $this->assertContains('settle_deals', PermissionService::getPermissionsForRole('agent', null));
    }

    public function test_refuses_non_closed_roles(): void
    {
        // admin is all-minus-exclude → NOT a closed-include role → must be refused.
        RolePermission::insert([
            ['role' => 'admin', 'permission_key' => 'settle_deals', 'agency_id' => null, 'scope' => null],
        ]);
        PermissionService::clearCache();

        $this->artisan('corex:reconcile-role-grants', ['--roles' => 'admin', '--apply' => true])
            ->assertExitCode(0);

        $this->assertTrue(
            RolePermission::where('role', 'admin')->where('permission_key', 'settle_deals')->exists(),
            'A non-closed (admin) role must never be pruned'
        );
    }
}
