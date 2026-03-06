<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Copy all admin role_permissions to office_admin (skip any that already exist)
        $adminPerms = DB::table('role_permissions')
            ->where('role', 'admin')
            ->get(['permission_key', 'scope', 'created_at', 'updated_at']);

        if ($adminPerms->isEmpty()) {
            return; // role_permissions not seeded yet — nothing to copy
        }

        $now = now();

        $rows = $adminPerms->map(fn($p) => [
            'role'           => 'office_admin',
            'permission_key' => $p->permission_key,
            'scope'          => $p->scope,
            'created_at'     => $now,
            'updated_at'     => $now,
        ])->all();

        // insertOrIgnore so re-running is safe and manual changes are preserved
        DB::table('role_permissions')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('role', 'office_admin')->delete();
    }
};
