<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §3.1/§3.2 — two or more observations for
 * the SAME item, WITHIN THE SAME inspection, that disagree on condition.
 * Scoped to "same inspection" deliberately, so ordinary wear-and-tear
 * between check-in and check-out never registers as a discrepancy (§0.4).
 * An inspection cannot complete while any linked discrepancy has
 * resolved_at IS NULL (§11 acceptance criteria).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_inspection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_inspection_item_id')
                ->constrained(indexName: 'ri_discrepancies_item_fk')->cascadeOnDelete();
            // explicit short constraint name — auto-generated one exceeds MySQL's 64-char limit.

            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->foreignId('accepted_observation_id')->nullable()
                ->constrained('rental_inspection_observations')->nullOnDelete();
            // set on resolution — which observation is now accepted as current

            $table->timestamps();

            // explicit short names — auto-generated ones exceed MySQL's 64-char limit.
            $table->index(['agency_id', 'rental_inspection_id'], 'ri_discrepancies_agency_inspection_idx');
            $table->index(['rental_inspection_item_id', 'resolved_at'], 'ri_discrepancies_item_resolved_idx');
        });

        Schema::create('rental_inspection_discrepancy_observations', function (Blueprint $table) {
            // pivot: which observations are in conflict
            // explicit short constraint names on both — auto-generated ones exceed
            // MySQL's 64-char limit on this long pivot table name.
            $table->foreignId('discrepancy_id')
                ->constrained('rental_inspection_discrepancies', indexName: 'ri_disc_obs_discrepancy_fk')
                ->cascadeOnDelete();
            $table->foreignId('observation_id')
                ->constrained('rental_inspection_observations', indexName: 'ri_disc_obs_observation_fk')
                ->cascadeOnDelete();
            $table->primary(['discrepancy_id', 'observation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_discrepancy_observations');
        Schema::dropIfExists('rental_inspection_discrepancies');
    }
};
