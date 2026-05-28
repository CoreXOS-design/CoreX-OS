<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Report-lifecycle Phase 2 — seed the new `mic.restore_reports` permission
 * and assign it to super_admin + admin (matching the spec's "admin-only
 * lifecycle action" intent — agents can archive their own uploads but not
 * recover any agency's reports).
 *
 * Pattern mirrors 2026_05_21_130001_seed_mic_permissions.php — config
 * (`config/corex-permissions.php`) is the source of truth that
 * `corex:sync-permissions` reads, but this migration is the deploy-time
 * backstop so existing environments pick up the row without anyone running
 * the sync command after pulling.
 *
 * Idempotent: keyed on `nexus_permissions.key` and (role, permission_key).
 */
return new class extends Migration {
    private const PERM_KEY        = 'mic.restore_reports';
    private const PERM_LABEL      = 'Restore Archived Market Reports';
    private const PERM_SORT_ORDER = 56;

    /** @var array<int, string> */
    private array $roles = ['super_admin', 'admin'];

    public function up(): void
    {
        $now = now();

        // ── Permission row ──
        $existing = DB::table('nexus_permissions')->where('key', self::PERM_KEY)->first();
        $payload = [
            'label'      => self::PERM_LABEL,
            'section'    => 'prospecting',
            'type'       => 'action',
            'module'     => 'mic',
            'sort_order' => self::PERM_SORT_ORDER,
            'updated_at' => $now,
        ];
        if ($existing) {
            $patch = $payload;
            if ($existing->deleted_at !== null) {
                $patch['deleted_at'] = null;
            }
            DB::table('nexus_permissions')->where('id', $existing->id)->update($patch);
        } else {
            DB::table('nexus_permissions')->insert(array_merge(
                ['key' => self::PERM_KEY, 'created_at' => $now],
                $payload,
            ));
        }

        // ── Role assignments ──
        foreach ($this->roles as $role) {
            if (!DB::table('roles')->where('name', $role)->exists()) {
                if (PHP_SAPI === 'cli') {
                    fwrite(STDOUT, "    → mic.restore_reports: role '{$role}' not present — skipping assignment" . PHP_EOL);
                }
                continue;
            }

            $existingAssignment = DB::table('role_permissions')
                ->where('role', $role)
                ->where('permission_key', self::PERM_KEY)
                ->first();
            if ($existingAssignment) {
                if ($existingAssignment->deleted_at !== null) {
                    DB::table('role_permissions')
                        ->where('id', $existingAssignment->id)
                        ->update(['deleted_at' => null, 'updated_at' => $now]);
                }
            } else {
                DB::table('role_permissions')->insert([
                    'role'           => $role,
                    'permission_key' => self::PERM_KEY,
                    'scope'          => null,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, '    → mic.restore_reports seeded + assigned to: ' . implode(', ', $this->roles) . PHP_EOL);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission_key', self::PERM_KEY)->delete();
        DB::table('nexus_permissions')->where('key', self::PERM_KEY)->delete();
    }
};
