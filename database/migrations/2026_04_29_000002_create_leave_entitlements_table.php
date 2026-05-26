<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payroll_employee_id')->constrained('payroll_employees')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->date('cycle_start_date');
            $table->date('cycle_end_date');
            $table->decimal('entitlement_days', 5, 2)->default(0);
            $table->decimal('accrued_days', 5, 2)->default(0);
            $table->decimal('carryover_from_previous_cycle', 5, 2)->default(0);
            $table->decimal('taken_days', 5, 2)->default(0);
            $table->decimal('pending_days', 5, 2)->default(0);
            // available_days as a regular column — updated by service.
            // MySQL generated columns with nullable FKs can cause issues on some versions.
            $table->decimal('available_days', 5, 2)->default(0)
                ->comment('Derived: accrued + carryover - taken - pending. Updated by LeaveBalanceService.');
            $table->timestamp('last_accrual_run_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['payroll_employee_id', 'leave_type_id', 'cycle_start_date'],
                'leave_entitlements_employee_type_cycle_unique'
            );
            $table->index(['agency_id', 'user_id']);
            $table->index(['agency_id', 'branch_id']);
            $table->index('cycle_end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_entitlements');
    }
};
