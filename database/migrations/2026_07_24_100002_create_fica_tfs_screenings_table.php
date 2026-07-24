<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-submission TFS screening OUTCOME — the version-stamped, defensible audit record.
 *
 * Every screen (auto or manual) writes a row: what was screened, against which list
 * import (version), the outcome, and any CO decision on a review/hit. "Screened & passed"
 * is a compliance assertion; this row + its import_id/list_fetched_at is what defends it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fica_tfs_screenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fica_submission_id')->constrained('fica_submissions')->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->enum('subject_kind', ['individual', 'entity'])->default('individual');

            // What was screened (snapshot — the submission may change later)
            $table->string('screened_name', 500)->nullable();
            $table->string('screened_name_normalised', 500)->nullable();
            $table->string('screened_id_number', 160)->nullable();
            $table->string('screened_id_normalised', 160)->nullable();
            $table->date('screened_dob')->nullable();

            // Outcome
            //  hit             = exact ID/passport match — hard block
            //  review_required = name/alias match, or borderline — CO must decide
            //  passed          = no match (see auto_pass_trusted for whether it auto-clears)
            //  error           = could not screen (no list / fetch failed) — never a silent pass
            $table->enum('outcome', ['hit', 'review_required', 'passed', 'error'])->default('error');
            $table->boolean('auto_pass_trusted')->default(false); // OFF until list completeness signed off
            $table->string('reason', 80)->nullable();             // exact_id_match | name_match | no_match | list_stale | no_list | fetch_failed

            // Which list version this screened against (freshness/audit)
            $table->foreignId('import_id')->nullable()->constrained('sanctions_list_imports')->nullOnDelete();
            $table->dateTime('list_fetched_at')->nullable();

            $table->unsignedInteger('match_count')->default(0);
            $table->json('candidates')->nullable();               // surfaced matches for review/hit

            $table->foreignId('screened_by')->nullable()->constrained('users')->nullOnDelete(); // null = automatic
            $table->dateTime('screened_at');

            // CO decision on a review/hit
            $table->enum('decision', ['pending', 'confirmed_hit', 'cleared_false_positive'])->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['fica_submission_id', 'outcome']);
            $table->index('outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fica_tfs_screenings');
    }
};
