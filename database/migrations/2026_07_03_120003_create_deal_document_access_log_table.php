<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-158 DR2 · WS4 (§4.6, §8.2, §16 POPIA) — the immutable access log.
 *
 * Append-only evidence trail for every secure-link interaction: link opened,
 * OTP sent/verified/failed, downloaded, revoked. Mirrors the comms / e-sign
 * audit doctrine — NO updated_at, NO deleted_at; the model's update()/delete()
 * throw. This is the POPIA record that proves who accessed which document,
 * when, and from where, and that identity was verified before the document
 * streamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_document_access_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('distribution_id');
            $table->enum('event', ['link_clicked', 'otp_sent', 'otp_verified', 'otp_failed', 'downloaded', 'revoked']);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('distribution_id')->references('id')->on('deal_document_distributions')->cascadeOnDelete();
            $table->index(['distribution_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_document_access_log');
    }
};
