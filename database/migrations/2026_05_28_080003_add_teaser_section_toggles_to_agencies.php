<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Part A3 — per-agency teaser section visibility toggles.
 *
 * The teaser view shows broad area context + locks property-specific data
 * until lead capture. These columns let the agency choose what counts as
 * "broad enough to share" vs "valuable enough to gate":
 *
 *   teaser_default_show_suburb_stats       — suburb median/sales count
 *   teaser_default_show_market_position    — where subject sits in price band
 *   teaser_default_show_asking_range       — pricing range (CMA lower/upper)
 *   teaser_default_show_holding_cost_summary — coarse "cost of waiting" number
 *
 * Defaults match the spec's recommendations: area context on (suburb_stats,
 * asking_range), property-specific off (market_position, holding_cost).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'teaser_default_show_suburb_stats')) {
                $table->boolean('teaser_default_show_suburb_stats')->default(true)
                    ->after('snapshot_link_ip_masking');
            }
            if (!Schema::hasColumn('agencies', 'teaser_default_show_market_position')) {
                $table->boolean('teaser_default_show_market_position')->default(false)
                    ->after('teaser_default_show_suburb_stats');
            }
            if (!Schema::hasColumn('agencies', 'teaser_default_show_asking_range')) {
                $table->boolean('teaser_default_show_asking_range')->default(true)
                    ->after('teaser_default_show_market_position');
            }
            if (!Schema::hasColumn('agencies', 'teaser_default_show_holding_cost_summary')) {
                $table->boolean('teaser_default_show_holding_cost_summary')->default(false)
                    ->after('teaser_default_show_asking_range');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            foreach ([
                'teaser_default_show_holding_cost_summary',
                'teaser_default_show_asking_range',
                'teaser_default_show_market_position',
                'teaser_default_show_suburb_stats',
            ] as $col) {
                if (Schema::hasColumn('agencies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
