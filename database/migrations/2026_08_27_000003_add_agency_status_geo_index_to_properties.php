<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Map perf (Johan, 2026-08-27) — the map's hfc_listings (Active Stock) layer
 * filters properties by agency_id + status + a lat/lng bounding box, but
 * properties has no composite index covering that shape — MySQL falls back
 * to properties_agency_ext_uq (agency_id only), scanning ~4,100 rows per
 * request and applying status + bounds as a post-filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->index(['agency_id', 'status', 'latitude', 'longitude'], 'idx_properties_agency_status_geo');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('idx_properties_agency_status_geo');
        });
    }
};
