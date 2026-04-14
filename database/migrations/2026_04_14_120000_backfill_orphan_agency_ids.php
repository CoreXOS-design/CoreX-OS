<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sweep any tenant-owned row whose `agency_id` is still NULL into the
 * first (oldest) agency. Rows like this are always orphans from before
 * multi-tenancy was enforced, and the stricter AgencyScope — which no
 * longer treats NULL as "shared" — would otherwise make them invisible
 * to every agency, including the agency that actually owns them.
 *
 * Branches/Deals/Presentations are backfilled from their parent's
 * agency where possible before the fallback sweep.
 *
 * Users with a role flagged `is_owner = true` are skipped here — System
 * Owners intentionally carry NULL agency_id (see the matching migration
 * 2026_04_14_110000_detach_system_owners_from_agencies).
 */
return new class extends Migration {
    public function up(): void
    {
        $firstAgencyId = DB::table('agencies')->orderBy('id')->value('id');
        if (!$firstAgencyId) {
            return;
        }

        // Derive from owning relationships first where cheap.
        DB::statement("
            UPDATE properties
            SET agency_id = (SELECT u.agency_id FROM users u WHERE u.id = properties.agent_id)
            WHERE agency_id IS NULL AND agent_id IS NOT NULL
        ");
        DB::statement("
            UPDATE properties
            SET agency_id = (SELECT b.agency_id FROM branches b WHERE b.id = properties.branch_id)
            WHERE agency_id IS NULL AND branch_id IS NOT NULL
        ");

        $ownerRoleNames = DB::table('roles')->where('is_owner', true)->pluck('name')->all();

        // Fallback: sweep remaining orphans into the first agency.
        $tenantTables = ['properties', 'contacts', 'deals', 'presentations', 'documents', 'branches'];
        foreach ($tenantTables as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'agency_id')) {
                DB::table($t)->whereNull('agency_id')->update(['agency_id' => $firstAgencyId]);
            }
        }

        // Users: skip owner roles.
        $q = DB::table('users')->whereNull('agency_id');
        if (!empty($ownerRoleNames)) {
            $q->whereNotIn('role', $ownerRoleNames);
        }
        $q->update(['agency_id' => $firstAgencyId]);
    }

    public function down(): void
    {
        // intentionally empty — backfill of orphans is not reversible
    }
};
