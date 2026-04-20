<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rmcp_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('variable_key', 100);
            $table->text('value')->nullable();
            $table->string('data_source', 50)->default('manual');
            $table->timestamps();

            $table->unique(['agency_id', 'variable_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rmcp_variables');
    }
};
