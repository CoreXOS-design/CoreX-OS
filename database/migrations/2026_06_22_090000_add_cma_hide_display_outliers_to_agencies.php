<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-78 FIX 3 — agency toggle for hiding valuation price-outliers from the
 * DISPLAY comps table. When true (default), comps the CMA engine rejected as
 * IQR price-outliers are hidden from the rendered comps table too, so a R13m
 * sale doesn't sit in a R2.5m CMA's comparable list. The outlier THRESHOLD
 * itself stays the agency's existing cma_compute_iqr_multiplier — this is the
 * on/off lever for mirroring that exclusion in the display.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('cma_hide_display_outliers')->default(true)->after('cma_band_upper_pct');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('cma_hide_display_outliers');
        });
    }
};
