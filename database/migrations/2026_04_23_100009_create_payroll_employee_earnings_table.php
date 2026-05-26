<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_employee_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies');
            $table->foreignId('payroll_employee_id')->constrained('payroll_employees')->cascadeOnDelete();
            $table->foreignId('earning_type_id')->constrained('payroll_earning_types')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['payroll_employee_id', 'effective_from', 'effective_to'], 'pee_employee_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_employee_earnings');
    }
};
