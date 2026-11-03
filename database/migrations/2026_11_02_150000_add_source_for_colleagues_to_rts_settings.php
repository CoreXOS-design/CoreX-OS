<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v4 23.15 `rts.source_for_colleagues`: whose buyers feed an agent's stock after their own - `off`, `branch` or `agency` (default). Additive and
 * defaulted; the code reads the default until this runs (RtsSetting::hasSourceForColleaguesColumn()).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rts_settings', 'source_for_colleagues')) {
            Schema::table('rts_settings', function (Blueprint $table) {
                $table->string('source_for_colleagues', 10)->default('agency');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rts_settings', 'source_for_colleagues')) {
            Schema::table('rts_settings', function (Blueprint $table) {
                $table->dropColumn('source_for_colleagues');
            });
        }
    }
};
