<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_computed_values', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('definition_id');
            $table->string('definition_key')->index();
            $table->unsignedInteger('definition_version')->index();

            $table->string('entity_type')->index();
            $table->unsignedBigInteger('entity_id')->index();

            $table->string('period')->nullable()->comment('YYYY-MM');

            $table->decimal('value_numeric', 18, 6)->nullable();
            $table->json('value_json')->nullable();

            $table->string('input_hash', 64)->nullable();
            $table->string('engine_version', 20)->default('v0');

            $table->timestamp('computed_at')->useCurrent();

            $table->timestamps();

            $table->foreign('definition_id')
                  ->references('id')
                  ->on('finance_definitions')
                  ->onDelete('cascade');

            $table->unique(
                ['definition_id', 'entity_type', 'entity_id', 'period'],
                'fcv_def_entity_period_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_computed_values');
    }
};
