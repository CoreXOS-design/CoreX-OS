<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-238 — the ambiguous-match review queue.
 *
 * Matching 2,069 historical free-text addresses against the property table lands in
 * three buckets: ~36% match exactly one property (safe to link), ~21% match SEVERAL
 * (a machine cannot pick, and guessing would attach a legal filing to the wrong
 * house), ~42% match nothing (they stay free text, with no shame).
 *
 * The middle bucket is the reason this table exists: the candidates are recorded and
 * a human chooses. Nothing is auto-linked on a coin-flip.
 *
 * Mirrors deal_link_review_queue (2026_06_02_080002) exactly, so the review pattern is
 * the same one the deals backfill already proved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filing_link_review_queue', function (Blueprint $table) {
            $table->id();

            $table->foreignId('filing_id')
                ->constrained('document_filing_register', 'id', 'flrq_filing_fk')
                ->cascadeOnDelete();
            $table->foreignId('agency_id')
                ->constrained('agencies', 'id', 'flrq_agency_fk')
                ->cascadeOnDelete();

            $table->timestamp('matched_at')->useCurrent();

            $table->enum('match_status', [
                'pending',            // awaiting a human pick
                'resolved_linked',    // a candidate was chosen and written to the filing row
                'resolved_unlinked',  // reviewed: none of these — the row stays free text
                'resolved_skip',      // deferred
            ])->default('pending');

            // The address we matched on + the candidates we found, frozen at match time,
            // so a reviewer sees what the matcher saw even if the data moves later.
            $table->string('matched_address', 255);
            $table->json('candidates_json');

            $table->foreignId('chosen_property_id')->nullable()
                ->constrained('properties', 'id', 'flrq_chosen_property_fk')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()
                ->constrained('users', 'id', 'flrq_reviewer_fk')
                ->nullOnDelete();
            $table->text('review_note')->nullable();

            $table->timestamps();

            // One open review per filing row.
            $table->unique(['filing_id'], 'flrq_filing_unique');
            $table->index(['agency_id', 'match_status'], 'flrq_agency_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filing_link_review_queue');
    }
};
