<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * AT-401 — a Role Manager save on agency 1's admin role (an unrelated save,
 * during this morning's contact_rental_history.view work) force-rebuilt
 * every role_permissions row for that role and, because
 * `rental_applications.view` is `type => 'access'` in config and so never
 * gets a scope selector in the UI, wrote its scope as NULL instead of
 * carrying forward whatever it held. `PermissionService::getDataScope()`
 * then returns NULL for that key, and
 * `AuthorizesRentalApplicationAccess::guardRentalApplication()` has no
 * branch that matches NULL — every rental application, including ones the
 * admin created themselves, 403s.
 *
 * Every other agency's admin role already holds scope='all' for this key
 * (agencies 20-26, seeded 2026-09-08, untouched since). This restores
 * agency 1 to the same value — matching Johan's ruling that admin/owner
 * roles see agency-wide — rather than inventing a new default.
 *
 * Scoped to exactly one row: agency_id=1, role=admin,
 * permission_key='rental_applications.view'. No other agency, role, or
 * permission key is touched by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        RolePermission::where('agency_id', 1)
            ->where('role', 'admin')
            ->where('permission_key', 'rental_applications.view')
            ->update(['scope' => 'all']);
    }

    public function down(): void
    {
        RolePermission::where('agency_id', 1)
            ->where('role', 'admin')
            ->where('permission_key', 'rental_applications.view')
            ->update(['scope' => null]);
    }
};
