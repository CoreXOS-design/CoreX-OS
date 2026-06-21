<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-77 — agency-configurable axis weights for the CMA comparable-stock
 * COMPARABILITY scorer (replaces the ~97%-everywhere membership score).
 *
 * JSON shape (null → code defaults in CompetitorStockMatchService):
 *   {
 *     "sectional": {"price":25,"beds":20,"baths":10,"garages":5,"type":15,"size":30},
 *     "freehold":  {"price":25,"beds":25,"baths":15,"garages":5,"type":20,"size":10}
 *   }
 * `size` = unit floor m² for sectional (heavy), erf m² for freehold (light) —
 * Johan's domain rule. Per-axis weights are relative; the scorer normalises by
 * the sum of the axes actually present on the comp, so a missing attribute just
 * drops out gracefully.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->json('competitor_stock_weights')->nullable()->after('competitor_stock_default_display_count');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('competitor_stock_weights');
        });
    }
};
