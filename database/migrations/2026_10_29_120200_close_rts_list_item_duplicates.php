<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 — the day/property unique index on rts_list_items is on tracked_property_id, and MySQL treats
 * NULLs as distinct, so two rows for the same listing or property with no tracked property could both
 * be stored. The builder now creates the tracked property before a row is written, but the database
 * must refuse the duplicate on its own.
 *
 * `property_key` is a stored generated column: for a live (not archived) row, the tracked property when
 * there is one, else the listing, else the property; NULL for an archived row or one that identifies
 * nothing (which the builder never writes). A unique index over (agency, track, user, day, key) closes
 * the gap. The original index stays.
 *
 * Any existing duplicate is archived first (soft delete - the newest stays), which frees its key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rts_list_items', 'property_key')) {
            return;
        }

        DB::statement(
            "ALTER TABLE rts_list_items ADD COLUMN property_key VARCHAR(24) AS (
                CASE
                    WHEN deleted_at IS NOT NULL THEN NULL
                    WHEN tracked_property_id IS NOT NULL THEN CONCAT('t', tracked_property_id)
                    WHEN prospecting_listing_id IS NOT NULL THEN CONCAT('l', prospecting_listing_id)
                    WHEN property_id IS NOT NULL THEN CONCAT('p', property_id)
                    ELSE NULL
                END
            ) STORED AFTER property_id"
        );

        DB::statement(
            "UPDATE rts_list_items a
               JOIN rts_list_items b
                 ON a.agency_id = b.agency_id AND a.track = b.track AND a.user_id = b.user_id
                AND a.list_date = b.list_date AND a.property_key = b.property_key AND a.id < b.id
                SET a.deleted_at = NOW()
              WHERE a.property_key IS NOT NULL"
        );

        Schema::table('rts_list_items', function ($table) {
            $table->unique(['agency_id', 'track', 'user_id', 'list_date', 'property_key'], 'rts_list_items_day_key_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('rts_list_items', 'property_key')) {
            Schema::table('rts_list_items', function ($table) {
                $table->dropUnique('rts_list_items_day_key_unique');
            });
            DB::statement('ALTER TABLE rts_list_items DROP COLUMN property_key');
        }
    }
};
