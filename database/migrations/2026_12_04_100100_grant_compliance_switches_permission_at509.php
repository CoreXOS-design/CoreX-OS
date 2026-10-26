<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * AT-509 — Compliance Switches (.ai/specs/property-compliance-gate-settings.md §9): the new Role Manager
 * key `compliance_switches.manage`.
 *
 * Fresh installs get it for `admin` through its all-minus-exclude role_defaults rule; owner roles hold it
 * through the owner bypass. Existing agencies already have their roles stored in role_permissions, so this
 * grants the key to every `admin` role (each agency's own copy and the global template rows) that already
 * holds `access_settings` — the people who can open Settings today. Nobody else gets it by default; an
 * agency ticks it per role in Role Manager.
 *
 * A soft-deleted row is a grant an administrator deliberately took away — left exactly as it is. Idempotent.
 */
return new class extends Migration
{
    private const KEY = 'compliance_switches.manage';

    public function up(): void
    {
        $holders = RolePermission::query()
            ->where('permission_key', 'access_settings')
            ->where('role', 'admin')
            ->get(['role', 'agency_id']);

        foreach ($holders as $h) {
            $row = RolePermission::withTrashed()->firstOrNew([
                'agency_id'      => $h->agency_id,
                'role'           => $h->role,
                'permission_key' => self::KEY,
            ]);
            if ($row->exists) {
                continue;
            }
            $row->scope = null;
            $row->save();
        }
    }

    public function down(): void
    {
        // Not reversed: these grants cannot be told apart from ones an administrator made.
    }
};
