<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-08 (Johan, same day as the column was added) — the timestamp
 * watermark + rolling overlap window is replaced by UID-based tracking
 * (server-side, exact, no clock involved — see
 * .ai/specs/at33-incremental-poll-rebuild-2026-09-08.md §UID rebuild). The
 * lookback-hours mechanism this column existed for no longer exists in code.
 * Deliberately removed rather than left unused: two overlapping mechanisms
 * for the same job is how the next bug hides.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (Schema::hasColumn('agencies', 'communication_poll_lookback_hours')) {
                $table->dropColumn('communication_poll_lookback_hours');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'communication_poll_lookback_hours')) {
                $table->unsignedSmallInteger('communication_poll_lookback_hours')->nullable();
            }
        });
    }
};
