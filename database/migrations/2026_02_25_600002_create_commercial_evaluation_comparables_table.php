<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_evaluation_comparables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commercial_evaluation_id');
            $table->string('address');
            $table->string('suburb')->nullable();
            $table->string('property_type');
            $table->decimal('size_m2', 12, 2)->nullable();
            $table->decimal('size_ha', 10, 4)->nullable();
            $table->bigInteger('sale_price')->nullable();
            $table->date('sale_date')->nullable();
            $table->bigInteger('price_per_m2')->nullable();
            $table->bigInteger('price_per_ha')->nullable();
            $table->string('notes')->nullable();
            $table->string('source')->nullable();

            $table->timestamps();

            $table->foreign('commercial_evaluation_id', 'ce_comparables_eval_fk')
                ->references('id')->on('commercial_evaluations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_evaluation_comparables');
    }
};
