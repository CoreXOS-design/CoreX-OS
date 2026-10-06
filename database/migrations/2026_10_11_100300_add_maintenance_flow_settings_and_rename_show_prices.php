<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §17.13 / §17.14 (foundation F2).
 *
 * 1. The new agency settings — all NULLABLE: a null column resolves to the
 *    DEFAULT_* constant at read time, exactly like every other rental setting
 *    (the row is never written on read). Defaults are neutral for any agency.
 * 2. The two "show prices" settings are RESTATED IN COST TERMS (§17.4.7). A
 *    plain column rename carries each agency's stored value across:
 *      rental_work_order_settings.show_prices_on_printed_job_card
 *        -> show_costs_on_printed_job_card
 *      rental_portal_settings.crew_link_show_prices -> crew_link_show_costs
 *    The code that reads them is switched from the selling columns to the
 *    cost columns in the SAME commit, so selling can never reach a crew.
 *
 * Idempotent (each step guarded).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_work_order_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_work_order_settings', 'default_parts_markup_percent')) {
                $table->decimal('default_parts_markup_percent', 6, 2)->nullable();
            }
            if (! Schema::hasColumn('rental_work_order_settings', 'default_labour_markup_percent')) {
                $table->decimal('default_labour_markup_percent', 6, 2)->nullable();
            }
            if (! Schema::hasColumn('rental_work_order_settings', 'variation_tolerance_percent')) {
                $table->decimal('variation_tolerance_percent', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('rental_work_order_settings', 'quote_estimate_term')) {
                $table->text('quote_estimate_term')->nullable();
            }
            if (! Schema::hasColumn('rental_work_order_settings', 'completion_response_window_days')) {
                $table->unsignedSmallInteger('completion_response_window_days')->nullable();
            }
            if (! Schema::hasColumn('rental_work_order_settings', 'tenant_completion_check_enabled')) {
                $table->boolean('tenant_completion_check_enabled')->nullable();
            }
            if (! Schema::hasColumn('rental_work_order_settings', 'notify_landlord_on_dispute')) {
                $table->boolean('notify_landlord_on_dispute')->nullable();
            }
            if (! Schema::hasColumn('rental_work_order_settings', 'notify_landlord_on_auto_variation')) {
                $table->boolean('notify_landlord_on_auto_variation')->nullable();
            }
            // percent | amount — the agency's fee on an outside contractor's quote (Decision 1); value 0 = off
            if (! Schema::hasColumn('rental_work_order_settings', 'external_quote_markup_type')) {
                $table->string('external_quote_markup_type', 10)->nullable();
            }
            if (! Schema::hasColumn('rental_work_order_settings', 'external_quote_markup_value')) {
                $table->decimal('external_quote_markup_value', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('rental_work_order_settings', 'dispute_notify_crew_immediately')) {
                $table->boolean('dispute_notify_crew_immediately')->nullable();
            }
        });

        if (Schema::hasColumn('rental_work_order_settings', 'show_prices_on_printed_job_card')
            && ! Schema::hasColumn('rental_work_order_settings', 'show_costs_on_printed_job_card')) {
            Schema::table('rental_work_order_settings', function (Blueprint $table) {
                $table->renameColumn('show_prices_on_printed_job_card', 'show_costs_on_printed_job_card');
            });
        }

        if (Schema::hasColumn('rental_portal_settings', 'crew_link_show_prices')
            && ! Schema::hasColumn('rental_portal_settings', 'crew_link_show_costs')) {
            Schema::table('rental_portal_settings', function (Blueprint $table) {
                $table->renameColumn('crew_link_show_prices', 'crew_link_show_costs');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_portal_settings', 'crew_link_show_costs')
            && ! Schema::hasColumn('rental_portal_settings', 'crew_link_show_prices')) {
            Schema::table('rental_portal_settings', function (Blueprint $table) {
                $table->renameColumn('crew_link_show_costs', 'crew_link_show_prices');
            });
        }

        if (Schema::hasColumn('rental_work_order_settings', 'show_costs_on_printed_job_card')
            && ! Schema::hasColumn('rental_work_order_settings', 'show_prices_on_printed_job_card')) {
            Schema::table('rental_work_order_settings', function (Blueprint $table) {
                $table->renameColumn('show_costs_on_printed_job_card', 'show_prices_on_printed_job_card');
            });
        }

        Schema::table('rental_work_order_settings', function (Blueprint $table) {
            foreach ([
                'default_parts_markup_percent', 'default_labour_markup_percent', 'variation_tolerance_percent',
                'quote_estimate_term', 'completion_response_window_days', 'tenant_completion_check_enabled',
                'notify_landlord_on_dispute', 'notify_landlord_on_auto_variation', 'external_quote_markup_type',
                'external_quote_markup_value', 'dispute_notify_crew_immediately',
            ] as $col) {
                if (Schema::hasColumn('rental_work_order_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
