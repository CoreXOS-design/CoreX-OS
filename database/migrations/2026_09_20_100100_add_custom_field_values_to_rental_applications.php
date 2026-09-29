<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-application-field-config.md §7 — where a captured custom
 * field answer lives: keyed by the custom field's own `key`
 * (rental_application_custom_fields.key), never a new real DB column per
 * field — avoids a schema-per-tenant explosion as agencies add fields over
 * time. A retired custom field's answer stays in this JSON exactly as
 * captured; retiring the DEFINITION never touches an application that
 * already answered it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->json('custom_field_values')->nullable()->after('field_config_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropColumn('custom_field_values');
        });
    }
};
