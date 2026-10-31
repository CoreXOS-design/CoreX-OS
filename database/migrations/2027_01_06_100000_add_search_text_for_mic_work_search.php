<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MIC Work tab search speed (mic-work-tab.md 6.13): ONE search column per table instead of five LIKEs per advert row and four per property file.
 *
 * `prospecting_listings.search_text` = address + suburb + agent + agency + advert ref; `tracked_properties.search_text` = street number + street name +
 * complex + suburb. They are STORED GENERATED columns, so MySQL keeps them current on EVERY write (Eloquent, raw DB::table updates, imports, merges) and
 * the ALTER back-fills every existing row once - no observer to forget, no drift. Same collation as the source columns (case-insensitive), spaces between
 * the parts (a one-word search cannot match across two fields), NULL parts skipped. The search remains a substring match, so every result the old
 * five-column search found is still found. Nothing is written by hand; no existing data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('prospecting_listings', 'search_text')) {
            DB::statement("ALTER TABLE prospecting_listings ADD COLUMN search_text VARCHAR(700) GENERATED ALWAYS AS (CONCAT_WS(' ', address, suburb, agent_name, agency_name, portal_ref)) STORED");
        }
        if (! Schema::hasColumn('tracked_properties', 'search_text')) {
            DB::statement("ALTER TABLE tracked_properties ADD COLUMN search_text VARCHAR(600) GENERATED ALWAYS AS (CONCAT_WS(' ', street_number, street_name, complex_name, suburb)) STORED");
        }
    }

    public function down(): void
    {
        foreach (['prospecting_listings', 'tracked_properties'] as $table) {
            if (Schema::hasColumn($table, 'search_text')) {
                DB::statement("ALTER TABLE {$table} DROP COLUMN search_text");
            }
        }
    }
};
