<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/2026-08-20-property-status-prospecting.md — deeds-capture duplicate-match
 * take rule (Johan, 2026-08-21). Extends the EXISTING CX-102 "same property?" decision
 * table (2026_08_26_140000_create_property_match_decisions_table.php) instead of
 * building a second one — Johan's own quality-dataset ask ("these properties were
 * considered matches but the agent said it was not") is the exact same shape of
 * record this table already keeps for the tracked_property-to-tracked_property match
 * layer; this just adds what's needed to ALSO cover the tracked_property-to-property
 * layer's explicit agent confirmation, plus a few fields that layer specifically
 * asked for (confidence score, a fixed reject-reason pick-list, and the outcome).
 *
 * Every new column is additive and nullable — every existing caller
 * (TrackedPropertyMatchOrCreateService's TP-to-TP matching, the MIC claim guard) is
 * unaffected.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('property_match_decisions', function (Blueprint $table) {
            // The explicit "agent said SAME" event — mirrors rejected_at/rejected_by_user_id,
            // which only ever covered the "agent said DIFFERENT" side.
            $table->timestamp('confirmed_at')->nullable()->after('decided_at');
            $table->foreignId('confirmed_by_user_id')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
            // Fixed pick-list for WHY a match was rejected — the single most useful field
            // for improving matching (which signal misled the matcher, not just "wrong").
            // rejected_reason (existing, free text) remains the optional note alongside it.
            $table->string('reject_reason_code', 60)->nullable()->after('rejected_reason');
            // The matcher produces no confidence score today — nullable for when it does.
            $table->unsignedTinyInteger('confidence_score')->nullable()->after('strategy');
            // What happened next: created_new|took_existing|blocked|sent_for_approval.
            $table->string('outcome', 40)->nullable()->after('resolved_matched_id');
        });
    }

    public function down(): void
    {
        Schema::table('property_match_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by_user_id');
            $table->dropColumn(['confirmed_at', 'reject_reason_code', 'confidence_score', 'outcome']);
        });
    }
};
