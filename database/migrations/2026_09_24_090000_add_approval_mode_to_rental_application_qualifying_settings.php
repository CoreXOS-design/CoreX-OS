<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-430 Part A, 2026-09-24 — Johan, via Sherry (single-person Cape Town
 * agency): today an application is reviewed, then sent for authorisation to
 * a SECOND person who approves it. For a one-person agency that hand-off is
 * a screen she sends to herself. `two_step` (default) is today's behaviour,
 * unchanged for every existing agency; `one_step` lets an agent who already
 * holds RO/CO tier approve/decline directly, skipping the hand-off.
 *
 * Nullable, no DB default — same pattern as return_gate_method
 * (2026_09_13_130000): RentalApplicationQualifyingSetting::approvalModeFor()
 * resolves the default in PHP, never creating a row on read (STANDARDS.md
 * Rule 17's safe pattern).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->string('approval_mode', 20)->nullable()->after('require_fica_before_authorisation');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn('approval_mode');
        });
    }
};
