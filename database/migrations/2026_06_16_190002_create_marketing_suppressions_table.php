<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-49 — identifier-level marketing suppression list.
 *
 * "One opt-out, suppressed everywhere." A suppression is keyed by a NORMALISED
 * identifier (lowercased email, or the last-9-digit SA mobile core — the same
 * forms ContactIdentifierResolver matches on), NOT by contact_id, so that a
 * re-imported / duplicate contact carrying the same email or number stays
 * blocked. No hard deletes: lifting an opt-out sets lifted_at (an opt-in),
 * never removes the row — the history is the audit trail.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketing_suppressions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->string('identifier', 255);                 // normalised email or last-9 phone
            $table->enum('identifier_type', ['email', 'phone']);
            $table->unsignedBigInteger('contact_id')->nullable(); // matched contact at suppression time (if any)
            $table->string('source', 40);                       // self_service_link | unsubscribe_page | agent | opt_out_link
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('send_id')->nullable();  // originating outreach send, if known
            $table->timestamp('suppressed_at');
            $table->timestamp('lifted_at')->nullable();         // set on opt-in; row is never deleted
            $table->unsignedBigInteger('lifted_by_user_id')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->timestamps();

            // Active-suppression lookup is (agency, identifier, lifted_at IS NULL).
            $table->index(['agency_id', 'identifier', 'lifted_at'], 'marketing_suppr_lookup_idx');
            $table->index(['agency_id', 'lifted_at'], 'marketing_suppr_agency_active_idx');

            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('send_id')->references('id')->on('seller_outreach_sends')->nullOnDelete();
            $table->foreign('recorded_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('lifted_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_suppressions');
    }
};
