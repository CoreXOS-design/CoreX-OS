<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-09 (Johan, poller-reliability incident — first-poll-never-completes
 * fix) — agency-configurable chunk size for IMAP header/flag fetches.
 *
 * Measured against a real mailbox: the IMAP SEARCH itself is fast (0.37s)
 * regardless of window size; fetching headers+flags for ALL matched messages
 * in one unbounded call is what scales with mailbox size (95 messages, 115s —
 * comfortably over any sane poll budget). Bounding each fetch to this many
 * messages at a time, checkpointing the watermark after every chunk, lets a
 * mailbox with any amount of history catch up over however many poll cycles
 * it takes, instead of one all-or-nothing fetch that never finishes and
 * makes zero durable progress. NULL -> config('communications.imap_poll_chunk_size', 25).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->unsignedSmallInteger('communication_poll_chunk_size')->nullable()
                ->after('communication_first_poll_days');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('communication_poll_chunk_size');
        });
    }
};
