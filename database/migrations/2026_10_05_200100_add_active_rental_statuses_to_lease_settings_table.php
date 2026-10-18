<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-command-centre.md §Unoccupied — Johan: the Unoccupied
 * tile was counting every rental property with no active lease, including
 * withdrawn/expired/draft/prospecting/sold/let-out-elsewhere ones. Fixed
 * by restricting Unoccupied to properties whose STATUS is one this agency
 * considers "active rental stock" — agency-configurable (never hardcoded,
 * CLAUDE.md non-negotiable #9), nullable so an agency that never visits
 * this setting gets the computed default (Property::systemStatuses() minus
 * Property::OFF_MARKET_STATUSES — the existing on-market definition
 * already used everywhere else in CoreX) rather than an empty/broken list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            $table->json('active_rental_statuses')->nullable()->after('default_pre_let_status');
        });
    }

    public function down(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            $table->dropColumn('active_rental_statuses');
        });
    }
};
