<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §55 — the agency's standard room list per kind of
 * property (house / flat / townhouse / anything else). A JSON map
 * {family: [{type, count}]}. Nullable, no backfill: null reads as the built-in
 * defaults (the read-time default pattern every other column on this table uses),
 * so every agency, including one created after this migration, starts with a sensible list.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rental_inspection_settings', 'room_templates')) {
            Schema::table('rental_inspection_settings', function (Blueprint $table) {
                $table->json('room_templates')->nullable()->after('custom_room_types');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_inspection_settings', 'room_templates')) {
            Schema::table('rental_inspection_settings', function (Blueprint $table) {
                $table->dropColumn('room_templates');
            });
        }
    }
};
