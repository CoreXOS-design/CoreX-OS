<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 Phase 2 — Johan's own design-conversation words: "application gets
 * returned, agent open application - sees application and supporting docs
 * on left panel of screen... then have a place on the right panel to input
 * things like - income, salary / etc etc... doing the calcs to the bottom
 * to see if tenant qualifies." Explicitly listed as OUT of Phase 1 in the
 * spec ("Assessment split-screen with agency-configurable affordability
 * calculator and approval routing") — this is that later phase, now
 * authorised.
 *
 * One row per rental application. Every field nullable (BUILD_STANDARD §2
 * — the agent may fill this in over several visits; nothing here may ever
 * block a save) and every write is an upsert on blur/change from the review
 * screen, not a single final submit — "nothing typed may ever be lost" is
 * an autosave requirement, not a UI nicety.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_application_id')->constrained()->cascadeOnDelete();
            $table->unique('rental_application_id');

            // Affordability inputs — the agent's own capture, never the
            // applicant's self-reported V8 fields (those already exist on
            // rental_applications itself; this is the agent's independent
            // assessment of what was submitted).
            $table->decimal('monthly_income', 12, 2)->nullable();
            $table->decimal('other_monthly_income', 12, 2)->nullable();
            $table->decimal('monthly_expenses', 12, 2)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_assessments');
    }
};
