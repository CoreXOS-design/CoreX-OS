<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SyncProperty24Activations chunking (2026-08-23) — the per-listing "last
 * ATTEMPTED" cursor for the activation-status check, same idiom as
 * P24StatsService's p24_stats_synced_at (AT-200, stale-first rotation).
 * Stamped for every property checked in syncAllActivations(), success or
 * failure — an ATTEMPT cursor, not a success cursor, so a chronically-failing
 * listing still rotates away instead of being retried every single run while
 * the rest of the set starves.
 *
 * Raw ALTER with an explicit ALGORITHM clause rather than the Schema
 * Builder's default (2026-08-23, ahead of the live deploy) — tested directly
 * on staging's own properties table (same scale as live, ~10k rows) before
 * assuming anything: ALGORITHM=INPLACE, LOCK=NONE actually took 26-38s wall
 * clock (real "altering table" execution time, confirmed via SHOW PROCESSLIST
 * and performance_schema.metadata_locks — not a lock wait) despite being
 * "should be instant" for a nullable column add. ALGORITHM=INSTANT — MySQL
 * 8.0.12+'s genuinely metadata-only path for a plain nullable column with no
 * default requiring backfill — measured at ~300-400ms on the same table.
 * INSTANT cannot be combined with an explicit LOCK= clause (MySQL errors:
 * "Incorrect usage of ALGORITHM=INSTANT and LOCK=NONE/SHARED/EXCLUSIVE") —
 * confirmed by testing, not assumed; the column addition itself is
 * non-blocking as an inherent property of INSTANT, so no LOCK clause is
 * needed or accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE properties '
            . 'ADD COLUMN p24_activation_last_checked_at TIMESTAMP NULL DEFAULT NULL AFTER p24_listing_last_synced_at, '
            . 'ALGORITHM=INSTANT'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE properties DROP COLUMN p24_activation_last_checked_at, ALGORITHM=INSTANT');
    }
};
