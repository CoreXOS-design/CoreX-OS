<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRES-CMA-REALFIX (Johan, 2026-06-16) — recommended-band half-widths.
 *
 * The §5/§6 recommended band is the EVALUATED VALUE (the comp-median middle,
 * with the agent's condition % applied once) ± a tight, agency-configurable
 * percentage. Pre-fix the band was sourced from the raw pool P25/P75, which
 * for clean same-type pools is ~±6% but for type-contaminated pools blew out
 * to ±20%+ — a band wide enough to lose the message.
 *
 * These are DISTINCT from `range_lower_pct` / `range_upper_pct`, which are the
 * pool-distribution PERCENTILES (defaults 25 / 75) recorded in pool_stats —
 * NOT ± fractions. Reusing those would (a) break their percentile semantics
 * and (b) fail their 1–49 / 51–99 validation. Hence a dedicated pair.
 *
 * Default 7.00% each (the tight cluster the band-evidence pass measured for
 * clean same-type comp pools). Null → CompPoolBuilder::DEF_BAND_*_PCT.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'cma_band_lower_pct')) {
                $table->decimal('cma_band_lower_pct', 5, 2)
                    ->nullable()->default(7.00)
                    ->after('range_upper_pct')
                    ->comment('PRES-CMA-REALFIX — recommended-band LOWER half-width: lower = middle × (1 − pct/100). Null → constant 7.');
            }
            if (!Schema::hasColumn('agencies', 'cma_band_upper_pct')) {
                $table->decimal('cma_band_upper_pct', 5, 2)
                    ->nullable()->default(7.00)
                    ->after('cma_band_lower_pct')
                    ->comment('PRES-CMA-REALFIX — recommended-band UPPER half-width: upper = middle × (1 + pct/100). Null → constant 7.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            foreach (['cma_band_upper_pct', 'cma_band_lower_pct'] as $col) {
                if (Schema::hasColumn('agencies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
