<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspection-form.md §13 — one row per (scan × item): what
 * the reader detected in that item's tick-box row, and what the agent
 * confirmed. This is the review-screen's own data and the audit trail —
 * NOT a parallel observation store. Applying a confirmed mark creates an
 * ORDINARY RentalInspectionObservation via ::record(), identical to a
 * screen tap; `applied_observation_id` here is the pointer back from that
 * real observation to the scan/page/reviewer that produced it, which is
 * what "every applied mark records that it came from a scan, which scan,
 * which page, and who confirmed it" actually means in practice — the
 * observation itself carries no new column, this table carries the
 * provenance.
 *
 * `ambiguous` covers both a genuinely undecidable read (two boxes marked
 * on one row, confidence too close to the threshold to trust) and a
 * legitimately blank row (nothing marked at all) — either way the review
 * screen must ask a human, never guess. `detected_condition_key` is null
 * in both of those cases; `confirmed_condition_key` is only ever set once
 * a human has looked at the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_scan_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained(indexName: 'ri_scan_marks_agency_fk')->cascadeOnDelete();
            $table->foreignId('rental_inspection_scan_id')
                ->constrained(indexName: 'ri_scan_marks_scan_fk')->cascadeOnDelete();
            $table->foreignId('rental_inspection_item_id')
                ->constrained(indexName: 'ri_scan_marks_item_fk')->cascadeOnDelete();
            $table->unsignedSmallInteger('page_number');

            $table->string('detected_condition_key', 60)->nullable();
            $table->decimal('detected_confidence', 3, 2)->nullable();
            $table->boolean('ambiguous')->default(false);

            $table->string('confirmed_condition_key', 60)->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()
                ->constrained('users', indexName: 'ri_scan_marks_confirmed_by_fk')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->foreignId('applied_observation_id')->nullable()
                ->constrained('rental_inspection_observations', indexName: 'ri_scan_marks_observation_fk')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['rental_inspection_scan_id', 'rental_inspection_item_id'], 'ri_scan_marks_scan_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_scan_marks');
    }
};
