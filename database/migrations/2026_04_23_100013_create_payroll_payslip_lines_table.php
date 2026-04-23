<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_payslip_id')->constrained('payroll_payslips')->cascadeOnDelete();
            $table->enum('line_type', ['earning', 'deduction', 'employer_contribution']);
            $table->unsignedBigInteger('source_type_id');
            $table->string('code_snapshot', 30);
            $table->string('label_snapshot', 100);
            $table->string('sars_source_code_snapshot', 4)->nullable();
            $table->decimal('amount', 15, 2);
            $table->boolean('is_taxable_snapshot');
            $table->tinyInteger('sort_order')->unsigned()->default(0);
            $table->timestamps();

            $table->index(['payroll_payslip_id', 'line_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payslip_lines');
    }
};
