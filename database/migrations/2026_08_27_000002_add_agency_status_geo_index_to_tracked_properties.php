<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Map perf (Johan, 2026-08-27) — the map's tracked_properties layer count+select
 * pair examined 8,764 rows via an index-merge of idx_tracked_props_agency_status
 * and idx_tracked_props_promoted, then applied the latitude/longitude bounding
 * box as a post-filter — neither existing index carries the geo columns, so
 * MySQL never narrows by bounds before touching rows. Measured 6xx ms warm on
 * this query pair alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_properties', function (Blueprint $table) {
            $table->index(
                ['agency_id', 'status', 'promoted_to_property_id', 'latitude', 'longitude'],
                'idx_tp_agency_status_promoted_geo'
            );
        });
    }

    public function down(): void
    {
        Schema::table('tracked_properties', function (Blueprint $table) {
            $table->dropIndex('idx_tp_agency_status_promoted_geo');
        });
    }
};
