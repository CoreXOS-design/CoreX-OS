<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §17 — the header block from Johan's real
 * paper form: everything above the room tables that this build never
 * captured. Meter readings are TEXT, not numeric — a body corporate
 * property's real form reads "BODY CORP" for both electricity and water,
 * and a numbers-only field would be unusable there. Keys/remotes are a
 * count PLUS a description, not a note — cc5's in-vs-out comparison work
 * reads the counts directly (a handed-over "3x SET KEYS" that comes back
 * as 2 is a real, chargeable deposit difference). property_type and
 * furnished_status are snapshotted onto the inspection itself, not read
 * live off `properties` — what was true (or confirmed) at THIS
 * inspection's moment, matching how every other fact in this table is a
 * point-in-time record, not a live join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->string('electricity_meter_reading', 100)->nullable()->after('type');
            $table->string('water_meter_reading', 100)->nullable()->after('electricity_meter_reading');
            // Free string, not an FK — agency-configurable via
            // PropertySettingItem::GROUP_FURNISHED_STATUS/GROUP_TYPE, same
            // convention Property::furnished_status/property_type already use.
            $table->string('furnished_status', 60)->nullable()->after('water_meter_reading');
            $table->string('property_type', 60)->nullable()->after('furnished_status');
            $table->unsignedInteger('keys_count')->nullable()->after('property_type');
            $table->string('keys_description', 191)->nullable()->after('keys_count');
            $table->unsignedInteger('remotes_count')->nullable()->after('keys_description');
            $table->string('remotes_description', 191)->nullable()->after('remotes_count');
            // §17 — only meaningfully populated on TYPE_OUT (the out form
            // carries the ORIGINAL move-in date, because the whole document
            // is a comparison against that moment); left nullable rather
            // than type-gated at the schema level, matching how this table
            // already handles type-specific fields elsewhere (e.g.
            // fault_report_deadline_at is TYPE_IN-only, enforced in code).
            $table->date('move_in_date_recorded')->nullable()->after('remotes_description');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->dropColumn([
                'electricity_meter_reading', 'water_meter_reading',
                'furnished_status', 'property_type',
                'keys_count', 'keys_description',
                'remotes_count', 'remotes_description',
                'move_in_date_recorded',
            ]);
        });
    }
};
