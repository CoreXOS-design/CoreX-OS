<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('property_seller_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamp('last_accessed_at')->nullable();
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'revoked_at']);
        });

        Schema::create('property_seller_link_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained('property_seller_links')->cascadeOnDelete();
            $table->timestamp('accessed_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
        });

        Schema::create('property_marketing_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->enum('activity_type', [
                'portal_listed', 'portal_renewed', 'photos_refreshed', 'price_adjusted',
                'show_day_held', 'social_share', 'featured_upgrade', 'marketing_email', 'other',
            ]);
            $table->json('activity_data')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('logged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('internal_only')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['property_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_marketing_activities');
        Schema::dropIfExists('property_seller_link_accesses');
        Schema::dropIfExists('property_seller_links');
    }
};
