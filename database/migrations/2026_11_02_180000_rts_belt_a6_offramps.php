<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v4, belt step A6 (road-to-sold.md 23.11 / 23.12 / 23.15):
 *   rts_park_reasons   station (nullable = any station; a belt stage key or "result") and kind (off_ramp | blocker): the off-ramp reasons are listed per station
 *   rts_settings       flag_offramp_excess_pct (default 25): the training flag fires when an agent's off-ramp rate at a station is this many percent above the
 *                      branch's (the agency's) rate
 * Additive; a re-run changes nothing. The station reasons themselves are seeded per agency on first use (ParkReasonService), not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // rts_park_reasons is created by 2026_12_20_100000 (future-dated: it sorts AFTER this file on a from-scratch build, which creates it with these columns already);
        // here it exists only on an environment that ran that file first, and then it needs the columns added
        if (Schema::hasTable('rts_park_reasons')) {
        Schema::table('rts_park_reasons', function (Blueprint $t) {
            if (! Schema::hasColumn('rts_park_reasons', 'station')) {
                $t->string('station', 30)->nullable()->after('track');
            }
            if (! Schema::hasColumn('rts_park_reasons', 'kind')) {
                $t->string('kind', 12)->default('off_ramp')->after('station');
            }
        });
        }
        if (! Schema::hasColumn('rts_settings', 'flag_offramp_excess_pct')) {
            Schema::table('rts_settings', function (Blueprint $t) {
                $t->unsignedSmallInteger('flag_offramp_excess_pct')->default(25);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rts_park_reasons')) {
            Schema::table('rts_park_reasons', function (Blueprint $t) {
                foreach (['station', 'kind'] as $c) {
                    if (Schema::hasColumn('rts_park_reasons', $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }
        if (Schema::hasColumn('rts_settings', 'flag_offramp_excess_pct')) {
            Schema::table('rts_settings', function (Blueprint $t) {
                $t->dropColumn('flag_offramp_excess_pct');
            });
        }
    }
};
