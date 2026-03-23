<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Address visibility toggles for PP syndication
            $table->boolean('pp_hide_street_name')->default(false)->after('pp_listing_last_synced_at');
            $table->boolean('pp_hide_street_number')->default(false)->after('pp_hide_street_name');
            $table->boolean('pp_hide_complex_name')->default(false)->after('pp_hide_street_number');
            $table->boolean('pp_hide_unit_number')->default(false)->after('pp_hide_complex_name');

            // Rental price type for commercial rentals (PerMonth, PerSqm)
            $table->string('rental_price_type')->nullable()->after('pp_hide_unit_number');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'pp_hide_street_name',
                'pp_hide_street_number',
                'pp_hide_complex_name',
                'pp_hide_unit_number',
                'rental_price_type',
            ]);
        });
    }
};
