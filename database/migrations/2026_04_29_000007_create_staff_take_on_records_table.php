<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_take_on_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('payroll_employee_id')->nullable()->constrained('payroll_employees')->nullOnDelete();
            $table->date('take_on_date');
            $table->text('previous_employer')->nullable();
            $table->date('previous_employment_start_date')->nullable();
            $table->date('original_employment_start_date');
            $table->enum('take_on_type', [
                'new_hire', 'migration_from_old_system', 'transfer_from_other_branch',
            ]);
            $table->boolean('personal_details_verified')->default(false);
            $table->boolean('banking_details_verified')->default(false);
            $table->boolean('tax_details_verified')->default(false);
            $table->boolean('employment_terms_verified')->default(false);
            $table->boolean('compensation_setup_verified')->default(false);
            $table->boolean('leave_balances_captured')->default(false);
            $table->boolean('compliance_documents_uploaded')->default(false);
            $table->boolean('signed_employment_contract_uploaded')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->enum('current_step', [
                'user', 'personal', 'tax_banking', 'employment',
                'compensation', 'leave', 'compliance', 'review',
            ])->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'completed_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_take_on_records');
    }
};
