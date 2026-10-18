<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, QA1 rentals test, 2026-10-07 — a tenant approved for R10,000 was put on
 * a lease at the property's R11,400 with nothing said about the gap. What an
 * agency wants to happen when the lease rent is above the amount the tenant was
 * approved for is the agency's own policy: `warn` (default — the agent sees both
 * figures and must confirm with a reason, which is logged) or `block` (the lease
 * cannot be created above the approved amount).
 *
 * Nullable, no DB default — same pattern as approval_mode (2026_09_24_090000):
 * RentalApplicationQualifyingSetting::rentAboveApprovedModeFor() resolves the
 * default in PHP and never creates a row on read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->string('rent_above_approved_mode', 10)->nullable()->after('approval_mode');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn('rent_above_approved_mode');
        });
    }
};
