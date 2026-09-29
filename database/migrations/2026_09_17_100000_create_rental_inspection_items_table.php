<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §3.2 — an Item is the actual thing being
 * watched over time (a whole space or something finer inside it). Property-
 * scoped, NEVER lease-scoped — a physical space outlives any one tenancy,
 * which is what lets an out-inspection carry forward a fault reported under
 * a PREVIOUS tenant (§0.2). No dependency on `leases` — can migrate
 * independently, before or after it.
 *
 * `is_retired`, not `deleted_at` — §3.3: an item cannot become invisible to
 * history-reading queries once any observation references it. is_retired
 * only blocks NEW observations against it; history stays queryable
 * regardless. Stricter than the standard soft-delete floor, deliberately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 10);
            // space | meter
            $table->string('label', 191);
            $table->string('space_type', 60)->nullable();
            // references config('property-spaces.all_space_types') for label consistency
            // only — never constrained to it (§0.6: inspection spaces differ from the
            // advertised room list; free-text label always wins display).

            $table->boolean('is_retired')->default(false);
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
            // No deleted_at — see docblock above. is_retired is the only removal state.

            $table->index(['agency_id', 'property_id', 'is_retired']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_items');
    }
};
