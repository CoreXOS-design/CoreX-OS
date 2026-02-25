<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_evaluation_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commercial_evaluation_id');
            $table->string('unit_name');
            $table->string('tenant_name')->nullable();
            $table->decimal('size_m2', 12, 2)->nullable();
            $table->bigInteger('monthly_rental')->nullable();
            $table->date('lease_start')->nullable();
            $table->date('lease_end')->nullable();
            $table->boolean('is_vacant')->default(false);
            $table->decimal('escalation_rate', 5, 2)->nullable();
            $table->string('notes')->nullable();

            $table->timestamps();

            $table->foreign('commercial_evaluation_id', 'ce_units_eval_fk')
                ->references('id')->on('commercial_evaluations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_evaluation_units');
    }
};
