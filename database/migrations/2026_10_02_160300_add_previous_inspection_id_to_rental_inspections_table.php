<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md — Johan's ruling, 2026-09-23: an
 * inspection is not a standalone document, it is the next link in a
 * chain. "You do not choose at out-inspection time which earlier
 * inspection to compare against — you chose it at the moment you
 * created the inspection, by pressing Next inspection from the one you
 * were looking at." In -> Routine -> Routine -> Out, any length.
 *
 * Self-referencing, nullable (the first inspection in any chain, and
 * every inspection that pre-dates this column, has none — §6 of the
 * approved proposal: must render gracefully, not look broken).
 * nullOnDelete (not cascade) — matches how this table already treats
 * cancelled_by_user_id/archived_by_user_id: losing the predecessor row
 * should never take a whole chain down with it, and §3.3 already gates
 * actual deletion at the application layer (zero observations) so a
 * hard-deleted predecessor is not the common case this needs to protect
 * against — a defensive default regardless.
 *
 * UNIQUE (nullable-unique — MySQL permits many NULLs under a unique
 * index, only non-null values collide): enforces exactly one successor
 * per inspection, so the chain can never accidentally fork — matches
 * Johan's own model of one linear chain per lease, not a tree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->foreignId('previous_inspection_id')->nullable()->after('lease_id')
                ->constrained('rental_inspections')->nullOnDelete();
            $table->unique('previous_inspection_id', 'rental_insp_prev_insp_unique');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->dropUnique('rental_insp_prev_insp_unique');
            $table->dropConstrainedForeignId('previous_inspection_id');
        });
    }
};
