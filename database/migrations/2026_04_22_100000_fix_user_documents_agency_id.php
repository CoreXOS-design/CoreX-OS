<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair user_documents rows where agency_id is NULL or mismatched
 * with the owning user's agency_id. Without correct agency_id the
 * AgencyScope global scope hides these rows, causing the Documents
 * tab to show "Missing" even though the row exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Set agency_id on user_documents to match the owning user's agency_id
        // wherever they are null or out of sync.
        DB::statement('
            UPDATE user_documents ud
            JOIN users u ON ud.user_id = u.id
            SET ud.agency_id = u.agency_id
            WHERE u.agency_id IS NOT NULL
              AND (ud.agency_id IS NULL OR ud.agency_id != u.agency_id)
        ');
    }

    public function down(): void
    {
        // No revert — data repair only
    }
};
