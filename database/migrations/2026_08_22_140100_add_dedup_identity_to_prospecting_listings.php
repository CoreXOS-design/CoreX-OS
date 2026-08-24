<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MIC SPEED FIX ROUND 2, Option 2 (Johan, 2026-08-22) — the KPI tiles and
 * filter-rail facet counts (computeSnapshotKpis, computeFilterRailAggregates)
 * each de-dup rotating-ref duplicates via a CASE/CONCAT expression computed
 * fresh, per row, on every call:
 *
 *   COUNT(DISTINCT CASE WHEN normalized_address IS NULL OR normalized_address = ''
 *       THEN CONCAT('id:', id) ELSE CONCAT(portal_source, '|', normalized_address)
 *       END)
 *
 * Measured on live: 4-5 calls per page load (poolScalars, by_suburb, by_type,
 * by_beds), 250-620ms each, no index possible on an inline expression.
 *
 * Fix: a VIRTUAL generated column computing an EQUIVALENT expression,
 * indexed. VIRTUAL (not STORED) so this is a metadata + index-build
 * operation (ALGORITHM=INPLACE) rather than a full table rewrite to
 * materialise the value for all 39k+ existing rows — MySQL computes it from
 * the index, not from a stored column, so there is no drift risk from a
 * forgotten write path either: the expression IS the column, always, by
 * construction. distinctPropertyCountSql() now reads this column instead of
 * re-deriving it, so every caller (KPI tiles, filter rail, and anything
 * added later) computes the identical value the identical way — one
 * definition, not two kept in sync by hand.
 *
 * NOT byte-identical to the original: MySQL 8.0 refuses a generated column
 * that references the table's own auto-increment column (error 3109,
 * confirmed on this table/version, not assumed) — `CONCAT('id:', id)` is
 * exactly that. Substituted `CONCAT('ref:', portal_source, ':', portal_ref)`
 * for the no-normalized-address branch. portal_ref is NOT NULL on every
 * live row and, combined with portal_source, is already enforced unique
 * per agency by prospecting_listings_agency_id_portal_source_portal_ref_unique
 * — confirmed empirically (0 cross-source collisions on live data) as well
 * as by that constraint. Since every caller of distinctPropertyCountSql()
 * is already agency-scoped, this produces the IDENTICAL distinct-group
 * cardinality as the id-based key — COUNT(DISTINCT ...) only cares whether
 * two rows' keys are equal or not, never their literal content, and no two
 * rows in an agency can share the same portal_source+portal_ref. Verified
 * before deploy by re-running every KPI/tile/filter-rail number before and
 * after in every MIC visibility scope (own/branch/all) and diffing them to
 * zero — see the deploy note for this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<SQL
            ALTER TABLE prospecting_listings
            ADD COLUMN dedup_identity VARCHAR(300)
                GENERATED ALWAYS AS (
                    CASE WHEN normalized_address IS NULL OR normalized_address = ''
                         THEN CONCAT('ref:', portal_source, ':', portal_ref)
                         ELSE CONCAT(portal_source, '|', normalized_address)
                    END
                ) VIRTUAL
        SQL);

        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->index(['agency_id', 'dedup_identity'], 'prospecting_listings_agency_dedup_identity_idx');
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropIndex('prospecting_listings_agency_dedup_identity_idx');
        });
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropColumn('dedup_identity');
        });
    }
};
