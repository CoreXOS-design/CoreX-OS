<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CX-102 part 2 — "the system must show its working and let the agent
 * overrule it" (Johan, 2026-08-19).
 *
 * ONE generic, reusable record of "CoreX decided these two things are the
 * same property" — who/what made the call, why in plain language, what the
 * alternatives were, and (if an agent later says it's wrong) who rejected it
 * and when. Deliberately not scoped to deeds-capture alone: subject_type
 * distinguishes callers (deeds-capture matching, the MIC claim guard, and
 * whatever CX-101 or a future caller needs) so every surface that makes a
 * "same property?" decision writes here and every surface that shows one
 * reads from here, instead of each inventing its own reason/rejection
 * storage. See App\Services\Prospecting\PropertyMatchDecisionService.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('property_match_decisions', function (Blueprint $table) {
            $table->comment('CX-102 — recorded reason for every "same property" match, and any agent rejection of it.');

            $table->id();

            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();

            // What is being matched — the caller's own stable identity for the
            // thing on the "incoming" side. Deeds/CMA capture: source_type +
            // source_ref from matchOrCreate()'s own $source array (e.g.
            // "deeds_capture:cmainfo:n0et01200000038100000"). MIC claim guard:
            // the prospecting listing (e.g. "mic_claim:listing:12689"). A
            // future caller defines its own subject_type/subject_key shape —
            // this table does not assume deeds or MIC.
            $table->string('subject_type', 50);
            $table->string('subject_key', 255);

            // What it was matched TO — polymorphic by column pair rather than
            // Eloquent morphs, so the two concrete kinds in play today
            // (tracked_properties, properties) stay simple foreign-key-shaped
            // even though the table itself has no FK constraint on them (the
            // target type varies).
            $table->string('matched_type', 30);
            $table->unsignedBigInteger('matched_id');

            // Which of the match strategies produced this, and the plain-
            // language sentence generated from it AT MATCH TIME (never
            // reconstructed later — Johan, verbatim: "must be recorded at
            // match time, not reconstructed later").
            $table->string('strategy', 60);
            $table->string('reason', 500);

            // Alternative candidates considered when the match strategy tied
            // (e.g. Strategy 5 token-overlap finding several same-street
            // properties) — lets the agent pick a different one instead of
            // only being able to reject. Null when there was only ever one
            // plausible candidate.
            $table->json('candidates')->nullable();

            $table->timestamp('decided_at');

            // Rejection — "Not the same property". Never deleted; this row
            // stays as the permanent record that this exact (subject, target)
            // pairing was rejected, so no future strategy is allowed to
            // silently offer it again (see PropertyMatchDecisionService::
            // isRejected()). rejected_reason is the agent's own optional
            // free-text note, distinct from `reason` (the SYSTEM's reason for
            // having proposed the match in the first place).
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejected_reason', 500)->nullable();

            // What the rejection resolved TO, if anything — the agent's own
            // pick from `candidates`, or the fresh record created when there
            // was no correct alternative on offer. Null while unrejected, and
            // stays null on a rejection that simply broke the link with
            // nothing to replace it.
            $table->string('resolved_matched_type', 30)->nullable();
            $table->unsignedBigInteger('resolved_matched_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The lookup every match strategy needs before returning a
            // candidate: "has THIS subject already been decided against THIS
            // target?" — and the lookup every display screen needs: "what is
            // the CURRENT decision for this subject?" (most recent row per
            // subject_key).
            $table->index(['agency_id', 'subject_type', 'subject_key'], 'idx_pmd_agency_subject');
            $table->index(['agency_id', 'subject_type', 'subject_key', 'matched_type', 'matched_id'], 'idx_pmd_veto_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_match_decisions');
    }
};
