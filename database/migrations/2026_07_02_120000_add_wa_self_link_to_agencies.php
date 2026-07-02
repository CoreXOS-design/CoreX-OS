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
            $table->boolean('wa_self_link_enabled')->default(true)->after('wa_history_backfill');
            $table->string('wa_session_prefix')->nullable()->after('wa_self_link_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['wa_self_link_enabled', 'wa_session_prefix']);
        });
    }
};
