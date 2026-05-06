<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('property_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('recommendation_code', 50);
            $table->string('title', 255);
            $table->text('reasoning');
            $table->string('suggested_action', 500)->nullable();
            $table->string('seller_facing_title', 255)->nullable();
            $table->text('seller_facing_reasoning')->nullable();
            $table->timestamp('generated_at');
            $table->timestamp('dismissed_at')->nullable();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('actioned_at')->nullable();
            $table->foreignId('actioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'dismissed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_recommendations');
    }
};
