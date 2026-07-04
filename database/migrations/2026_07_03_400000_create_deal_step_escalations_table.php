<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-158 DR2 WS6 — the notification/escalation idempotency + audit log.
 *
 * The calendar notification pattern (CalendarNotificationDispatcher) fires once
 * per colour-transition EDGE with no log — fine for a single monotonic
 * transition. Escalation is different: the SAME overdue step must notify the
 * agent, then the BM at +N days, then admin at +M days — a LADDER, where each
 * rung must fire exactly once and survive hourly re-runs. A transition edge
 * can't express "which ladder rungs have fired", so WS6 records each fired
 * notification here, keyed by (step, level_key). The row is also the deal
 * timeline's evidence that the chase happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_step_escalations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('deal_id')->index();            // deals_v2
            $table->foreignId('deal_step_instance_id')->constrained('deal_step_instances')->cascadeOnDelete();
            // 'rag:amber' | 'rag:red' | 'rag:overdue' | 'escalation:branch_manager'
            // | 'escalation:admin' | 'approval_pending' | 'rejected'
            $table->string('level_key');
            $table->string('kind')->default('escalation'); // rag_transition|escalation|approval|rejection
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('channels')->nullable();  // ['in_app','email'] actually sent
            $table->json('context')->nullable();   // rag / days_overdue snapshot at fire time
            $table->timestamp('notified_at')->useCurrent();
            $table->timestamps();

            // One row per (step, level, recipient) — the same rung can't re-fire to
            // the same person, and "has this rung fired at all?" = exists(step, level_key).
            $table->unique(['deal_step_instance_id', 'level_key', 'recipient_user_id'], 'dse_step_level_recipient_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_step_escalations');
    }
};
