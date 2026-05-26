<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('property_buyer_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->unsignedSmallInteger('score'); // 0-100
            $table->string('tier', 20); // perfect, strong, approximate
            $table->json('breakdown')->nullable();
            $table->json('missing_features')->nullable();
            $table->timestamp('computed_at');

            $table->unique(['property_id', 'contact_id']);
            $table->index(['contact_id', 'score']);
            $table->index(['property_id', 'score']);
        });

        Schema::create('buyer_portal_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamp('last_accessed_at')->nullable();
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('buyer_property_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->enum('response', ['interested', 'not_interested', 'viewing_requested']);
            $table->string('reason', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 30)->default('buyer_portal');
            $table->timestamp('responded_at');
            $table->timestamps();

            $table->index(['contact_id', 'property_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_property_responses');
        Schema::dropIfExists('buyer_portal_links');
        Schema::dropIfExists('property_buyer_matches');
    }
};
