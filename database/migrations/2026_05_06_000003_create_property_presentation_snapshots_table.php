<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('property_presentation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->unsignedBigInteger('presentation_id')->nullable();
            $table->timestamp('generated_at');
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('market_data_snapshot')->nullable();
            $table->decimal('recommended_price_at_time', 14, 2)->nullable();
            $table->unsignedInteger('days_on_market_at_time')->nullable();
            $table->boolean('is_dynamic')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_presentation_snapshots');
    }
};
