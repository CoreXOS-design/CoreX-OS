<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-231 P2 — the learned-reference store (Match-or-Create for correspondence).
 * Mirrors pdf_splitter_learned_phrases: a matched signal, saved on a manual
 * first-verify, that auto-files future correspondence carrying the same signal
 * ("done for the rest of the transaction"). Only is_verified rows auto-apply.
 * See .ai/specs/at231-inbound-attorney-comms-filing.md §3.5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_learned_refs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('deal_id')->index();                    // DR1 deals.id
            $table->unsignedBigInteger('attorney_provider_id')->nullable();    // firm
            $table->unsignedBigInteger('attorney_provider_contact_id')->nullable(); // person
            // cx_token | thread_key | subject_pattern | external_ref | sender_email
            $table->string('signal_type', 30);
            $table->string('signal_value', 200);   // normalised (lowercased/trimmed)
            $table->boolean('is_verified')->default(false);   // only verified rows auto-file
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedInteger('hits')->default(0);      // times auto-applied (audit)
            $table->timestamps();
            $table->softDeletes();

            // One canonical row per external identity in an agency; re-ingest bumps hits.
            $table->unique(['agency_id', 'signal_type', 'signal_value'], 'clr_agency_signal_uq');
            $table->index(['agency_id', 'is_verified'], 'clr_agency_verified_idx');
            $table->index(['agency_id', 'attorney_provider_id'], 'clr_agency_firm_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_learned_refs');
    }
};
