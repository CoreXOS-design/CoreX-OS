<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-agency thresholds for the prospecting Suggested-Action Chips rules engine
 * (Build E v2 — .ai/specs/build-e-suggested-action-chips-spec.md §8.1).
 *
 * Exactly one row per agency. All values are integers ≥ 1. Defaults match
 * spec §7.2 — the same values seeded by SuggestedActionThresholdsSeeder.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('suggested_action_thresholds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();

            $table->unsignedSmallInteger('stale_listing_days')->default(14);          // R1
            $table->unsignedSmallInteger('expiry_warning_hours')->default(6);         // R2
            $table->unsignedSmallInteger('outcome_overdue_days')->default(2);         // R3 (lower bound — days since pitch)
            $table->unsignedSmallInteger('outcome_stale_days')->default(30);          // R3 (upper bound)
            $table->unsignedSmallInteger('follow_up_days')->default(7);               // R4
            $table->unsignedSmallInteger('pitch_recency_days')->default(7);           // R5, R6
            $table->unsignedSmallInteger('high_value_strong_min')->default(3);        // R5
            $table->unsignedSmallInteger('stock_repitch_days')->default(30);          // R7
            $table->unsignedSmallInteger('colleague_claim_stale_days')->default(21);  // R8
            $table->unsignedSmallInteger('investigate_mid_min')->default(5);          // R9

            $table->timestamps();
            $table->softDeletes();

            $table->unique('agency_id', 'unq_suggested_action_thresholds_agency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suggested_action_thresholds');
    }
};
