<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-135 — make WhatsApp body-backfill matching @lid-NATIVE.
 *
 * WhatsApp Web identifies chats by an @lid (linked id, e.g. 222758646611979@lid)
 * that carries NO phone. AT-133 resolves @lid→phone for the LIVE capture path, but
 * the backfill target set was phone-keyed only — so the idle sweep, which walks
 * chats identified by @lid, had to reverse-resolve every chat to match. That
 * reverse map is unreliable (it missed Elize's chat → her body never backfilled).
 *
 * Storing the @lid as a queryable column lets the server hand the extension the
 * @lid directly: the sweep matches a chat by its raw @lid (read straight off the
 * list, zero resolution) — closing the @lid-vs-phone asymmetry that bit twice
 * (live=AT-133, backfill=this). Consent is unaffected: targets are still the
 * opted-in set, and the server re-checks isCaptureOptedIn before filling a body.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->string('counterpart_lid', 64)->nullable()->after('thread_key')->index();
        });

        // Backfill existing WhatsApp rows whose @lid is preserved in thread_key
        // (e.g. comms 95/96/105 on staging). DB-only — the @lid is pure digits +
        // '@lid', so stripping the suffix yields the matchable key. No disk read.
        DB::table('communications')
            ->where('channel', 'whatsapp')
            ->where('thread_key', 'like', '%@lid')
            ->whereNull('counterpart_lid')
            ->update(['counterpart_lid' => DB::raw("REPLACE(thread_key, '@lid', '')")]);
    }

    public function down(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->dropIndex(['counterpart_lid']);
            $table->dropColumn('counterpart_lid');
        });
    }
};
