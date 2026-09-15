<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 Phase 2 — Johan, design conversation: "qualifying formula - agency
 * can set this." Agency-configurable threshold for the affordability
 * calculation, sensible default applied in memory when no row exists (same
 * pattern as RentalApplicationChecklistConfig / RentalApplicationDocument
 * Requirement::checklistFor() elsewhere in this module — never a hardcoded
 * constant, never persisted until the agency actually opens the settings
 * screen and saves).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->unique('agency_id');

            // Default 3.0 — the common SA/international rule of thumb (gross
            // monthly income should be at least 3x the monthly rent).
            $table->decimal('income_to_rent_multiplier', 5, 2)->default(3.00);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_qualifying_settings');
    }
};
