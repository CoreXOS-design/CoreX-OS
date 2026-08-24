<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC SPEED FIX ROUND 2, Option 3 (Johan, 2026-08-22) — every hot MIC-page
 * rollup query against prospecting_buyer_matches filters `dismissed_at IS
 * NULL` alongside agency_id/prospecting_listing_id/contact_id, but no
 * existing index includes dismissed_at (pbm_agency_contact_idx,
 * pbm_agency_listing_idx, pbm_listing_score, pbm_contact_score all stop one
 * column short). MySQL narrows on the leading column then filters
 * dismissed_at row-by-row. Measured live offenders this covers:
 *   - computeSnapshotKpis() $matchAgg: agency_id + dismissed_at + score,
 *     COUNT(DISTINCT contact_id)/COUNT(DISTINCT prospecting_listing_id)
 *   - computeActionPresetCounts() $strongMatches: agency_id + dismissed_at
 *     + score, GROUP BY prospecting_listing_id
 *   - $micBuyerIds: agency_id + dismissed_at, DISTINCT contact_id
 *   - $matchedListingCount: joined on prospecting_listing_id, filtered by
 *     dismissed_at on the matches side
 * Same category as the prospecting_listings recency-sort indexes shipped
 * earlier today: plain composite secondary indexes, ADD INDEX only —
 * ALGORITHM=INPLACE, LOCK=NONE, no query rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_buyer_matches', function (Blueprint $table) {
            $table->index(['agency_id', 'dismissed_at', 'score', 'prospecting_listing_id'], 'pbm_agency_dismissed_score_listing_idx');
            $table->index(['agency_id', 'dismissed_at', 'contact_id'], 'pbm_agency_dismissed_contact_idx');
            $table->index(['prospecting_listing_id', 'dismissed_at'], 'pbm_listing_dismissed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_buyer_matches', function (Blueprint $table) {
            $table->dropIndex('pbm_agency_dismissed_score_listing_idx');
            $table->dropIndex('pbm_agency_dismissed_contact_idx');
            $table->dropIndex('pbm_listing_dismissed_idx');
        });
    }
};
