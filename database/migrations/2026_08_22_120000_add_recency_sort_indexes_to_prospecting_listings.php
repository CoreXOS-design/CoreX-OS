<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC SPEED FIX (Johan, 2026-08-22) — the Market Intelligence work screen's
 * default sort (last_seen_at desc) and first_seen_at both filesort the
 * agency's full matching row set: EXPLAIN on live showed
 * `rows=19862 Extra=Using where; Using filesort` for the exact default
 * query (agency_id = ? AND deleted_at IS NULL ORDER BY last_seen_at DESC).
 * price and suburb sorts already have working single-column indexes and
 * do not filesort — this migration only covers the two that do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->index(['agency_id', 'deleted_at', 'last_seen_at'], 'prospecting_listings_agency_deleted_last_seen_idx');
            $table->index(['agency_id', 'deleted_at', 'first_seen_at'], 'prospecting_listings_agency_deleted_first_seen_idx');
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropIndex('prospecting_listings_agency_deleted_last_seen_idx');
            $table->dropIndex('prospecting_listings_agency_deleted_first_seen_idx');
        });
    }
};
