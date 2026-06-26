<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Part 3 — suburb centroids for the Buyer-Demand heatmap. p24_suburbs had no
     * coordinates; one-time geocode (map:geocode-suburbs) fills these from the average
     * lat/lng of already-geocoded properties per suburb (free), with an AddressResolver
     * fallback. The heatmap places per-suburb demand intensity at [latitude, longitude].
     */
    public function up(): void
    {
        if (Schema::hasColumn('p24_suburbs', 'latitude')) {
            return;
        }

        Schema::table('p24_suburbs', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('p24_city_id');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('centroid_source', 24)->nullable()->after('longitude'); // properties_avg | geocoder
            $table->timestamp('centroid_geocoded_at')->nullable()->after('centroid_source');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('p24_suburbs', 'latitude')) {
            return;
        }

        Schema::table('p24_suburbs', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'centroid_source', 'centroid_geocoded_at']);
        });
    }
};
