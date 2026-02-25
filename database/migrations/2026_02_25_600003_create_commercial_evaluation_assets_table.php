<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_evaluation_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commercial_evaluation_id');
            $table->string('category');
            $table->string('description');
            $table->unsignedInteger('quantity')->nullable();
            $table->bigInteger('estimated_value')->nullable();
            $table->string('notes')->nullable();

            $table->timestamps();

            $table->foreign('commercial_evaluation_id', 'ce_assets_eval_fk')
                ->references('id')->on('commercial_evaluations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_evaluation_assets');
    }
};
