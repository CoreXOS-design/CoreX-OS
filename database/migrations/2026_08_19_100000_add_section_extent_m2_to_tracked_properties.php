<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/deeds-capture.md §6 Part A extent contract (Johan, 2026-08-19):
 * three distinct extent values, three distinct homes, never substituted:
 *   erf_extent_m2 (freehold Extent)          -> tracked_properties.erf_size_m2 (existing)
 *   cadastral_extent_m2 (freehold Cadastral) -> tracked_properties.cadastral_extent (existing)
 *   section_extent_m2 (sectional Section)    -> tracked_properties.section_extent_m2 (NEW — this migration)
 *
 * `cadastral_extent` was previously overloaded to also carry a sectional
 * unit's Section extent (the root cause of a sectional unit's floor size
 * landing in a promoted Property's erf-size column — properties 6166/6186).
 * This column gives Section extent its own home so it stops colliding with
 * Cadastral extent.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tracked_properties', function (Blueprint $table) {
            $table->decimal('section_extent_m2', 10, 2)->nullable()->after('cadastral_extent')
                  ->comment('Sectional title unit registered extent (cmainfo "Section extent") — NEVER the same value as cadastral_extent or erf_size_m2. .ai/specs/deeds-capture.md §6.');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_properties', function (Blueprint $table) {
            $table->dropColumn('section_extent_m2');
        });
    }
};
