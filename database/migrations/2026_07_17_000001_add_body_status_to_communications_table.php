<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-135 — mark a captured communication whose body could NOT be recovered.
 *
 * WhatsApp stores message bodies encrypted-at-rest in IndexedDB, so the capture
 * extension fills the body from the rendered DOM bubble (AT-135). When the bubble
 * isn't in the open-chat DOM (scrolled out / chat not open), the message is still
 * archived (identity + metadata are correct) but with no body text. body_status
 * distinguishes that "captured, body not recoverable" case from a genuine empty
 * message — so the UI can show it honestly and a future backfill can target it.
 *
 *   NULL      → normal (a real body_text, or a legitimately empty message)
 *   unreadable → body could not be captured (encrypted IDB + bubble absent)
 *
 * Additive + nullable; no behaviour change for existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->string('body_status', 20)->nullable()->after('body_preview');
        });

        // AT-135 — agency-configurable toggle for the read-only WhatsApp body
        // backfill sweep (default ON; an agency may switch it OFF to keep capture
        // strictly passive/live-only — Johan's ToS risk control).
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('wa_history_backfill')->default(true)->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->dropColumn('body_status');
        });
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('wa_history_backfill');
        });
    }
};
