<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inventory.md §8 — the move-out disposition vocabulary
 * (present/short/damaged/missing), agency-configurable. A genuinely
 * separate settings row from RentalInspectionSetting, NOT an extension of
 * it: cc2's condition_states/requires_notes work on that model is
 * unlanded (a different, in-flight branch) at the time this was built —
 * touching that file now would risk a collision with work this session
 * cannot see. The {key, label, requires_notes} SHAPE mirrors what cc2
 * built there, per cc5's explicit recommendation; the STORAGE is this
 * table's own column, on this table's own model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inventory_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('disposition_presets')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inventory_settings');
    }
};
