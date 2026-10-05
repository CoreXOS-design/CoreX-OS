<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPRA FFC renewal — Confirmation of Employment letter.
 * .ai/specs/ppra-ffc-employment-letter.md
 *
 * Every merge field (agent name/ID/FFC ref/designation, agency legal+trading
 * name, agency PPRA firm number, the principal's own name/FFC ref) is read
 * live from `users`/`agencies`/`branches` at render time for an unsigned
 * letter — nothing is duplicated onto this table. Only what the SIGNING
 * CEREMONY itself produces (who signed, when, from where, the baked
 * signature images, and the final immutable PDF) is persisted here, plus
 * the few FK/snapshot columns the list/detail/scoping screens need.
 *
 * principal_user_id is nullable by design — it is both "the principal
 * resolved for this letter" (today: whichever user resolved from
 * users.is_principal_practitioner, admin-picked when the agency has more
 * than one) AND the explicit hook for a later true per-agent mentor
 * override, so that future feature does not require a schema change.
 *
 * branch_id snapshots the signing agent's branch AT CREATION TIME (not a
 * live join) so the admin list's branch filter/scoping stays accurate even
 * if the agent is later reassigned to a different branch — the letter
 * reflects which branch's employment was being confirmed at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppra_employment_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->comment('The agent/candidate this letter is about.');
            $table->foreignId('principal_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users');

            $table->enum('status', [
                'draft',
                'awaiting_agent_signature',
                'awaiting_principal_signature',
                'signed',
            ])->default('awaiting_agent_signature');

            // Snapshotted at sign time (encrypted at rest) — survives the
            // signer's own saved signature being replaced later, same
            // pattern as evaluation_certificates.candidate_signature_image.
            $table->text('agent_signature_image')->nullable();
            $table->text('principal_signature_image')->nullable();

            $table->timestamp('agent_signed_at')->nullable();
            $table->string('agent_signed_ip', 45)->nullable();
            $table->timestamp('principal_signed_at')->nullable();
            $table->string('principal_signed_ip', 45)->nullable();

            $table->string('signed_pdf_path')->nullable();

            // Reminder-engine bookkeeping (agency setting: remind principal
            // every N days while awaiting_principal_signature).
            $table->timestamp('reminder_last_sent_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['agency_id', 'status']);
            $table->index(['agency_id', 'user_id']);
            $table->index(['agency_id', 'branch_id']);
            $table->index(['agency_id', 'principal_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppra_employment_letters');
    }
};
