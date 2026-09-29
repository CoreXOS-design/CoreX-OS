<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-09-23 — "Expiry is an agency-configurable setting with a
 * sensible default; pick the default and justify it in one line." 90
 * days: long enough to cover the real post-move-out window a tenant or
 * landlord actually needs to follow up in (deposit release, a dispute
 * raised soon after handover), short enough that a leaked/forwarded link
 * doesn't stay live indefinitely — and the agent can always issue a
 * fresh link from the inspection's own screen at any later date (a
 * dispute surfacing "2 years later" per Johan's own §0.2 ruling is
 * handled by re-generating, not by the original link never expiring).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('public_link_expiry_days')->nullable()->after('out_inspection_signing_window_days');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('public_link_expiry_days');
        });
    }
};
