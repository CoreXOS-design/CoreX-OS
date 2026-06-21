<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-22 §1 / §1.5 / §5 — agency-configurable comp-selection + range thresholds.
 *
 * These drive App\Services\Presentations\CompPoolBuilder (the gate-then-rank
 * pipeline shared by MicSnapshotHydrator and AnalysisDataService) and the
 * recommended-range derivation. All defaults are Johan's locked values
 * (AT-22 comment, 11 Jun 2026). Null on any column falls back to the
 * CompPoolBuilder service constant — legacy agencies keep working.
 *
 *   comp_price_band_pct   — comps must fall within subject CMA anchor ± this %.
 *                           Anchored on the cleaned-pool market estimate, NOT
 *                           asking price. Default 25.00.
 *   comp_erf_band_pct     — erf-size proximity (± this %) used as a RANKING
 *                           factor inside the gates (not a hard drop — erf is
 *                           nullable on comp rows). Default 30.00.
 *   comp_radius_m         — initial Haversine radius (m). Default 300 (matches
 *                           CMA Info; the prior 1000 was too wide).
 *   comp_radius_widen_steps — CSV widen ladder. When < comp_min_count comps
 *                           resolve, the radius expands through these steps so
 *                           sparse/rural mandates still get a usable pool.
 *                           Default "300,600,1000,1500,3000".
 *   comp_radius_max_m     — hard ceiling for the widen ladder. Default 3000.
 *   comp_min_count        — minimum comps before the widen ladder stops.
 *                           Default 5.
 *   comp_max_count        — max comps shortlisted after ranking (don't
 *                           force-drop below this). Default 15.
 *   anchor_divergence_pct — if the cleaned-pool estimate diverges from the
 *                           vicinity average by more than this %, treat the
 *                           pool as thin/low and widen the radius. Default 25.00.
 *   range_lower_pct       — lower percentile for the recommended range.
 *                           Default 25 (P25).
 *   range_upper_pct       — upper percentile for the recommended range.
 *                           Default 75 (P75).
 *
 * Spec: .ai/specs/at22-presentation-quality.md §0.1, §1, §1.5, §5.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'comp_price_band_pct')) {
                $col = $table->decimal('comp_price_band_pct', 5, 2)
                    ->nullable()->default(25.00)
                    ->comment('AT-22 §1 — comp price band ± % around the cleaned-pool CMA anchor (not asking). Null → CompPoolBuilder constant.');
                // This migration's filename sorts BEFORE 2026_06_17_160000, which
                // creates cma_compute_iqr_multiplier. On a fresh/behind DB the
                // anchor doesn't exist yet, so only position after it when present
                // (incrementally-migrated envs) and otherwise append at the end —
                // column order is cosmetic and the app never depends on it.
                if (Schema::hasColumn('agencies', 'cma_compute_iqr_multiplier')) {
                    $col->after('cma_compute_iqr_multiplier');
                }
            }
            if (!Schema::hasColumn('agencies', 'comp_erf_band_pct')) {
                $table->decimal('comp_erf_band_pct', 5, 2)
                    ->nullable()->default(30.00)
                    ->after('comp_price_band_pct')
                    ->comment('AT-22 §1 — erf-size proximity ± % used as a ranking factor (not a hard drop). Null → constant.');
            }
            if (!Schema::hasColumn('agencies', 'comp_radius_m')) {
                $table->unsignedSmallInteger('comp_radius_m')
                    ->nullable()->default(300)
                    ->after('comp_erf_band_pct')
                    ->comment('AT-22 §1 — initial comp radius (m). Default 300 (the prior presentations_default_radius_m 1000 was too wide). Null → constant.');
            }
            if (!Schema::hasColumn('agencies', 'comp_radius_widen_steps')) {
                $table->string('comp_radius_widen_steps', 120)
                    ->nullable()->default('300,600,1000,1500,3000')
                    ->after('comp_radius_m')
                    ->comment('AT-22 §1 — CSV widen ladder for the radius when comps are thin. Null → constant ladder.');
            }
            if (!Schema::hasColumn('agencies', 'comp_radius_max_m')) {
                $table->unsignedSmallInteger('comp_radius_max_m')
                    ->nullable()->default(3000)
                    ->after('comp_radius_widen_steps')
                    ->comment('AT-22 §1 — hard ceiling for the radius widen ladder. Default 3000 (rural mandates must resolve). Null → constant.');
            }
            if (!Schema::hasColumn('agencies', 'comp_min_count')) {
                $table->unsignedSmallInteger('comp_min_count')
                    ->nullable()->default(10)
                    ->after('comp_radius_max_m')
                    ->comment('AT-22 §1 — minimum comps before the widen ladder stops expanding. Default 10 (round-1: auto-widen 300→600→1000m to catch on-profile comps just outside 300m). Null → constant.');
            }
            if (!Schema::hasColumn('agencies', 'comp_max_count')) {
                $table->unsignedSmallInteger('comp_max_count')
                    ->nullable()->default(15)
                    ->after('comp_min_count')
                    ->comment('AT-22 §1 — max comps shortlisted after ranking (PRES 87 curated 13; do not force-drop). Null → constant.');
            }
            if (!Schema::hasColumn('agencies', 'anchor_divergence_pct')) {
                $table->decimal('anchor_divergence_pct', 5, 2)
                    ->nullable()->default(25.00)
                    ->after('comp_max_count')
                    ->comment('AT-22 §1.5 — widen the radius when the cleaned-pool estimate diverges from the vicinity average by more than this %. Null → constant.');
            }
            if (!Schema::hasColumn('agencies', 'range_lower_pct')) {
                $table->unsignedTinyInteger('range_lower_pct')
                    ->nullable()->default(25)
                    ->after('anchor_divergence_pct')
                    ->comment('AT-22 §5 — lower percentile for the recommended range. Default 25 (P25). Null → constant.');
            }
            if (!Schema::hasColumn('agencies', 'range_upper_pct')) {
                $table->unsignedTinyInteger('range_upper_pct')
                    ->nullable()->default(75)
                    ->after('range_lower_pct')
                    ->comment('AT-22 §5 — upper percentile for the recommended range. Default 75 (P75). Null → constant.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            foreach ([
                'range_upper_pct', 'range_lower_pct', 'anchor_divergence_pct',
                'comp_max_count', 'comp_min_count', 'comp_radius_max_m',
                'comp_radius_widen_steps', 'comp_radius_m', 'comp_erf_band_pct',
                'comp_price_band_pct',
            ] as $col) {
                if (Schema::hasColumn('agencies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
