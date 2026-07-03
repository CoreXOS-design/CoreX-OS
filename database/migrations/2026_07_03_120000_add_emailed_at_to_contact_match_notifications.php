<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Match-email digest support.
 *
 * `contact_match_notifications` is the dedup ledger for Core Matches — one row
 * per (contact_match, property) that has ever been surfaced to an agent. Adding
 * `emailed_at` turns the same ledger into the digest queue: a NULL value means
 * "surfaced (bell fired) but not yet included in a daily digest email". The
 * daily `corex:matches:send-digests` command sweeps NULL rows into one email
 * per agent and stamps emailed_at.
 *
 * Existing rows are backfilled to their created_at so a first digest run can
 * never flood agents with historic matches — only matches surfaced AFTER this
 * migration are digest-eligible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_match_notifications', function (Blueprint $table) {
            $table->timestamp('emailed_at')->nullable()->after('notification_id');
            // Digest query is "un-emailed rows for a given agent".
            $table->index(['notified_user_id', 'emailed_at'], 'cmn_user_emailed_idx');
        });

        // Backfill: everything that already exists is treated as already delivered
        // (the real-time email path had already fired for these), so the first
        // digest run starts from a clean slate and only picks up new matches.
        DB::table('contact_match_notifications')
            ->whereNull('emailed_at')
            ->update(['emailed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('contact_match_notifications', function (Blueprint $table) {
            $table->dropIndex('cmn_user_emailed_idx');
            $table->dropColumn('emailed_at');
        });
    }
};
