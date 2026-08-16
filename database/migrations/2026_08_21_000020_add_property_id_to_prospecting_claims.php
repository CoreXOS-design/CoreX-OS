<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pitch Now #4 — key the permanent pitch claim on the PROPERTY, not just the
 * prospecting listing. Portal refs rotate (the same property returns under a new
 * ref), so a listing-only claim lets a claimed property be re-pitched under a
 * fresh ref. Adding property_id lets the MIC read treat a property as claimed
 * regardless of which rotating ref surfaced.
 *
 * Additive: prospecting_listing_id stays for history. Backfills existing claims
 * from their listing's matched property so current claims keep hiding correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_claims', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id')->nullable()->after('prospecting_listing_id');
            $table->index(['agency_id', 'property_id', 'is_active'], 'pc_agency_property_active_idx');
            $table->foreign('property_id')->references('id')->on('properties')->nullOnDelete();
        });

        // Backfill: existing claims inherit the property their listing is matched
        // to. JOIN properties so only property ids that actually exist are written
        // (FK-safe; soft-deleted properties still have their row). Keep all rows.
        DB::statement('
            UPDATE prospecting_claims c
            JOIN prospecting_listings pl ON pl.id = c.prospecting_listing_id
            JOIN properties p ON p.id = pl.matched_property_id
            SET c.property_id = pl.matched_property_id
            WHERE c.property_id IS NULL
              AND pl.matched_property_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::table('prospecting_claims', function (Blueprint $table) {
            $table->dropForeign(['property_id']);
            $table->dropIndex('pc_agency_property_active_idx');
            $table->dropColumn('property_id');
        });
    }
};
