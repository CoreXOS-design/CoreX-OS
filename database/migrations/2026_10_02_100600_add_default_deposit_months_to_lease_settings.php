<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-09-22 — "deposit default. When a property has no deposit
 * amount, default it to a configurable multiple of the monthly rent,
 * agency-configurable with a sensible default." One month is the SA-market
 * default (.ai/specs/rental-property-tab.md's own scraped listing example:
 * "Deposit R4710 One Month's rental R1500 Once off" reads as deposit = 1x
 * rent). Lives on lease_settings alongside expiry_notice_window_days /
 * show_lease_type_field — same "one row per agency, narrow independent
 * saver per column" pattern (LeaseSetting's own docblock), not a new
 * table: this is a Lease-domain figure (deposit_amount ends up on the
 * Lease record), not a property-listing figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            $table->decimal('default_deposit_months', 4, 2)->nullable()->after('show_lease_type_field');
        });
    }

    public function down(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            $table->dropColumn('default_deposit_months');
        });
    }
};
