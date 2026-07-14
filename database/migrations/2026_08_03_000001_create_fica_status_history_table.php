<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-236 — durable, immutable FICA audit trail.
 *
 * FICA is a legal surface (FIC Act): every approval-workflow hop must be
 * recorded in a queryable, append-only ledger — not only a Log line. One row
 * per transition/action, including BLOCKED actions (a secondary officer's
 * self-approval attempt) and every Refer-to-CO hop (refer → CO approve/reject/
 * return). Append-only: no updated_at, no soft-delete, never mutated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fica_status_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('fica_submission_id');
            // Transition endpoints. from_status is null for the first event; for a
            // non-transition action (e.g. a blocked self-approval) from == to.
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            // What happened, in workflow vocabulary (agent_approved, co_approved,
            // co_rejected, returned_to_agent, referred_to_co, co_returned_to_referrer,
            // self_approval_blocked, …). Distinct from status so a "blocked" or
            // "returned" event is legible without decoding statuses.
            $table->string('action', 60);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            // The actor's officer tier at the moment of the action, captured for the
            // audit (primary_compliance_officer / mlro / agent / admin / system).
            $table->string('actor_tier', 40)->nullable();
            // Mandatory reason on a referral / return; optional elsewhere.
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['agency_id', 'fica_submission_id', 'id'], 'fica_hist_submission_idx');
            $table->index(['agency_id', 'action'], 'fica_hist_action_idx');

            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->foreign('fica_submission_id')->references('id')->on('fica_submissions')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fica_status_history');
    }
};
