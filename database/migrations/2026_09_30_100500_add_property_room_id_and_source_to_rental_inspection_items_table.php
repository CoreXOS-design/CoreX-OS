<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2 of the inspections rework. `property_room_id` replaces the
 * free-text "Room — Facet" label convention used for last night's
 * hand-built walkthrough data (property 21044) with a real join: a
 * kind='space' facet item (e.g. "Ceiling") now belongs to a PropertyRoom
 * instance ("Bedroom 2") instead of encoding the room in its own label
 * string. `kind='meter'` rows never set this — meters aren't rooms.
 *
 * `space_type` stays on the table unchanged, for backward compatibility
 * with rows created before this column existed and for meters (which have
 * no room to derive a type from). New space-kind rows derive their type
 * through property_room_id -> PropertyRoom.type instead of duplicating it.
 *
 * `source` mirrors property_rooms' own column — advertising_space |
 * advertising_feature | manual — traceability for the seeder, never a
 * behavior gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_items', function (Blueprint $table) {
            $table->foreignId('property_room_id')->nullable()->after('property_id')
                ->constrained('property_rooms')->nullOnDelete();
            $table->string('source', 30)->default('manual')->after('space_type');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_room_id');
            $table->dropColumn('source');
        });
    }
};
