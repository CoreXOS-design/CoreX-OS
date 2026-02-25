<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_evaluation_livestock', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commercial_evaluation_id');
            $table->string('livestock_type');       // cattle_beef, cattle_dairy, sheep, etc.
            $table->string('breed')->nullable();
            $table->unsignedInteger('head_count');
            $table->unsignedInteger('breeding_stock_count')->nullable();
            $table->bigInteger('value_per_head')->nullable();    // cents
            $table->bigInteger('total_value')->nullable();       // cents
            $table->decimal('carrying_capacity_ha_per_lsu', 5, 2)->nullable();
            $table->decimal('hectares_used', 10, 2)->nullable();
            $table->bigInteger('annual_revenue')->nullable();    // cents
            $table->bigInteger('annual_cost')->nullable();       // cents
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('commercial_evaluation_id', 'ce_livestock_eval_fk')
                  ->references('id')->on('commercial_evaluations')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_evaluation_livestock');
    }
};
