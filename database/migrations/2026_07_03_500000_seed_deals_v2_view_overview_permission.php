<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-158 DR2 WS8 — the pipeline-overview / dashboard-board capability.
 *
 * The overview surface (KPI cards + milestone board + scoped export) is for
 * managers monitoring the whole book — branch_manager + admin only; agents keep
 * the register (access_deal_register_v2). config/corex-permissions.php carries
 * the same row + role_default (source of truth for corex:sync-permissions); this
 * migration is the deploy-time backstop so the perm + grants land on a
 * `migrate --force` deploy without the sync command (BUILD_STANDARD §8 — AT-162:
 * reference data travels with the deploy). Idempotent.
 *
 * Grants are cloned from the exact (role, agency_id, scope) distribution of the
 * sibling BM+admin perm `deals_v2.manage_pipeline`, so the overview reaches every
 * role/agency that already holds the managerial DR2 perms — no more, no less.
 */
return new class extends Migration {
    private string $key = 'deals_v2.view_overview';
    private string $sibling = 'deals_v2.manage_pipeline';

    public function up(): void
    {
        $now = now();

        $existing = DB::table('nexus_permissions')->where('key', $this->key)->first();
        $payload = [
            'label'      => 'View Pipeline Overview',
            'section'    => 'deals-v2',
            'type'       => 'action',
            'module'     => 'deals_v2',
            'sort_order' => 19,
            'updated_at' => $now,
        ];

        if ($existing) {
            $update = $payload;
            if ($existing->deleted_at !== null) {
                $update['deleted_at'] = null;
            }
            DB::table('nexus_permissions')->where('id', $existing->id)->update($update);
        } else {
            DB::table('nexus_permissions')->insert(array_merge(['key' => $this->key, 'created_at' => $now], $payload));
        }

        // Clone the sibling perm's (agency_id, scope) distribution onto the new
        // key, but ONLY for the intended manager roles. Filtering by role — rather
        // than trusting the sibling's role set — makes this robust against
        // environment drift (staging had `manage_pipeline` wrongly granted to
        // agent/office_admin; the overview must stay branch_manager + admin only).
        $siblingGrants = DB::table('role_permissions')
            ->where('permission_key', $this->sibling)
            ->whereIn('role', ['super_admin', 'admin', 'branch_manager'])
            ->whereNull('deleted_at')
            ->get(['role', 'agency_id', 'scope']);

        foreach ($siblingGrants as $g) {
            $exists = DB::table('role_permissions')
                ->where('permission_key', $this->key)
                ->where('role', $g->role)
                ->where(fn ($q) => is_null($g->agency_id) ? $q->whereNull('agency_id') : $q->where('agency_id', $g->agency_id))
                ->exists();
            if ($exists) {
                continue;
            }
            DB::table('role_permissions')->insert([
                'role' => $g->role, 'permission_key' => $this->key,
                'agency_id' => $g->agency_id, 'scope' => $g->scope,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // No hard deletes (non-negotiable #1) — soft-delete the perm + its grants.
        DB::table('role_permissions')->where('permission_key', $this->key)
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
        DB::table('nexus_permissions')->where('key', $this->key)
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
