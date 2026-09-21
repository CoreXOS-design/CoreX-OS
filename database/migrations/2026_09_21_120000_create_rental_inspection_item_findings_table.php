<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspection-form.md §7 — the in-vs-out deposit comparison.
 * The comparison CLASSIFICATION itself (unchanged/improved/declined/etc.) is
 * never stored here — it's derived at read time from existing observations,
 * same "never a column" principle as rental_inspection_items §3.1. This
 * table stores only the one thing that genuinely cannot be derived: an
 * agent's own judgement that a declined item is fair wear and tear (and is
 * therefore excluded from the deposit conversation) versus a genuine,
 * flagged difference worth pursuing. No amount, no currency, no
 * deduction — that half is explicitly not built until Johan rules
 * (rental-inspection-form.md §7.2/§10).
 *
 * Never edited in place, never deleted — a finding is evidence. Corrected
 * by superseding (superseded_at/superseded_by_finding_id), same pattern
 * already proven by RentalInspectionSignature::supersedeWetInk().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_item_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_inspection_id')->constrained()->cascadeOnDelete();
            // ^ always the OUT inspection — the comparison is anchored to a
            // specific out-inspection event, never the in-inspection.
            // Explicit short FK name — the auto-generated
            // "rental_inspection_item_findings_rental_inspection_item_id_foreign"
            // exceeds MySQL's 64-char identifier limit.
            $table->foreignId('rental_inspection_item_id')
                ->constrained('rental_inspection_items', 'id', 'riif_item_id_foreign')
                ->cascadeOnDelete();

            $table->string('disposition', 20);
            // wear_and_tear | flagged — see model. Only ever recorded
            // against a 'declined' classification (enforced in the
            // service/controller, not the schema).
            $table->text('note');
            // required always — an agent's reasoning, mirrors
            // RentalInspectionObservation::requiresNotes()'s existing
            // "a judgement call needs a reason on record" standard.

            $table->foreignId('recorded_by_user_id')->constrained('users');
            $table->timestamp('recorded_at');

            $table->timestamp('superseded_at')->nullable();
            // Same identifier-length reason as rental_inspection_item_id above.
            $table->foreignId('superseded_by_finding_id')->nullable()
                ->constrained('rental_inspection_item_findings', 'id', 'riif_superseded_by_foreign')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['agency_id', 'rental_inspection_id', 'rental_inspection_item_id'], 'riif_agency_inspection_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_item_findings');
    }
};
