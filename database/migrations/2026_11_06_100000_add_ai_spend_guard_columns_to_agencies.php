<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-456 Part A — AI Spend Safety (.ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §A.2).
 *
 * Replaces the monthly rand budget with a rolling-window alert + cut-out. The
 * old ai_monthly_budget_zar / ai_budget_* columns are deliberately LEFT IN PLACE
 * and unused (§A.1) — no destructive change now; they go in a later cleanup once
 * nothing reads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            // Set when the agency's windowed spend reaches the cut-out; every AI
            // call is refused while non-null. Cleared only by an owner resume.
            $table->timestamp('ai_paused_at')->nullable();
            $table->string('ai_paused_reason', 255)->nullable();
            $table->decimal('ai_paused_spend_zar', 10, 4)->nullable();
            // The guard counts spend since max(now - window, ai_resumed_at), so a
            // resumed agency is not re-paused by the spend that paused it.
            $table->timestamp('ai_resumed_at')->nullable();
            // At most one alert email per agency per window.
            $table->timestamp('ai_alert_last_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn([
                'ai_paused_at',
                'ai_paused_reason',
                'ai_paused_spend_zar',
                'ai_resumed_at',
                'ai_alert_last_sent_at',
            ]);
        });
    }
};
