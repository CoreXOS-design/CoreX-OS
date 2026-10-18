<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §46 — a personal signing link per party (each tenant, the landlord, the agent) on an
 * inspection. One row per issued link; a revoked or replaced link keeps its row (nothing is hard-deleted), the live
 * link for a party is simply the newest row that is neither revoked nor expired.
 *
 * The token is stored as issued (like rental_inspections.public_token) because the agent's screen must be able to show
 * the same link again — copy, QR, WhatsApp — without sending a new one every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_signing_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('rental_inspection_id');
            $table->string('party_role', 20);
            $table->unsignedBigInteger('party_contact_id')->nullable();
            $table->unsignedBigInteger('party_user_id')->nullable();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');

            // How it reached the party (email / whatsapp / copied / qr / device) and whether the send worked.
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedInteger('send_count')->default(0);
            $table->string('last_sent_channel', 20)->nullable();
            $table->string('last_sent_to', 191)->nullable();
            $table->string('last_send_status', 20)->nullable();
            $table->text('last_send_error')->nullable();

            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);

            // The party's own act through this link: signed or declined. Null = nothing yet.
            $table->string('outcome', 20)->nullable();
            $table->timestamp('outcome_at')->nullable();
            $table->unsignedBigInteger('signature_id')->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['rental_inspection_id', 'party_role', 'party_contact_id'], 'ri_signing_links_party_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_signing_links');
    }
};
