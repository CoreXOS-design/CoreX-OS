<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * AT-392 — Johan's default is agency-wide ('all') for EVERY role, not the
 * per-role scope_defaults mapping (config/corex-permissions.php's own
 * super_admin/admin=all, branch_manager=branch, agent=own, viewer=branch)
 * that `--merge-defaults`/`--seed-defaults` would otherwise apply — that
 * mapping is right for every OTHER scoped permission but wrong here,
 * since Johan explicitly wants the SAME default for everyone.
 *
 * Without an explicit row, PermissionService::contactRentalHistoryScope()
 * still resolves correctly to 'all' at runtime (its own null-coalesce
 * default) — but the Role Manager UI's own scopeMatrix init
 * (role-manager.blade.php:847-861) shows an ungranted permission as
 * "None" regardless of what the backend would actually do, which is a
 * real, misleading discrepancy: an admin looking at this screen would
 * see "None" and reasonably believe access is off when it is actually
 * wide open. This backfill closes that gap by making the true default
 * an explicit, visible, editable row — exactly like every other
 * configured permission on this screen — rather than an invisible
 * code-level fallback the UI can't represent.
 *
 * Piggybacks on contacts.view's existing grants (55 agency/role pairs
 * plus the NULL-agency global template, at the time of writing) rather
 * than inventing a new "which roles should see this" judgement — any
 * role/agency that can already see contacts at all gets the new
 * contact-history permission too, at Johan's mandated 'all' scope,
 * matching "any user working with a contact can see the history."
 */
return new class extends Migration
{
    public function up(): void
    {
        $contactsViewGrants = RolePermission::where('permission_key', 'contacts.view')->get(['agency_id', 'role']);

        // BUILD_STANDARD.md §5a — a unique index on (role, permission_key,
        // agency_id) has no soft-delete awareness; a plain updateOrCreate()
        // (whose query respects SoftDeletes and so never finds a trashed
        // row) throws a raw duplicate-key error instead of restoring one.
        // withTrashed()->firstOrNew() + explicit restore() is the correct
        // pattern, not a naive updateOrCreate().
        foreach ($contactsViewGrants as $grant) {
            $row = RolePermission::withTrashed()->firstOrNew([
                'agency_id' => $grant->agency_id,
                'role' => $grant->role,
                'permission_key' => 'contact_rental_history.view',
            ]);
            if ($row->trashed()) {
                $row->restore();
            }
            $row->scope = 'all';
            $row->save();
        }
    }

    public function down(): void
    {
        RolePermission::where('permission_key', 'contact_rental_history.view')->delete();
    }
};
