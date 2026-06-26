<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-agency map settings — fixes the multi-tenancy bug where HFC's KZN South
     * Coast box / center / zoom were hardcoded for every agency. One row per agency;
     * NULL columns fall back to config('map.defaults.*'). Mirrors AgencyContactSettings.
     */
    public function up(): void
    {
        if (Schema::hasTable('agency_map_settings')) {
            return;
        }

        Schema::create('agency_map_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();

            // Initial view.
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lng', 10, 7)->nullable();
            $table->unsignedTinyInteger('default_zoom')->nullable();

            // Fit-to bounds box.
            $table->decimal('bounds_north', 10, 7)->nullable();
            $table->decimal('bounds_south', 10, 7)->nullable();
            $table->decimal('bounds_east', 10, 7)->nullable();
            $table->decimal('bounds_west', 10, 7)->nullable();

            // Default sold-date window for the agency Sold layer (3mo|6mo|12mo|24mo|all).
            $table->string('default_sold_window', 8)->nullable();

            // Optional per-agency cap overrides (json) — null falls back to config caps.
            $table->json('layer_caps')->nullable();

            $table->timestamps();
            $table->unique('agency_id', 'agency_map_settings_agency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_map_settings');
    }
};
