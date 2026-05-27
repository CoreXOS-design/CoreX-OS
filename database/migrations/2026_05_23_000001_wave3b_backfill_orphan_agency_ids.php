<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Wave 3b prerequisite: every tenant-owned row in the dev DB needs agency_id
 * before child tables can backfill from it. In this DB there is exactly one
 * agency (id=1), so all NULLs resolve to 1.
 *
 * Production guard: skip when more than one agency exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $agencyCount = DB::table('agencies')->count();
        if ($agencyCount !== 1) {
            // Multi-agency env — never blindly assign. Operator must backfill manually.
            return;
        }
        $aid = (int) DB::table('agencies')->value('id');

        $tables = [
            'users', 'branches', 'properties', 'contacts', 'deals',
            'presentations', 'contact_matches', 'calendar_events',
            'commission_ledger', 'fica_submissions', 'rmcp_versions',
            'rmcp_acknowledgements', 'employee_screenings',
            'whistleblow_complaints',
        ];
        foreach ($tables as $t) {
            if (!\Illuminate\Support\Facades\Schema::hasTable($t)) continue;
            if (!\Illuminate\Support\Facades\Schema::hasColumn($t, 'agency_id')) continue;
            DB::statement("UPDATE `{$t}` SET agency_id={$aid} WHERE agency_id IS NULL");
        }
    }

    public function down(): void
    {
        // No-op: cannot safely un-backfill.
    }
};
