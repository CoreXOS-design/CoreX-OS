<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PRES-CMA-REALFIX (Johan, 2026-06-16) — asymmetric CMA band default.
 *
 * The recommended-band half-widths shipped with a 7/7 placeholder. Reverse-
 * engineering CMA's OWN stated low/middle/high across 105 evidenced imported
 * reports (`.ai/audits/` band-evidence + the gap analysis) showed CMA sets an
 * ASYMMETRIC band — median ~11.5% below / ~13.6% above, tightening to ~10% /
 * ~13% on well-evidenced reports (≥8 comps), with NO price-scaling. We adopt
 * **10% below / 13% above** as the market-norm default.
 *
 * This migration ONLY moves the column DEFAULT (it does not touch the original
 * add-column migration) and backfills agencies still sitting on the old 7.00
 * default. An agency that has explicitly chosen any other value is left alone.
 */
return new class extends Migration {
    public function up(): void
    {
        // 1. Move the column defaults 7.00 → 10.00 / 13.00. Raw ALTER avoids a
        //    doctrine/dbal dependency for a default-only change and preserves
        //    the decimal(5,2) type exactly.
        DB::statement('ALTER TABLE agencies ALTER COLUMN cma_band_lower_pct SET DEFAULT 10.00');
        DB::statement('ALTER TABLE agencies ALTER COLUMN cma_band_upper_pct SET DEFAULT 13.00');

        // 2. Backfill ONLY agencies still on the old 7.00 default — per column,
        //    so an agency that customised one edge keeps the other. Anything
        //    that isn't exactly 7.00 is treated as an explicit choice.
        $lower = DB::table('agencies')->where('cma_band_lower_pct', 7.00)
            ->update(['cma_band_lower_pct' => 10.00]);
        $upper = DB::table('agencies')->where('cma_band_upper_pct', 7.00)
            ->update(['cma_band_upper_pct' => 13.00]);

        $msg = "    → cma_band default backfill: lower 7→10 on {$lower} agencies, upper 7→13 on {$upper} agencies";
        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, $msg . PHP_EOL);
        }
        Log::info('cma_band asymmetric-default backfill', ['lower_updated' => $lower, 'upper_updated' => $upper]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE agencies ALTER COLUMN cma_band_lower_pct SET DEFAULT 7.00');
        DB::statement('ALTER TABLE agencies ALTER COLUMN cma_band_upper_pct SET DEFAULT 7.00');
        // Reverse the backfill for rows we moved to the new market-norm default.
        DB::table('agencies')->where('cma_band_lower_pct', 10.00)->update(['cma_band_lower_pct' => 7.00]);
        DB::table('agencies')->where('cma_band_upper_pct', 13.00)->update(['cma_band_upper_pct' => 7.00]);
    }
};
