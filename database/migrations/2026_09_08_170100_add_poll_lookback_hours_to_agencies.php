<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-08 (Johan) — rolling overlap window for incremental polling. Each
 * poll searches from (watermark - lookback), not the watermark exactly,
 * because IMAP's SINCE search is DATE granularity only (no way to ask "since
 * 22:14") and because clock skew / mid-poll arrivals / out-of-order landing
 * are real gaps a hard cutoff leaves open. Safe only because dedup is by
 * Message-ID (confirmed, see EmailArchiveIngestor::alreadySeen()) — the
 * overlap deliberately re-reads already-processed messages every run.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'communication_poll_lookback_hours')) {
                $table->unsignedSmallInteger('communication_poll_lookback_hours')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('communication_poll_lookback_hours');
        });
    }
};
