<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Build 8b — agency-configurable cleaning controls for CmaComputeService.
 *
 *   cma_compute_recency_months — drop comps older than N months from the
 *     compute-engine input pool. Decoupled from
 *     presentations_default_period_months (which controls the hydrator's
 *     comp materialisation window AND the coverage-badge sample): the
 *     compute engine needs its own knob so an agent can tune the
 *     compute filter without bloating the hydrator's pool or shifting
 *     the badge thresholds. Range 1-600 months. Default 36.
 *
 *   cma_compute_iqr_multiplier — IQR multiplier for the lower-bound
 *     R/m² outlier cut. Lower fence = median − (multiplier × IQR).
 *     Median-anchored (per locked spec) is more aggressive than Q1-
 *     anchored Tukey: catches noise that has pushed Q1 down into the
 *     outlier zone. No top trim, no hardcoded price floor.
 *     Range 0.5-5.0. Default 1.5.
 *
 * Spec: Build 8b prompt — locked decisions.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'cma_compute_recency_months')) {
                $table->unsignedSmallInteger('cma_compute_recency_months')
                      ->nullable()
                      ->default(36)
                      ->after('presentations_freshness_days')
                      ->comment('Build 8b — recency window (months) for CmaComputeService input pool. Decoupled from presentations_default_period_months which drives the hydrator + coverage badge. Null falls back to service constant.');
            }
            if (!Schema::hasColumn('agencies', 'cma_compute_iqr_multiplier')) {
                $table->decimal('cma_compute_iqr_multiplier', 4, 2)
                      ->nullable()
                      ->default(1.50)
                      ->after('cma_compute_recency_months')
                      ->comment('Build 8b — IQR multiplier for R/m² lower-bound outlier fence (median − multiplier × IQR). 1.5 is Tukey standard. Null falls back to service constant.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            foreach (['cma_compute_iqr_multiplier', 'cma_compute_recency_months'] as $col) {
                if (Schema::hasColumn('agencies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
