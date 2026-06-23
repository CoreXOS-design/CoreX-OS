<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roll out the Command Center visibility-scope feature with the most
 * restrictive default: every existing non-owner role sees only its OWN
 * Calendar and Task data (scope = 'own'). Owners are unaffected — owner
 * roles bypass permission checks and always see everything.
 *
 * For each non-owner role and each of the two view keys we force scope =
 * 'own' (insert if missing, overwrite otherwise). Forcing — rather than only
 * filling NULLs — makes the rollout order-independent: before this feature
 * these keys were access-type with no meaningful scope, so there is no admin
 * customisation to preserve, and the result is the same whether the
 * permission sync runs before or after this migration on deploy.
 *
 * This grants access (so nobody is locked out of pages now gated on these
 * keys) while constraining breadth to "own" until an admin widens it in
 * Role Manager.
 */
return new class extends Migration
{
    private array $keys = [
        'command_center.calendar.view',
        'command_center.tasks.view',
    ];

    public function up(): void
    {
        $roles = DB::table('roles')
            ->where('is_owner', false)
            ->whereNull('deleted_at')
            ->pluck('name');

        $now = now();

        foreach ($roles as $role) {
            foreach ($this->keys as $key) {
                $existing = DB::table('role_permissions')
                    ->where('role', $role)
                    ->where('permission_key', $key)
                    ->first();

                if (!$existing) {
                    DB::table('role_permissions')->insert([
                        'role'           => $role,
                        'permission_key' => $key,
                        'scope'          => 'own',
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ]);
                } elseif ($existing->scope !== 'own') {
                    DB::table('role_permissions')
                        ->where('id', $existing->id)
                        ->update(['scope' => 'own', 'updated_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: scope values are configuration, not schema. Leaving
        // them in place on rollback avoids clobbering admin customisations.
    }
};
