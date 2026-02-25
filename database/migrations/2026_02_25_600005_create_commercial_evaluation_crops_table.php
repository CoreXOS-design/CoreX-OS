<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_evaluation_crops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commercial_evaluation_id');
            $table->string('crop_type');           // macadamias, bananas, sugar_cane, etc.
            $table->string('variety')->nullable();
            $table->decimal('hectares', 10, 2);
            $table->unsignedSmallInteger('year_planted')->nullable();
            $table->unsignedSmallInteger('age_years')->nullable();
            $table->unsignedSmallInteger('expected_lifespan_years')->nullable();
            $table->unsignedSmallInteger('remaining_productive_years')->nullable();
            $table->unsignedInteger('trees_per_hectare')->nullable();
            $table->unsignedInteger('total_trees')->nullable();
            $table->decimal('current_yield_tons_per_ha', 10, 2)->nullable();
            $table->decimal('expected_peak_yield_tons_per_ha', 10, 2)->nullable();
            $table->decimal('yield_percentage', 5, 2)->nullable();
            $table->bigInteger('current_price_per_ton')->nullable();   // cents
            $table->bigInteger('annual_revenue')->nullable();          // cents
            $table->bigInteger('annual_cost_per_ha')->nullable();      // cents
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('commercial_evaluation_id', 'ce_crops_eval_fk')
                  ->references('id')->on('commercial_evaluations')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_evaluation_crops');
    }
};
