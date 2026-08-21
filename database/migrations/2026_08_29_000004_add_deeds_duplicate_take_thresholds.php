<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/2026-08-20-property-status-prospecting.md — deeds-capture duplicate-match
 * take rule (Johan, 2026-08-21). Off-market age bands that decide whether a deeds
 * capture matching an existing property may be taken automatically, needs admin/BM
 * approval, or is refused outright. Agency-configurable per Johan's standing
 * principle: "complicated rules carry a setting" — never hardcoded.
 *
 *   deeds_duplicate_no_go_days   (X) younger than this → refused outright
 *   deeds_duplicate_auto_take_days (Y) at/older than this → agent takes it automatically
 *   between X and Y → admin/BM approval required
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('suggested_action_thresholds', function (Blueprint $table) {
            $table->unsignedSmallInteger('deeds_duplicate_no_go_days')->default(7)->after('claim_release_days');
            $table->unsignedSmallInteger('deeds_duplicate_auto_take_days')->default(14)->after('deeds_duplicate_no_go_days');
        });
    }

    public function down(): void
    {
        Schema::table('suggested_action_thresholds', function (Blueprint $table) {
            $table->dropColumn(['deeds_duplicate_no_go_days', 'deeds_duplicate_auto_take_days']);
        });
    }
};
