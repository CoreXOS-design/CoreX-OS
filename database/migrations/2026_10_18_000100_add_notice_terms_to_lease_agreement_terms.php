<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/leases.md §18 — the lease's own notice and early-cancellation terms, as columns of the structured terms row
 * (`lease_agreement_terms`, one per lease): the notice period (length + unit), the earliest date notice may be given,
 * whether early cancellation is allowed, the notice it needs and the penalty wording. Columns only — no data is written
 * here (back-fill is the hand-run `leases:backfill-notice-terms`, dry-run first).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lease_agreement_terms')) {
            return;
        }

        Schema::table('lease_agreement_terms', function (Blueprint $table) {
            if (! Schema::hasColumn('lease_agreement_terms', 'notice_period')) {
                $table->unsignedSmallInteger('notice_period')->nullable();
            }
            if (! Schema::hasColumn('lease_agreement_terms', 'notice_period_unit')) {
                $table->string('notice_period_unit', 10)->nullable();          // days | weeks | months
            }
            if (! Schema::hasColumn('lease_agreement_terms', 'earliest_notice_date')) {
                $table->date('earliest_notice_date')->nullable();               // notice may not be given before this day
            }
            if (! Schema::hasColumn('lease_agreement_terms', 'early_cancellation_allowed')) {
                $table->string('early_cancellation_allowed', 3)->nullable();    // yes | no | null = not on record
            }
            if (! Schema::hasColumn('lease_agreement_terms', 'early_cancellation_notice')) {
                $table->unsignedSmallInteger('early_cancellation_notice')->nullable();
            }
            if (! Schema::hasColumn('lease_agreement_terms', 'early_cancellation_notice_unit')) {
                $table->string('early_cancellation_notice_unit', 10)->nullable();
            }
            if (! Schema::hasColumn('lease_agreement_terms', 'early_cancellation_penalty')) {
                $table->text('early_cancellation_penalty')->nullable();
            }
            if (! Schema::hasColumn('lease_agreement_terms', 'notice_terms_source')) {
                $table->string('notice_terms_source', 20)->nullable();          // captured | edited | carried_forward | agency_default | esign
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lease_agreement_terms')) {
            return;
        }

        Schema::table('lease_agreement_terms', function (Blueprint $table) {
            $cols = ['notice_period', 'notice_period_unit', 'earliest_notice_date', 'early_cancellation_allowed', 'early_cancellation_notice',
                'early_cancellation_notice_unit', 'early_cancellation_penalty', 'notice_terms_source'];
            $table->dropColumn(array_values(array_filter($cols, fn ($c) => Schema::hasColumn('lease_agreement_terms', $c))));
        });
    }
};
