<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payroll_employee_id')->constrained('payroll_employees');
            $table->foreignId('user_id')->constrained();
            $table->foreignId('leave_type_id')->constrained('leave_types');
            $table->string('application_number', 30)->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_half_day')->default(false);
            $table->enum('half_day_period', ['morning', 'afternoon'])->nullable();
            $table->decimal('working_days_requested', 5, 2);
            $table->smallInteger('calendar_days_requested')->unsigned();
            $table->text('reason')->nullable();
            $table->enum('status', [
                'draft', 'submitted', 'approved', 'rejected',
                'cancelled', 'taken', 'no_show',
            ])->default('submitted');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('decided_by_role', ['branch_manager', 'admin', 'owner'])->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('taken_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedBigInteger('payslip_id')->nullable();
            $table->boolean('affects_payroll')->default(false);
            $table->decimal('payroll_impact_amount', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'status']);
            $table->index(['agency_id', 'branch_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_applications');
    }
};
