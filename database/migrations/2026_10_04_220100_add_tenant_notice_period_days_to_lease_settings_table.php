<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-renewals.md §2 — tenant notice period, agency-configurable,
 * default 30 days (SA residential-lease convention, not a legal minimum CoreX
 * enforces). Same per-agency-row pattern as the other LeaseSetting columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            $table->integer('tenant_notice_period_days')->nullable()->after('default_deposit_months');
        });
    }

    public function down(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            $table->dropColumn('tenant_notice_period_days');
        });
    }
};
