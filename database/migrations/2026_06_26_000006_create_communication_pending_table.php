<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound grace buffer (AT-32, spec §7.5). Unmatched INBOUND communications
 * park here for a short grace window (default 4 calendar days, max 5). If a
 * matching contact appears in time the item attaches retroactively to the
 * permanent archive; otherwise it prunes (POPIA data-minimisation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_pending', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies', 'id', 'comm_pend_agency_fk')->cascadeOnDelete();

            $table->enum('channel', ['email', 'whatsapp']);
            $table->enum('direction', ['inbound', 'outbound'])->default('inbound');

            $table->string('external_id', 255);
            $table->string('thread_key', 255)->nullable();
            $table->string('from_identifier', 255)->nullable();
            $table->json('participant_identifiers')->nullable();

            $table->dateTime('occurred_at');
            $table->dateTime('captured_at');

            $table->string('subject', 1024)->nullable();
            $table->mediumText('body_text')->nullable();
            $table->string('body_preview', 255)->nullable();

            $table->string('raw_path', 1024)->nullable();
            $table->boolean('has_attachments')->default(false);
            $table->char('content_hash', 64)->nullable();
            $table->string('source_ref', 512)->nullable();

            $table->dateTime('expires_at');
            $table->timestamp('nudged_at')->nullable();      // near-expiry nudge sent

            $table->timestamps();
            $table->softDeletes();
            $table->timestamp('purged_at')->nullable();
            $table->string('purged_reason', 255)->nullable();

            $table->unique(['agency_id', 'external_id'], 'comm_pend_agency_ext_uq');
            $table->index('expires_at', 'comm_pend_expires_idx');
            $table->index('from_identifier', 'comm_pend_from_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_pending');
    }
};
