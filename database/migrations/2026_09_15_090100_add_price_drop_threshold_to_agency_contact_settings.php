<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-Core-Matches, Johan's "reduced into match" ruling — how big a price
 * cut has to be before it's worth telling a human about (his own example:
 * a R1,000 drop on a R2m house is not news). A percentage, not a flat
 * rand amount, so it scales correctly from a starter home to an estate.
 * Default 3% deliberately lower than mic_price_band_pct's 10 — that
 * setting tolerates drift in a MATCH SCORE, this one decides whether a
 * price cut is newsworthy, a materially stricter bar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('core_matches_price_drop_threshold_pct')->default(3)->after('core_matches_working_window_days');
        });
    }

    public function down(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->dropColumn('core_matches_price_drop_threshold_pct');
        });
    }
};
