<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §49 — Johan's ruling of 8 Oct 2026: whether an inspection needs the three signatures
 * (every tenant, the landlord, the agent) before it can be completed is the AGENCY's own setting, per inspection type.
 * Nullable, read-time-default (RentalInspectionSetting::DEFAULT_SIGNATURES_REQUIRED): null means "use the default"
 * (In, Out and Interim required; Routine optional), never a default baked into the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->boolean('signatures_required_in')->nullable();
            $table->boolean('signatures_required_out')->nullable();
            $table->boolean('signatures_required_interim')->nullable();
            $table->boolean('signatures_required_routine')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn(['signatures_required_in', 'signatures_required_out', 'signatures_required_interim', 'signatures_required_routine']);
        });
    }
};
