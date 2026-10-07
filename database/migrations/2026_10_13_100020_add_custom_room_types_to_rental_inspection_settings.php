<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §45.4 item 3 (Build I-2) — agency-addable
 * room types. A JSON list of {key, label, archived}, per agency. Nullable, no
 * backfill: null reads as "no custom types" (the read-time default pattern
 * every other column on this table uses). Only an agency's own settings row
 * is ever written; the 50 standard types stay in config/property-spaces.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rental_inspection_settings', 'custom_room_types')) {
            Schema::table('rental_inspection_settings', function (Blueprint $table) {
                $table->json('custom_room_types')->nullable()->after('room_type_walking_order');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_inspection_settings', 'custom_room_types')) {
            Schema::table('rental_inspection_settings', function (Blueprint $table) {
                $table->dropColumn('custom_room_types');
            });
        }
    }
};
