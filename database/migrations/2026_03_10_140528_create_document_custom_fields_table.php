<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('docuperfect_templates')->cascadeOnDelete();
            $table->string('field_key');               // dot-notation e.g. custom.field_name
            $table->string('label');                    // human readable label
            $table->enum('assigned_to', ['agent', 'lessor', 'lessee', 'buyer', 'seller'])->default('agent');
            $table->enum('field_type', ['text', 'date', 'number'])->default('text');
            $table->string('default_value')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_custom_fields');
    }
};
