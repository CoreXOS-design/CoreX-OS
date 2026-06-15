<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication Archive — index table (AT-32, spec §4.1).
 *
 * Channel-agnostic index of every captured business communication. The raw
 * payload lives on disk (raw_path); MySQL holds the index only. Append-only +
 * soft-delete; 5-year prune is a recorded soft event (purged_at/purged_reason).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies', 'id', 'comm_agency_fk')->cascadeOnDelete();

            $table->enum('channel', ['email', 'whatsapp']);
            $table->enum('direction', ['inbound', 'outbound']);

            $table->string('external_id', 255);          // email Message-ID / WA message id — dedup key
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

            $table->timestamps();
            $table->softDeletes();
            $table->timestamp('purged_at')->nullable();
            $table->string('purged_reason', 255)->nullable();

            $table->unique(['agency_id', 'external_id'], 'comm_agency_ext_uq');
            $table->index('thread_key', 'comm_thread_idx');
            $table->index('occurred_at', 'comm_occurred_idx');
            $table->index(['agency_id', 'channel'], 'comm_agency_channel_idx');
            $table->index('content_hash', 'comm_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communications');
    }
};
