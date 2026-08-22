<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC SPEED FIX ROUND 2 — Option 2 REVERTED (Johan, 2026-08-22).
 *
 * Measured, not assumed: the dedup_identity VIRTUAL generated column
 * (2026_08_22_140100) does NOT deliver the hoped-for speedup. Timed the
 * raw SQL directly (old inline CASE/CONCAT vs. new COUNT(DISTINCT
 * dedup_identity)) on two real data scales:
 *   - QA1 (12,692 rows):    72-79ms either way, no measurable difference.
 *   - Staging (39,665 rows): NEW is consistently ~10-15% SLOWER than OLD
 *     (274ms vs 255ms, 300ms vs 270ms, 251ms vs 214ms) — EXPLAIN shows
 *     the by_suburb/by_type/by_beds aggregate queries never use the new
 *     index at all (index_merge/intersect + filesort instead), so the
 *     virtual column adds a layer of indirection with no offsetting read
 *     benefit.
 *
 * Also found and would need fixing regardless: distinctPropertyCountSql()
 * had a live bug (`if ($alias = ...)` treats the valid empty-string
 * "unqualified column" result as falsy, so it silently always fell back
 * to the old expression) — moot now since the whole approach is reverted,
 * but recorded here so it isn't rediscovered as a mystery later if this
 * column is ever revisited.
 *
 * Reconciliation was PROVEN perfect in both directions (byte-identical
 * KPI/tile/filter-rail output, every MIC scope, before vs after, with the
 * bug fixed and the code path genuinely active) — this is not a
 * correctness revert, it is a "does not deliver the benefit it was built
 * for" revert. Shipping added schema complexity for zero (or negative)
 * measured speed is not worth it.
 *
 * Option 3 (prospecting_buyer_matches dismissed_at indexes, migration
 * 2026_08_22_140000) is NOT reverted — proven to deliver a genuine ~35%
 * improvement on the measured query shape when actually used (202.8ms
 * forced vs 317-324ms on the old index), even though MySQL's optimizer
 * doesn't always choose it automatically without a query-level hint
 * (not added tonight — that is an application-code change needing its
 * own verification, out of scope for a straight index revert).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropIndex('prospecting_listings_agency_dedup_identity_idx');
        });
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropColumn('dedup_identity');
        });
    }

    public function down(): void
    {
        // Intentionally not reversible back to the reverted state — if
        // this column is wanted again, revisit the design (see the
        // migration this reverts, 2026_08_22_140100) rather than
        // resurrecting the exact same approach that measured worse.
    }
};
