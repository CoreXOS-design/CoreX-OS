<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §46 — the two agency settings for signing an inspection by link. Nullable,
 * read-time-default (RentalInspectionSetting::DEFAULT_SIGNING_LINK_*): null means "use the default" (enabled, 30
 * days), never a default baked into the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->boolean('signing_link_enabled')->nullable();
            $table->unsignedSmallInteger('signing_link_expiry_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn(['signing_link_enabled', 'signing_link_expiry_days']);
        });
    }
};
