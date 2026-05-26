<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies');
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('payroll_employee_id')->constrained('payroll_employees')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('payslip_number', 40)->unique();
            $table->string('employee_name_snapshot');
            $table->string('id_number_snapshot')->nullable();
            $table->string('tax_reference_snapshot')->nullable();
            $table->date('employment_date_snapshot');
            $table->string('designation_snapshot');
            $table->date('period_month');
            $table->date('pay_date');
            $table->decimal('total_earnings', 15, 2);
            $table->decimal('total_deductions', 15, 2);
            $table->decimal('taxable_income', 15, 2);
            $table->decimal('paye_amount', 15, 2);
            $table->decimal('uif_employee_amount', 15, 2);
            $table->decimal('uif_employer_amount', 15, 2);
            $table->decimal('sdl_amount', 15, 2);
            $table->decimal('net_pay', 15, 2);
            $table->foreignId('document_id')->nullable()->constrained('documents');
            $table->timestamp('pdf_generated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['payroll_run_id', 'payroll_employee_id']);
            $table->index(['user_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payslips');
    }
};
