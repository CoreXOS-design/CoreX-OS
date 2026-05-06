<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('buyer_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->unique()->constrained('contacts')->cascadeOnDelete();
            $table->decimal('budget_min', 14, 2)->nullable();
            $table->decimal('budget_max', 14, 2)->nullable();
            $table->unsignedSmallInteger('bedrooms_min')->nullable();
            $table->unsignedSmallInteger('bedrooms_max')->nullable();
            $table->json('must_have_features')->nullable();
            $table->json('deal_breakers')->nullable();
            $table->json('preferred_areas')->nullable();
            $table->json('preferred_property_types')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('buyer_lost_risk_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->unsignedSmallInteger('score'); // 0-100
            $table->json('factors_breakdown')->nullable();
            $table->timestamp('computed_at');

            $table->index(['contact_id', 'computed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_lost_risk_scores');
        Schema::dropIfExists('buyer_preferences');
    }
};
