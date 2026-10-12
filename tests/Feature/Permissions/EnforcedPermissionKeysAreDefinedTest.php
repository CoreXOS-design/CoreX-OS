<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use App\Services\Permissions\RoleDefaultsResolver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * AT-449 permission audit (2026-10-12). Five keys were ENFORCED in code but never DEFINED in
 * config/corex-permissions.php — shared_drive.drives.{create,create_restricted,manage},
 * branches.self_assign_managed, and a typo'd commercial_evaluations.edit (the real key is
 * commercial_evals.edit). An undefined key can never appear in Role Manager, so nobody but the
 * platform owner could pass the check: Shared Drive could not be created in, branch self-assign was
 * dead, and archived commercial evaluations could not be restored.
 *
 * This is the structural guard for the whole class: every literal permission key that code enforces
 * (->hasPermission('x'), PermissionService::userHasPermission($u, 'x'), 'permission:x' middleware)
 * must exist in the catalogue. Reads files only — no DB.
 */
final class EnforcedPermissionKeysAreDefinedTest extends TestCase
{
    public function test_every_literal_permission_key_enforced_in_code_is_defined_in_the_catalogue(): void
    {
        $defined = array_flip(array_column(config('corex-permissions.permissions'), 'key'));

        $undefined = [];
        foreach ($this->enforcedLiteralKeys() as $key => $file) {
            if (! isset($defined[$key])) {
                $undefined[] = "{$key}  ({$file})";
            }
        }

        $this->assertSame([], $undefined,
            "Permission keys enforced in code but missing from config/corex-permissions.php — Role Manager cannot grant them, "
            ."so only the platform owner can pass the check:\n  ".implode("\n  ", $undefined));
    }

    public function test_the_audited_keys_are_defined_with_the_default_roles_the_specs_name(): void
    {
        $config  = config('corex-permissions');
        $allKeys = array_column($config['permissions'], 'key');
        $keysFor = fn (string $role): array => RoleDefaultsResolver::keysForDef($config['role_defaults'][$role], $allKeys);

        foreach (['shared_drive.drives.create', 'shared_drive.drives.create_restricted', 'shared_drive.drives.manage', 'branches.self_assign_managed'] as $key) {
            $this->assertContains($key, $allKeys, "{$key} must be defined");
        }

        // .ai/specs/shared-drive.md: create = admin, branch_manager, agent; create_restricted = admin, branch_manager; manage = admin only.
        $this->assertContains('shared_drive.drives.create', $keysFor('agent'));
        $this->assertNotContains('shared_drive.drives.create_restricted', $keysFor('agent'));
        $this->assertNotContains('shared_drive.drives.manage', $keysFor('agent'));

        $this->assertContains('shared_drive.drives.create', $keysFor('branch_manager'));
        $this->assertContains('shared_drive.drives.create_restricted', $keysFor('branch_manager'));
        $this->assertNotContains('shared_drive.drives.manage', $keysFor('branch_manager'));

        foreach (['shared_drive.drives.create', 'shared_drive.drives.create_restricted', 'shared_drive.drives.manage', 'branches.self_assign_managed'] as $key) {
            $this->assertContains($key, $keysFor('admin'), "admin must get {$key} (all-minus-exclude)");
        }

        // .ai/specs/admin-multi-branch-manager.md §4.3: branch managers and agents deliberately do NOT get it.
        $this->assertNotContains('branches.self_assign_managed', $keysFor('branch_manager'));
        $this->assertNotContains('branches.self_assign_managed', $keysFor('agent'));
    }

    public function test_commercial_evaluation_restore_checks_the_real_edit_key(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/CommercialEvaluationController.php'));

        $this->assertStringContainsString("hasPermission('commercial_evals.edit')", $src);
        $this->assertStringNotContainsString('commercial_evaluations.edit', $src);
    }

    /** @return array<string,string> key => first file it is enforced in */
    private function enforcedLiteralKeys(): array
    {
        $found = [];
        foreach ([app_path(), base_path('routes'), resource_path('views')] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! preg_match('/\.php$/', (string) $file)) {
                    continue;
                }
                $c   = file_get_contents((string) $file);
                $rel = str_replace(base_path() . '/', '', (string) $file);

                $hits = [];
                if (preg_match_all("/->(?:hasPermission|userHasPermission)\(\s*['\"]([a-z0-9_.]+)['\"]/", $c, $m)) {
                    $hits = array_merge($hits, $m[1]);
                }
                if (preg_match_all("/PermissionService::userHasPermission\([^,]+,\s*['\"]([a-z0-9_.]+)['\"]/", $c, $m)) {
                    $hits = array_merge($hits, $m[1]);
                }
                if (preg_match_all("/['\"]permission:([a-z0-9_.,|]+)['\"]/", $c, $m)) {
                    foreach ($m[1] as $list) {
                        $hits = array_merge($hits, preg_split('/[,|]/', $list));
                    }
                }
                foreach ($hits as $k) {
                    $found[$k] ??= $rel;
                }
            }
        }

        return $found;
    }
}
