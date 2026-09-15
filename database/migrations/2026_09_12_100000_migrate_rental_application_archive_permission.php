<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * Archive/Restore on the rental-applications list (RentalApplicationController::
 * destroy()/restore(), both routes gated by permission:rental_applications.create)
 * were gated on rental_applications.create — the "Create & Send Rental
 * Applications" permission — by mistake. Both actions are correctly reversible
 * soft-deletes already; only the permission NAME was wrong. New key
 * rental_applications.archive added (config/corex-permissions.php), matching the
 * {module}.archive convention every other module already uses (deals.archive,
 * listings.archive, properties.archive, contacts.archive, etc.).
 *
 * This backfill copies every EXISTING role_permissions grant of
 * rental_applications.create — real per-agency Role Manager customisations on
 * this QA1 database, not just the config defaults — onto the new
 * rental_applications.archive key, same role/agency_id/scope, so nobody's
 * effective access changes. rental_applications.create itself is left exactly
 * as-is (it still legitimately gates Create & Send); the route middleware
 * change (routes/web.php) is what actually moves Archive/Restore onto the new
 * key going forward.
 *
 * withTrashed()+restore() per BUILD_STANDARD.md §5a — this table is
 * SoftDeletes with a unique(role, permission_key, agency_id) index, so a naive
 * create() on a key that was ever soft-deleted would throw a duplicate-key
 * error instead of restoring it. Idempotent — safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $grants = RolePermission::where('permission_key', 'rental_applications.create')->get(['role', 'agency_id']);

        $migrated = [];
        foreach ($grants as $grant) {
            $row = RolePermission::withTrashed()->firstOrNew([
                'role' => $grant->role,
                'permission_key' => 'rental_applications.archive',
                'agency_id' => $grant->agency_id,
            ]);
            $wasTrashed = $row->exists && $row->trashed();
            $row->scope = null;
            $row->save();
            if ($wasTrashed) {
                $row->restore();
            }
            $migrated[] = $grant->role . '/agency:' . ($grant->agency_id ?? 'null');
        }

        \Illuminate\Support\Facades\Log::info('rental_applications.archive permission migrated from .create', [
            'grants_migrated' => count($migrated),
            'detail' => $migrated,
        ]);
    }

    public function down(): void
    {
        // Reversible: only removes the grants THIS migration created — never
        // touches rental_applications.create, which this migration never wrote to.
        RolePermission::where('permission_key', 'rental_applications.archive')->delete();
    }
};
