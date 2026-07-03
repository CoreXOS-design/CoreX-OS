<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-168 Part A — de-fragment WhatsApp threads.
 *
 * A single contact split into multiple archive threads because
 * `communications.thread_key` stored the RAW WhatsApp chat id, which differs by
 * capture engine for the SAME human: the browser extension keys a chat by its
 * `@lid` (e.g. 244632797597780@lid) while WAHA keys it by the phone `@c.us`
 * (27799522551@c.us). Same person → two thread_keys → two threads.
 *
 * The fix makes `thread_key` a CANONICAL, engine-independent grouping key
 * (`wa:<last-9 of the resolved number>`) so both engine views of one number
 * collapse to one thread. The RAW chat id is still needed to address WAHA for
 * media re-download (WaMediaRecoveryService), so it moves to a dedicated
 * `wa_chat_id` column — one source of truth per concern.
 *
 * This migration is the SCHEMA + raw-preservation half (safe, mechanical): add
 * the column and copy the current raw thread_key into it for every WA row. The
 * canonical re-key of existing rows + the merge of any per-thread settings /
 * grants is done by the idempotent `communications:recanonicalize-wa-threads`
 * command (dry-run-able, agency-scoped) — the same command-driven cleanup
 * pattern as AT-151's noise purge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            // The raw WhatsApp chat id (…@lid / …@c.us / group jid) exactly as the
            // capture source sent it — the WAHA addressing key for media recovery.
            $table->string('wa_chat_id', 255)->nullable()->after('thread_key')->index();
        });

        // Preserve the current raw chat id before any recanonicalization re-keys
        // thread_key. WA rows only; other channels never carry a WA chat id.
        DB::table('communications')
            ->where('channel', 'whatsapp')
            ->whereNull('wa_chat_id')
            ->update(['wa_chat_id' => DB::raw('thread_key')]);
    }

    public function down(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->dropIndex(['wa_chat_id']);
            $table->dropColumn('wa_chat_id');
        });
    }
};
