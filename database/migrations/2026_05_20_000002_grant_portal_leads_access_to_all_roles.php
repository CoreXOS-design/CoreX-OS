<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grant Portal Leads access to every existing role.
 *
 * role_defaults in config/corex-permissions.php only apply on fresh installs.
 * Production tenants need an explicit backfill into role_permissions for the
 * new sidebar entry to appear for non-super-admin roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('role_permissions')) {
            return;
        }

        $roles = DB::table('role_permissions')->select('role')->distinct()->pluck('role');
        if ($roles->isEmpty()) {
            return;
        }

        $now = now();
        $rows = [];

        foreach (['access_portal_leads', 'portal_leads.view'] as $key) {
            foreach ($roles as $role) {
                $rows[] = [
                    'role'           => $role,
                    'permission_key' => $key,
                    'scope'          => 'all',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }
        }

        if ($rows) {
            DB::table('role_permissions')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('role_permissions')) {
            return;
        }
        DB::table('role_permissions')
            ->whereIn('permission_key', ['access_portal_leads', 'portal_leads.view'])
            ->delete();
    }
};
