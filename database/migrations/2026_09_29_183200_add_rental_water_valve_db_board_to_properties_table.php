<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rentals-faults-work-orders.md §3 — checked directly against the
 * code first (spaces_json / property_rooms / rental_inspection_items):
 * none of them carry a location description or photo for a specific
 * utility control point. Two new nullable field pairs, sitting with the
 * property's other rental-tab fields (deposit_amount, admin_fee,
 * rental_no_approval_spend_threshold).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('rental_main_water_valve_location', 255)->nullable()
                ->after('rental_no_approval_spend_threshold');
            $table->string('rental_main_water_valve_photo_path')->nullable()
                ->after('rental_main_water_valve_location');
            $table->string('rental_db_board_location', 255)->nullable()
                ->after('rental_main_water_valve_photo_path');
            $table->string('rental_db_board_photo_path')->nullable()
                ->after('rental_db_board_location');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'rental_main_water_valve_location',
                'rental_main_water_valve_photo_path',
                'rental_db_board_location',
                'rental_db_board_photo_path',
            ]);
        });
    }
};
