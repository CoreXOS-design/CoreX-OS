<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC funnel phase 2 (Johan 2026-08-13) — agency-configurable STALE-CLAIM thresholds.
 *
 * A pitched/claimed prospecting property sitting unworked WARNs the agent at `claim_warn_days`
 * (default 7) and becomes stale for BM/admin move-or-keep review at `claim_release_days` (default 10).
 * Working/updating the claim resets the timer. Per-agency, editable in the prospecting settings
 * screen + onboarding wizard — no hardcoded thresholds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suggested_action_thresholds', function (Blueprint $table) {
            $table->unsignedSmallInteger('claim_warn_days')->default(7)->after('colleague_claim_stale_days');
            $table->unsignedSmallInteger('claim_release_days')->default(10)->after('claim_warn_days');
        });
    }

    public function down(): void
    {
        Schema::table('suggested_action_thresholds', function (Blueprint $table) {
            $table->dropColumn(['claim_warn_days', 'claim_release_days']);
        });
    }
};
