<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-158 DR2 · WS4 (§4.6, §8) — the distribution send-record + comms anchor.
 *
 * One row per (document → party) send. Carries the delivery mode, the
 * secure-link token (secure_link mode), the lifecycle status, and the
 * communication_id of the archived outbound email (§10). Recipient is EITHER a
 * CoreX contact OR a directory provider (electrician/entomologist/…) — never a
 * freeform address; recipient_email is a snapshot at send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_document_distributions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('deal_id');
            $table->unsignedBigInteger('document_id')->nullable(); // NULL only briefly for a generate-on-send COC
            $table->string('party_role', 40);
            $table->unsignedBigInteger('recipient_contact_id')->nullable();
            $table->unsignedBigInteger('recipient_provider_id')->nullable();
            $table->string('recipient_email', 191); // snapshot at send
            $table->enum('delivery_mode', ['secure_link', 'direct_attachment']);
            $table->char('secure_token', 40)->nullable()->unique(); // secure_link mode
            $table->boolean('otp_required')->default(true);
            $table->enum('status', ['queued', 'sent', 'delivered_failed', 'opened', 'downloaded', 'revoked'])->default('queued');
            $table->unsignedBigInteger('communication_id')->nullable(); // the archived outbound email (§10)
            $table->unsignedBigInteger('sent_by_id')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('first_opened_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('deal_id')->references('id')->on('deals_v2')->cascadeOnDelete();
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
            $table->foreign('recipient_contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('recipient_provider_id')->references('id')->on('agency_service_providers')->nullOnDelete();
            $table->foreign('communication_id')->references('id')->on('communications')->nullOnDelete();
            $table->index(['agency_id', 'deal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_document_distributions');
    }
};
