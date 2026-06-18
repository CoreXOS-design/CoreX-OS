<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency-configurable knobs for provisional-comm reconciliation (AT-59).
 * NULL means "inherit the config default" (config/communications.php) — the
 * services never hardcode the values.
 *
 *   - communication_reconcile_window_minutes: how far apart (± minutes) an
 *     ingested outbound message and a provisional click may be to match by the
 *     time-window fallback when the text hashes differ (e.g. the agent edited
 *     the message before sending). Exact text-hash matches ignore the window.
 *   - communication_provisional_prune_hours: how old an unreconciled provisional
 *     row may get before the scheduled prune soft-purges it (orphan from an
 *     edited-before-send message that never matched).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->unsignedInteger('communication_reconcile_window_minutes')->nullable();
            $table->unsignedInteger('communication_provisional_prune_hours')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn([
                'communication_reconcile_window_minutes',
                'communication_provisional_prune_hours',
            ]);
        });
    }
};
