<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_evaluation_financials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commercial_evaluation_id');
            $table->string('financial_year');
            $table->unsignedSmallInteger('period_months')->default(12);

            // Income
            $table->bigInteger('gross_revenue')->nullable();
            $table->bigInteger('rental_income')->nullable();
            $table->bigInteger('room_revenue')->nullable();
            $table->bigInteger('food_beverage_revenue')->nullable();
            $table->bigInteger('other_income')->nullable();
            $table->decimal('vacancy_rate', 5, 2)->nullable();

            // Operating Expenses
            $table->bigInteger('rates_taxes')->nullable();
            $table->bigInteger('insurance')->nullable();
            $table->bigInteger('utilities')->nullable();
            $table->bigInteger('maintenance')->nullable();
            $table->bigInteger('management_fees')->nullable();
            $table->bigInteger('salaries_wages')->nullable();
            $table->bigInteger('security')->nullable();
            $table->bigInteger('marketing')->nullable();
            $table->bigInteger('food_beverage_cost')->nullable();
            $table->bigInteger('farm_operating_costs')->nullable();
            $table->bigInteger('other_expenses')->nullable();
            $table->bigInteger('total_expenses')->nullable();

            // Calculated
            $table->bigInteger('net_operating_income')->nullable();
            $table->bigInteger('ebitda')->nullable();

            $table->timestamps();

            $table->foreign('commercial_evaluation_id', 'ce_financials_eval_fk')
                ->references('id')->on('commercial_evaluations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_evaluation_financials');
    }
};
