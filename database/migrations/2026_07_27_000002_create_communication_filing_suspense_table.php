<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-231 P2 — the SUSPENSE / review queue. A parked inbound attorney email that
 * needs the agent to verify (first email of a correspondence) or manually link
 * (difficult route). Surfaced in BOTH the Deals and Comms homes (spider-web).
 * One row per parked communication. See §3.7 of the spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_filing_suspense', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('communication_id');
            $table->string('channel', 20)->default('email');
            $table->unsignedBigInteger('suggested_deal_id')->nullable();   // DR1 deals.id (auto-suggest)
            $table->string('confidence', 10)->default('low');              // high | medium | low
            $table->string('status', 12)->default('pending');              // pending | verified | dismissed
            $table->unsignedBigInteger('resolved_deal_id')->nullable();    // the deal the agent confirmed
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            // The signal that would be learned on verify (so a re-file is silent next time).
            $table->string('matched_signal_type', 30)->nullable();
            $table->string('matched_signal_value', 200)->nullable();
            $table->unsignedBigInteger('attorney_provider_id')->nullable();
            $table->unsignedBigInteger('attorney_provider_contact_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'communication_id'], 'cfs_agency_comm_uq');
            $table->index(['agency_id', 'status'], 'cfs_agency_status_idx');
            $table->index(['agency_id', 'suggested_deal_id'], 'cfs_agency_deal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_filing_suspense');
    }
};
