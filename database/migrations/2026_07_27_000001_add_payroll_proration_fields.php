<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-237 B1/B2 — partial-period payroll (Johan's rules): a run has an
 * operator-selectable CUT DATE (agency-default, per-run overridable); BASIC
 * pro-rates to the cut, allowances pay full; a terminated employee always gets a
 * final (pro-rated) payslip in the run covering their leave date.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Run-level cut date. NULL = full period (cut = month-end) — the default.
        Schema::table('payroll_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_runs', 'cut_date')) {
                $table->date('cut_date')->nullable()->after('pay_date');
            }
        });

        // Which earning types pro-rate on a partial period. Default FALSE
        // (allowances/bonus/overtime/commission pay full); Basic is backfilled true.
        Schema::table('payroll_earning_types', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_earning_types', 'pro_rates_on_partial')) {
                $table->boolean('pro_rates_on_partial')->default(false)->after('affects_sdl_remuneration');
            }
        });
        DB::table('payroll_earning_types')->where('code', 'basic')->update(['pro_rates_on_partial' => true]);

        // Agency-configurable payroll defaults.
        Schema::table('agencies', function (Blueprint $table) {
            if (! Schema::hasColumn('agencies', 'payroll_default_cut_day')) {
                // NULL = default to full month (cut = period end). 1-31 = a default cut day-of-month.
                $table->unsignedTinyInteger('payroll_default_cut_day')->nullable()->after('id');
            }
            if (! Schema::hasColumn('agencies', 'payroll_default_daily_rate_basis')) {
                $table->enum('payroll_default_daily_rate_basis', ['fixed_21_67', 'calendar_working_days', 'hours_per_day'])
                    ->default('fixed_21_67')->after('payroll_default_cut_day');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            if (Schema::hasColumn('payroll_runs', 'cut_date')) {
                $table->dropColumn('cut_date');
            }
        });
        Schema::table('payroll_earning_types', function (Blueprint $table) {
            if (Schema::hasColumn('payroll_earning_types', 'pro_rates_on_partial')) {
                $table->dropColumn('pro_rates_on_partial');
            }
        });
        Schema::table('agencies', function (Blueprint $table) {
            foreach (['payroll_default_cut_day', 'payroll_default_daily_rate_basis'] as $c) {
                if (Schema::hasColumn('agencies', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
