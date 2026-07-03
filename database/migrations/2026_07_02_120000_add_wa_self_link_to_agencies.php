<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-156 — WhatsApp Capture Linking (My Portal → Tools).
 *
 * Per-agency controls, mirroring the AT-135 `wa_history_backfill` pattern
 * (plain columns on `agencies`):
 *   - wa_self_link_enabled : may agents self-link WhatsApp capture (default ON)
 *   - wa_session_prefix    : session-name prefix (null => agency{id})
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            // No ->after(): column position is cosmetic, and depending on
            // `wa_history_backfill` here caused a migrate ORDERING failure on a
            // migrations-built DB — that column is created by the later
            // 2026_07_17_000001 migration, so this 07-02 migration ran first and
            // failed on live. Order-independent now.
            $table->boolean('wa_self_link_enabled')->default(true);
            $table->string('wa_session_prefix')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['wa_self_link_enabled', 'wa_session_prefix']);
        });
    }
};
