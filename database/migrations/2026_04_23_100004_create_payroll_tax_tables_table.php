<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_tax_tables', function (Blueprint $table) {
            $table->id();
            $table->date('tax_year_start');
            $table->date('tax_year_end');
            $table->tinyInteger('bracket_order')->unsigned();
            $table->decimal('income_from', 15, 2);
            $table->decimal('income_to', 15, 2)->nullable();
            $table->decimal('base_tax', 15, 2);
            $table->decimal('rate_percent', 5, 2);
            $table->timestamps();

            $table->unique(['tax_year_start', 'bracket_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_tax_tables');
    }
};
