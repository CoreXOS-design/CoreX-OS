<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_tax_rebates', function (Blueprint $table) {
            $table->id();
            $table->date('tax_year_start')->unique();
            $table->decimal('primary_rebate', 15, 2);
            $table->decimal('secondary_rebate', 15, 2);
            $table->decimal('tertiary_rebate', 15, 2);
            $table->decimal('tax_threshold_under_65', 15, 2);
            $table->decimal('tax_threshold_65_74', 15, 2);
            $table->decimal('tax_threshold_75_plus', 15, 2);
            $table->decimal('medical_credit_main', 10, 2);
            $table->decimal('medical_credit_additional', 10, 2);
            $table->decimal('uif_ceiling_monthly', 15, 2);
            $table->decimal('uif_rate_percent', 5, 3);
            $table->decimal('sdl_threshold_annual', 15, 2);
            $table->decimal('sdl_rate_percent', 5, 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_tax_rebates');
    }
};
