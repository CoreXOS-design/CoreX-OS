<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-09-21, property 5792: "then we can also allow sorting of
 * rooms. currently it just adds rooms at the bottom... theres no logical
 * way to line up the rooms as the inspection goes." An agency-editable,
 * ORDERED list of every space type (config('property-spaces.all_space_types')),
 * the order an inspector should walk them in. JSON so it stores a full
 * permutation of the type list; null resolves to
 * RentalInspectionSetting::DEFAULT_ROOM_TYPE_WALKING_ORDER — same
 * read-time-default pattern as this table's other columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->json('room_type_walking_order')->nullable()->after('room_type_item_defaults');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('room_type_walking_order');
        });
    }
};
