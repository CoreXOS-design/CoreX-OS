<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MIC Work search (mic-work-tab.md 6.13): the property-file half of the search reads `tracked_properties.search_text` with a leading-wildcard LIKE, so the
 * index is only useful as a NARROW copy of the table to scan (agency_id + the text, no wide rows to fetch). Measured on QA1: the file lookup drops from
 * about 220 ms to about 100 ms with it. An index on prospecting_listings was tried and gave nothing, so it is not created.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tracked_properties', 'search_text') && ! collect(DB::select("SHOW INDEX FROM tracked_properties WHERE Key_name = 'idx_tp_agency_search'"))->isNotEmpty()) {
            DB::statement('ALTER TABLE tracked_properties ADD INDEX idx_tp_agency_search (agency_id, search_text)');
        }
    }

    public function down(): void
    {
        if (collect(DB::select("SHOW INDEX FROM tracked_properties WHERE Key_name = 'idx_tp_agency_search'"))->isNotEmpty()) {
            DB::statement('ALTER TABLE tracked_properties DROP INDEX idx_tp_agency_search');
        }
    }
};
