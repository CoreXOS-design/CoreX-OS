<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3e E — agency-scoped holding-cost defaults.
 *
 * Coarse, deliberately simple inputs so HoldingCostEstimator can produce a
 * sensible monthly cost without a per-municipality rates table (deferred
 * per CLAUDE.md non-negotiable #6 — no half-built infrastructure).
 *
 * Defaults are seeded for the KZN South Coast at typical 2026 levels and
 * can be tuned per agency.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'presentations_default_rates_per_million_zar')) {
                $table->unsignedInteger('presentations_default_rates_per_million_zar')
                    ->default(800)
                    ->after('presentations_default_radius_m')
                    ->comment('Monthly municipal rates per R1M of property value.');
            }
            if (!Schema::hasColumn('agencies', 'presentations_default_levies_sectional_per_m2_zar')) {
                $table->unsignedSmallInteger('presentations_default_levies_sectional_per_m2_zar')
                    ->default(25)
                    ->after('presentations_default_rates_per_million_zar')
                    ->comment('Monthly body-corporate levies per m² for sectional title only.');
            }
            if (!Schema::hasColumn('agencies', 'presentations_default_insurance_per_million_zar')) {
                $table->unsignedSmallInteger('presentations_default_insurance_per_million_zar')
                    ->default(200)
                    ->after('presentations_default_levies_sectional_per_m2_zar')
                    ->comment('Monthly building insurance per R1M of property value.');
            }
            if (!Schema::hasColumn('agencies', 'presentations_default_utilities_zar')) {
                $table->unsignedSmallInteger('presentations_default_utilities_zar')
                    ->default(1200)
                    ->after('presentations_default_insurance_per_million_zar')
                    ->comment('Flat monthly utilities estimate.');
            }
            if (!Schema::hasColumn('agencies', 'presentations_default_opportunity_cost_pct')) {
                $table->decimal('presentations_default_opportunity_cost_pct', 5, 2)
                    ->default(8.00)
                    ->after('presentations_default_utilities_zar')
                    ->comment('Annual % return on net equity; divided by 12 for monthly opportunity cost.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            foreach ([
                'presentations_default_opportunity_cost_pct',
                'presentations_default_utilities_zar',
                'presentations_default_insurance_per_million_zar',
                'presentations_default_levies_sectional_per_m2_zar',
                'presentations_default_rates_per_million_zar',
            ] as $col) {
                if (Schema::hasColumn('agencies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
