<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_employees', 'working_days_per_week')) {
                $table->tinyInteger('working_days_per_week')->unsigned()->default(5)
                    ->after('pay_day_of_month');
            }
            if (!Schema::hasColumn('payroll_employees', 'working_pattern')) {
                $table->enum('working_pattern', ['monday_to_friday', 'monday_to_saturday', 'custom'])
                    ->default('monday_to_friday')
                    ->after('working_days_per_week');
            }
            if (!Schema::hasColumn('payroll_employees', 'working_days_mask')) {
                $table->tinyInteger('working_days_mask')->unsigned()->default(31)
                    ->after('working_pattern')
                    ->comment('Bitmap: bit 0=Mon, bit 1=Tue ... bit 6=Sun. Default 31 = Mon-Fri');
            }
            if (!Schema::hasColumn('payroll_employees', 'daily_rate_basis')) {
                $table->enum('daily_rate_basis', ['fixed_21_67', 'calendar_working_days', 'hours_per_day'])
                    ->default('fixed_21_67')
                    ->after('working_days_mask');
            }
            if (!Schema::hasColumn('payroll_employees', 'hours_per_day')) {
                $table->decimal('hours_per_day', 4, 2)->default(8.00)
                    ->after('daily_rate_basis');
            }
            if (!Schema::hasColumn('payroll_employees', 'take_on_completed_at')) {
                $table->timestamp('take_on_completed_at')->nullable()
                    ->after('hours_per_day');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_employees', function (Blueprint $table) {
            $cols = ['take_on_completed_at', 'hours_per_day', 'daily_rate_basis',
                     'working_days_mask', 'working_pattern', 'working_days_per_week'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('payroll_employees', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
