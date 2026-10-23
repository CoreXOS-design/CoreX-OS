<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 - Road to Sold v2 phase 2 (road-to-sold.md sections 5.2, 10.5, 11.1, 13.1): the two agency thresholds the agent
 * view and the capture form read. Defaults mirror RtsSetting::SCALAR_DEFAULTS.
 *
 *   summary_ahead_from_pct   a task counts as "ahead" at or above this share of where the agent should be by now (105)
 *   capture_back_date_hours  how far back a captured task may be dated (48 hours); a future time is never accepted
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rts_settings', 'summary_ahead_from_pct')) {
                $table->unsignedSmallInteger('summary_ahead_from_pct')->default(105);
            }
            if (! Schema::hasColumn('rts_settings', 'capture_back_date_hours')) {
                $table->unsignedSmallInteger('capture_back_date_hours')->default(48);
            }
        });
    }

    public function down(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            foreach (['summary_ahead_from_pct', 'capture_back_date_hours'] as $column) {
                if (Schema::hasColumn('rts_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
