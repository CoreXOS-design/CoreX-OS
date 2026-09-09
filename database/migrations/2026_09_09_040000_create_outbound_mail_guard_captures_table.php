<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-URGENT-2026-09-09 (Johan, via conductor) — kill-switch, part 3. There is
 * no Mailpit on live, so a message intercepted there cannot be "redirected"
 * anywhere real — it must be captured durably or it silently vanishes.
 * "Silently discarding a client's e-sign invitation would be unforgivable.
 * It must be captured, visible, and countable — at minimum we must be able
 * to say '142 messages were held while this was on, here they are'."
 *
 * Deliberately NO released_at/released_by columns — Johan approved the
 * recommendation of view/count/export only, no automated release (a raw-MIME
 * replay would bypass whatever state machine the original message type
 * normally updates, e.g. a resent e-sign invite via raw MIME would not
 * advance SignatureRequest::invite_send_status or its own audit log).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_mail_guard_captures', function (Blueprint $table) {
            $table->id();
            $table->text('to_addresses');
            $table->text('cc_addresses')->nullable();
            $table->text('bcc_addresses')->nullable();
            $table->text('subject')->nullable();
            $table->longText('raw_mime');
            $table->string('environment', 40);
            $table->boolean('forwarded_to_sink')->default(false);
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index('captured_at');
            $table->index('environment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_mail_guard_captures');
    }
};
