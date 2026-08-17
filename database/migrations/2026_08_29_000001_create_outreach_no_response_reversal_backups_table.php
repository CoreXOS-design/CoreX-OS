<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot table for `outreach:reverse-false-no-response` (AT-81 false-opt-out
 * remediation). Before the command clears a wrongly-lapsed contact's opt-out
 * triplet and lifts its marketing suppressions, it records the exact prior state
 * here — so the reversal is fully restorable (`--restore=<batch>`). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outreach_no_response_reversal_backups')) {
            return;
        }
        Schema::create('outreach_no_response_reversal_backups', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id', 40)->index();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('contact_id')->index();

            // Snapshot of the contact opt-out triplet as it was BEFORE reversal.
            $table->timestamp('opt_out_at')->nullable();
            $table->text('opt_out_reason')->nullable();
            $table->unsignedBigInteger('opt_out_recorded_by_user_id')->nullable();
            $table->string('opt_out_source')->nullable();
            $table->string('opt_out_kind')->nullable();

            // marketing_suppressions ids lifted for this contact (JSON array).
            $table->json('suppression_ids')->nullable();

            $table->timestamp('reversed_at')->nullable();   // when --apply cleared it
            $table->timestamp('restored_at')->nullable();   // when --restore put it back
            $table->timestamps();

            $table->unique(['batch_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_no_response_reversal_backups');
    }
};
