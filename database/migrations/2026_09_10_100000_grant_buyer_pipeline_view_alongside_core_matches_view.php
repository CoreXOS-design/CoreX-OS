<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * AT-401 — buyer_pipeline.view is a brand-new permission (the sales-side
 * board at /corex/command-center/buyers/pipeline has never had one; this
 * key gates ONLY the new Rentals -> Rental Pipeline entry point). A new
 * permission with no grant rows renders as invisible/inaccessible to
 * everyone, which would mean nobody sees the new nav entry the same day it
 * ships — the exact failure mode non-negotiable #2 exists to prevent.
 *
 * Piggybacks on core_matches.view's existing grants (same target audience —
 * agents/managers who work buyer leads) rather than inventing a new
 * judgement about who should see this, mirroring the exact approach already
 * used successfully this session for contact_rental_history.view (see
 * 2026_09_10_040000_grant_contact_rental_history_view_alongside_contacts_view.php).
 * Scope 'all' (agency-wide) by default — the sales-side board today has no
 * per-role restriction at all (no permission gate whatsoever), so 'all' is
 * the closest match to current real-world access breadth, not a narrowing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $coreMatchesViewGrants = RolePermission::where('permission_key', 'core_matches.view')->get(['agency_id', 'role']);

        // BUILD_STANDARD.md §5a — withTrashed()->firstOrNew() + explicit
        // restore(), never a plain updateOrCreate() that can't see a
        // previously soft-deleted row and collides with the unique index.
        foreach ($coreMatchesViewGrants as $grant) {
            $row = RolePermission::withTrashed()->firstOrNew([
                'agency_id' => $grant->agency_id,
                'role' => $grant->role,
                'permission_key' => 'buyer_pipeline.view',
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
        RolePermission::where('permission_key', 'buyer_pipeline.view')->delete();
    }
};
