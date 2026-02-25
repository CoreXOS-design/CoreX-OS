<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_evaluations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->enum('status', ['draft', 'completed', 'archived'])->default('draft');
            $table->enum('property_type', ['commercial', 'industrial', 'hospitality', 'agricultural']);

            // Property Details
            $table->string('property_name');
            $table->text('address')->nullable();
            $table->string('suburb')->nullable();
            $table->string('town')->nullable();
            $table->string('province')->default('KwaZulu-Natal');
            $table->string('erf_number')->nullable();
            $table->string('zoning')->nullable();
            $table->decimal('total_land_size_m2', 12, 2)->nullable();
            $table->decimal('total_land_size_ha', 10, 4)->nullable();
            $table->decimal('total_building_size_m2', 12, 2)->nullable();
            $table->unsignedSmallInteger('year_built')->nullable();
            $table->enum('condition', ['excellent', 'good', 'fair', 'poor'])->nullable();
            $table->bigInteger('asking_price')->nullable();
            $table->bigInteger('municipal_evaluation')->nullable();
            $table->string('seller_name')->nullable();
            $table->text('notes')->nullable();

            // Computed results
            $table->json('evaluation_json')->nullable();
            $table->bigInteger('recommended_range_low')->nullable();
            $table->bigInteger('recommended_range_mid')->nullable();
            $table->bigInteger('recommended_range_high')->nullable();
            $table->string('primary_method')->nullable();
            $table->timestamp('evaluated_at')->nullable();

            $table->timestamps();

            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_evaluations');
    }
};
