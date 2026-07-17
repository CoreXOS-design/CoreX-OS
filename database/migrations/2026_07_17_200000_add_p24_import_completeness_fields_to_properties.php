<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture the P24 listings-CSV fields that the importer was dropping.
 *
 * The audit of run 10 (.ai/audits/p24-import-run10-audit-2026-07-17.md) found the
 * parser/confirm path silently dropped a set of columns the CSV carries:
 * OccupationDate, SourceReference, LightstoneId, DevelopmentId, EyeSpy360Id, and
 * the erf/floor area UNITS (without which a "2 ha" erf would be stored as "2 m²").
 * These columns make every P24 CSV field land somewhere queryable — nothing from
 * the export is thrown away. (SuburbId → existing `p24_suburb_id`; matterport /
 * youtube / virtual tour already had homes.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->date('occupation_date')->nullable()->after('expiry_date');
            $table->string('source_reference', 191)->nullable()->after('p24_ref');
            $table->string('lightstone_id', 64)->nullable()->after('source_reference');
            $table->string('development_id', 64)->nullable()->after('lightstone_id');
            $table->string('eyespy_360_id', 191)->nullable()->after('matterport_id');
            // Original P24 area units, kept so a normalised m² value is auditable
            // back to what the export actually said (m²/ha/acres/…).
            $table->string('erf_area_unit', 16)->nullable()->after('erf_size_m2');
            $table->string('floor_area_unit', 16)->nullable()->after('size_m2');

            $table->index('source_reference', 'properties_source_reference_index');
            $table->index('lightstone_id', 'properties_lightstone_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('properties_source_reference_index');
            $table->dropIndex('properties_lightstone_id_index');
            $table->dropColumn([
                'occupation_date', 'source_reference', 'lightstone_id',
                'development_id', 'eyespy_360_id', 'erf_area_unit', 'floor_area_unit',
            ]);
        });
    }
};
