<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('property_image_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('image_path', 512)
                ->comment('Path relative to storage (matches values in properties.gallery_images_json etc.)');
            $table->enum('status', ['queued', 'processing', 'complete', 'failed'])->default('queued');
            $table->json('detected_features')->nullable()->comment('[{token, confidence}]');
            $table->json('detected_spaces')->nullable()->comment('[{token, confidence}]');
            $table->json('raw_response')->nullable()->comment('Full Claude vision response for debug');
            $table->decimal('cost_usd', 8, 5)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'property_id']);
            $table->index('status');
            $table->index('image_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_image_analyses');
    }
};
