<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/leases.md §18.2 — the agency's DEFAULT notice and early-cancellation terms (what a new lease starts with and
 * what `leases:backfill-notice-terms` writes onto leases that have none). All nullable: a null reads as the model's
 * DEFAULT_* constant (read-time default pattern); the notice LENGTH is the existing `tenant_notice_period_days`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lease_settings')) {
            return;
        }

        Schema::table('lease_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('lease_settings', 'tenant_notice_period_unit')) {
                $table->string('tenant_notice_period_unit', 10)->nullable();
            }
            if (! Schema::hasColumn('lease_settings', 'default_earliest_notice_months')) {
                $table->unsignedTinyInteger('default_earliest_notice_months')->nullable();
            }
            if (! Schema::hasColumn('lease_settings', 'default_early_cancellation_allowed')) {
                $table->string('default_early_cancellation_allowed', 3)->nullable();
            }
            if (! Schema::hasColumn('lease_settings', 'default_early_cancellation_notice')) {
                $table->unsignedSmallInteger('default_early_cancellation_notice')->nullable();
            }
            if (! Schema::hasColumn('lease_settings', 'default_early_cancellation_notice_unit')) {
                $table->string('default_early_cancellation_notice_unit', 10)->nullable();
            }
            if (! Schema::hasColumn('lease_settings', 'default_early_cancellation_penalty')) {
                $table->text('default_early_cancellation_penalty')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lease_settings')) {
            return;
        }

        Schema::table('lease_settings', function (Blueprint $table) {
            $cols = ['tenant_notice_period_unit', 'default_earliest_notice_months', 'default_early_cancellation_allowed', 'default_early_cancellation_notice',
                'default_early_cancellation_notice_unit', 'default_early_cancellation_penalty'];
            $table->dropColumn(array_values(array_filter($cols, fn ($c) => Schema::hasColumn('lease_settings', $c))));
        });
    }
};
