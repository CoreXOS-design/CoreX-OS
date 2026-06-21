<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-75 — agency-configurable MIC buyer-match knobs.
 *
 *  - mic_match_threshold: the floor % the MIC "Buyer matched" tile + slider
 *    anchor on (default 75). NOT "any match ≥1%".
 *  - mic_price_band_pct: how far a listing's price may drift past the buyer's
 *    stated band and still score full on price, before the canonical scorer
 *    decays it (default ±10%). Agents retune both; never hardcoded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('mic_match_threshold')->default(75)->after('min_countable_criteria');
            $table->unsignedTinyInteger('mic_price_band_pct')->default(10)->after('mic_match_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->dropColumn(['mic_match_threshold', 'mic_price_band_pct']);
        });
    }
};
