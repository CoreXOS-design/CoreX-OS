<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provisional outbound support for the Communication Archive (AT-59).
 *
 * When an agent clicks WhatsApp/Email on a contact, CoreX records a PROVISIONAL
 * outbound communication immediately (instant tile feedback) before the real
 * message has been ingested from the mailbox Sent folder / WA capture. Later
 * ingestion RECONCILES the provisional row in place — promotes it to a confirmed
 * archive record — instead of inserting a duplicate.
 *
 *   - provisional_at: set when the row is created provisionally; cleared (NULL)
 *     on reconciliation. NOT NULL = unreconciled provisional (prune candidate).
 *   - text_hash: normalised message-text hash (channel + subject + body),
 *     computed identically on click and on ingest so the two can be matched
 *     deterministically. Distinct from content_hash (which hashes the raw
 *     .eml/.json payload and therefore never matches across the two paths).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->dateTime('provisional_at')->nullable()->after('captured_at');
            $table->char('text_hash', 64)->nullable()->after('content_hash');

            $table->index(['agency_id', 'channel', 'provisional_at'], 'comm_provisional_idx');
            $table->index('text_hash', 'comm_texthash_idx');
        });
    }

    public function down(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->dropIndex('comm_provisional_idx');
            $table->dropIndex('comm_texthash_idx');
            $table->dropColumn(['provisional_at', 'text_hash']);
        });
    }
};
