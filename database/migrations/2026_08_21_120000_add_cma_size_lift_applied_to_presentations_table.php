<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Johan (2026-08-21, size-lift ruling) — records the agent's explicit
     * choice to apply the CMA size-normalised lift (median R/m² x subject
     * extent) instead of the default plain comp-median headline. Recorded
     * on the presentation, not the version, so it persists across
     * regenerates the same way cma_selected_range does. Default false:
     * the lift is opt-in, never automatic.
     */
    public function up(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            $table->boolean('cma_size_lift_applied')->default(false)->after('cma_selected_range');
        });
    }

    public function down(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            $table->dropColumn('cma_size_lift_applied');
        });
    }
};
