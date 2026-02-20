<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_audit_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('audit_run_id');
            $table->string('definition_key')->index();
            $table->string('entity_type')->index();
            $table->unsignedBigInteger('entity_id')->index();
            $table->string('period')->nullable();

            $table->decimal('expected_numeric', 18, 6)->nullable();
            $table->decimal('actual_numeric', 18, 6)->nullable();
            $table->decimal('diff_numeric', 18, 6)->nullable();

            $table->json('expected_json')->nullable();
            $table->json('actual_json')->nullable();
            $table->json('diff_json')->nullable();

            $table->string('severity')->default('info')->comment('info|warn|error');
            $table->string('message')->nullable();

            $table->timestamps();

            $table->foreign('audit_run_id')
                  ->references('id')
                  ->on('finance_audit_runs')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_audit_items');
    }
};
