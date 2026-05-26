<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IMPORTANT: This table is IMMUTABLE by design.
     * The model will block update() and delete() via boot method.
     * No updated_at, no deleted_at. Only created_at.
     * Corrections are done by inserting a reversal transaction.
     */
    public function up(): void
    {
        Schema::create('leave_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_employee_id')->constrained('payroll_employees');
            $table->foreignId('user_id')->constrained();
            $table->foreignId('leave_type_id')->constrained('leave_types');
            $table->date('cycle_start_date');
            $table->enum('transaction_type', [
                'opening_balance', 'accrual', 'application_approved',
                'application_cancelled', 'manual_adjustment', 'carry_over',
                'forfeiture', 'termination_payout', 'reversal',
            ]);
            $table->decimal('days_delta', 7, 3);
            $table->date('effective_date');
            $table->text('description');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('reversal_of_transaction_id')->nullable();
            $table->timestamp('created_at')->nullable();
            // No updated_at — immutable by design
            // No deleted_at — immutable by design

            $table->foreign('reversal_of_transaction_id')
                ->references('id')->on('leave_transactions')
                ->nullOnDelete();

            $table->index(['payroll_employee_id', 'leave_type_id', 'effective_date'], 'leave_txn_employee_type_date');
            $table->index(['agency_id', 'transaction_type']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_transactions');
    }
};
