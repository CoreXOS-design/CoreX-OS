<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Communication flag alerts (AT-36, triage addendum §4.3) — the BM contradiction
 * queue. Raised when a real_estate classification contradicts a prior
 * not_real_estate flag from a different agent (agent_vs_agent) or, in Phase B,
 * the stored AI verdict (agent_vs_ai). Routed to the flagging agent's branch BM
 * AND agency compliance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_flag_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies', 'id', 'cfa_agency_fk')->cascadeOnDelete();
            $table->string('identifier', 255);
            $table->foreignId('original_flag_id')->constrained('communication_flags', 'id', 'cfa_orig_fk')->cascadeOnDelete();
            $table->foreignId('contradicting_flag_id')->nullable()->constrained('communication_flags', 'id', 'cfa_contra_fk')->nullOnDelete();
            $table->enum('alert_type', ['agent_vs_agent', 'agent_vs_ai']);
            $table->enum('status', ['open', 'reviewed', 'dismissed', 'actioned'])->default('open');
            $table->foreignId('reviewed_by')->nullable()->constrained('users', 'id', 'cfa_reviewedby_fk')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'status'], 'cfa_agency_status_idx');
            $table->index('identifier', 'cfa_identifier_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_flag_alerts');
    }
};
