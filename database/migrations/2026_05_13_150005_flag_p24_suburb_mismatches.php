<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mark every existing property whose free-text `suburb` doesn't link to a
 * known P24 suburb as `p24_suburb_mismatch = true`. The property edit form
 * will then surface a banner requiring the user to pick a P24-recognised
 * suburb before they can save edits (Pass 6 enforcement).
 *
 * NB: requires p24_suburbs to be populated first (run `p24:sync-locations`
 * before this migration if data isn't there yet). If no suburbs exist we
 * skip the flagging so we don't mark every property as mismatched.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('p24_suburbs') || !Schema::hasColumn('properties', 'p24_suburb_mismatch')) {
            return;
        }

        $suburbCount = DB::table('p24_suburbs')->whereNotNull('p24_city_id')->count();
        if ($suburbCount === 0) {
            // No P24 data yet — skip. The Refresh button or monthly sync will
            // populate p24_suburbs; flagging can be re-run via the artisan
            // command after that.
            return;
        }

        // Properties with a NULL or unmatched free-text suburb get flagged.
        DB::statement("
            UPDATE properties p
            LEFT JOIN p24_suburbs s
                ON LOWER(TRIM(p.suburb)) = LOWER(s.name)
                AND s.p24_city_id IS NOT NULL
            SET p.p24_suburb_mismatch = CASE
                WHEN p.p24_suburb_id IS NOT NULL THEN 0
                WHEN s.id IS NULL THEN 1
                ELSE 0
            END
            WHERE p.deleted_at IS NULL
        ");

        // Auto-link clean matches so users with unambiguous legacy suburbs
        // don't have to re-pick. Skip ambiguous names (suburb exists in
        // multiple cities) — those are left flagged.
        DB::statement("
            UPDATE properties p
            JOIN (
                SELECT LOWER(TRIM(name)) AS lname, MIN(id) AS id, COUNT(*) AS cnt
                FROM p24_suburbs
                WHERE p24_city_id IS NOT NULL AND deleted_at IS NULL
                GROUP BY lname
                HAVING COUNT(*) = 1
            ) uniq ON LOWER(TRIM(p.suburb)) = uniq.lname
            JOIN p24_suburbs s ON s.id = uniq.id
            JOIN p24_cities c ON c.id = s.p24_city_id
            SET p.p24_suburb_id = s.id,
                p.p24_city_id = c.id,
                p.p24_province_id = c.p24_province_id,
                p.p24_suburb_mismatch = 0
            WHERE p.p24_suburb_id IS NULL
        ");
    }

    public function down(): void
    {
        // Non-destructive: leave p24_suburb_mismatch flags in place on rollback.
    }
};
