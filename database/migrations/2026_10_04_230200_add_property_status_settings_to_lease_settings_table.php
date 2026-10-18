<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-renewals.md §15 (GATE 2) — three independent per-
 * transition toggles (rows 2, 6, 7), each default ON, plus the fallback
 * on-market status for rows 6/7 when no status_before_letting was stored
 * (a lease active before this feature shipped). No new PROPERTY STATUS
 * is introduced — this fallback is a pointer at an EXISTING agency
 * property_status value, same PropertySettingItem vocabulary the lease
 * type / lease settings already use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            $table->boolean('auto_readvertise_on_notice')->default(true)->after('tenant_notice_period_days');
            $table->boolean('auto_restore_status_on_lease_ended')->default(true)->after('auto_readvertise_on_notice');
            $table->boolean('auto_restore_status_on_lease_cancelled')->default(true)->after('auto_restore_status_on_lease_ended');
            $table->string('default_pre_let_status', 40)->nullable()->after('auto_restore_status_on_lease_cancelled');
        });
    }

    public function down(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            $table->dropColumn(['auto_readvertise_on_notice', 'auto_restore_status_on_lease_ended', 'auto_restore_status_on_lease_cancelled', 'default_pre_let_status']);
        });
    }
};
